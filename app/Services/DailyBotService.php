<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\DailyEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DailyBotService
{
    private BotApi $api;

    // Ordered steps — must match handleStep logic
    private const STEPS = [
        'waiting_sleep'   => 'sleep_time',
        'waiting_wake'    => 'wake_time',
        'waiting_work'    => 'work_hours',
        'waiting_gym'     => 'gym',
        'waiting_gaming'  => 'gaming_minutes',
        'waiting_social'  => 'social',
        'waiting_mood'    => 'mood',
        'waiting_trigger' => 'emotional_trigger',
    ];

    private const STEP_ORDER = [
        'waiting_sleep',
        'waiting_wake',
        'waiting_work',
        'waiting_gym',
        'waiting_gaming',
        'waiting_social',
        'waiting_mood',
        'waiting_trigger',
    ];

    public function __construct(BotApi $api)
    {
        $this->api = $api;
    }

    // ── Public entry point for every incoming message ──

    public function handle(string $chatId, string $platform, string $text, ?string $username = null): void
    {
        $text = trim($text);

        // If user is mid-flow, delegate to state handler (except hard commands)
        $state = $this->getState($chatId, $platform);

        // Commands that always work even mid-flow
        if ($text === '/cancel') {
            $this->clearState($chatId, $platform);
            $this->api->sendMessage($chatId, "❌ ثبت لغو شد. برای شروع دوباره /log را بزن.");
            return;
        }

        // If in a flow, handle step input before checking other commands
        if ($state && $state->state !== null) {
            // Allow /start /today /week to interrupt flow
            if (in_array($text, ['/start', '/today', '/week', '/log'])) {
                $this->clearState($chatId, $platform);
                // fall through to command handling below
            } else {
                $this->handleStep($chatId, $platform, $text, $state);
                return;
            }
        }

        // Command routing
        match (true) {
            $text === '/start' => $this->handleStart($chatId),
            $text === '/log' => $this->startLogging($chatId, $platform),
            $text === '/today' => $this->handleToday($chatId, $platform),
            $text === '/week' => $this->handleWeek($chatId, $platform),
            $text === '/help' => $this->handleStart($chatId),
            $text === '📝 ثبت امروز' => $this->startLogging($chatId, $platform),
            $text === '📊 امروز' => $this->handleToday($chatId, $platform),
            $text === '📅 هفته' => $this->handleWeek($chatId, $platform),
            default => $this->handleUnknown($chatId),
        };
    }

    // Also handle callback queries (inline buttons) if we use them
    public function handleCallback(string $chatId, string $platform, string $data, string $callbackQueryId): void
    {
        $this->api->answerCallbackQuery($callbackQueryId);
        // Map callbacks to inputs for gym/social/mood steps
        $state = $this->getState($chatId, $platform);
        if ($state && $state->state) {
            $this->handleStep($chatId, $platform, $data, $state);
        }
    }

    // ── Commands ──

    private function handleStart(string $chatId): void
    {
        $text = "سلام! 👋\n"
            . "من ربات ثبت فعالیت‌های روزانه‌ات هستم.\n\n"
            . "هفت مورد را هر روز ثبت می‌کنیم:\n"
            . "😴 خواب/بیداری — 💼 کار مفید — 🏋️ باشگاه — 🎮 گیم — 👥 تعامل اجتماعی — 😊 حال (۱-۱۰) — 💭 محرک احساسی\n\n"
            . "دستورات:\n"
            . "/log — شروع ثبت امروز (مرحله به مرحله)\n"
            . "/today — نمایش ثبت امروز\n"
            . "/week — نمایش ۷ روز گذشته\n"
            . "/cancel — لغو ثبت جاری\n\n"
            . "برای شروع /log را بزن.";

        $keyboard = [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته', '/help'],
        ];
        $this->api->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }

    private function handleUnknown(string $chatId): void
    {
        $this->api->sendMessage($chatId, "متوجه نشدم 🤔\nبرای ثبت امروز /log و برای دیدن امروز /today را بزن. راهنما: /start");
    }

    private function startLogging(string $chatId, string $platform): void
    {
        $this->setState($chatId, $platform, 'waiting_sleep', []);
        $this->api->sendMessage($chatId, "شروع می‌کنیم! 📝\n\n۱/۸ — ساعت خوابت کی بود؟\nمثال: 23:30 یا 00:15\n(برای لغو /cancel)");
    }

    private function handleToday(string $chatId, string $platform): void
    {
        $entry = DailyEntry::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->whereDate('entry_date', Carbon::today()->toDateString())
            ->first();

        if (!$entry) {
            $this->api->sendMessage($chatId, "هنوز برای امروز چیزی ثبت نکردی.\nبا /log شروع کن.");
            return;
        }

        $this->api->sendMessage($chatId, $this->formatEntry($entry, "📊 ثبت امروز"));
    }

    private function handleWeek(string $chatId, string $platform): void
    {
        $entries = DailyEntry::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->whereDate('entry_date', '>=', Carbon::today()->subDays(6)->toDateString())
            ->orderBy('entry_date', 'asc')
            ->get();

        if ($entries->isEmpty()) {
            $this->api->sendMessage($chatId, "هنوز هیچ ثبتی نداری. با /log شروع کن.");
            return;
        }

        $lines = ["📅 ۷ روز گذشته:\n"];
        foreach ($entries as $e) {
            $d = Carbon::parse($e->entry_date)->format('m/d');
            $mood = $e->mood ?? '-';
            $gym = $e->gym ? '✅' : '❌';
            $social = $e->social ? '✅' : '❌';
            $lines[] = "{$d} | خواب {$e->sleep_time}-{$e->wake_time} | کار {$e->work_hours}h | باشگاه {$gym} | گیم {$e->gaming_minutes}m | اجتماعی {$social} | حال {$mood}/10";
            if ($e->emotional_trigger) {
                $lines[count($lines)-1] .= "\n  💭 {$e->emotional_trigger}";
            }
        }

        $this->api->sendMessage($chatId, implode("\n", $lines));
    }

    // ── Step handler ──

    private function handleStep(string $chatId, string $platform, string $input, BotState $state): void
    {
        $current = $state->state;
        $data = $state->data ?? [];

        $input = trim($input);

        // Handle /skip for emotional_trigger
        if ($current === 'waiting_trigger' && ($input === '/skip' || $input === 'skip' || $input === '-')) {
            $data['emotional_trigger'] = null;
            $this->saveEntry($chatId, $platform, $data);
            $this->clearState($chatId, $platform);
            return;
        }

        // Validation + save to data + advance
        switch ($current) {
            case 'waiting_sleep':
                if (!$this->isValidTime($input)) {
                    $this->api->sendMessage($chatId, "فرمت ساعت درست نیست. مثال: 23:30 یا 01:15\nدوباره بفرست:");
                    return;
                }
                $data['sleep_time'] = $this->normalizeTime($input);
                $this->setState($chatId, $platform, 'waiting_wake', $data);
                $this->api->sendMessage($chatId, "۲/۸ — ساعت بیداریت؟\nمثال: 07:00");
                break;

            case 'waiting_wake':
                if (!$this->isValidTime($input)) {
                    $this->api->sendMessage($chatId, "فرمت ساعت درست نیست. مثال: 07:00\nدوباره بفرست:");
                    return;
                }
                $data['wake_time'] = $this->normalizeTime($input);
                $this->setState($chatId, $platform, 'waiting_work', $data);
                $this->api->sendMessage($chatId, "۳/۸ — چند ساعت کار مفید کردی؟\nعدد بفرست مثلا: 6 یا 4.5 (بین 0 تا 16)");
                break;

            case 'waiting_work':
                if (!is_numeric($input) || (float)$input < 0 || (float)$input > 16) {
                    $this->api->sendMessage($chatId, "عدد بین 0 تا 16 بفرست. مثلا: 6");
                    return;
                }
                $data['work_hours'] = round((float)$input, 1);
                $this->setState($chatId, $platform, 'waiting_gym', $data);
                $this->api->sendMessageWithKeyboard($chatId, "۴/۸ — امروز باشگاه رفتی؟", [['بله', 'خیر']]);
                break;

            case 'waiting_gym':
                $val = $this->parseYesNo($input);
                if ($val === null) {
                    $this->api->sendMessageWithKeyboard($chatId, "لطفا بله یا خیر بفرست:", [['بله', 'خیر']]);
                    return;
                }
                $data['gym'] = $val;
                $this->setState($chatId, $platform, 'waiting_gaming', $data);
                $this->api->sendMessage($chatId, "۵/۸ — چند دقیقه گیم زدی؟\nعدد بفرست مثلا: 45 یا 0");
                break;

            case 'waiting_gaming':
                if (!is_numeric($input) || (int)$input < 0 || (int)$input > 1440) {
                    $this->api->sendMessage($chatId, "تعداد دقیقه را بفرست (0 تا 1440). مثلا: 30");
                    return;
                }
                $data['gaming_minutes'] = (int)$input;
                $this->setState($chatId, $platform, 'waiting_social', $data);
                $this->api->sendMessageWithKeyboard($chatId, "۶/۸ — امروز تعامل اجتماعی داشتی؟", [['بله', 'خیر']]);
                break;

            case 'waiting_social':
                $val = $this->parseYesNo($input);
                if ($val === null) {
                    $this->api->sendMessageWithKeyboard($chatId, "لطفا بله یا خیر بفرست:", [['بله', 'خیر']]);
                    return;
                }
                $data['social'] = $val;
                $this->setState($chatId, $platform, 'waiting_mood', $data);
                $this->api->sendMessage($chatId, "۷/۸ — حالت امروز از ۱۰ چند بود؟\nعدد 1 تا 10 بفرست:");
                break;

            case 'waiting_mood':
                if (!ctype_digit($input) || (int)$input < 1 || (int)$input > 10) {
                    $this->api->sendMessage($chatId, "عدد 1 تا 10 بفرست:");
                    return;
                }
                $data['mood'] = (int)$input;
                $this->setState($chatId, $platform, 'waiting_trigger', $data);
                $this->api->sendMessage($chatId, "۸/۸ — مهم‌ترین چیزی که حالت را تغییر داد چی بود؟\nیک جمله بنویس. اگر چیزی نبود /skip بفرست.");
                break;

            case 'waiting_trigger':
                $data['emotional_trigger'] = mb_substr($input, 0, 500);
                $this->saveEntry($chatId, $platform, $data);
                $this->clearState($chatId, $platform);
                break;

            default:
                $this->clearState($chatId, $platform);
                $this->api->sendMessage($chatId, "خطا در وضعیت. دوباره /log را بزن.");
                break;
        }
    }

    private function saveEntry(string $chatId, string $platform, array $data): void
    {
        // Use whereDate-compatible lookup to avoid sqlite "2026-09-06" vs "2026-09-06 00:00:00" mismatch
        $today = Carbon::today()->toDateString();
        $existing = DailyEntry::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->whereDate('entry_date', $today)
            ->first();

        $attrs = [
            'sleep_time' => $data['sleep_time'] ?? null,
            'wake_time' => $data['wake_time'] ?? null,
            'work_hours' => $data['work_hours'] ?? null,
            'gym' => $data['gym'] ?? false,
            'gaming_minutes' => $data['gaming_minutes'] ?? 0,
            'social' => $data['social'] ?? false,
            'mood' => $data['mood'] ?? null,
            'emotional_trigger' => $data['emotional_trigger'] ?? null,
        ];

        if ($existing) {
            $existing->update($attrs);
            $entry = $existing->refresh();
        } else {
            $entry = DailyEntry::create(array_merge([
                'chat_id' => $chatId,
                'platform' => $platform,
                'entry_date' => $today,
            ], $attrs));
        }

        $keyboard = [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته'],
        ];
        $this->api->sendMessageWithKeyboard($chatId, $this->formatEntry($entry, "✅ ثبت شد!"), $keyboard);
    }

    private function formatEntry(DailyEntry $e, string $title): string
    {
        $gym = $e->gym ? 'بله ✅' : 'خیر ❌';
        $social = $e->social ? 'بله ✅' : 'خیر ❌';
        $trigger = $e->emotional_trigger ? "\n💭 محرک: {$e->emotional_trigger}" : "\n💭 محرک: —";
        return "{$title} ({$e->entry_date->format('Y-m-d')})\n"
            . "😴 خواب: {$e->sleep_time} → {$e->wake_time}\n"
            . "💼 کار مفید: {$e->work_hours} ساعت\n"
            . "🏋️ باشگاه: {$gym}\n"
            . "🎮 گیم: {$e->gaming_minutes} دقیقه\n"
            . "👥 اجتماعی: {$social}\n"
            . "😊 حال: {$e->mood}/10"
            . $trigger;
    }

    // ── Helpers ──

    private function getState(string $chatId, string $platform): ?BotState
    {
        return BotState::where('chat_id', $chatId)->where('platform', $platform)->first();
    }

    private function setState(string $chatId, string $platform, string $state, array $data): void
    {
        BotState::updateOrCreate(
            ['chat_id' => $chatId, 'platform' => $platform],
            ['state' => $state, 'data' => $data]
        );
    }

    private function clearState(string $chatId, string $platform): void
    {
        BotState::where('chat_id', $chatId)->where('platform', $platform)->delete();
    }

    private function isValidTime(string $input): bool
    {
        return preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', trim($input)) === 1;
    }

    private function normalizeTime(string $input): string
    {
        $parts = explode(':', trim($input));
        return sprintf('%02d:%02d', (int)$parts[0], (int)$parts[1]);
    }

    private function parseYesNo(string $input): ?bool
    {
        $t = trim(mb_strtolower($input));
        if (in_array($t, ['بله', 'yes', 'y', '1', 'true', 'آره'], true)) return true;
        if (in_array($t, ['خیر', 'نه', 'no', 'n', '0', 'false'], true)) return false;
        return null;
    }
}
