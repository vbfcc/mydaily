<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\BotSubscriber;
use App\Models\DailyEntry;
use App\Models\Routine;
use App\Models\RoutineLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Morilog\Jalali\Jalalian;

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
        $this->touchSubscriber($chatId, $platform, $username);

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
            // Allow /start /today /week /export /routine to interrupt flow
            if (in_array($text, ['/start', '/today', '/week', '/log', '/export', '/help', '/routine', '/routines', '🔁 روتین‌ها'])) {
                $this->clearState($chatId, $platform);
                // fall through to command handling below
            } else {
                $this->handleStep($chatId, $platform, $text, $state);
                return;
            }
        }

        // Routine commands
        if ($text === '/routine' || $text === '/routines' || $text === '🔁 روتین‌ها' || $text === '🔁 روتین') {
            $this->handleRoutines($chatId, $platform);
            return;
        }
        if ($text === '/routine_new' || $text === '➕ روتین جدید') {
            $this->startRoutineWizard($chatId, $platform);
            return;
        }
        if (str_starts_with($text, '/routine_stop')) {
            $parts = preg_split('/\s+/', trim($text));
            $this->handleRoutineStop($chatId, $platform, $parts[1] ?? null);
            return;
        }

        // Command routing — /export now shows inline menu (JSON only)
        if (str_starts_with($text, '/export')) {
            $parts = preg_split('/\s+/', trim($text));
            $fmt = strtolower($parts[1] ?? '');
            // Direct JSON for explicit "/export json" (backward compat) — skip menu
            if (in_array($fmt, ['json'], true)) {
                $this->handleExport($chatId, $platform, 'json');
                return;
            }
            if (in_array($fmt, ['excel', 'xlsx', 'csv'], true)) {
                $this->api->sendMessage($chatId, "📄 الان فقط JSON داریم (Excel حذف شد).\nبرای JSON روی /export بزن و بازه رو انتخاب کن.");
                $this->showExportMenu($chatId, $platform);
                return;
            }
            $this->showExportMenu($chatId, $platform);
            return;
        }

        match (true) {
            $text === '/start' => $this->handleStart($chatId),
            $text === '/log' => $this->startLogging($chatId, $platform),
            $text === '/today' => $this->handleToday($chatId, $platform),
            $text === '/week' => $this->handleWeek($chatId, $platform),
            $text === '/help' => $this->handleStart($chatId),
            $text === '/routine' => $this->handleRoutines($chatId, $platform),
            $text === '/routines' => $this->handleRoutines($chatId, $platform),
            $text === '📝 ثبت امروز' => $this->startLogging($chatId, $platform),
            $text === '📊 امروز' => $this->handleToday($chatId, $platform),
            $text === '📅 هفته' => $this->handleWeek($chatId, $platform),
            $text === '📊 خروجی' => $this->showExportMenu($chatId, $platform),
            $text === '📄 JSON' => $this->showExportMenu($chatId, $platform),
            $text === '📊 اکسل' => $this->showExportMenu($chatId, $platform),
            $text === '📥 اکسل' => $this->showExportMenu($chatId, $platform),
            default => $this->handleUnknown($chatId),
        };
    }

    // Also handle callback queries (inline buttons) if we use them
    public function handleCallback(string $chatId, string $platform, string $data, string $callbackQueryId): void
    {
        $this->api->answerCallbackQuery($callbackQueryId);
        $this->touchSubscriber($chatId, $platform);

        // Export inline menu — JSON only
        if (str_starts_with($data, 'exp:')) {
            $this->handleExportCallback($chatId, $platform, $data);
            return;
        }

        // Routine inline buttons
        if ($data === 'rt:new') {
            $this->clearState($chatId, $platform);
            $this->startRoutineWizard($chatId, $platform);
            return;
        }
        if (str_starts_with($data, 'rt:stop:')) {
            $this->handleRoutineStop($chatId, $platform, substr($data, 8));
            return;
        }
        if ($data === 'rt:yes' || $data === 'rt:no') {
            $state = $this->getState($chatId, $platform);
            if ($state && $state->state === 'waiting_routine_done') {
                $this->handleStep($chatId, $platform, $data === 'rt:yes' ? 'بله' : 'خیر', $state);
            }
            return;
        }
        if ($data === 'rt:skip_note') {
            $state = $this->getState($chatId, $platform);
            if ($state && $state->state === 'waiting_routine_note') {
                $this->handleStep($chatId, $platform, '/skip', $state);
            }
            return;
        }

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
            . "/routine — مدیریت روتین‌ها (مثل روتین پوستی با تاریخ شروع/پایان)\n"
            . "/export — خروجی JSON (با انتخاب بازه؛ فقط JSON)\n"
            . "/cancel — لغو ثبت جاری\n\n"
            . "برای شروع /log را بزن.";

        $keyboard = [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته', '📄 JSON'],
            ['🔁 روتین‌ها', '/help'],
        ];
        $this->api->sendMessageWithKeyboard($chatId, $text, $keyboard);
    }

    // ── Export: inline menu (JSON only) ──
    private function showExportMenu(string $chatId, string $platform): void
    {
        $exportService = new ExportService();
        $entries = $exportService->getEntries($chatId, $platform);

        if ($entries->isEmpty()) {
            $this->api->sendMessage($chatId, "هنوز هیچ ثبتی نداری که خروجی بدم.\nبا /log شروع کن، بعد /export را بزن.");
            return;
        }

        $nowJ = Jalalian::forge(Carbon::now('Asia/Tehran'));
        $curMonthName = $nowJ->format('F Y'); // e.g. شهریور ۱۴۰۵
        $prevJ = Jalalian::forge(Carbon::now('Asia/Tehran'))->subMonths(1);
        $prevMonthName = $prevJ->format('F Y');

        // Build specific month buttons for last 4 Shamsi months
        $monthButtons = [];
        for ($i = 0; $i < 4; $i++) {
            $j = $i === 0 ? $nowJ : Jalalian::forge(Carbon::now('Asia/Tehran'))->subMonths($i);
            $y = $j->getYear();
            $m = $j->getMonth();
            $name = $j->format('F Y');
            $cb = sprintf('exp:sh:%04d-%02d', $y, $m);
            $monthButtons[] = ['text' => $name, 'callback_data' => $cb];
        }

        $inlineKeyboard = [
            [['text' => '📅 این هفته (۷ روز)', 'callback_data' => 'exp:7'], ['text' => '📅 ۲ هفته (۱۴ روز)', 'callback_data' => 'exp:14']],
            [['text' => '📅 ۳ هفته (۲۱ روز)', 'callback_data' => 'exp:21'], ['text' => '📅 ۴ هفته (۲۸ روز)', 'callback_data' => 'exp:28']],
            [['text' => "📅 این ماه: {$curMonthName}", 'callback_data' => 'exp:curM'], ['text' => "📅 ماه قبل: {$prevMonthName}", 'callback_data' => 'exp:prevM']],
            // 4 specific Shamsi months
            [$monthButtons[0], $monthButtons[1]],
            [$monthButtons[2], $monthButtons[3]],
            [['text' => '📄 همه', 'callback_data' => 'exp:all']],
        ];

        $count = $entries->count();
        $firstShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->first()->entry_date);
        $lastShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->last()->entry_date);
        $text = "📄 خروجی JSON — بازه رو انتخاب کن:\n"
            . "کل رکوردها: {$count} ({$firstShamsi} تا {$lastShamsi})\n"
            . "فقط JSON (بدون Excel) — تاریخ شمسی + میلادی\n"
            . "👇 یک گزینه رو بزن:";

        $this->api->sendMessageWithInlineKeyboard($chatId, $text, $inlineKeyboard);
    }

    private function handleExportCallback(string $chatId, string $platform, string $data): void
    {
        $from = null; $to = null; $label = '';
        $today = Carbon::today()->toDateString();

        if ($data === 'exp:7') {
            $from = Carbon::today()->subDays(6)->toDateString(); $to = $today; $label = '۷ روز اخیر';
        } elseif ($data === 'exp:14') {
            $from = Carbon::today()->subDays(13)->toDateString(); $to = $today; $label = '۱۴ روز اخیر';
        } elseif ($data === 'exp:21') {
            $from = Carbon::today()->subDays(20)->toDateString(); $to = $today; $label = '۲۱ روز اخیر';
        } elseif ($data === 'exp:28') {
            $from = Carbon::today()->subDays(27)->toDateString(); $to = $today; $label = '۲۸ روز اخیر';
        } elseif ($data === 'exp:all') {
            $from = null; $to = null; $label = 'همه';
        } elseif ($data === 'exp:curM') {
            [$from, $to] = $this->getShamsiMonthRange(0, true); $label = 'این ماه شمسی';
        } elseif ($data === 'exp:prevM') {
            [$from, $to] = $this->getShamsiMonthRange(1, false); $label = 'ماه قبل شمسی';
        } elseif (str_starts_with($data, 'exp:sh:')) {
            $ym = substr($data, 7); // 1405-06
            if (preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
                [$from, $to] = $this->getShamsiMonthRangeByYm((int)$m[1], (int)$m[2]);
                $label = "ماه {$ym}";
            }
        }

        if ($label === '' && $from === null && $to === null && $data !== 'exp:all') {
            $this->api->sendMessage($chatId, "بازه نامعتبر. دوباره /export را بزن.");
            return;
        }

        $this->sendJsonExport($chatId, $platform, $from, $to, $label);
    }

    /**
     * @return array{0: string, 1: string} Gregorian Y-m-d for Shamsi month offset.
     */
    private function getShamsiMonthRange(int $offset, bool $currentPartialToToday): array
    {
        // offset 0 = current month, 1 = prev month, etc. Uses Jalalian subtractions.
        $j = Jalalian::forge(Carbon::now('Asia/Tehran'));
        if ($offset > 0) $j = $j->subMonths($offset);
        $y = $j->getYear(); $m = $j->getMonth();
        return $this->getShamsiMonthRangeByYm($y, $m, $currentPartialToToday && $offset === 0);
    }

    private function getShamsiMonthRangeByYm(int $jy, int $jm, bool $partialToToday = false): array
    {
        $startJ = new Jalalian($jy, $jm, 1, 0, 0, 0, new \DateTimeZone('Asia/Tehran'));
        $from = $startJ->toCarbon()->format('Y-m-d');
        if ($partialToToday) {
            $to = Carbon::today()->toDateString();
        } else {
            $days = $startJ->getMonthDays();
            $endJ = new Jalalian($jy, $jm, $days, 23, 59, 59, new \DateTimeZone('Asia/Tehran'));
            $to = $endJ->toCarbon()->format('Y-m-d');
        }
        return [$from, $to];
    }

    private function sendJsonExport(string $chatId, string $platform, ?string $from, ?string $to, string $label): void
    {
        try {
            $exportService = new ExportService();
            $entries = $exportService->getEntries($chatId, $platform, $from, $to);

            if ($entries->isEmpty()) {
                $rangeTxt = $from ? " ({$from} تا {$to})" : '';
                $this->api->sendMessage($chatId, "برای بازه «{$label}»{$rangeTxt} رکوردی پیدا نشد.");
                return;
            }

            $this->api->sendMessage($chatId, "⏳ در حال ساخت JSON برای «{$label}»...");

            $jsonArray = $exportService->toArrayWithShamsi($entries);
            $jsonContent = json_encode($jsonArray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $suffix = $from ? "_{$from}_to_{$to}" : "_all";
            $jsonFileName = "mydaily-{$chatId}-{$platform}{$suffix}-" . now()->format('Y-m-d') . ".json";
            $jsonFilePath = storage_path("app/exports/{$jsonFileName}");
            if (!is_dir(dirname($jsonFilePath))) mkdir(dirname($jsonFilePath), 0755, true);
            file_put_contents($jsonFilePath, $jsonContent);

            $shamsiCount = $entries->count();
            $firstShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->first()->entry_date);
            $lastShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->last()->entry_date);

            $caption = "📄 خروجی JSON — {$label}\n"
                . "تعداد رکورد: {$shamsiCount}\n"
                . "بازه: {$firstShamsi} تا {$lastShamsi}\n"
                . ($from ? "گریگوری: {$from} تا {$to}\n" : "")
                . "فرمت: JSON شمسی+میلادی";

            $result = $this->api->sendDocument($chatId, $jsonFilePath, $jsonFileName, $caption);
            if (($result['ok'] ?? false) !== true) {
                Log::warning("[{$platform}] export json sendDocument failed", ['result' => $result, 'label' => $label]);
                $preview = mb_substr($jsonContent, 0, 3500);
                $this->api->sendMessage($chatId, "📄 JSON ({$label}) — فایل ارسال نشد، پیش‌نمایش:\n```\n{$preview}\n```");
            }
        } catch (\Throwable $e) {
            Log::error("[{$platform}] sendJsonExport error: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $this->api->sendMessage($chatId, "❌ خطا در ساخت JSON: " . $e->getMessage());
        }
    }

    // Legacy direct handler (for /export json without menu)
    private function handleExport(string $chatId, string $platform, ?string $requestedFormat = null): void
    {
        // Only JSON now — Excel removed per request
        $this->sendJsonExport($chatId, $platform, null, null, 'همه');
    }

    private function handleUnknown(string $chatId): void
    {
        $this->api->sendMessage($chatId, "متوجه نشدم 🤔\nبرای ثبت امروز /log و برای دیدن امروز /today را بزن. راهنما: /start\nیا /export برای خروجی JSON، و /routine برای مدیریت روتین‌ها");
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
            $routineTxt = $this->formatRoutineLogs($e->chat_id, $e->platform, Carbon::parse($e->entry_date)->toDateString());
            if ($routineTxt !== '') {
                $lines[count($lines)-1] .= "\n  🔁 " . str_replace("\n", "\n  ", $routineTxt);
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

        // Handle /skip for emotional_trigger and routine notes
        if (($current === 'waiting_trigger' || $current === 'waiting_routine_note')
            && ($input === '/skip' || $input === 'skip' || $input === '-')) {
            if ($current === 'waiting_trigger') {
                $data['emotional_trigger'] = null;
                $this->proceedToRoutinesOrSave($chatId, $platform, $data);
            } else {
                $this->handleRoutineNote($chatId, $platform, $data, null);
            }
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
                $this->proceedToRoutinesOrSave($chatId, $platform, $data);
                break;

            case 'waiting_routine_title':
                $title = mb_substr(trim($input), 0, 100);
                if ($title === '') {
                    $this->api->sendMessage($chatId, "اسم روتین خالیه 😅\nمثلا بنویس: روتین پوستی");
                    return;
                }
                $this->setState($chatId, $platform, 'waiting_routine_start', ['routine_title' => $title]);
                $todayShamsi = \App\Helpers\ShamsiDateHelper::dateOnly(Carbon::today());
                $this->api->sendMessage($chatId, "📅 تاریخ شروع «{$title}» کی باشه؟\n"
                    . "مثلا: امروز، فردا، 1405/07/01 (شمسی) یا 2026-09-23 (میلادی)\n"
                    . "(امروز: {$todayShamsi})");
                break;

            case 'waiting_routine_start':
                $startsOn = $this->parseRoutineDate($input);
                if ($startsOn === null) {
                    $this->api->sendMessage($chatId, "تاریخ رو نفهمیدم 😅\nمثلا: امروز، فردا، 1405/07/01 یا 2026-09-23");
                    return;
                }
                $data['routine_starts_on'] = $startsOn;
                $this->setState($chatId, $platform, 'waiting_routine_end', $data);
                $shamsiStart = \App\Helpers\ShamsiDateHelper::dateOnly(Carbon::parse($startsOn));
                $this->api->sendMessage($chatId, "شروع: {$shamsiStart} ({$startsOn})\n"
                    . "📅 تاریخ پایان کی باشه؟\n"
                    . "مثلا: 1405/08/01 یا فقط بنویس 30 (یعنی ۳۰ روز از شروع) یا «30 روز»");
                break;

            case 'waiting_routine_end':
                $startsOn = $data['routine_starts_on'] ?? Carbon::today()->toDateString();
                $endsOn = $this->parseRoutineEnd($input, $startsOn);
                if ($endsOn === null) {
                    $this->api->sendMessage($chatId, "تاریخ پایان رو نفهمیدم 😅\nمثلا: 1405/08/01 یا فقط 30 (۳۰ روزه)");
                    return;
                }
                if ($endsOn < $startsOn) {
                    $this->api->sendMessage($chatId, "پایان ({$endsOn}) قبل از شروعه ({$startsOn})!\nیه تاریخ بعد از شروع بفرست:");
                    return;
                }
                $routine = Routine::create([
                    'chat_id' => $chatId,
                    'platform' => $platform,
                    'title' => $data['routine_title'],
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'is_active' => true,
                ]);
                $this->clearState($chatId, $platform);
                $shamsiStart = \App\Helpers\ShamsiDateHelper::dateOnly(Carbon::parse($startsOn));
                $shamsiEnd = \App\Helpers\ShamsiDateHelper::dateOnly(Carbon::parse($endsOn));
                $this->api->sendMessage($chatId, "✅ روتین «{$routine->title}» فعال شد!\n📅 {$shamsiStart} تا {$shamsiEnd}\n\n"
                    . "تا وقتی فعاله، موقع ثبت روزانه (/log) ازت می‌پرسم انجامش دادی یا نه (با دکمه بله/خیر + توضیح).");
                $this->handleRoutines($chatId, $platform);
                break;

            case 'waiting_routine_done':
                $val = $this->parseYesNo($input);
                if ($val === null) {
                    $this->askRoutineDone($chatId, $data);
                    return;
                }
                $idx = $data['routine_index'] ?? 0;
                $answers = $data['routine_answers'] ?? [];
                $answers[$idx]['routine_id'] = $data['routine_ids'][$idx];
                $answers[$idx]['done'] = $val;
                $answers[$idx]['note'] = null;
                $data['routine_answers'] = $answers;
                $this->setState($chatId, $platform, 'waiting_routine_note', $data);
                $routine = Routine::find($data['routine_ids'][$idx]);
                $title = $routine?->title ?? 'روتین';
                $emoji = $val ? '✅' : '❌';
                $this->api->sendMessageWithInlineKeyboard($chatId,
                    "{$emoji} «{$title}» ثبت شد.\n📝 توضیحی داری؟ (مثلا چی کار کردی یا چرا نشد)\nبنویس یا /skip بزن.",
                    [[['text' => '⏭ رد کردن توضیح', 'callback_data' => 'rt:skip_note']]]);
                break;

            case 'waiting_routine_note':
                $this->handleRoutineNote($chatId, $platform, $data, mb_substr($input, 0, 500));
                break;

            default:
                $this->clearState($chatId, $platform);
                $this->api->sendMessage($chatId, "خطا در وضعیت. دوباره /log را بزن.");
                break;
        }
    }

    private function saveEntry(string $chatId, string $platform, array $data): void
    {
        $entry = $this->persistEntry($chatId, $platform, $data);

        $keyboard = [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته', '📄 JSON'],
            ['🔁 روتین‌ها', '/help'],
        ];
        $this->api->sendMessageWithKeyboard($chatId, $this->formatEntry($entry, "✅ ثبت شد!"), $keyboard);
    }

    /** ذخیره‌ی خام بدون پیام — برای فلو روتین که اول لاگ‌ها را ذخیره می‌کند بعد پیام می‌دهد. */
    private function persistEntry(string $chatId, string $platform, array $data): DailyEntry
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
            return $existing->refresh();
        }

        return DailyEntry::create(array_merge([
            'chat_id' => $chatId,
            'platform' => $platform,
            'entry_date' => $targetDate,
        ], $attrs));
    }

    private function formatEntry(DailyEntry $e, string $title): string
    {
        $gym = $e->gym ? 'بله ✅' : 'خیر ❌';
        $social = $e->social ? 'بله ✅' : 'خیر ❌';
        $trigger = $e->emotional_trigger ? "\n💭 محرک: {$e->emotional_trigger}" : "\n💭 محرک: —";
        $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($e->entry_date);
        $text = "{$title} ({$e->entry_date->format('Y-m-d')} — {$shamsi})\n"
            . "😴 خواب: {$e->sleep_time} → {$e->wake_time}\n"
            . "💼 کار مفید: {$e->work_hours} ساعت\n"
            . "🏋️ باشگاه: {$gym}\n"
            . "🎮 گیم: {$e->gaming_minutes} دقیقه\n"
            . "👥 اجتماعی: {$social}\n"
            . "😊 حال: {$e->mood}/10"
            . $trigger;

        $routineLines = $this->formatRoutineLogs($e->chat_id, $e->platform, $e->entry_date->format('Y-m-d'));
        if ($routineLines !== '') {
            $text .= "\n\n🔁 روتین‌ها:\n" . $routineLines;
        }

        return $text;
    }

    private function formatRoutineLogs(string $chatId, string $platform, string $dateYmd): string
    {
        $logs = RoutineLog::with('routine')
            ->where('chat_id', $chatId)
            ->where('platform', $platform)
            ->whereDate('entry_date', $dateYmd)
            ->get();

        if ($logs->isEmpty()) return '';

        $lines = [];
        foreach ($logs as $log) {
            $title = $log->routine?->title ?? 'روتین';
            $mark = $log->done ? '✅ بله' : '❌ خیر';
            $line = "• {$title}: {$mark}";
            if ($log->note) $line .= " — {$log->note}";
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    // ── Routines ──

    /** روتین‌های فعال یک چت برای یک تاریخ (فلگ + بازه‌ی شروع/پایان). */
    public function activeRoutinesFor(string $chatId, string $platform, string $dateYmd): \Illuminate\Support\Collection
    {
        return Routine::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->whereDate('starts_on', '<=', $dateYmd)
            ->whereDate('ends_on', '>=', $dateYmd)
            ->orderBy('id')
            ->get();
    }

    private function handleRoutines(string $chatId, string $platform): void
    {
        $today = Carbon::today()->toDateString();
        $active = Routine::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->where('is_active', true)
            ->whereDate('ends_on', '>=', $today)
            ->orderBy('starts_on')
            ->get();
        $past = Routine::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->where(function ($q) use ($today) {
                $q->where('is_active', false)->orWhereDate('ends_on', '<', $today);
            })
            ->orderByDesc('ends_on')
            ->limit(5)
            ->get();

        $lines = ["🔁 روتین‌های تو:\n"];
        if ($active->isEmpty()) {
            $lines[] = "فعلا روتین فعالی نداری.";
        } else {
            $lines[] = "فعال:";
            foreach ($active as $r) {
                $s = \App\Helpers\ShamsiDateHelper::dateOnly($r->starts_on);
                $e = \App\Helpers\ShamsiDateHelper::dateOnly($r->ends_on);
                $lines[] = "• [{$r->id}] {$r->title} — {$s} تا {$e}";
            }
        }
        if (!$past->isEmpty()) {
            $lines[] = "\nتمام‌شده/متوقف:";
            foreach ($past as $r) {
                $tag = $r->is_active ? 'تمام‌شده' : 'متوقف';
                $lines[] = "• [{$r->id}] {$r->title} ({$tag})";
            }
        }
        $lines[] = "\nبرای ساخت روتین جدید /routine_new را بزن یا دکمه‌ی زیر.";
        $lines[] = "توقف: /routine_stop ID";

        $inline = [[['text' => '➕ روتین جدید', 'callback_data' => 'rt:new']]];
        foreach ($active as $r) {
            $label = mb_substr("⏹ توقف «{$r->title}»", 0, 40);
            $inline[] = [['text' => $label, 'callback_data' => "rt:stop:{$r->id}"]];
        }

        $this->api->sendMessageWithInlineKeyboard($chatId, implode("\n", $lines), $inline);
    }

    private function startRoutineWizard(string $chatId, string $platform): void
    {
        $this->setState($chatId, $platform, 'waiting_routine_title', []);
        $this->api->sendMessage($chatId, "➕ روتین جدید!\n\nاسم روتین چیه؟\nمثلا: روتین پوستی\n(برای لغو /cancel)");
    }

    private function handleRoutineStop(string $chatId, string $platform, ?string $id): void
    {
        if (!$id || !ctype_digit((string) $id)) {
            $this->api->sendMessage($chatId, "آیدی روتین رو بفرست: /routine_stop ID\n(آیدی‌ها رو با /routine ببین)");
            return;
        }
        $routine = Routine::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->where('id', (int) $id)
            ->first();
        if (!$routine) {
            $this->api->sendMessage($chatId, "روتینی با این آیدی پیدا نکردم.");
            return;
        }
        if (!$routine->is_active) {
            $this->api->sendMessage($chatId, "«{$routine->title}» قبلا متوقف شده.");
            return;
        }
        $routine->update(['is_active' => false]);
        $this->api->sendMessage($chatId, "⏹ روتین «{$routine->title}» متوقف شد.\nلاگ‌های قبلی سر جاشون می‌مونن.");
        $this->handleRoutines($chatId, $platform);
    }

    /** بعد از آخرین سوال گزارش: اگر روتین فعال هست، وارد سوال‌های روتین شو وگرنه ذخیره کن. */
    private function proceedToRoutinesOrSave(string $chatId, string $platform, array $data): void
    {
        $targetDate = $data['entry_date'] ?? Carbon::today()->toDateString();
        try { $targetDate = Carbon::parse($targetDate)->toDateString(); } catch (\Throwable $e) { $targetDate = Carbon::today()->toDateString(); }

        $routines = $this->activeRoutinesFor($chatId, $platform, $targetDate);

        if ($routines->isEmpty()) {
            $this->saveEntry($chatId, $platform, $data);
            $this->clearState($chatId, $platform);
            return;
        }

        $data['entry_date'] = $targetDate;
        $data['routine_ids'] = $routines->pluck('id')->all();
        $data['routine_index'] = 0;
        $data['routine_answers'] = [];
        $this->setState($chatId, $platform, 'waiting_routine_done', $data);
        $this->askRoutineDone($chatId, $data);
    }

    private function askRoutineDone(string $chatId, array $data): void
    {
        $idx = $data['routine_index'] ?? 0;
        $ids = $data['routine_ids'] ?? [];
        $total = count($ids);
        $routine = Routine::find($ids[$idx] ?? 0);
        $title = $routine?->title ?? 'روتین';

        $this->api->sendMessageWithInlineKeyboard($chatId,
            "🔁 روتین «{$title}» (" . ($idx + 1) . " از {$total})\nامروز انجامش دادی؟",
            [[
                ['text' => '✅ بله', 'callback_data' => 'rt:yes'],
                ['text' => '❌ خیر', 'callback_data' => 'rt:no'],
            ]]);
    }

    /** ثبت توضیح روتین و رفتن به روتین بعدی یا ذخیره‌ی نهایی. */
    private function handleRoutineNote(string $chatId, string $platform, array $data, ?string $note): void
    {
        $idx = $data['routine_index'] ?? 0;
        $answers = $data['routine_answers'] ?? [];
        $answers[$idx]['routine_id'] = $data['routine_ids'][$idx];
        // done قبلا در مرحله‌ی waiting_routine_done ست شده؛ اگر به هر دلیلی نبود، null می‌ماند
        $answers[$idx]['done'] = $answers[$idx]['done'] ?? null;
        $answers[$idx]['note'] = $note ? mb_substr($note, 0, 500) : null;
        $data['routine_answers'] = $answers;

        $next = $idx + 1;
        if ($next < count($data['routine_ids'])) {
            $data['routine_index'] = $next;
            $this->setState($chatId, $platform, 'waiting_routine_done', $data);
            $this->askRoutineDone($chatId, $data);
            return;
        }

        // همه‌ی روتین‌ها جواب داده شدند — ذخیره‌ی نهایی
        $entry = $this->persistEntry($chatId, $platform, $data);
        $this->saveRoutineLogs($chatId, $platform, $data['entry_date'], $answers);
        $this->clearState($chatId, $platform);

        $keyboard = [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته', '📄 JSON'],
            ['🔁 روتین‌ها', '/help'],
        ];
        $this->api->sendMessageWithKeyboard($chatId, $this->formatEntry($entry->refresh(), "✅ ثبت شد!"), $keyboard);
    }

    private function saveRoutineLogs(string $chatId, string $platform, string $dateYmd, array $answers): void
    {
        foreach ($answers as $a) {
            if (!isset($a['routine_id'])) continue;
            // whereDate مثل saveEntry — چون entry_date از نوع date است
            $existing = RoutineLog::where('routine_id', $a['routine_id'])
                ->whereDate('entry_date', $dateYmd)
                ->first();
            $attrs = [
                'chat_id' => $chatId,
                'platform' => $platform,
                'done' => $a['done'] ?? null,
                'note' => $a['note'] ?? null,
            ];
            if ($existing) {
                $existing->update($attrs);
            } else {
                RoutineLog::create(array_merge([
                    'routine_id' => $a['routine_id'],
                    'entry_date' => $dateYmd,
                ], $attrs));
            }
        }
    }

    /**
     * پارس تاریخ روتین: «امروز/فردا/دیروز»، شمسی 1405/07/01، میلادی 2026-09-23.
     * خروجی Y-m-d میلادی یا null.
     */
    private function parseRoutineDate(string $input): ?string
    {
        $raw = trim($input);
        if ($raw === '') return null;
        $raw = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9'], $raw);
        $lower = mb_strtolower($raw);

        if (in_array($lower, ['امروز', 'today', 'الان', 'now'], true)) return Carbon::today()->toDateString();
        if (in_array($lower, ['فردا', 'tomorrow'], true)) return Carbon::tomorrow()->toDateString();
        if (in_array($lower, ['دیروز', 'yesterday'], true)) return Carbon::yesterday()->toDateString();

        $norm = str_replace(['-', '.', ' '], '/', preg_replace('/\s+/', '', $lower));
        if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $norm, $m)) return null;
        $y = (int) $m[1]; $mo = (int) $m[2]; $d = (int) $m[3];

        // سال ۱۳۰۰-۱۵۰۰ = شمسی، بقیه = میلادی
        if ($y >= 1300 && $y <= 1500) {
            try {
                return Jalalian::fromFormat('Y/m/d', sprintf('%04d/%02d/%02d', $y, $mo, $d))->toCarbon()->toDateString();
            } catch (\Throwable $e) { return null; }
        }
        try {
            $c = Carbon::createFromDate($y, $mo, $d);
            if (!$c || $c->format('Y-m-d') !== sprintf('%04d-%02d-%02d', $y, $mo, $d)) return null;
            return $c->toDateString();
        } catch (\Throwable $e) { return null; }
    }

    /**
     * پارس پایان روتین: یا تاریخ (مثل شروع) یا مدت مثل «30» / «30 روز».
     * مدت N روزه یعنی ends_on = starts_on + (N-1) روز (شامل روز شروع).
     */
    private function parseRoutineEnd(string $input, string $startsOn): ?string
    {
        $raw = trim($input);
        $fa = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9'], $raw);
        if (preg_match('/^(\d{1,3})\s*(روز|days?)?$/ui', trim($fa), $m)) {
            $n = (int) $m[1];
            if ($n < 1 || $n > 365) return null;
            // اگر کاربر کلمه‌ی «روز» نگفته و ورودی شبیه تاریخ است، تاریخ را ترجیح بده
            if (empty($m[2]) && str_contains($fa, '/')) {
                return $this->parseRoutineDate($input);
            }
            if (empty($m[2]) && $n > 365) return null;
            return Carbon::parse($startsOn)->addDays($n - 1)->toDateString();
        }
        return $this->parseRoutineDate($input);
    }

    /** متن یادآور ۱۲ شب — اگر دیروز ثبت نشده، بهش اشاره می‌کند. */
    public static function midnightReminderText(string $chatId, string $platform): string
    {
        $yesterday = Carbon::yesterday()->toDateString();
        $hasYesterday = DailyEntry::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->whereDate('entry_date', $yesterday)
            ->exists();

        $text = "⏰ ساعت ۱۲ شب شد!\nبیا گزارش امروز رو پر کن 📝\nبرای شروع /log را بزن.";
        if (!$hasYesterday) {
            $yesterdayShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay(Carbon::yesterday());
            $text .= "\n\n⚠️ دیروز ({$yesterdayShamsi}) رو ثبت نکردی — با /log می‌تونی اول انتخاب کنی دیروز یا امروز.";
        }
        return $text;
    }

    private function touchSubscriber(string $chatId, string $platform, ?string $username = null): void
    {
        try {
            BotSubscriber::updateOrCreate(
                ['chat_id' => $chatId, 'platform' => $platform],
                ['username' => $username, 'last_seen_at' => now()]
            );
        } catch (\Throwable $e) {
            Log::warning("[{$platform}] touchSubscriber failed: " . $e->getMessage());
        }
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
