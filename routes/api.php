<?php

use App\Http\Controllers\BotWebhookController;
use Illuminate\Support\Facades\Route;

// No auth — Telegram/Bale call these directly
Route::post('/webhook/telegram', [BotWebhookController::class, 'telegram']);
Route::post('/webhook/bale', [BotWebhookController::class, 'bale']);

// Health check
Route::get('/health', fn () => response()->json(['ok' => true, 'app' => 'mydayli']));
