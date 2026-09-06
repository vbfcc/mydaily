<?php

namespace App\Http\Controllers;

use App\Services\BotApi;
use App\Services\DailyBotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BotWebhookController extends Controller
{
    public function telegram(Request $request)
    {
        return $this->handle($request, 'telegram');
    }

    public function bale(Request $request)
    {
        return $this->handle($request, 'bale');
    }

    private function handle(Request $request, string $platform)
    {
        // Make platform available to BotApi via container (like factorland)
        app()->instance('current_platform', $platform);

        $update = $request->all();
        Log::info("[{$platform}] webhook", $update);

        try {
            $api = new BotApi($platform);
            $service = new DailyBotService($api);

            // Callback query (inline buttons)
            if (isset($update['callback_query'])) {
                $cq = $update['callback_query'];
                $chatId = (string) ($cq['message']['chat']['id'] ?? $cq['from']['id'] ?? '');
                $data = (string) ($cq['data'] ?? '');
                $cqId = (string) ($cq['id'] ?? '');
                if ($chatId !== '') {
                    $service->handleCallback($chatId, $platform, $data, $cqId);
                }
                return response()->json(['ok' => true]);
            }

            // Normal message
            $message = $update['message'] ?? $update['edited_message'] ?? null;
            if (!$message) {
                return response()->json(['ok' => true]);
            }

            $chatId = (string) ($message['chat']['id'] ?? '');
            $text = (string) ($message['text'] ?? $message['caption'] ?? '');
            $username = $message['from']['username'] ?? null;

            if ($chatId === '') {
                return response()->json(['ok' => true]);
            }

            // Ignore non-text without handling? Still require text for flow.
            if ($text === '' && isset($message['contact'])) {
                $text = $message['contact']['phone_number'] ?? '';
            }

            $service->handle($chatId, $platform, $text, $username);

        } catch (\Throwable $e) {
            Log::error("[{$platform}] webhook error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }

        return response()->json(['ok' => true]);
    }
}
