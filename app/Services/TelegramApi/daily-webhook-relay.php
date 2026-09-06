<?php
/**
 * daily-webhook-relay.php
 * Receives Telegram webhook updates on the foreign VPS (not filtered)
 * and forwards them to the MyDaily Iran server.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHERE THIS RUNS
 * This file is NOT executed by the Laravel app. It is the source-of-truth copy,
 * kept in the repo at app/Services/TelegramApi/daily-webhook-relay.php, and must be
 * DEPLOYED BY HAND to the foreign VPS web root:
 *
 *   LIVE (used by prod):  tm.factorland.ir  (46.165.210.28)
 *       web root file  →  <docroot>/daily-webhook-relay.php
 *       upload:           FTP  ftp.tm.factorland.ir:21  user h360018
 *       (no SSH on port 22 for this box) — fallback via Gluta-EU below.
 *
 *   ALT / fallback:       tg.factorland.ir  (103.75.196.85, SSH "Glutamarket-EU")
 *       /var/www/html/tg.factorland.ir/daily-webhook-relay.php
 *
 * Flow:
 *   Telegram → POST https://tm.factorland.ir/daily-webhook-relay.php
 *            → cURL POST https://daily.factorland.ir/api/webhook/telegram
 *            → Laravel BotWebhookController@telegram
 *
 * Why needed: the Iran server (185.8.174.229) hosts daily.factorland.ir.
 * Telegram can POST to it directly in many cases, but to avoid filtering /
 * TLS issues and to mirror the factorland architecture (webhook-relay.php
 * for api.factorland.ir), we put the same foreign-VPS relay in front of
 * MyDaily. The bot webhook for Telegram MUST be set to the relay URL, not
 * directly to daily.factorland.ir.
 *
 *   Correct:  https://tm.factorland.ir/daily-webhook-relay.php
 *   Wrong:    https://daily.factorland.ir/api/webhook/telegram (direct)
 *
 * Bale does NOT need a relay — Bale is not filtered in Iran — so its
 * webhook stays direct: https://daily.factorland.ir/api/webhook/bale
 *
 * Companion file: telegram-proxy.php (same VPS, handles OUTGOING Bot API calls
 *   via TELEGRAM_PROXY_URL). This file handles INCOMING webhook updates.
 *
 * DEPLOY CHECKLIST
 *   1. Back up remote file: daily-webhook-relay.php.bak.YYYYMMDD
 *   2. Upload this file to web root (tm via FTP, tg via SCP)
 *   3. php -l daily-webhook-relay.php && chown www-data / h360018
 *   4. Test: curl -X POST https://tm.factorland.ir/daily-webhook-relay.php
 *           -H "Content-Type: application/json" -d '{"test":1}'
 *      Check daily-webhook-relay.log on VPS + laravel.log on Iran server.
 *   5. Set webhook: ssh Iran → php artisan bot:webhook telegram set --url=https://tm.factorland.ir/daily-webhook-relay.php
 *      (via BotApi → proxy → Telegram)
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── CONFIG ──────────────────────────────────
$targetUrl = 'https://daily.factorland.ir/api/webhook/telegram';
$logFile   = __DIR__ . '/daily-webhook-relay.log';

// ── LOG HELPER ───────────────────────────────
function log_write(string $level, string $msg, array $ctx = []): void
{
    global $logFile;

    if (! file_exists($logFile)) {
        file_put_contents($logFile, "");
    }

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

// ── ONLY POST is real updates; GET is health ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'daily relay online', 'target' => $targetUrl, 'time' => date('Y-m-d H:i:s')]);
    exit;
}

// ── READ RAW TELEGRAM UPDATE ─────────────────
$rawBody = file_get_contents('php://input');

// ── FORWARD TO IRAN SERVER ───────────────────
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $targetUrl,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $rawBody,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 20,
]);

$start     = microtime(true);
$response  = curl_exec($ch);
$ms        = round((microtime(true) - $start) * 1000);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrNo = curl_errno($ch);
curl_close($ch);

if ($response === false || $curlErrNo !== 0) {
    log_write('error', 'Forward failed', ['errno' => $curlErrNo, 'error' => $curlError, 'ms' => $ms, 'bytes' => strlen($rawBody)]);
    // Always return 200 to Telegram so it doesn't retry storm
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'relay_error' => $curlError]);
    exit;
}

log_write('info', 'Forwarded update', ['http' => $httpCode, 'ms' => $ms, 'bytes_in' => strlen($rawBody), 'bytes_out' => strlen($response ?? '')]);

http_response_code(200);
header('Content-Type: application/json');
echo $response;
