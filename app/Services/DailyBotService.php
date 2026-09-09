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
            // Allow /start /today /week /export to interrupt flow
            if (in_array($text, ['/start', '/today', '/week', '/log', '/export', '/help'])) {
                $this->clearState($chatId, $platform);
                // fall through to command handling below
            } else {
                $this->handleStep($chatId, $platform, $text, $state);
                return;
            }
        }

        // Command routing — /export with optional format: /export json | /export excel | /export csv
        if (str_starts_with($text, '/export')) {
            $parts = preg_split('/\s+/', trim($text));
            $fmt = strtolower($parts[1] ?? '');
            $fmt = in_array($fmt, ['json', 'excel', 'xlsx', 'csv'], true) ? $fmt : null;
            // Normalize xlsx → excel
            if ($fmt === 'xlsx') $fmt = 'excel';
            $this->handleExport($chatId, $platform, $fmt);
            return;
        }

        match (true) {
            $text === '/start' => $this->handleStart($chatId),
            $text === '/log' => $this->startLogging($chatId, $platform),
            $text === '/today' => $this->handleToday($chatId, $platform),
            $text === '/week' => $this->handleWeek($chatId, $platform),
            $text === '/help' => $this->handleStart($chatId),
            $text === '📝 ثبت امروز' => $this->startLogging($chatId, $platform),
            $text === '📊 امروز' => $this->handleToday($chatId, $platform),
            $text === '📅 هفته' => $this->handleWeek($chatId, $platform),
            $text === '📊 خروجی' => $this->handleExport($chatId, $platform, null),
            $text === '📄 JSON' => $this->handleExport($chatId, $platform, 'json'),
            $text === '📊 اکسل' => $this->handleExport($chatId, $platform, 'excel'),
            $text === '📥 اکسل' => $this->handleExport($chatId, $platform, 'excel'),
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
            . "/export — خروجی اکسل + JSON با تاریخ شمسی\n"
            . "/cancel — لغو ثبت جاری\n\n"
            . "برای شروع /log را بزن.";

        $keyboard = [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته', '📊 خروجی'],
            ['📄 JSON', '📊 اکسل'],
            ['/help'],
        ];
        $this->api->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }

    private function handleExport(string $chatId, string $platform, ?string $requestedFormat = null): void
    {
        try {
            $exportService = new ExportService();
            $entries = $exportService->getEntries($chatId, $platform);

            if ($entries->isEmpty()) {
                $this->api->sendMessage($chatId, "هنوز هیچ ثبتی نداری که خروجی بدم.\nبا /log شروع کن، بعد /export را بزن.");
                return;
            }

            $this->api->sendMessage($chatId, "⏳ در حال ساخت خروجی با تاریخ شمسی...");

            $shamsiCount = $entries->count();
            $firstShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->first()->entry_date);
            $lastShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->last()->entry_date);

            $sendExcel = $requestedFormat === null || $requestedFormat === 'excel';
            $sendJson = $requestedFormat === null || $requestedFormat === 'json';

            // ── Excel ──
            if ($sendExcel) {
                $fileName = "mydaily-{$chatId}-{$platform}-" . now()->format('Y-m-d') . ".xlsx";
                $filePath = storage_path("app/exports/{$fileName}");
                $exportService->generateExcel($entries, $filePath);

                $caption = "📊 خروجی Excel\n"
                    . "تعداد رکورد: {$shamsiCount}\n"
                    . "بازه: {$firstShamsi} تا {$lastShamsi}\n"
                    . "تاریخ‌ها شمسی + میلادی (ستون‌های A-C)";

                $result = $this->api->sendDocument($chatId, $filePath, $fileName, $caption);

                if (($result['ok'] ?? false) !== true) {
                    Log::warning("[{$platform}] export sendDocument excel failed", ['result' => $result]);
                    $this->api->sendMessage($chatId, "❌ ارسال Excel ناموفق بود.\nخطا: " . ($result['description'] ?? 'unknown'));
                }
            }

            // ── JSON (درخواستی شما) ──
            if ($sendJson) {
                $jsonFileName = "mydaily-{$chatId}-{$platform}-" . now()->format('Y-m-d') . ".json";
                $jsonFilePath = storage_path("app/exports/{$jsonFileName}");
                $jsonArray = $exportService->toArrayWithShamsi($entries);
                $jsonContent = json_encode($jsonArray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                if (!is_dir(dirname($jsonFilePath))) mkdir(dirname($jsonFilePath), 0755, true);
                file_put_contents($jsonFilePath, $jsonContent);

                $jsonCaption = "📄 خروجی JSON\n"
                    . "تعداد رکورد: {$shamsiCount}\n"
                    . "بازه: {$firstShamsi} تا {$lastShamsi}\n"
                    . "فرمت: JSON با تاریخ شمسی + میلادی (ready for AI)";

                $resultJson = $this->api->sendDocument($chatId, $jsonFilePath, $jsonFileName, $jsonCaption);

                if (($resultJson['ok'] ?? false) !== true) {
                    Log::warning("[{$platform}] export sendDocument json failed", ['result' => $resultJson]);
                    // Fallback: send as text preview if file send fails
                    $jsonPreview = mb_substr($jsonContent, 0, 3500);
                    $this->api->sendMessage($chatId, "📄 JSON (preview — فایل ارسال نشد):\n```\n{$jsonPreview}\n```");
                }
            }

            // Quick hint for next time
            if ($requestedFormat === null) {
                $this->api->sendMessage($chatId, "💡 دفعه بعد می‌تونی فقط یک فرمت بگیری:\n`/export json` → فقط JSON\n`/export excel` → فقط Excel\nیا از API: `GET /api/export?format=json&chat_id={$chatId}&platform={$platform}`");
            }
        } catch (\Throwable $e) {
            Log::error("[{$platform}] handleExport error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->api->sendMessage($chatId, "❌ خطا در ساخت خروجی: " . $e->getMessage());
        }
    }

    private function handleUnknown(string $chatId): void
    {
        $this->api->sendMessage($chatId, "متوجه نشدم 🤔\nبرای ثبت امروز /log و برای دیدن امروز /today را بزن. راهنما: /start\nیا /export برای خروجی اکسل با تاریخ شمسی");
    }

    private function startLogging(string $chatId, string $platform): void
    {
        $today = Carbon::today()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        $hasYesterday = DailyEntry::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->whereDate('entry_date', $yesterday)
            ->exists();

        // Only offer "yesterday" if it was not logged yet — minimal, non-nagging
        if (!$hasYesterday) {
            $todayShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay(Carbon::today());
            $yesterdayShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay(Carbon::yesterday());
            $this->setState($chatId, $platform, 'waiting_date_choice', []);
            $text = "دیروز ({$yesterdayShamsi}) رو ثبت نکردی.\nکدوم روز رو می‌خوای ثبت کنی؟\n(امروز: {$todayShamsi})";
            $keyboard = [
                ['دیروز', 'امروز'],
                ["📝 دیروز — {$yesterdayShamsi}", "📝 امروز — {$todayShamsi}"],
            ];
            $this->api->sendMessageWithKeyboard($chatId, $text, $keyboard);
            return;
        }

        $this->setState($chatId, $platform, 'waiting_sleep', ['entry_date' => $today]);
        $shamsiToday = \App\Helpers\ShamsiDateHelper::dateWithDay(Carbon::parse($today));
        $this->api->sendMessage($chatId, "شروع می‌کنیم! 📝\n📅 {$shamsiToday} — ثبت امروز\n\n۱/۸ — ساعت خوابت کی بود؟\nمثال: 23:30 یا 6 صبح یا 7 عصر\n(برای لغو /cancel)");
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

        // 0) Date choice — only shown when yesterday is missing
        if ($current === 'waiting_date_choice') {
            $normalized = mb_strtolower(trim($input));
            // Accept "دیروز", "📝 دیروز", "yesterday", or any string containing دیروز
            $isYesterday = mb_strpos($normalized, 'دیروز') !== false || mb_strpos($normalized, 'yesterday') !== false;
            $isToday = mb_strpos($normalized, 'امروز') !== false || mb_strpos($normalized, 'today') !== false
                || mb_strpos($normalized, 'الان') !== false;

            // If user typed a button with Shamsi suffix, it still contains keyword
            if ($isYesterday && !$isToday) {
                $data['entry_date'] = Carbon::yesterday()->toDateString();
            } elseif ($isToday && !$isYesterday) {
                $data['entry_date'] = Carbon::today()->toDateString();
            } elseif ($isYesterday && $isToday) {
                // ambiguous — prioritize yesterday button exact match handled above, fallback to reprompt
                $this->api->sendMessageWithKeyboard($chatId, "لطفا یکی رو انتخاب کن:", [['دیروز', 'امروز']]);
                return;
            } else {
                $this->api->sendMessageWithKeyboard($chatId, "لطفا «دیروز» یا «امروز» رو انتخاب کن:", [['دیروز', 'امروز']]);
                return;
            }
            $this->setState($chatId, $platform, 'waiting_sleep', $data);
            $shamsiChosen = \App\Helpers\ShamsiDateHelper::dateWithDay(Carbon::parse($data['entry_date']));
            $label = $data['entry_date'] === Carbon::today()->toDateString() ? 'ثبت امروز' : 'ثبت دیروز';
            $this->api->sendMessage($chatId, "شروع می‌کنیم! 📝\n📅 {$shamsiChosen} — {$label}\n\n۱/۸ — ساعت خوابت کی بود؟\nمثال: 23:30 یا 6 صبح یا 7 عصر\n(برای لغو /cancel)");
            return;
        }

        // Validation + save to data + advance
        switch ($current) {
            case 'waiting_sleep':
                $parsed = $this->parseFlexibleTime($input);
                if ($parsed === null) {
                    $this->api->sendMessage($chatId, "ساعت رو درست متوجه نشدم 😅\nمثلا بفرست: 23:30 یا 6 صبح یا 7 عصر یا فقط 6\nدوباره بفرست:");
                    return;
                }
                $data['sleep_time'] = $parsed;
                $this->setState($chatId, $platform, 'waiting_wake', $data);
                $this->api->sendMessage($chatId, "۲/۸ — ساعت بیداریت؟\nمثال: 07:00 یا 6 صبح");
                break;

            case 'waiting_wake':
                $parsed = $this->parseFlexibleTime($input);
                if ($parsed === null) {
                    $this->api->sendMessage($chatId, "ساعت رو درست متوجه نشدم 😅\nمثلا بفرست: 07:00 یا 6 صبح یا فقط 6\nدوباره بفرست:");
                    return;
                }
                $data['wake_time'] = $parsed;
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
        // Use entry_date from flow (today or yesterday) — fallback to today for legacy states
        $targetDate = $data['entry_date'] ?? Carbon::today()->toDateString();
        // Ensure YYYY-MM-DD format
        try { $targetDate = Carbon::parse($targetDate)->toDateString(); } catch (\Throwable $e) { $targetDate = Carbon::today()->toDateString(); }

        $existing = DailyEntry::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->whereDate('entry_date', $targetDate)
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
                'entry_date' => $targetDate,
            ], $attrs));
        }

        $keyboard = [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته', '📊 خروجی'],
            ['📄 JSON', '📊 اکسل'],
        ];
        $this->api->sendMessageWithKeyboard($chatId, $this->formatEntry($entry, "✅ ثبت شد!"), $keyboard);
    }

    private function formatEntry(DailyEntry $e, string $title): string
    {
        $gym = $e->gym ? 'بله ✅' : 'خیر ❌';
        $social = $e->social ? 'بله ✅' : 'خیر ❌';
        $trigger = $e->emotional_trigger ? "\n💭 محرک: {$e->emotional_trigger}" : "\n💭 محرک: —";
        $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($e->entry_date);
        return "{$title} ({$e->entry_date->format('Y-m-d')} — {$shamsi})\n"
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

    /**
     * Flexible time parser — product-minimal.
     * Accepts: 23:30, 23.30, 6 صبح, 6:30 صبح, ۶ صبح, 7 عصر, 6 شب, 12 ظهر, 6, 06, 18, 6am, 6pm
     * Returns normalized HH:MM or null.
     * DB stays HH:MM — no schema change.
     */
    private function parseFlexibleTime(string $input): ?string
    {
        $raw = trim($input);
        if ($raw === '') return null;

        // 1) Persian/Arabic digits → English
        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9'];
        $raw = str_replace($persian, $english, $raw);
        $raw = str_replace($arabic, $english, $raw);

        $lower = mb_strtolower($raw);

        // Remove filler words
        $lower = str_replace(['ساعت', 'حدودا', 'حدوداً', 'حدود', 'تقریبا', 'تقریباً'], ' ', $lower);
        $lower = trim(preg_replace('/\s+/', ' ', $lower));

        // 2) Detect am/pm keywords
        $isPM = false;
        $isAM = false;
        $pmKeywords = ['عصر', 'شب', 'ظهر', 'بعدازظهر', 'بعد از ظهر', 'شام', 'pm', 'p.m', 'بعدظهر'];
        $amKeywords = ['صبح', 'بامداد', 'am', 'a.m'];

        foreach ($pmKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) { $isPM = true; break; }
        }
        foreach ($amKeywords as $kw) {
            if (mb_strpos($lower, $kw) !== false) { $isAM = true; break; }
        }
        // If both detected, prefer PM (e.g. typo) — but usually only one

        // 3) Extract hour/minute — accept : . / - as separator
        if (!preg_match('/(\d{1,2})(?:\s*[:\.\-\/]\s*(\d{1,2}))?/', $lower, $m)) {
            return null;
        }
        $hour = (int)$m[1];
        $minute = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;

        if ($minute < 0 || $minute > 59) return null;

        // 4) Apply am/pm conversion
        if ($isPM && !$isAM) {
            // 12 ظهر = noon, 12 شب = midnight
            if (mb_strpos($lower, 'شب') !== false && $hour == 12) {
                $hour = 0; // 12 شب → 00:xx
            } elseif ($hour >= 1 && $hour <= 11) {
                $hour += 12;
            } elseif ($hour == 12) {
                $hour = 12; // 12 ظهر stays 12
            }
        } elseif ($isAM && !$isPM) {
            if ($hour == 12) $hour = 0; // 12 صبح = 00:xx
        }
        // No keyword: keep as-is — bare "6" means 06:00, "18" means 18:00

        if ($hour < 0 || $hour > 23) return null;

        return sprintf('%02d:%02d', $hour, $minute);
    }

    // Legacy strict check kept for reference (unused) — use parseFlexibleTime instead
    private function isValidTime(string $input): bool
    {
        return $this->parseFlexibleTime($input) !== null;
    }

    private function normalizeTime(string $input): string
    {
        return $this->parseFlexibleTime($input) ?? '00:00';
    }

    private function parseYesNo(string $input): ?bool
    {
        $t = trim(mb_strtolower($input));
        if (in_array($t, ['بله', 'yes', 'y', '1', 'true', 'آره'], true)) return true;
        if (in_array($t, ['خیر', 'نه', 'no', 'n', '0', 'false'], true)) return false;
        return null;
    }
}
