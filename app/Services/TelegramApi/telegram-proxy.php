<?php
/**
 * telegram-proxy.php
 * GET  → diagnostic: check if Telegram is reachable
 * POST → forward request to api.telegram.org
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHERE THIS RUNS
 * This file is NOT executed by the Laravel app. It is the source-of-truth copy,
 * kept in the repo at app/Services/TelegramApi/telegram-proxy.php, and must be
 * DEPLOYED BY HAND to the foreign VPS web root that serves the Telegram proxy:
 *
 *   LIVE (used by prod):  tm.factorland.ir  (46.165.210.28)
 *       web root file  →  <docroot>/telegram-proxy.php
 *       upload:           FTP  ftp.tm.factorland.ir:21  user newtm@tm.factorland.ir
 *       (no SSH on port 22 for this box)
 *
 *   ALT / fallback:       tg.factorland.ir  (103.75.196.85, SSH "Glutamarket-EU")
 *       /var/www/html/tg.factorland.ir/telegram-proxy.php
 *
 * The Iran API server (185.8.174.229) cannot reach api.telegram.org, so every
 * Telegram Bot API call from Laravel goes:
 *   TelegramApi::sendRequest() → POST TELEGRAM_PROXY_URL (this file) → api.telegram.org
 * TELEGRAM_PROXY_URL in the Iran server .env points at tm.factorland.ir.
 *
 * DEPLOY CHECKLIST
 *   1. Back up the current remote file (telegram-proxy.php.bak.YYYYMMDD).
 *   2. Upload this file to the web root.
 *   3. chown to the web-server user, run `php -l` to check syntax.
 *   4. From the Iran server: GET https://tm.factorland.ir/telegram-proxy.php
 *      → expect {"proxy_status":"online","telegram_reachable":true}.
 *   5. Send a real photo through the bot and run the tracking-code flow.
 *   Do NOT run `php artisan config:cache` on prod — it nulls TELEGRAM_* env vars.
 *
 * Companion file: webhook-relay.php (same web root, same deploy-by-hand rule).
 * Full detail + incident history: docs/telegram-proxy-connectivity.md
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── CONFIG ──────────────────────────────────
$allowedIPs = ['185.8.174.229'];
$logFile    = __DIR__ . '/proxy.log';

// ── LOG HELPER ───────────────────────────────
function log_write(string $level, string $msg, array $ctx = []): void
{
    global $logFile;

    // Create file if not exists
    if (! file_exists($logFile)) {
        file_put_contents($logFile, "");
    }

    // Rotate if bigger than 2MB
    if (filesize($logFile) > 2 * 1024 * 1024) {
        rename($logFile, $logFile . '.bak');
        file_put_contents($logFile, "");
    }

    $line = sprintf("[%s] [%s] %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $msg,
        $ctx ? ' | ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : ''
    );

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

// ── RESPONSE HELPER ──────────────────────────
function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── IP CHECK ─────────────────────────────────
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (! in_array($clientIP, $allowedIPs)) {
    log_write('warn', 'Access denied', ['ip' => $clientIP]);
    respond(['error' => 'Access denied'], 403);
}

// ── GET → DIAGNOSTIC ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'https://api.telegram.org',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_NOBODY         => true,
    ]);

    $start = microtime(true);
    curl_exec($ch);
    $ms        = round((microtime(true) - $start) * 1000);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $reachable = (curl_errno($ch) === 0 && $httpCode > 0);
    curl_close($ch);

    log_write('info', 'Diagnostic test', [
        'telegram_reachable' => $reachable,
        'http_code'          => $httpCode,
        'ms'                 => $ms,
        'error'              => $curlError ?: null,
    ]);

    respond([
        'proxy_status'       => 'online',
        'telegram_reachable' => $reachable,
        'telegram_http_code' => $httpCode,
        'response_ms'        => $ms,
        'curl_error'         => $curlError ?: null,
        'server_ip'          => $_SERVER['SERVER_ADDR'] ?? 'unknown',
        'time'               => date('Y-m-d H:i:s'),
    ]);
}

// ── POST → PROXY ─────────────────────────────
$method = trim($_POST['method'] ?? '');
$token  = trim($_POST['bot_token'] ?? '');
$params = json_decode($_POST['params'] ?? '[]', true);

if (! is_array($params)) {
    $params = [];
}

if (empty($method) || empty($token)) {
    log_write('error', 'Missing fields', ['method' => $method, 'has_token' => ! empty($token)]);
    respond(['error' => 'Missing method or bot_token'], 400);
}

log_write('info', 'Proxying', ['method' => $method, 'params' => array_keys($params)]);

// ── downloadFile → stream raw file bytes ─────
// Telegram has no "downloadFile" Bot API method. Downloading a file is a plain
// GET on https://api.telegram.org/file/bot<token>/<file_path>, where <file_path>
// comes from a prior getFile call. The Iran server can't reach that host, so the
// download has to go through this proxy too. Accept either the raw file_path
// (preferred) or a full file_url.
if ($method === 'downloadFile') {
    $filePath = trim($params['file_path'] ?? '');
    $fileUrl  = trim($params['file_url'] ?? '');

    if ($fileUrl !== '') {
        $downloadUrl = $fileUrl;
    } elseif ($filePath !== '') {
        $downloadUrl = "https://api.telegram.org/file/bot{$token}/" . ltrim($filePath, '/');
    } else {
        log_write('error', 'downloadFile without file_path/file_url', ['method' => $method]);
        respond(['ok' => false, 'error_code' => 400, 'description' => 'Missing file_path'], 400);
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $downloadUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $start     = microtime(true);
    $body      = curl_exec($ch);
    $ms        = round((microtime(true) - $start) * 1000);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
    $curlError = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    if ($body === false || $curlErrNo !== 0) {
        log_write('error', 'downloadFile cURL failed', ['errno' => $curlErrNo, 'error' => $curlError, 'ms' => $ms]);
        respond(['ok' => false, 'error_code' => $curlErrNo, 'description' => "Proxy download error: {$curlError}"], 502);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        log_write('warn', 'downloadFile upstream error', ['http' => $httpCode, 'ms' => $ms, 'url' => $downloadUrl]);
        respond(['ok' => false, 'error_code' => $httpCode, 'description' => 'Telegram file download failed'], $httpCode);
    }

    log_write('info', 'downloadFile ok', ['http' => $httpCode, 'ms' => $ms, 'bytes' => strlen($body)]);

    http_response_code(200);
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$hasUploadedFiles = ! empty($_FILES);
$postFields = json_encode($params);
$headers = ['Content-Type: application/json'];

if ($hasUploadedFiles) {
    $postFields = $params;
    $headers = [];

    foreach ($_FILES as $fieldName => $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            log_write('error', 'File upload failed', [
                'method' => $method,
                'field' => $fieldName,
                'error' => $file['error'] ?? null,
            ]);

            respond(['ok' => false, 'description' => 'Proxy upload error'], 400);
        }

        $postFields[$fieldName] = new CURLFile(
            $file['tmp_name'],
            $file['type'] ?? 'application/octet-stream',
            $file['name'] ?? $fieldName
        );
    }

    log_write('info', 'Proxying multipart request', [
        'method' => $method,
        'params' => array_keys($params),
        'files' => array_keys($_FILES),
    ]);
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://api.telegram.org/bot{$token}/{$method}",
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => $hasUploadedFiles ? 120 : 25,
]);

$start     = microtime(true);
$response  = curl_exec($ch);
$ms        = round((microtime(true) - $start) * 1000);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrNo = curl_errno($ch);
curl_close($ch);

if ($response === false || $curlErrNo !== 0) {
    log_write('error', 'cURL failed', ['method' => $method, 'errno' => $curlErrNo, 'error' => $curlError, 'ms' => $ms]);
    respond(['ok' => false, 'error_code' => $curlErrNo, 'description' => "Proxy error: {$curlError}"], 502);
}

$ok = json_decode($response, true)['ok'] ?? false;
log_write($ok ? 'info' : 'warn', $ok ? 'Success' : 'Telegram error', ['method' => $method, 'http' => $httpCode, 'ms' => $ms]);

http_response_code($httpCode);
header('Content-Type: application/json');
echo $response;
