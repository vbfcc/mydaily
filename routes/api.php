<?php

use App\Http\Controllers\BotWebhookController;
use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

// No auth — Telegram/Bale call these directly
Route::post('/webhook/telegram', [BotWebhookController::class, 'telegram']);
Route::post('/webhook/bale', [BotWebhookController::class, 'bale']);

// Health check
Route::get('/health', fn () => response()->json(['ok' => true, 'app' => 'mydayli']));

// Export — Shamsi dates via ShamsiDateHelper (Excel/CSV/JSON, per chat_id+platform or all)
// GET /api/export?format=json|excel|csv&chat_id=123&platform=telegram&from=2026-09-01&to=2026-09-30
Route::get('/export', ExportController::class);
