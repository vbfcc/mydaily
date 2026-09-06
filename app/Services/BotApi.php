<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BotApi — unified gateway for Telegram and Bale.
 * Based on E:\factorland_localhost\app\Services\TelegramApi\TelegramApi.php
 *
 * Platform selection:
 *   - new BotApi('bale') → forces Bale
 *   - new BotApi('telegram') → forces Telegram
 *   - new BotApi() → reads app('current_platform') or defaults to 'telegram'
 */
class BotApi
{
    protected ?string $forcePlatform = null;

    public function __construct(?string $platform = null)
    {
        $this->forcePlatform = $platform;
    }

    public function getPlatform(): string
    {
        return $this->getCurrentPlatform();
    }

    private function getCurrentPlatform(): string
    {
        if ($this->forcePlatform !== null) {
            return $this->forcePlatform;
        }
        try {
            return app()->has('current_platform') ? app('current_platform') : 'telegram';
        } catch (\Exception $e) {
            return 'telegram';
        }
    }

    private function getBotToken(): string
    {
        return $this->getCurrentPlatform() === 'bale'
            ? (string) env('BALE_BOT_TOKEN', '')
            : (string) env('TELEGRAM_BOT_TOKEN', '');
    }

    private function getBaseUrl(): string
    {
        $token = $this->getBotToken();
        return $this->getCurrentPlatform() === 'bale'
            ? "https://tapi.bale.ai/bot{$token}/"
            : "https://api.telegram.org/bot{$token}/";
    }

    private function getProxyUrl(): ?string
    {
        return $this->getCurrentPlatform() === 'bale'
            ? null
            : env('TELEGRAM_PROXY_URL');
    }

    // Bale doesn't render HTML well — strip tags
    private function stripHtmlForBale(string $text): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = strip_tags($text);
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function sanitizeBalePayload(array $params): array
    {
        if (isset($params['text']) && is_string($params['text'])) {
            $params['text'] = $this->stripHtmlForBale($params['text']);
        }
        if (isset($params['caption']) && is_string($params['caption'])) {
            $params['caption'] = $this->stripHtmlForBale($params['caption']);
        }
        unset($params['parse_mode']);
        return $params;
    }

    private function sendRequest(string $method, array $params = []): array
    {
        try {
            $platform = $this->getCurrentPlatform();
            $proxyUrl = $this->getProxyUrl();

            if ($platform === 'bale') {
                $params = $this->sanitizeBalePayload($params);
                $url = $this->getBaseUrl() . $method;
                $response = Http::timeout(30)->post($url, $params);
            } elseif ($proxyUrl) {
                $response = $this->sendViaProxyWithRetry($proxyUrl, $method, $params);
            } else {
                $url = $this->getBaseUrl() . $method;
                $response = Http::timeout(30)->post($url, $params);
            }

            $rawBody = $response->body();
            $json = json_decode($rawBody, true);

            if ($json === null && json_last_error() !== JSON_ERROR_NONE) {
                Log::error("[{$platform}] API invalid JSON", ['method' => $method, 'body' => substr($rawBody, 0, 500)]);
                return ['ok' => false, 'error' => true, 'message' => 'Invalid JSON'];
            }

            if (isset($json['ok']) && $json['ok'] === false) {
                Log::warning("[{$platform}] API error", ['method' => $method, 'desc' => $json['description'] ?? 'unknown']);
            }

            return $json;
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error("[" . $this->getCurrentPlatform() . "] API error [{$method}]: " . $e->getMessage());
            return ['ok' => false, 'error' => true, 'message' => $e->getMessage()];
        }
    }

    private function sendViaProxyWithRetry(string $proxyUrl, string $method, array $params)
    {
        $maxAttempts = 2;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return Http::asForm()
                    ->withOptions(['verify' => false])
                    ->timeout(12)
                    ->post($proxyUrl, [
                        'method'    => $method,
                        'bot_token' => $this->getBotToken(),
                        'params'    => json_encode($params),
                    ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                if ($attempt < $maxAttempts) {
                    Log::warning("[telegram] Proxy attempt {$attempt} failed, retrying {$method}");
                    usleep(300000);
                    continue;
                }
                Log::warning("[telegram] Proxy failed after {$attempt} attempts, fallback direct {$method}");
            }
        }
        $url = $this->getBaseUrl() . $method;
        return Http::timeout(30)->post($url, $params);
    }

    // ── Public API ──

    public function sendMessage($chatId, string $text, bool $isSilent = false): array
    {
        return $this->sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_notification' => $isSilent,
        ]);
    }

    public function sendMessageWithKeyboard($chatId, string $text, array $keyboard): array
    {
        return $this->sendRequest('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode([
                'keyboard' => $keyboard,
                'resize_keyboard' => true,
                'one_time_keyboard' => false,
            ]),
        ]);
    }

    public function sendMessageWithInlineKeyboard($chatId, string $text, array $inlineKeyboard): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['inline_keyboard' => $inlineKeyboard]),
        ];
        // Telegram supports HTML, Bale will be stripped
        if ($this->getCurrentPlatform() === 'telegram') {
            $params['parse_mode'] = 'HTML';
        }
        return $this->sendRequest('sendMessage', $params);
    }

    public function sendMessageHtml($chatId, string $text, ?array $inlineKeyboard = null): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
        ];
        if ($this->getCurrentPlatform() === 'telegram') {
            $params['parse_mode'] = 'HTML';
        }
        if ($inlineKeyboard) {
            $params['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard]);
        }
        return $this->sendRequest('sendMessage', $params);
    }

    public function removeKeyboard($chatId, string $text): array
    {
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => json_encode(['remove_keyboard' => true]),
        ];
        if ($this->getCurrentPlatform() === 'telegram') {
            $params['parse_mode'] = 'HTML';
        }
        return $this->sendRequest('sendMessage', $params);
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): array
    {
        $params = ['callback_query_id' => $callbackQueryId];
        if ($text) $params['text'] = $text;
        return $this->sendRequest('answerCallbackQuery', $params);
    }

    public function setWebhook(?string $url, bool $unset = false): array
    {
        $webhookUrl = $unset ? '' : $url;
        Log::info("[" . $this->getCurrentPlatform() . "] Setting webhook: {$webhookUrl}");
        return $this->sendRequest('setWebhook', ['url' => $webhookUrl]);
    }

    public function getWebhookInfo(): array
    {
        return $this->sendRequest('getWebhookInfo', []);
    }

    public function deleteWebhook(): array
    {
        return $this->sendRequest('deleteWebhook', []);
    }

    public function getUpdates(?int $offset = null, ?int $limit = null, ?int $timeout = null): array
    {
        $params = [];
        if ($offset !== null) $params['offset'] = $offset;
        if ($limit !== null) $params['limit'] = $limit;
        if ($timeout !== null) $params['timeout'] = $timeout;
        return $this->sendRequest('getUpdates', $params);
    }
}
