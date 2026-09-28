<?php

namespace App\Services;

use App\Models\BotState;
use App\Models\BotSubscriber;
use App\Models\CoachingProfile;
use App\Models\DailyEntry;
use App\Models\FreeNote;
use App\Models\Note;
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
        'waiting_sleep'             => 'sleep_time',
        'waiting_wake'              => 'wake_time',
        'waiting_work'              => 'work_hours',
        'waiting_gym'               => 'gym',
        'waiting_gaming'            => 'gaming_minutes',
        'waiting_social'            => 'social',
        'waiting_mood'              => 'mood',
        'waiting_trigger'           => 'emotional_trigger',
        'waiting_positive_trigger'  => 'positive_trigger',
        'waiting_positive_intensity' => 'positive_intensity',
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
        'waiting_positive_trigger',
        'waiting_positive_intensity',
    ];

    public function __construct(BotApi $api)
    {
        $this->api = $api;
    }

    // ── CBT ──
    private function cbt(): CbtService
    {
        return new CbtService($this->api);
    }

    private function isCbtText(string $text): bool
    {
        return $text === '🧠 دفترچه فکر و احساس'
            || $text === '🧠 دفترچه فکر'
            || str_starts_with($text, '🧠 دفترچه')
            || $text === '/cbt'
            || $text === '/thought'
            || $text === '/fekr';
    }

    private function isNoteText(string $text): bool
    {
        return $text === '📌 نکته'
            || $text === 'نکته'
            || $text === '/note'
            || $text === '/notes'
            || $text === '/nokte';
    }

    private function isFreeNoteText(string $text): bool
    {
        return $text === '✍️ توضیحات آزاد'
            || $text === 'توضیحات آزاد'
            || $text === 'توضیح آزاد'
            || $text === '/freenote'
            || $text === '/free'
            || $text === '/azad'
            || $text === '/desc';
    }

    private function isCoachingText(string $text): bool
    {
        return $text === '🔄 آپدیت پرونده کوچینگ'
            || $text === 'آپدیت پرونده کوچینگ'
            || $text === 'آپدیت پرونده'
            || $text === 'پرونده کوچینگ'
            || $text === '/coaching'
            || $text === '/coach';
    }

    private function isCoachingGetText(string $text): bool
    {
        return $text === '📥 دریافت پرونده کوچینگ'
            || $text === 'دریافت پرونده کوچینگ'
            || $text === 'دریافت پرونده'
            || $text === '/coaching_get'
            || $text === '/get_coaching';
    }

    // ── Public entry point for every incoming message ──

    public function handle(string $chatId, string $platform, string $text, ?string $username = null): void
    {
        $text = trim($text);
        $this->touchSubscriber($chatId, $platform, $username);

        $cbt = $this->cbt();

        // CBT wizard has priority (stored in Cache, not BotState)
        if ($cbt->hasActiveState($chatId, $platform)) {
            if ($text === '/cancel') {
                $cbt->clear($chatId, $platform);
                $this->clearState($chatId, $platform);
                $this->api->sendMessage($chatId, "❌ ثبت لغو شد. برای شروع دوباره /log را بزن.");
                return;
            }
            // Allow hard commands to interrupt CBT flow
            $isDirectDayBtn = str_starts_with($text, '📝 دیروز') || str_starts_with($text, '📝 امروز') || $text === 'دیروز' || $text === 'امروز';
            if (in_array($text, ['/start', '/today', '/week', '/log', '/export', '/help', '/routine', '/routines', '🔁 روتین‌ها', '/profile', '👤 حساب کاربری', '👤 پروفایل', '🧠 دفترچه فکر و احساس', '/cbt', '/thought', '/fekr', '📌 نکته', 'نکته', '/note', '/notes', '/nokte', '✍️ توضیحات آزاد', 'توضیحات آزاد', '/freenote', '/free', '/azad', '/desc', '🔄 آپدیت پرونده کوچینگ', '/coaching', '/coach', '📥 دریافت پرونده کوچینگ', '/coaching_get', '/get_coaching']) || $isDirectDayBtn || $this->isCbtText($text) || $this->isNoteText($text) || $this->isFreeNoteText($text) || $this->isCoachingText($text) || $this->isCoachingGetText($text)) {
                $cbt->clear($chatId, $platform);
                // fall through to normal handling (also clear BotState if needed below)
                $this->clearState($chatId, $platform);
            } else {
                $cbt->handleStep($chatId, $platform, $text);
                return;
            }
        }

        // If user is mid-flow, delegate to state handler (except hard commands)
        $state = $this->getState($chatId, $platform);

        // Commands that always work even mid-flow
        if ($text === '/cancel') {
            $this->clearState($chatId, $platform);
            // also clear CBT if somehow still active
            $cbt->clear($chatId, $platform);
            $this->api->sendMessage($chatId, "❌ ثبت لغو شد. برای شروع دوباره /log را بزن.");
            return;
        }

        // Direct day buttons — allow interrupting flow as well
        $isDirectDayBtn = str_starts_with($text, '📝 دیروز') || str_starts_with($text, '📝 امروز') || $text === 'دیروز' || $text === 'امروز';

        // CBT menu entry (works even mid-flow — interrupts daily flow)
        if ($this->isCbtText($text)) {
            if ($state && $state->state !== null) $this->clearState($chatId, $platform);
            $cbt->menu($chatId, $platform);
            return;
        }

        // If in a flow, handle step input before checking other commands
        if ($state && $state->state !== null) {
            // Allow /start /today /week /export /routine /note /freenote /coaching + direct day buttons + CBT to interrupt flow
            if (in_array($text, ['/start', '/today', '/week', '/log', '/export', '/help', '/routine', '/routines', '🔁 روتین‌ها', '/profile', '👤 حساب کاربری', '🧠 دفترچه فکر و احساس', '/cbt', '📌 نکته', 'نکته', '/note', '/notes', '/nokte', '✍️ توضیحات آزاد', 'توضیحات آزاد', '/freenote', '/free', '/azad', '/desc', '🔄 آپدیت پرونده کوچینگ', '/coaching', '/coach', '📥 دریافت پرونده کوچینگ', '/coaching_get', '/get_coaching']) || $isDirectDayBtn || $this->isCbtText($text) || $this->isNoteText($text) || $this->isFreeNoteText($text) || $this->isCoachingText($text) || $this->isCoachingGetText($text)) {
                $this->clearState($chatId, $platform);
                // fall through to command handling below
            } else {
                $this->handleStep($chatId, $platform, $text, $state);
                return;
            }
        }

        // Direct day buttons from main menu (outside flow) — start immediately for that date
        if (str_starts_with($text, '📝 دیروز') || $text === 'دیروز') {
            $this->startLoggingForDate($chatId, $platform, Carbon::yesterday()->toDateString());
            return;
        }
        if (str_starts_with($text, '📝 امروز') && $text !== '📝 ثبت امروز') {
            $this->startLoggingForDate($chatId, $platform, Carbon::today()->toDateString());
            return;
        }
        if ($text === 'امروز') {
            $this->startLoggingForDate($chatId, $platform, Carbon::today()->toDateString());
            return;
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
        if ($this->isCbtText($text)) {
            $cbt->menu($chatId, $platform);
            return;
        }

        if ($this->isNoteText($text)) {
            if ($state && $state->state !== null) $this->clearState($chatId, $platform);
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }

        if ($this->isFreeNoteText($text)) {
            if ($state && $state->state !== null) $this->clearState($chatId, $platform);
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }

        if ($this->isCoachingText($text)) {
            if ($state && $state->state !== null) $this->clearState($chatId, $platform);
            $this->showCoachingUpdate($chatId, $platform);
            return;
        }

        if ($this->isCoachingGetText($text)) {
            if ($state && $state->state !== null) $this->clearState($chatId, $platform);
            $this->showCoachingFile($chatId, $platform);
            return;
        }

        if ($text === '/profile' || $text === '👤 حساب کاربری' || $text === '👤 پروفایل') {
            $this->handleProfile($chatId, $platform);
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

        if ($this->isCbtText($text)) {
            $cbt->menu($chatId, $platform);
            return;
        }

        if ($this->isNoteText($text)) {
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }

        if ($this->isFreeNoteText($text)) {
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }

        if ($this->isCoachingText($text)) {
            $this->showCoachingUpdate($chatId, $platform);
            return;
        }

        if ($this->isCoachingGetText($text)) {
            $this->showCoachingFile($chatId, $platform);
            return;
        }

        match (true) {
            $text === '/start' => $this->handleStart($chatId, $platform),
            $text === '/log' => $this->startLogging($chatId, $platform),
            $text === '/today' => $this->handleToday($chatId, $platform),
            $text === '/week' => $this->handleWeek($chatId, $platform),
            $text === '/help' => $this->handleStart($chatId, $platform),
            $text === '/routine' => $this->handleRoutines($chatId, $platform),
            $text === '/routines' => $this->handleRoutines($chatId, $platform),
            $text === '/profile' => $this->handleProfile($chatId, $platform),
            $text === '👤 حساب کاربری' => $this->handleProfile($chatId, $platform),
            $text === '👤 پروفایل' => $this->handleProfile($chatId, $platform),
            $text === '📝 ثبت امروز' => $this->startLogging($chatId, $platform),
            $text === '📊 امروز' => $this->handleToday($chatId, $platform),
            $text === '📅 هفته' => $this->handleWeek($chatId, $platform),
            $text === '📊 خروجی' => $this->showExportMenu($chatId, $platform),
            $text === '📄 JSON' => $this->showExportMenu($chatId, $platform),
            $text === '📊 اکسل' => $this->showExportMenu($chatId, $platform),
            $text === '📥 اکسل' => $this->showExportMenu($chatId, $platform),
            $text === '🧠 دفترچه فکر و احساس' => $cbt->menu($chatId, $platform),
            $text === '/cbt' => $cbt->menu($chatId, $platform),
            $text === '📌 نکته' => $this->showNotesMenu($chatId, $platform, 0),
            $text === 'نکته' => $this->showNotesMenu($chatId, $platform, 0),
            $text === '/note' => $this->showNotesMenu($chatId, $platform, 0),
            $text === '/notes' => $this->showNotesMenu($chatId, $platform, 0),
            $text === '✍️ توضیحات آزاد' => $this->showFreeNotesMenu($chatId, $platform, 0),
            $text === 'توضیحات آزاد' => $this->showFreeNotesMenu($chatId, $platform, 0),
            $text === '/freenote' => $this->showFreeNotesMenu($chatId, $platform, 0),
            $text === '/free' => $this->showFreeNotesMenu($chatId, $platform, 0),
            $text === '/azad' => $this->showFreeNotesMenu($chatId, $platform, 0),
            $text === '/desc' => $this->showFreeNotesMenu($chatId, $platform, 0),
            $text === '🔄 آپدیت پرونده کوچینگ' => $this->showCoachingUpdate($chatId, $platform),
            $text === '/coaching' => $this->showCoachingUpdate($chatId, $platform),
            $text === '/coach' => $this->showCoachingUpdate($chatId, $platform),
            $text === '📥 دریافت پرونده کوچینگ' => $this->showCoachingFile($chatId, $platform),
            $text === '/coaching_get' => $this->showCoachingFile($chatId, $platform),
            $text === '/get_coaching' => $this->showCoachingFile($chatId, $platform),
            default => $this->handleUnknown($chatId),
        };
    }

    // Also handle callback queries (inline buttons) if we use them
    public function handleCallback(string $chatId, string $platform, string $data, string $callbackQueryId, ?int $messageId = null): void
    {
        $this->api->answerCallbackQuery($callbackQueryId);
        $this->touchSubscriber($chatId, $platform);

        // CBT — thought_record_* callbacks
        if (str_starts_with($data, 'thought_record_')) {
            $this->handleCbtCallback($chatId, $platform, $data, $messageId);
            return;
        }

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
        // Coaching — «همین بود، ذخیره کن» زیر پیام رسید قسمت‌ها
        if ($data === 'coaching:done') {
            $this->finishCoachingFromBuffer($chatId, $platform);
            return;
        }
        if ($data === 'pf:share') {
            $this->sendShareCard($chatId, $platform);
            return;
        }
        if ($data === 'pf:menu') {
            $this->sendMainMenu($chatId, $platform);
            return;
        }

        // Notes — note:new / note:list / note:page:N / note:view:ID / note:del:ID / note:delconf:ID / note:editt:ID / note:editb:ID / note:menu
        if ($data === 'note:new' || $data === 'note:list' || $data === 'note:menu' || str_starts_with($data, 'note:page:') || str_starts_with($data, 'note:view:') || str_starts_with($data, 'note:delconf:') || str_starts_with($data, 'note:del:') || str_starts_with($data, 'note:editt:') || str_starts_with($data, 'note:editb:')) {
            $this->handleNoteCallback($chatId, $platform, $data);
            return;
        }

        // Free notes (توضیحات آزاد — بدون تایتل) — free:new / free:list / free:page:N / free:view:ID / free:del:ID / free:delconf:ID / free:edit:ID / free:menu
        if ($data === 'free:new' || $data === 'free:list' || $data === 'free:menu' || str_starts_with($data, 'free:page:') || str_starts_with($data, 'free:view:') || str_starts_with($data, 'free:delconf:') || str_starts_with($data, 'free:del:') || str_starts_with($data, 'free:edit:')) {
            $this->handleFreeNoteCallback($chatId, $platform, $data);
            return;
        }

        // Map callbacks to inputs for gym/social/mood steps
        $state = $this->getState($chatId, $platform);
        if ($state && $state->state) {
            $this->handleStep($chatId, $platform, $data, $state);
        }

        // CBT fallback: if CBT wizard is active, let its step handler try (e.g. typed distortions via callback path)
        $cbt = $this->cbt();
        if ($cbt->hasActiveState($chatId, $platform)) {
            $cbt->handleStep($chatId, $platform, $data);
        }
    }

    // ── Commands ──

    /** متن معرفی ربات — هم در /start هم در بازگشت به منوی اصلی استفاده می‌شود. */
    private function welcomeText(): string
    {
        return "سلام! 👋\n"
            . "من ربات مای‌دیلی هستم — دستیار ثبت فعالیت‌های روزانه‌ات.\n\n"
            . "این موارد را هر روز ثبت می‌کنیم:\n"
            . "😴 خواب/بیداری — 💼 کار مفید — 🏋️ باشگاه — 🎮 گیم — 👥 تعامل اجتماعی — 😊 حال (۱-۱۰) — 💭 محرک احساسی — 🌟 عامل مثبت و شدت اثرش\n\n"
            . "دستورات:\n"
            . "/log — شروع ثبت امروز (مرحله به مرحله)\n"
            . "/today — نمایش ثبت امروز\n"
            . "/week — نمایش ۷ روز گذشته\n"
            . "/routine — مدیریت روتین‌ها (مثل روتین پوستی با تاریخ شروع/پایان)\n"
            . "/cbt — دفترچه فکر و احساس (CBT) — روانشناس گفته هر بار حس منفی داشتی، این جدول رو پر کن\n"
            . "/note — نکته‌ها (یادداشت با اسم، متن تا ۱۵۰۰ حرف)\n"
            . "/freenote — توضیحات آزاد (بدون تایتل؛ با تاریخ شمسی در خروجی JSON می‌آید)\n"
            . "/coaching — پرونده کوچینگ (دریافت پرامت آپدیت + ثبت JSON جدید)\n"
            . "/coaching_get — دریافت متن فعلی پرونده کوچینگ\n"
            . "/profile — حساب کاربری (نام، شناسه، تاریخ عضویت)\n"
            . "/export — خروجی JSON (با انتخاب بازه؛ فقط JSON)\n"
            . "/cancel — لغو ثبت جاری\n\n"
            . "برای شروع /log را بزن.";
    }

    private function handleCbtCallback(string $chatId, string $platform, string $data, ?int $messageId = null): void
    {
        $cbt = $this->cbt();

        // distortion toggle: thought_record_dist_0 .. 9
        if (preg_match('/^thought_record_dist_(\d+)$/', $data, $m)) {
            $cbt->addDistortion($chatId, $platform, (int) $m[1], $messageId);
            return;
        }

        // closing MCQ: thought_record_closing_{index}_{letter|skip}
        if (preg_match('/^thought_record_closing_(\d+)_([A-D]|skip)$/', $data, $m)) {
            $cbt->selectClosing($chatId, $platform, (int) $m[1], $m[2] === 'skip' ? null : $m[2]);
            return;
        }

        match ($data) {
            'thought_record_menu' => $cbt->menu($chatId, $platform),
            'thought_record_home' => $this->handleStart($chatId, $platform),
            'thought_record_new' => $cbt->startNew($chatId, $platform),
            'thought_record_skip_event' => $cbt->skipEvent($chatId, $platform),
            'thought_record_cancel' => $cbt->cancel($chatId, $platform),
            'thought_record_dist_done' => $cbt->doneDistortion($chatId, $platform),
            'thought_record_q_skip' => $cbt->skipQuestion($chatId, $platform),
            'thought_record_view' => $cbt->view($chatId, $platform),
            'thought_record_reset' => $cbt->resetPrompt($chatId, $platform),
            'thought_record_reset_confirm' => $cbt->resetConfirmed($chatId, $platform),
            default => $cbt->menu($chatId, $platform),
        };
    }

    private function handleStart(string $chatId, ?string $platform = null): void
    {
        $this->api->sendMessageWithKeyboard($chatId, $this->welcomeText(), $this->mainMenuKeyboard($chatId, $platform));
    }

    /** کیبورد منوی اصلی — اگر دیروز ثبت نشده، دکمه‌ی «دیروز» را هم نشان می‌دهد. */
    private function mainMenuKeyboard(string $chatId, ?string $platform = null): array
    {
        $showYesterdayBtn = false;
        $yesterdayShamsi = null;
        try {
            $yesterday = Carbon::yesterday()->toDateString();
            $q = DailyEntry::where('chat_id', $chatId)->whereDate('entry_date', $yesterday);
            if ($platform !== null) $q->where('platform', $platform);
            if (!$q->exists()) {
                $showYesterdayBtn = true;
                $yesterdayShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay(Carbon::yesterday());
            }
        } catch (\Throwable $e) {
            $showYesterdayBtn = false;
        }

        if ($showYesterdayBtn) {
            return [
                ['📝 ثبت امروز', "📝 دیروز — {$yesterdayShamsi}"],
                ['📊 امروز', '📅 هفته'],
                ['📄 JSON', '🔁 روتین‌ها'],
                ['🧠 دفترچه فکر و احساس', '📌 نکته'],
                ['✍️ توضیحات آزاد', '👤 حساب کاربری'],
                ['📥 دریافت پرونده کوچینگ', '🔄 آپدیت پرونده کوچینگ'],
            ];
        }
        return [
            ['📝 ثبت امروز', '📊 امروز'],
            ['📅 هفته', '📄 JSON'],
            ['🔁 روتین‌ها', '🧠 دفترچه فکر و احساس'],
            ['📌 نکته', '✍️ توضیحات آزاد'],
            ['👤 حساب کاربری'],
            ['📥 دریافت پرونده کوچینگ', '🔄 آپدیت پرونده کوچینگ'],
        ];
    }

    // ── Export: inline menu (JSON only) ──
    private function showExportMenu(string $chatId, string $platform): void
    {
        $exportService = new ExportService();
        $entries = $exportService->getEntries($chatId, $platform);
        $freeCount = $exportService->getFreeNotes($chatId, $platform)->count();

        if ($entries->isEmpty() && $freeCount === 0) {
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
        $text = "📄 خروجی JSON — بازه رو انتخاب کن:\n";
        if ($count > 0) {
            $firstShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->first()->entry_date);
            $lastShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->last()->entry_date);
            $text .= "کل رکوردهای روزانه: {$count} ({$firstShamsi} تا {$lastShamsi})\n";
        }
        if ($freeCount > 0) {
            $text .= "توضیحات آزاد: {$freeCount} مورد (با تاریخ شمسی داخل فایل می‌آید)\n";
        }
        $text .= "فقط JSON (بدون Excel) — تاریخ شمسی + میلادی\n"
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
            $freeNotes = $exportService->getFreeNotes($chatId, $platform, $from, $to);

            if ($entries->isEmpty() && $freeNotes->isEmpty()) {
                $rangeTxt = $from ? " ({$from} تا {$to})" : '';
                $this->api->sendMessage($chatId, "برای بازه «{$label}»{$rangeTxt} رکوردی پیدا نشد.");
                return;
            }

            $this->api->sendMessage($chatId, "⏳ در حال ساخت JSON برای «{$label}»...");

            // توضیحات آزاد با تاریخ شمسی داخل همان فایل JSON می‌آید (کلید free_descriptions)
            $jsonArray = [
                'label' => $label,
                'from_miladi' => $from,
                'to_miladi' => $to,
                'exported_at_shamsi' => \App\Helpers\ShamsiDateHelper::fullDateTime(now()),
                'daily_entries' => $exportService->toArrayWithShamsi($entries),
                'free_descriptions' => $exportService->freeNotesToArrayWithShamsi($freeNotes),
            ];
            $jsonContent = json_encode($jsonArray, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $suffix = $from ? "_{$from}_to_{$to}" : "_all";
            $jsonFileName = "mydaily-{$chatId}-{$platform}{$suffix}-" . now()->format('Y-m-d') . ".json";
            $jsonFilePath = storage_path("app/exports/{$jsonFileName}");
            if (!is_dir(dirname($jsonFilePath))) mkdir(dirname($jsonFilePath), 0755, true);
            file_put_contents($jsonFilePath, $jsonContent);

            $shamsiCount = $entries->count();
            $freeCount = $freeNotes->count();
            if (!$entries->isEmpty()) {
                $firstShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->first()->entry_date);
                $lastShamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($entries->last()->entry_date);
                $rangeLine = "بازه: {$firstShamsi} تا {$lastShamsi}\n";
            } else {
                $rangeLine = '';
            }

            $caption = "📄 خروجی JSON — {$label}\n"
                . "تعداد رکورد روزانه: {$shamsiCount}\n"
                . "تعداد توضیحات آزاد: {$freeCount}\n"
                . $rangeLine
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
        $this->api->sendMessage($chatId, "متوجه نشدم 🤔\nبرای ثبت امروز /log و برای دیدن امروز /today را بزن. راهنما: /start\nیا /export برای خروجی JSON، و /routine برای مدیریت روتین‌ها، و /note برای نکته‌ها، و /freenote برای توضیحات آزاد");
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
        $this->api->sendMessage($chatId, "شروع می‌کنیم! 📝\n📅 {$shamsiToday} — ثبت امروز\n\n۱/۱۰ — ساعت خوابت کی بود؟\nمثال: 23:30 یا 6 صبح یا 7 عصر\n(برای لغو /cancel)");
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
            if ($e->positive_trigger) {
                $intensityTxt = $e->positive_intensity !== null ? " ({$e->positive_intensity}/10)" : "";
                $lines[count($lines)-1] .= "\n  🌟 {$e->positive_trigger}{$intensityTxt}";
            }
            $routineTxt = $this->formatRoutineLogs($e->chat_id, $e->platform, Carbon::parse($e->entry_date)->toDateString());
            if ($routineTxt !== '') {
                $lines[count($lines)-1] .= "\n  🔁 " . str_replace("\n", "\n  ", $routineTxt);
            }
        }

        $this->api->sendMessage($chatId, implode("\n", $lines));
    }

    private function handleProfile(string $chatId, string $platform): void
    {
        // touchSubscriber در ابتدای handle() اجرا شده، پس رکورد حتما هست؛
        // created_at آن = اولین تعامل = تاریخ عضویت
        $sub = BotSubscriber::where('chat_id', $chatId)->where('platform', $platform)->first();
        $username = ($sub && $sub->username) ? '@' . ltrim($sub->username, '@') : '—';
        $memberSince = ($sub && $sub->created_at)
            ? \App\Helpers\ShamsiDateHelper::dateWithDay($sub->created_at)
            : '—';

        $text = "👤 حساب کاربری\n\n"
            . "نام کاربری: {$username}\n"
            . "شناسه کاربری: {$chatId}\n"
            . "تاریخ عضویت: {$memberSince}";

        $this->api->sendMessageWithInlineKeyboard($chatId, $text, [
            [['text' => '📤 معرفی به دوستان', 'callback_data' => 'pf:share']],
            [['text' => '🏠 منوی اصلی', 'callback_data' => 'pf:menu']],
        ]);
    }

    /** بازگشت به منوی اصلی از دکمه‌ی اینلاین پروفایل — متن معرفی + کیبورد اصلی. */
    private function sendMainMenu(string $chatId, string $platform): void
    {
        $this->api->sendMessageWithKeyboard($chatId, $this->welcomeText(), $this->mainMenuKeyboard($chatId, $platform));
    }

    /** کارت معرفیِ فورواردی: آیدی ربات + توضیح مینیمال — کاربر برای دوستانش فوروارد می‌کند. */
    private function sendShareCard(string $chatId, string $platform): void
    {
        $botUsername = null;
        try {
            $me = $this->api->getMe();
            $botUsername = ($me['ok'] ?? false) ? ltrim((string) ($me['result']['username'] ?? ''), '@') : null;
        } catch (\Throwable $e) {
            Log::warning("[{$platform}] getMe failed: " . $e->getMessage());
        }

        $lines = [
            "🤖 ربات MyDayli — ثبت فعالیت روزانه",
            "",
            "هر روز ۲ دقیقه:",
            "😴 خواب و بیداری، 💼 کار مفید، 🏋️ باشگاه",
            "🎮 گیم، 👥 تعامل اجتماعی، 😊 حال روزانه",
            "🔁 روتین شخصی (مثل روتین پوستی) + یادآوری هر شب ساعت ۱۲",
        ];
        if ($botUsername) {
            $link = $platform === 'bale' ? "@{$botUsername}" : "https://t.me/{$botUsername}";
            $lines[] = "";
            $lines[] = "👉 شروع: {$link}";
        } else {
            $lines[] = "";
            $lines[] = "👉 از همین ربات شروع کن: /start";
        }
        $lines[] = "";
        $lines[] = "این پیام را برای دوستانت فوروارد کن 📤";

        $this->api->sendMessage($chatId, implode("\n", $lines));
    }

    // ── Step handler ──

    private function handleStep(string $chatId, string $platform, string $input, BotState $state): void
    {
        $current = $state->state;
        $data = $state->data ?? [];

        $input = trim($input);

        // Handle /skip for emotional_trigger, positive steps and routine notes
        if (in_array($current, ['waiting_trigger', 'waiting_positive_trigger', 'waiting_positive_intensity', 'waiting_routine_note'], true)
            && ($input === '/skip' || $input === 'skip' || $input === '-')) {
            if ($current === 'waiting_trigger') {
                $data['emotional_trigger'] = null;
                $this->askPositiveTrigger($chatId, $platform, $data);
            } elseif ($current === 'waiting_positive_trigger') {
                $data['positive_trigger'] = null;
                $data['positive_intensity'] = null;
                $this->proceedToRoutinesOrSave($chatId, $platform, $data);
            } elseif ($current === 'waiting_positive_intensity') {
                $data['positive_intensity'] = null;
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
            $this->api->sendMessage($chatId, "شروع می‌کنیم! 📝\n📅 {$shamsiChosen} — {$label}\n\n۱/۱۰ — ساعت خوابت کی بود؟\nمثال: 23:30 یا 6 صبح یا 7 عصر\n(برای لغو /cancel)");
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
                $dw = $this->dayWord($data);
                $this->api->sendMessage($chatId, "۲/۱۰ — ساعت بیداریت ({$dw})؟\nمثال: 07:00 یا 6 صبح");
                break;

            case 'waiting_wake':
                $parsed = $this->parseFlexibleTime($input);
                if ($parsed === null) {
                    $this->api->sendMessage($chatId, "ساعت رو درست متوجه نشدم 😅\nمثلا بفرست: 07:00 یا 6 صبح یا فقط 6\nدوباره بفرست:");
                    return;
                }
                $data['wake_time'] = $parsed;
                $this->setState($chatId, $platform, 'waiting_work', $data);
                $dw = $this->dayWord($data);
                $this->api->sendMessage($chatId, "۳/۱۰ — {$dw} چند ساعت کار مفید کردی؟\nعدد بفرست مثلا: 6 یا 4.5 (بین 0 تا 16)");
                break;

            case 'waiting_work':
                if (!is_numeric($input) || (float)$input < 0 || (float)$input > 16) {
                    $this->api->sendMessage($chatId, "عدد بین 0 تا 16 بفرست. مثلا: 6");
                    return;
                }
                $data['work_hours'] = round((float)$input, 1);
                $this->setState($chatId, $platform, 'waiting_gym', $data);
                $dw = $this->dayWord($data);
                $this->api->sendMessageWithKeyboard($chatId, "۴/۱۰ — {$dw} باشگاه رفتی؟", [['بله', 'خیر']]);
                break;

            case 'waiting_gym':
                $val = $this->parseYesNo($input);
                if ($val === null) {
                    $this->api->sendMessageWithKeyboard($chatId, "لطفا بله یا خیر بفرست:", [['بله', 'خیر']]);
                    return;
                }
                $data['gym'] = $val;
                $this->setState($chatId, $platform, 'waiting_gaming', $data);
                $dw = $this->dayWord($data);
                $this->api->sendMessage($chatId, "۵/۱۰ — {$dw} چند دقیقه گیم زدی؟\nعدد بفرست مثلا: 45 یا 0");
                break;

            case 'waiting_gaming':
                if (!is_numeric($input) || (int)$input < 0 || (int)$input > 1440) {
                    $this->api->sendMessage($chatId, "تعداد دقیقه را بفرست (0 تا 1440). مثلا: 30");
                    return;
                }
                $data['gaming_minutes'] = (int)$input;
                $this->setState($chatId, $platform, 'waiting_social', $data);
                $dw = $this->dayWord($data);
                $this->api->sendMessageWithKeyboard($chatId, "۶/۱۰ — {$dw} تعامل اجتماعی داشتی؟", [['بله', 'خیر']]);
                break;

            case 'waiting_social':
                $val = $this->parseYesNo($input);
                if ($val === null) {
                    $this->api->sendMessageWithKeyboard($chatId, "لطفا بله یا خیر بفرست:", [['بله', 'خیر']]);
                    return;
                }
                $data['social'] = $val;
                $this->setState($chatId, $platform, 'waiting_mood', $data);
                $dw = $this->dayWord($data);
                $this->api->sendMessage($chatId, "۷/۱۰ — حالت {$dw} از ۱۰ چند بود؟\nعدد 1 تا 10 بفرست:");
                break;

            case 'waiting_mood':
                if (!ctype_digit($input) || (int)$input < 1 || (int)$input > 10) {
                    $this->api->sendMessage($chatId, "عدد 1 تا 10 بفرست:");
                    return;
                }
                $data['mood'] = (int)$input;
                $this->setState($chatId, $platform, 'waiting_trigger', $data);
                $dw = $this->dayWord($data);
                $this->api->sendMessage($chatId, "۸/۱۰ — مهم‌ترین چیزی که {$dw} حالت را تغییر داد چی بود؟\nیک جمله بنویس. اگر چیزی نبود /skip بفرست.");
                break;

            case 'waiting_trigger':
                $data['emotional_trigger'] = mb_substr($input, 0, 500);
                $this->askPositiveTrigger($chatId, $platform, $data);
                break;

            case 'waiting_positive_trigger':
                $data['positive_trigger'] = mb_substr($input, 0, 500);
                $this->setState($chatId, $platform, 'waiting_positive_intensity', $data);
                $dw = $this->dayWord($data);
                $this->api->sendMessage($chatId, "۱۰/۱۰ — این اتفاق خوب ({$dw}) چقدر حالت را بالا برد؟\nعدد 1 تا 10 بفرست (مثلا: 9 یا 9/10). اگر نمی‌دونی /skip بفرست.");
                break;

            case 'waiting_positive_intensity':
                $intensity = $this->parseIntensity($input);
                if ($intensity === null) {
                    $this->api->sendMessage($chatId, "عدد 1 تا 10 بفرست (مثلا: 8 یا 8/10).\nاگر نمی‌خوای ثبت کنی /skip بفرست:");
                    return;
                }
                $data['positive_intensity'] = $intensity;
                $this->proceedToRoutinesOrSave($chatId, $platform, $data);
                break;

            case 'waiting_coaching_json':
                $this->handleCoachingJsonInput($chatId, $platform, $input, $state);
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
                $data['routine_ends_on'] = $endsOn;
                $this->setState($chatId, $platform, 'waiting_routine_ask_remind', $data);
                $this->api->sendMessageWithKeyboard($chatId,
                    "⏰ یادآوری ساعتی می‌خوای؟\nمثلا هر روز ساعت ۱۳:۳۰ بهت یادآوری کنم «{$data['routine_title']}» رو انجام بدی (به وقت تهران).",
                    [['بله', 'خیر']]);
                break;

            case 'waiting_routine_ask_remind':
                $val = $this->parseYesNo($input);
                if ($val === null) {
                    $this->api->sendMessageWithKeyboard($chatId, "لطفا بله یا خیر بفرست:", [['بله', 'خیر']]);
                    return;
                }
                if (!$val) {
                    $this->finishRoutineWizard($chatId, $platform, $data, null);
                    break;
                }
                $this->setState($chatId, $platform, 'waiting_routine_remind_at', $data);
                $this->api->sendMessage($chatId, "⏰ ساعت یادآوری روزانه رو بگو (به وقت تهران):\nمثلا: 13:30 یا 1:30 ظهر یا 9 صبح\n(برای لغو /cancel)");
                break;

            case 'waiting_routine_remind_at':
                $parsed = $this->parseFlexibleTime($input);
                if ($parsed === null) {
                    $this->api->sendMessage($chatId, "ساعت رو درست متوجه نشدم 😅\nمثلا بفرست: 13:30 یا 1:30 ظهر یا 9 صبح\nدوباره بفرست:");
                    return;
                }
                $data['routine_remind_at'] = $parsed;
                $this->setState($chatId, $platform, 'waiting_routine_silent', $data);
                $this->api->sendMessageWithKeyboard($chatId,
                    "🔔 یادآوری ساعت {$parsed} سایلنت باشه (بدون صدا) یا با صدا بیاد؟",
                    [['🔕 سایلنت', '🔔 با صدا']]);
                break;

            case 'waiting_routine_silent':
                $lower = mb_strtolower(trim($input));
                if (mb_strpos($lower, 'سایلنت') !== false || mb_strpos($lower, 'silent') !== false) {
                    $silent = true;
                } elseif (mb_strpos($lower, 'صدا') !== false || mb_strpos($lower, 'sound') !== false) {
                    $silent = false;
                } else {
                    $this->api->sendMessageWithKeyboard($chatId, "لطفا یکی رو انتخاب کن:", [['🔕 سایلنت', '🔔 با صدا']]);
                    return;
                }
                $this->finishRoutineWizard($chatId, $platform, $data, $data['routine_remind_at'] ?? null, $silent);
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

            case 'waiting_note_title':
                $title = trim($input);
                if ($title === '') {
                    $this->api->sendMessage($chatId, "اسم نکته خالیه 😅\nمثلا بنویس: ایده کتاب\n(برای لغو /cancel)");
                    return;
                }
                if (mb_strlen($title) > 100) {
                    $this->api->sendMessage($chatId, "اسمت " . mb_strlen($title) . " حرفه، حداکثر ۱۰۰ حرفه.\nکوتاه‌تر بفرست:");
                    return;
                }
                $data['note_title'] = $title;
                $this->setState($chatId, $platform, 'waiting_note_body', $data);
                $this->api->sendMessage($chatId, "حالا متن نکته «{$title}» رو بفرست:\n(حداکثر ۱۵۰۰ کاراکتر — برای لغو /cancel)");
                break;

            case 'waiting_note_body':
                $body = trim($input);
                if ($body === '') {
                    $this->api->sendMessage($chatId, "متن خالیه 😅\nمتن نکته رو بفرست (حداکثر ۱۵۰۰ کاراکتر):");
                    return;
                }
                $len = mb_strlen($body);
                if ($len > 1500) {
                    $over = $len - 1500;
                    $this->api->sendMessage($chatId, "متنت {$len} کاراکتره، حداکثر ۱۵۰۰ مجازه.\n{$over} حرف کم کن و دوباره بفرست:");
                    return;
                }
                $title = $data['note_title'] ?? 'بدون اسم';
                $note = Note::create([
                    'chat_id' => $chatId,
                    'platform' => $platform,
                    'title' => mb_substr($title, 0, 100),
                    'body' => $body,
                ]);
                $this->clearState($chatId, $platform);
                $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($note->created_at);
                $this->api->sendMessageWithInlineKeyboard($chatId,
                    "✅ نکته «{$note->title}» ذخیره شد!\n📅 {$shamsi}\n📝 {$len}/۱۵۰۰ حرف",
                    [
                        [['text' => '👁 دیدن نکته', 'callback_data' => "note:view:{$note->id}"]],
                        [['text' => '📋 لیست نکته‌ها', 'callback_data' => 'note:list'], ['text' => '➕ نکته جدید', 'callback_data' => 'note:new']],
                        [['text' => '🏠 منوی اصلی', 'callback_data' => 'note:menu']],
                    ]);
                break;

            case 'waiting_note_edit_title':
                $newTitle = trim($input);
                if ($newTitle === '') {
                    $this->api->sendMessage($chatId, "اسم خالیه 😅\nاسم جدید رو بفرست (حداکثر ۱۰۰ حرف):");
                    return;
                }
                if (mb_strlen($newTitle) > 100) {
                    $this->api->sendMessage($chatId, "اسمت " . mb_strlen($newTitle) . " حرفه، حداکثر ۱۰۰ حرفه.\nکوتاه‌تر بفرست:");
                    return;
                }
                $note = Note::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) ($data['note_id'] ?? 0))->first();
                if (!$note) {
                    $this->clearState($chatId, $platform);
                    $this->api->sendMessage($chatId, "نکته پیدا نشد (شاید حذف شده).");
                    $this->showNotesMenu($chatId, $platform, 0);
                    return;
                }
                $note->update(['title' => mb_substr($newTitle, 0, 100)]);
                $this->clearState($chatId, $platform);
                $this->api->sendMessage($chatId, "✅ اسم نکته به «{$note->title}» تغییر کرد.");
                $this->showNoteDetail($chatId, $platform, (string) $note->id);
                break;

            case 'waiting_note_edit_body':
                $newBody = trim($input);
                if ($newBody === '') {
                    $this->api->sendMessage($chatId, "متن خالیه 😅\nمتن جدید رو بفرست (حداکثر ۱۵۰۰ کاراکتر):");
                    return;
                }
                $len = mb_strlen($newBody);
                if ($len > 1500) {
                    $over = $len - 1500;
                    $this->api->sendMessage($chatId, "متنت {$len} کاراکتره، حداکثر ۱۵۰۰ مجازه.\n{$over} حرف کم کن و دوباره بفرست:");
                    return;
                }
                $note = Note::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) ($data['note_id'] ?? 0))->first();
                if (!$note) {
                    $this->clearState($chatId, $platform);
                    $this->api->sendMessage($chatId, "نکته پیدا نشد (شاید حذف شده).");
                    $this->showNotesMenu($chatId, $platform, 0);
                    return;
                }
                $note->update(['body' => $newBody]);
                $this->clearState($chatId, $platform);
                $this->api->sendMessage($chatId, "✅ متن نکته «{$note->title}» ویرایش شد ({$len}/۱۵۰۰ حرف).");
                $this->showNoteDetail($chatId, $platform, (string) $note->id);
                break;

            case 'waiting_freenote_body':
                $body = trim($input);
                if ($body === '') {
                    $this->api->sendMessage($chatId, "متن خالیه 😅\nتوضیحت رو بفرست (بدون تایتل، حداکثر ۲۰۰۰ کاراکتر):");
                    return;
                }
                $len = mb_strlen($body);
                if ($len > 2000) {
                    $over = $len - 2000;
                    $this->api->sendMessage($chatId, "متنت {$len} کاراکتره، حداکثر ۲۰۰۰ مجازه.\n{$over} حرف کم کن و دوباره بفرست:");
                    return;
                }
                $free = FreeNote::create([
                    'chat_id' => $chatId,
                    'platform' => $platform,
                    'body' => $body,
                ]);
                $this->clearState($chatId, $platform);
                $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($free->created_at);
                $this->api->sendMessageWithInlineKeyboard($chatId,
                    "✅ توضیح آزاد ذخیره شد!\n📅 {$shamsi}\n📝 {$len}/۲۰۰۰ حرف",
                    [
                        [['text' => '👁 دیدن', 'callback_data' => "free:view:{$free->id}"]],
                        [['text' => '📋 لیست توضیحات', 'callback_data' => 'free:list'], ['text' => '➕ توضیح جدید', 'callback_data' => 'free:new']],
                        [['text' => '🏠 منوی اصلی', 'callback_data' => 'free:menu']],
                    ]);
                break;

            case 'waiting_freenote_edit':
                $newBody = trim($input);
                if ($newBody === '') {
                    $this->api->sendMessage($chatId, "متن خالیه 😅\nمتن جدید رو بفرست (حداکثر ۲۰۰۰ کاراکتر):");
                    return;
                }
                $len = mb_strlen($newBody);
                if ($len > 2000) {
                    $over = $len - 2000;
                    $this->api->sendMessage($chatId, "متنت {$len} کاراکتره، حداکثر ۲۰۰۰ مجازه.\n{$over} حرف کم کن و دوباره بفرست:");
                    return;
                }
                $free = FreeNote::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) ($data['free_id'] ?? 0))->first();
                if (!$free) {
                    $this->clearState($chatId, $platform);
                    $this->api->sendMessage($chatId, "توضیح پیدا نشد (شاید حذف شده).");
                    $this->showFreeNotesMenu($chatId, $platform, 0);
                    return;
                }
                $free->update(['body' => $newBody]);
                $this->clearState($chatId, $platform);
                $this->api->sendMessage($chatId, "✅ توضیح آزاد ویرایش شد ({$len}/۲۰۰۰ حرف).");
                $this->showFreeNoteDetail($chatId, $platform, (string) $free->id);
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

        $this->api->sendMessageWithKeyboard($chatId, $this->formatEntry($entry, "✅ ثبت شد!"), $this->mainMenuKeyboard($chatId, $platform));
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
            'positive_trigger' => $data['positive_trigger'] ?? null,
            'positive_intensity' => $data['positive_intensity'] ?? null,
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
        if ($e->positive_trigger) {
            $intensityTxt = $e->positive_intensity !== null ? " (اثر {$e->positive_intensity}/10)" : "";
            $positive = "\n🌟 عامل مثبت: {$e->positive_trigger}{$intensityTxt}";
        } else {
            $positive = "\n🌟 عامل مثبت: —";
        }
        $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($e->entry_date);
        $text = "{$title} ({$e->entry_date->format('Y-m-d')} — {$shamsi})\n"
            . "😴 خواب: {$e->sleep_time} → {$e->wake_time}\n"
            . "💼 کار مفید: {$e->work_hours} ساعت\n"
            . "🏋️ باشگاه: {$gym}\n"
            . "🎮 گیم: {$e->gaming_minutes} دقیقه\n"
            . "👥 اجتماعی: {$social}\n"
            . "😊 حال: {$e->mood}/10"
            . $trigger
            . $positive;

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
                $remind = $r->remind_at
                    ? ' ⏰ ' . mb_substr((string) $r->remind_at, 0, 5) . ($r->silent_remind ? ' 🔕' : '')
                    : '';
                $lines[] = "• [{$r->id}] {$r->title} — {$s} تا {$e}{$remind}";
            }
        }
        if (!$past->isEmpty()) {
            $lines[] = "\nتمام‌شده/متوقف:";
            foreach ($past as $r) {
                $tag = $r->is_active ? 'تمام‌شده' : 'متوقف';
                $lines[] = "• [{$r->id}] {$r->title} ({$tag})";
            }
        }
        $lines[] = "\nبرای ساخت روتین جدید /routine_new را بزن.";
        $lines[] = "توقف: /routine_stop ID";

        $this->api->sendMessageWithKeyboard($chatId, implode("\n", $lines), $this->mainMenuKeyboard($chatId, $platform));
    }

    // ── Notes (نکته‌ها: یادداشت با اسم + متن تا ۱۵۰۰ حرف + تاریخ شمسی) ──

    private const NOTE_PAGE_SIZE = 5;

    private function startRoutineWizard(string $chatId, string $platform): void
    {
        $this->setState($chatId, $platform, 'waiting_routine_title', []);
        $this->api->sendMessage($chatId, "➕ روتین جدید!\n\nاسم روتین چیه؟\nمثلا: روتین پوستی\n(برای لغو /cancel)");
    }

    private function startNoteWizard(string $chatId, string $platform): void
    {
        $this->setState($chatId, $platform, 'waiting_note_title', []);
        $this->api->sendMessage($chatId, "📝 نکته جدید!\n\nاسم نکته چیه؟\nمثلا: ایده کتاب\n(حداکثر ۱۰۰ حرف — برای لغو /cancel)");
    }

    private function handleNoteCallback(string $chatId, string $platform, string $data): void
    {
        if ($data === 'note:new') {
            $this->clearState($chatId, $platform);
            $this->startNoteWizard($chatId, $platform);
            return;
        }
        if ($data === 'note:list') {
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        if ($data === 'note:menu') {
            $this->clearState($chatId, $platform);
            $this->sendMainMenu($chatId, $platform);
            return;
        }
        if (str_starts_with($data, 'note:page:')) {
            $page = (int) substr($data, 10);
            $this->showNotesMenu($chatId, $platform, max(0, $page));
            return;
        }
        if (str_starts_with($data, 'note:view:')) {
            $this->showNoteDetail($chatId, $platform, substr($data, 10));
            return;
        }
        if (str_starts_with($data, 'note:delconf:')) {
            $this->deleteNote($chatId, $platform, substr($data, 13));
            return;
        }
        if (str_starts_with($data, 'note:del:')) {
            $this->askDeleteNote($chatId, $platform, substr($data, 9));
            return;
        }
        if (str_starts_with($data, 'note:editt:')) {
            $this->startNoteEditTitle($chatId, $platform, substr($data, 11));
            return;
        }
        if (str_starts_with($data, 'note:editb:')) {
            $this->startNoteEditBody($chatId, $platform, substr($data, 11));
            return;
        }
    }

    /** لیست نکته‌های قبلی + دکمه ساخت جدید — با صفحه‌بندی قبل/بعد. */
    private function showNotesMenu(string $chatId, string $platform, int $page = 0): void
    {
        $this->clearState($chatId, $platform);

        $total = Note::where('chat_id', $chatId)->where('platform', $platform)->count();

        if ($total === 0) {
            $this->api->sendMessageWithInlineKeyboard($chatId,
                "📌 نکته‌ها\n\nهنوز نکته‌ای نداری.\nاسم + متن (تا ۱۵۰۰ حرف) بفرست تا ذخیره کنم.",
                [
                    [['text' => '➕ ساخت نکته جدید', 'callback_data' => 'note:new']],
                    [['text' => '🏠 منوی اصلی', 'callback_data' => 'note:menu']],
                ]);
            return;
        }

        $perPage = self::NOTE_PAGE_SIZE;
        $totalPages = (int) ceil($total / $perPage);
        $page = max(0, min($page, $totalPages - 1));

        $notes = Note::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->orderByDesc('id')
            ->skip($page * $perPage)
            ->take($perPage)
            ->get();

        $lines = ["📌 نکته‌ها ({$total} مورد) — صفحه " . ($page + 1) . " از {$totalPages}:\n"];
        $keyboard = [];
        foreach ($notes as $n) {
            $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($n->created_at);
            $len = mb_strlen((string) $n->body);
            $preview = mb_substr((string) $n->body, 0, 40);
            if (mb_strlen((string) $n->body) > 40) $preview .= '…';
            $lines[] = "• «{$n->title}» — {$shamsi} ({$len} حرف)\n  {$preview}";
            $btnTitle = mb_substr($n->title, 0, 20);
            $keyboard[] = [['text' => "📌 {$btnTitle}", 'callback_data' => "note:view:{$n->id}"]];
        }

        $lines[] = "\nبرای دیدن متن کامل روی هر نکته بزن.";

        // ساخت + صفحه‌بندی
        $keyboard[] = [['text' => '➕ ساخت نکته جدید', 'callback_data' => 'note:new']];
        if ($totalPages > 1) {
            $nav = [];
            if ($page > 0) $nav[] = ['text' => '◀ قبلی', 'callback_data' => 'note:page:' . ($page - 1)];
            if ($page < $totalPages - 1) $nav[] = ['text' => 'بعدی ▶', 'callback_data' => 'note:page:' . ($page + 1)];
            if (!empty($nav)) $keyboard[] = $nav;
        }
        $keyboard[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'note:menu']];

        $this->api->sendMessageWithInlineKeyboard($chatId, implode("\n", $lines), $keyboard);
    }

    /** نمایش یک نکته با اسم + متن کامل + تاریخ شمسی ایجاد. */
    private function showNoteDetail(string $chatId, string $platform, string $id): void
    {
        if (!ctype_digit($id)) {
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $note = Note::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->api->sendMessage($chatId, "نکته‌ای با این مشخصات پیدا نکردم.");
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($note->created_at);
        $len = mb_strlen((string) $note->body);
        $text = "📌 «{$note->title}»\n📅 ایجاد: {$shamsi}\n📝 {$len}/۱۵۰۰ حرف\n\n{$note->body}";

        // تلگرام سقف ~۴۰۹۶ کاراکتر دارد — متن ما حداکثر ~۱۶۰۰ است، مشکلی نیست
        $this->api->sendMessageWithInlineKeyboard($chatId, $text, [
            [['text' => '✏️ ویرایش اسم', 'callback_data' => "note:editt:{$note->id}"], ['text' => '✏️ ویرایش متن', 'callback_data' => "note:editb:{$note->id}"]],
            [['text' => '🗑 حذف', 'callback_data' => "note:del:{$note->id}"], ['text' => '📋 لیست نکته‌ها', 'callback_data' => 'note:list']],
            [['text' => '➕ نکته جدید', 'callback_data' => 'note:new'], ['text' => '🏠 منوی اصلی', 'callback_data' => 'note:menu']],
        ]);
    }

    private function askDeleteNote(string $chatId, string $platform, string $id): void
    {
        $note = Note::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $this->api->sendMessageWithInlineKeyboard($chatId,
            "🗑 «{$note->title}» حذف بشه؟",
            [
                [['text' => '✅ بله، حذف کن', 'callback_data' => "note:delconf:{$note->id}"], ['text' => '↩ انصراف', 'callback_data' => "note:view:{$note->id}"]],
            ]);
    }

    private function deleteNote(string $chatId, string $platform, string $id): void
    {
        $note = Note::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $title = $note->title;
        $note->delete();
        $this->api->sendMessage($chatId, "🗑 نکته «{$title}» حذف شد.");
        $this->showNotesMenu($chatId, $platform, 0);
    }

    /** شروع ویرایش اسم نکته — state جداگانه تا /cancel هم کار کند. */
    private function startNoteEditTitle(string $chatId, string $platform, string $id): void
    {
        if (!ctype_digit($id)) {
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $note = Note::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->api->sendMessage($chatId, "نکته‌ای با این مشخصات پیدا نکردم.");
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $this->setState($chatId, $platform, 'waiting_note_edit_title', ['note_id' => $note->id]);
        $this->api->sendMessage($chatId, "✏️ اسم فعلی: «{$note->title}»\nاسم جدید رو بفرست:\n(حداکثر ۱۰۰ حرف — برای لغو /cancel)");
    }

    /** شروع ویرایش متن نکته — سقف ۱۵۰۰ کاراکتر مثل ساخت. */
    private function startNoteEditBody(string $chatId, string $platform, string $id): void
    {
        if (!ctype_digit($id)) {
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $note = Note::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->api->sendMessage($chatId, "نکته‌ای با این مشخصات پیدا نکردم.");
            $this->showNotesMenu($chatId, $platform, 0);
            return;
        }
        $len = mb_strlen((string) $note->body);
        $this->setState($chatId, $platform, 'waiting_note_edit_body', ['note_id' => $note->id]);
        $this->api->sendMessage($chatId, "✏️ متن فعلی «{$note->title}» ({$len}/۱۵۰۰ حرف):\n\n{$note->body}\n\n───\nمتن جدید رو بفرست:\n(حداکثر ۱۵۰۰ کاراکتر — برای لغو /cancel)");
    }

    // ── Free notes (✍️ توضیحات آزاد: بدون تایتل، با تاریخ شمسی در خروجی JSON) ──

    private const FREE_PAGE_SIZE = 5;
    private const FREE_MAX_LEN = 2000;

    private function startFreeNoteWizard(string $chatId, string $platform): void
    {
        $this->setState($chatId, $platform, 'waiting_freenote_body', []);
        $this->api->sendMessage($chatId, "✍️ توضیح آزاد جدید!\n\nبدون هیچ تایتل یا عنوانی — فقط متنت رو بفرست:\n(حداکثر ۲۰۰۰ حرف — تاریخ شمسی امروز بهش می‌خوره و توی خروجی JSON با همون بازه میاد)\n(برای لغو /cancel)");
    }

    private function handleFreeNoteCallback(string $chatId, string $platform, string $data): void
    {
        if ($data === 'free:new') {
            $this->clearState($chatId, $platform);
            $this->startFreeNoteWizard($chatId, $platform);
            return;
        }
        if ($data === 'free:list') {
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }
        if ($data === 'free:menu') {
            $this->clearState($chatId, $platform);
            $this->sendMainMenu($chatId, $platform);
            return;
        }
        if (str_starts_with($data, 'free:page:')) {
            $page = (int) substr($data, 10);
            $this->showFreeNotesMenu($chatId, $platform, max(0, $page));
            return;
        }
        if (str_starts_with($data, 'free:view:')) {
            $this->showFreeNoteDetail($chatId, $platform, substr($data, 10));
            return;
        }
        if (str_starts_with($data, 'free:delconf:')) {
            $this->deleteFreeNote($chatId, $platform, substr($data, 13));
            return;
        }
        if (str_starts_with($data, 'free:del:')) {
            $this->askDeleteFreeNote($chatId, $platform, substr($data, 9));
            return;
        }
        if (str_starts_with($data, 'free:edit:')) {
            $this->startFreeNoteEdit($chatId, $platform, substr($data, 10));
            return;
        }
    }

    // ── Coaching profile (پرونده واحد کوچینگ — دریافت + آپدیت) ──

    private const COACHING_MAX_CHARS = 120000;

    /**
     * متن نمایشی پرونده: خود رشته‌ی ذخیره‌شده (تا `{}` خالی به `[]` تبدیل نشود).
     * اگر رشته خراب بود، از روی آرایه دوباره ساخته می‌شود.
     */
    private function coachingDisplayJson(CoachingProfile $profile): string
    {
        $raw = trim((string) $profile->profile_json);
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) return $raw;
            } catch (\Throwable $e) {
                // fall through to rebuild
            }
        }
        return (string) json_encode($profile->toProfileArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * دکمه‌ی «📥 دریافت پرونده کوچینگ»: متن فعلی پرونده را می‌دهد (بدون شروع فلو آپدیت).
     */
    private function showCoachingFile(string $chatId, string $platform): void
    {
        $profile = CoachingProfile::getOrCreate($chatId, $platform);
        $profileArray = $profile->toProfileArray();
        $profileJson = $this->coachingDisplayJson($profile);

        $lastSummary = '';
        try {
            $last = $profileArray['last_update'] ?? null;
            if (is_array($last) && !empty($last['date'])) {
                $lastSummary = "آخرین آپدیت: {$last['date']}"
                    . (!empty($last['summary']) ? " — {$last['summary']}" : '');
            }
        } catch (\Throwable $e) {
            $lastSummary = '';
        }

        $intro = "📥 پرونده فعلی کوچینگ"
            . ($lastSummary !== '' ? "\n{$lastSummary}" : '')
            . "\n\nمتن کامل JSON:";
        $this->api->sendMessage($chatId, $intro);
        $this->sendLongMessage($chatId, (string) $profileJson);
        $this->api->sendMessageWithKeyboard($chatId,
            "برای به‌روزرسانی، «🔄 آپدیت پرونده کوچینگ» را بزن.",
            $this->mainMenuKeyboard($chatId, $platform));
    }

    /**
     * دکمه‌ی «🔄 آپدیت پرونده کوچینگ»: پرامت آماده را با پرونده فعلی + اطلاعات جدید می‌دهد
     * و منتظر JSON جدید از ChatGPT می‌ماند (state = waiting_coaching_json).
     */
    private function showCoachingUpdate(string $chatId, string $platform): void
    {
        $profile = CoachingProfile::getOrCreate($chatId, $platform);
        $profileArray = $profile->toProfileArray();
        $profileJson = $this->coachingDisplayJson($profile);

        $lastSummary = '';
        try {
            $last = $profileArray['last_update'] ?? null;
            if (is_array($last) && !empty($last['date'])) {
                $lastSummary = "آخرین آپدیت: {$last['date']}"
                    . (!empty($last['summary']) ? " — {$last['summary']}" : '');
            }
        } catch (\Throwable $e) {
            $lastSummary = '';
        }

        $newInfoJson = $this->buildCoachingNewInformation($chatId, $platform, $profileArray);

        $prompt = "این پرونده فعلی کوچینگ من است:\n\n"
            . $profileJson
            . "\n\nاطلاعات و گزارش‌های جدید من نیز در ادامه آمده‌اند:\n\n"
            . $newInfoJson
            . "\n\nبر اساس تمام اطلاعات قبلی و جدید، یک نسخه جدید و کامل از «پرونده کوچینگ» من تولید کن.\n\n"
            . "قوانین:\n\n"
            . "1. اطلاعات قبلی که هنوز معتبر هستند حفظ شوند.\n"
            . "2. اطلاعات جدید یا تغییرکرده به‌روزرسانی شوند.\n"
            . "3. فقط اطلاعات مهم و مؤثر در کوچینگ وارد پرونده شوند.\n"
            . "4. اطلاعات روزمره و بی‌اهمیت وارد پرونده اصلی نشوند.\n"
            . "5. اگر اطلاعات جدیدی درباره یک موضوع وجود ندارد، اطلاعات قبلی آن موضوع حفظ شود.\n"
            . "6. هیچ اطلاعاتی را حدس نزن.\n"
            . "7. واقعیت، الگوی مشاهده‌شده و فرضیه را با هم قاطی نکن.\n"
            . "8. اهداف و برنامه‌های قدیمی که دیگر فعال نیستند حذف نشوند؛ در صورت نیاز وضعیت آنها را تغییر بده.\n"
            . "9. جزئیات کامل گزارش‌های روزانه و CBT را داخل پرونده کپی نکن؛ فقط نتیجه و الگوهای مهم آنها را نگه دار.\n"
            . "10. پرونده باید خلاصه، خوانا و مناسب استفاده در گفتگوهای آینده باشد.\n"
            . "11. ساختار JSON همان ساختار پرونده فعلی باقی بماند.\n"
            . "12. تاریخ و خلاصه تغییرات آخرین به‌روزرسانی را نیز اصلاح کن.\n\n"
            . "فقط JSON نهایی را بده.\n"
            . "هیچ توضیح، مقدمه، markdown یا متن دیگری خارج از JSON ننویس.";

        $intro = "🔄 آپدیت پرونده کوچینگ\n\n"
            . "روش کار (۳ قدم):\n"
            . "۱️⃣ پرامتی که الان می‌فرستم را کپی کن و در ChatGPT بفرست.\n"
            . "۲️⃣ فقط JSON ای که ChatGPT داد را همین‌جا برایم بفرست (اگر چند پیام شد، پشت سر هم بفرست).\n"
            . "۳️⃣ من آن را به‌عنوان نسخه جدید پرونده ذخیره می‌کنم.\n"
            . ($lastSummary !== '' ? "\n{$lastSummary}\n" : "\n")
            . "\nپرامت آماده (کپی کن):\n(برای لغو /cancel)";

        $this->setState($chatId, $platform, 'waiting_coaching_json', []);
        $this->api->sendMessage($chatId, $intro);
        $this->sendLongMessage($chatId, $prompt);
        $this->api->sendMessage($chatId, "👆 پرامت بالا را در ChatGPT بفرست، بعد فقط JSON نهایی را همین‌جا بفرست تا ذخیره کنم.\n(اگر پاسخت چند پیام شد، همه را پشت سر هم بفرست — هر قسمت را که بگیرم خبرت می‌کنم. برای شروع دوباره /cancel)");
    }

    /**
     * اطلاعات جدید برای پرامت: گزارش‌های روزانه + CBT + نکته‌ها + توضیحات آزاد
     * از آخرین آپدیت به بعد (اگر تاریخی نبود: ۱۴ روز اخیر). فشرده و محدود تا پرامت قابل‌کپی بماند.
     */
    private function buildCoachingNewInformation(string $chatId, string $platform, array $profileArray): string
    {
        $since = null;
        try {
            $last = $profileArray['last_update'] ?? null;
            if (is_array($last) && !empty($last['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $last['date'])) {
                $since = (string) $last['date'];
            }
        } catch (\Throwable $e) {
            $since = null;
        }
        if ($since === null) {
            $since = Carbon::today()->subDays(14)->toDateString();
        }

        $info = ['since' => $since];

        // گزارش‌های روزانه (حداکثر ۱۴ مورد آخر، فشرده)
        try {
            $entries = DailyEntry::where('chat_id', $chatId)
                ->where('platform', $platform)
                ->whereDate('entry_date', '>=', $since)
                ->orderBy('entry_date', 'desc')
                ->take(14)
                ->get()
                ->reverse()
                ->values();
            $info['daily_reports'] = $entries->map(function (DailyEntry $e) {
                $row = [
                    'date' => Carbon::parse($e->entry_date)->toDateString(),
                    'mood' => $e->mood !== null ? (int) $e->mood : null,
                ];
                if ($e->sleep_time) $row['sleep'] = $e->sleep_time;
                if ($e->wake_time) $row['wake'] = $e->wake_time;
                if ($e->work_hours !== null) $row['work_hours'] = (float) $e->work_hours;
                if ($e->gym !== null) $row['gym'] = (bool) $e->gym;
                if ($e->gaming_minutes !== null) $row['gaming_minutes'] = (int) $e->gaming_minutes;
                if ($e->social !== null) $row['social'] = (bool) $e->social;
                if ($e->emotional_trigger) $row['trigger'] = mb_substr((string) $e->emotional_trigger, 0, 200);
                if ($e->positive_trigger) {
                    $row['positive'] = mb_substr((string) $e->positive_trigger, 0, 200);
                    if ($e->positive_intensity !== null) $row['positive_intensity'] = (int) $e->positive_intensity;
                }
                return $row;
            })->toArray();
        } catch (\Throwable $e) {
            $info['daily_reports'] = [];
        }

        // رکوردهای CBT جدید (خلاصه، حداکثر ۱۰ مورد)
        try {
            $cbtRows = \App\Models\CbtRecord::where('chat_id', $chatId)
                ->where('platform', $platform)
                ->orderByDesc('id')
                ->take(10)
                ->get();
            $info['cbt_recent'] = $cbtRows->map(function ($r) {
                return [
                    'date' => $r->date_shamsi ?? null,
                    'event' => isset($r->event) ? mb_substr((string) $r->event, 0, 200) : null,
                    'thought' => isset($r->thought) ? mb_substr((string) $r->thought, 0, 200) : null,
                    'feeling' => $r->feeling ?? null,
                    'score_feeling' => $r->score_feeling ?? null,
                    'score_feeling_after' => $r->score_feeling_after ?? null,
                ];
            })->toArray();
        } catch (\Throwable $e) {
            $info['cbt_recent'] = [];
        }

        // نکته‌ها و توضیحات آزاد جدید (خلاصه، حداکثر ۱۰ مورد از هر کدام)
        try {
            $info['notes_recent'] = Note::where('chat_id', $chatId)
                ->where('platform', $platform)
                ->orderByDesc('id')
                ->take(10)
                ->get()
                ->map(fn (Note $n) => [
                    'title' => $n->title,
                    'body' => mb_substr((string) $n->body, 0, 200),
                ])->toArray();
        } catch (\Throwable $e) {
            $info['notes_recent'] = [];
        }
        try {
            $info['free_notes_recent'] = FreeNote::where('chat_id', $chatId)
                ->where('platform', $platform)
                ->orderByDesc('id')
                ->take(10)
                ->get()
                ->map(fn (FreeNote $n) => mb_substr((string) $n->body, 0, 200))->toArray();
        } catch (\Throwable $e) {
            $info['free_notes_recent'] = [];
        }

        $json = json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return $json === false ? '{}' : (string) $json;
    }

    /**
     * دریافت JSON جدید از کاربر و ذخیره به‌عنوان نسخه جدید پرونده.
     *
     * خروجی ChatGPT ممکن است در چند پیام تلگرام تکه‌تکه برسد؛ پس هر پیام را به
     * بافر state اضافه می‌کنیم و بعد از هر پیام، ترکیب کامل را امتحان می‌کنیم:
     * کامل و سالم بود → ذخیره؛ ناقص بود → رسید را اعلام کن و منتظر ادامه بمان؛
     * کامل ولی خراب بود → خطا بده و بافر را خالی کن تا پیام بعدی از نو شروع شود.
     */
    private function handleCoachingJsonInput(string $chatId, string $platform, string $input, BotState $state): void
    {
        $data = $state->data ?? [];
        $parts = (isset($data['coaching_parts']) && is_array($data['coaching_parts']))
            ? array_values($data['coaching_parts'])
            : [];

        $candidate = $this->stripCoachingFences(trim($input));

        if ($candidate === '') {
            $this->api->sendMessage($chatId, "چیزی دریافت نکردم 😅\nفقط JSON نهایی ChatGPT را بفرست (اگر چند پیام شد، پشت سر هم بفرست).\n(برای لغو /cancel)");
            return;
        }

        // ۱) شاید همین یک پیام کامل است (حالت عادی یا ارسال مجدد کل متن)
        $decoded = $this->tryDecodeJson($candidate);
        if (is_array($decoded) && $this->coachingShapeHits($decoded) >= 2) {
            $this->saveCoachingProfile($chatId, $platform, $decoded);
            return;
        }

        // ۲) به بافر بچسبان و ترکیب را امتحان کن (اتصال دقیق، بدون جداکننده:
        // برش ممکن است وسط یک خط یا وسط یک رشته باشد و newline اضافه خرابش می‌کند)
        $parts[] = $candidate;
        $combined = $this->stripCoachingFences(implode('', $parts));

        if (strlen($combined) > self::COACHING_MAX_CHARS) {
            $this->setState($chatId, $platform, 'waiting_coaching_json', []);
            $this->api->sendMessage($chatId, "متن خیلی بزرگ شد، از اول شروع می‌کنیم ❌\nJSON نهایی را دوباره بفرست (اگر چند پیام است، پشت سر هم).\n(برای لغو /cancel)");
            return;
        }

        $decoded = $this->tryDecodeJson($combined);
        if (is_array($decoded)) {
            if ($this->coachingShapeHits($decoded) >= 2) {
                $this->saveCoachingProfile($chatId, $platform, $decoded);
                return;
            }
            // JSON سالم است ولی شبیه پرونده کوچینگ نیست
            $this->setState($chatId, $platform, 'waiting_coaching_json', []);
            $this->api->sendMessage($chatId, "این JSON شبیه پرونده کوچینگ نیست ❌\nباید همان ساختار پرونده فعلی را داشته باشد (بخش‌هایی مثل goals ،current_state ،strategy و last_update).\nخروجی ChatGPT را کامل کپی کن و بفرست (اگر چند پیام است، همه را پشت سر هم از اول بفرست).\n(برای لغو /cancel)");
            return;
        }

        // ۳) ناقص است یا خراب؟
        if (!$this->coachingBracketsBalanced($combined)) {
            $this->setState($chatId, $platform, 'waiting_coaching_json', ['coaching_parts' => $parts]);
            $n = count($parts);
            $this->api->sendMessageWithInlineKeyboard($chatId,
                "قسمت {$n} رسید ✅\nادامه‌ی JSON را بفرست (تا کامل شود همین‌طور ادامه بده).\n(اگر همین‌ها کامل است، دکمه‌ی زیر را بزن. برای شروع دوباره /cancel)",
                [[['text' => '✅ همین بود، ذخیره کن', 'callback_data' => 'coaching:done']]]);
            return;
        }

        // کامل ولی خراب: خطا بده و بافر را خالی کن تا پیام بعدی تمیز شروع شود
        $this->setState($chatId, $platform, 'waiting_coaching_json', []);
        $this->api->sendMessage($chatId, "این متن JSON معتبر نیست ❌\nلطفا فقط JSON نهایی را کامل بفرست (بدون توضیح اضافه). اگر چند پیام است، همه را پشت سر هم از اول بفرست.\n(برای لغو /cancel)");
    }

    /**
     * دکمه‌ی «✅ همین بود، ذخیره کن»: بافر فعلی را همان‌طور که هست امتحان می‌کند.
     * برخلاف مسیر خودکار، اینجا بافر را دور نمی‌ریزیم تا کاربر بتواند ادامه بدهد یا اصلاح کند.
     */
    private function finishCoachingFromBuffer(string $chatId, string $platform): void
    {
        $state = $this->getState($chatId, $platform);
        if (!$state || $state->state !== 'waiting_coaching_json') {
            $this->api->sendMessage($chatId, "فلو آپدیت فعال نیست.\nبرای شروع «🔄 آپدیت پرونده کوچینگ» را بزن.");
            return;
        }
        $data = $state->data ?? [];
        $parts = (isset($data['coaching_parts']) && is_array($data['coaching_parts']))
            ? array_values($data['coaching_parts'])
            : [];

        if ($parts === []) {
            $this->api->sendMessage($chatId, "هنوز چیزی نفرستادی.\nJSON نهایی ChatGPT را بفرست (اگر چند پیام است، پشت سر هم).\n(برای لغو /cancel)");
            return;
        }

        $combined = $this->stripCoachingFences(implode('', $parts));
        $decoded = $this->tryDecodeJson($combined);
        if (is_array($decoded) && $this->coachingShapeHits($decoded) >= 2) {
            $this->saveCoachingProfile($chatId, $platform, $decoded);
            return;
        }

        if (!$this->coachingBracketsBalanced($combined)) {
            $n = count($parts);
            $this->api->sendMessageWithInlineKeyboard($chatId,
                "هنوز ناقص است ❌ ({$n} قسمت رسیده ولی JSON کامل نشده).\nادامه را بفرست تا کامل شود.\n(برای شروع دوباره /cancel)",
                [[['text' => '✅ همین بود، ذخیره کن', 'callback_data' => 'coaching:done']]]);
            return;
        }

        if (is_array($decoded)) {
            $this->api->sendMessage($chatId, "این JSON شبیه پرونده کوچینگ نیست ❌\nبخش‌هایی مثل goals ،current_state ،strategy و last_update باید داخلش باشد.\nقسمت‌های رسیده نگه داشته شده — ادامه را بفرست یا با /cancel از اول شروع کن.");
            return;
        }

        $this->api->sendMessage($chatId, "این متن JSON معتبر نیست ❌\nقسمت‌های رسیده نگه داشته شده — ادامه یا اصلاح را بفرست، یا با /cancel از اول شروع کن.");
    }

    /**
     * حذف فنس markdown (```json ... ```) — حتی اگر شروع و پایان در پیام‌های جدا باشند.
     */
    private function stripCoachingFences(string $text): string
    {
        $text = trim($text);
        // خط شروع ```json یا ```
        $text = (string) preg_replace('/\A```[a-zA-Z]*\s*/u', '', $text);
        // خط پایانی ```
        $text = (string) preg_replace('/\s*```\s*\z/u', '', $text);
        return trim($text);
    }

    /** decode امن: نامعتبر بود null برمی‌گرداند. */
    private function tryDecodeJson(string $text): mixed
    {
        try {
            return json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** تعداد کلیدهای سطح‌بالای پرونده کوچینگ داخل JSON. */
    private function coachingShapeHits(mixed $decoded): int
    {
        if (!is_array($decoded)) return 0;
        $hits = 0;
        foreach (CoachingProfile::expectedKeys() as $key) {
            if (array_key_exists($key, $decoded)) $hits++;
        }
        return $hits;
    }

    /**
     * آیا آکولادها و براکت‌ها متوازن‌اند؟ (خارج از رشته‌ها، با احترام به escape)
     * نامتوازن = متن به احتمال زیاد ناقص است و ادامه دارد.
     */
    private function coachingBracketsBalanced(string $text): bool
    {
        $depthCurly = 0; $depthSquare = 0;
        $inString = false; $escaped = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inString) {
                if ($escaped) $escaped = false;
                elseif ($ch === '\\') $escaped = true;
                elseif ($ch === '"') $inString = false;
                continue;
            }
            if ($ch === '"') $inString = true;
            elseif ($ch === '{') $depthCurly++;
            elseif ($ch === '}') { $depthCurly--; if ($depthCurly < 0) return false; }
            elseif ($ch === '[') $depthSquare++;
            elseif ($ch === ']') { $depthSquare--; if ($depthSquare < 0) return false; }
        }
        return !$inString && $depthCurly === 0 && $depthSquare === 0;
    }

    /** ذخیره‌ی نسخه جدید پرونده + پیام موفقیت. */
    private function saveCoachingProfile(string $chatId, string $platform, array $decoded): void
    {
        $pretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($pretty === false) {
            $this->api->sendMessage($chatId, "خطا در ذخیره‌سازی ❌\nدوباره تلاش کن. (برای لغو /cancel)");
            return;
        }

        CoachingProfile::updateOrCreate(
            ['chat_id' => $chatId, 'platform' => $platform],
            ['profile_json' => $pretty]
        );
        $this->clearState($chatId, $platform);

        $summary = '';
        try {
            $last = $decoded['last_update'] ?? null;
            if (is_array($last) && !empty($last['summary'])) {
                $summary = "\n📝 خلاصه تغییرات: " . mb_substr((string) $last['summary'], 0, 300);
            } elseif (is_array($last) && !empty($last['date'])) {
                $summary = "\n📅 تاریخ آپدیت: " . (string) $last['date'];
            }
        } catch (\Throwable $e) {
            $summary = '';
        }

        $this->api->sendMessageWithKeyboard($chatId,
            "✅ پرونده کوچینگ به‌روز شد!{$summary}\n\nبا «🔄 آپدیت پرونده کوچینگ» هر وقت خواستی دوباره آپدیت بگیر.",
            $this->mainMenuKeyboard($chatId, $platform));
    }

    /**
     * ارسال پیام طولانی با تکه‌تکه کردن روی مرز خطوط (سقف تلگرام ~۴۰۹۶ کاراکتر).
     */
    private function sendLongMessage(string $chatId, string $text, int $chunkSize = 3500): void
    {
        $text = trim($text);
        if ($text === '') return;
        if (mb_strlen($text) <= $chunkSize) {
            $this->api->sendMessage($chatId, $text);
            return;
        }
        $lines = preg_split('/\n/', $text) ?: [$text];
        $buf = '';
        foreach ($lines as $line) {
            $next = $buf === '' ? $line : $buf . "\n" . $line;
            if (mb_strlen($next) > $chunkSize && $buf !== '') {
                $this->api->sendMessage($chatId, $buf);
                $buf = $line;
            } else {
                $buf = $next;
            }
        }
        if (trim($buf) !== '') {
            $this->api->sendMessage($chatId, $buf);
        }
    }

    /** لیست توضیحات آزاد + دکمه ساخت جدید — با صفحه‌بندی قبل/بعد. */
    private function showFreeNotesMenu(string $chatId, string $platform, int $page = 0): void
    {        $this->clearState($chatId, $platform);

        $total = FreeNote::where('chat_id', $chatId)->where('platform', $platform)->count();

        if ($total === 0) {
            $this->api->sendMessageWithInlineKeyboard($chatId,
                "✍️ توضیحات آزاد\n\nهنوز توضیحی نداری.\nبدون تایتل، فقط متنت رو بفرست تا با تاریخ شمسی ذخیره کنم — توی خروجی JSON هم با همون بازه‌ی زمانی میاد.",
                [
                    [['text' => '➕ توضیح جدید', 'callback_data' => 'free:new']],
                    [['text' => '🏠 منوی اصلی', 'callback_data' => 'free:menu']],
                ]);
            return;
        }

        $perPage = self::FREE_PAGE_SIZE;
        $totalPages = (int) ceil($total / $perPage);
        $page = max(0, min($page, $totalPages - 1));

        $notes = FreeNote::where('chat_id', $chatId)
            ->where('platform', $platform)
            ->orderByDesc('id')
            ->skip($page * $perPage)
            ->take($perPage)
            ->get();

        $lines = ["✍️ توضیحات آزاد ({$total} مورد) — صفحه " . ($page + 1) . " از {$totalPages}:\n"];
        $keyboard = [];
        foreach ($notes as $n) {
            $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($n->created_at);
            $len = mb_strlen((string) $n->body);
            $preview = mb_substr((string) $n->body, 0, 40);
            if (mb_strlen((string) $n->body) > 40) $preview .= '…';
            $lines[] = "• {$shamsi} ({$len} حرف)\n  {$preview}";
            $short = mb_substr((string) $n->body, 0, 20);
            $keyboard[] = [['text' => "✍️ {$short}", 'callback_data' => "free:view:{$n->id}"]];
        }

        $lines[] = "\nبرای دیدن متن کامل روی هر مورد بزن.";

        $keyboard[] = [['text' => '➕ توضیح جدید', 'callback_data' => 'free:new']];
        if ($totalPages > 1) {
            $nav = [];
            if ($page > 0) $nav[] = ['text' => '◀ قبلی', 'callback_data' => 'free:page:' . ($page - 1)];
            if ($page < $totalPages - 1) $nav[] = ['text' => 'بعدی ▶', 'callback_data' => 'free:page:' . ($page + 1)];
            if (!empty($nav)) $keyboard[] = $nav;
        }
        $keyboard[] = [['text' => '🏠 منوی اصلی', 'callback_data' => 'free:menu']];

        $this->api->sendMessageWithInlineKeyboard($chatId, implode("\n", $lines), $keyboard);
    }

    /** نمایش یک توضیح آزاد با متن کامل + تاریخ شمسی ایجاد. */
    private function showFreeNoteDetail(string $chatId, string $platform, string $id): void
    {
        if (!ctype_digit($id)) {
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }
        $note = FreeNote::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->api->sendMessage($chatId, "توضیحی با این مشخصات پیدا نکردم.");
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }
        $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay($note->created_at);
        $len = mb_strlen((string) $note->body);
        $text = "✍️ توضیح آزاد\n📅 {$shamsi}\n📝 {$len}/۲۰۰۰ حرف\n\n{$note->body}";

        $this->api->sendMessageWithInlineKeyboard($chatId, $text, [
            [['text' => '✏️ ویرایش', 'callback_data' => "free:edit:{$note->id}"], ['text' => '🗑 حذف', 'callback_data' => "free:del:{$note->id}"]],
            [['text' => '📋 لیست توضیحات', 'callback_data' => 'free:list'], ['text' => '➕ جدید', 'callback_data' => 'free:new']],
            [['text' => '🏠 منوی اصلی', 'callback_data' => 'free:menu']],
        ]);
    }

    private function askDeleteFreeNote(string $chatId, string $platform, string $id): void
    {
        $note = FreeNote::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }
        $preview = mb_substr((string) $note->body, 0, 60);
        $this->api->sendMessageWithInlineKeyboard($chatId,
            "🗑 این توضیح حذف بشه؟\n«{$preview}…»",
            [
                [['text' => '✅ بله، حذف کن', 'callback_data' => "free:delconf:{$note->id}"], ['text' => '↩ انصراف', 'callback_data' => "free:view:{$note->id}"]],
            ]);
    }

    private function deleteFreeNote(string $chatId, string $platform, string $id): void
    {
        $note = FreeNote::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }
        $note->delete();
        $this->api->sendMessage($chatId, "🗑 توضیح آزاد حذف شد.");
        $this->showFreeNotesMenu($chatId, $platform, 0);
    }

    /** شروع ویرایش توضیح آزاد — سقف ۲۰۰۰ کاراکتر مثل ساخت. */
    private function startFreeNoteEdit(string $chatId, string $platform, string $id): void
    {
        if (!ctype_digit($id)) {
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }
        $note = FreeNote::where('chat_id', $chatId)->where('platform', $platform)->where('id', (int) $id)->first();
        if (!$note) {
            $this->api->sendMessage($chatId, "توضیحی با این مشخصات پیدا نکردم.");
            $this->showFreeNotesMenu($chatId, $platform, 0);
            return;
        }
        $len = mb_strlen((string) $note->body);
        $this->setState($chatId, $platform, 'waiting_freenote_edit', ['free_id' => $note->id]);
        $this->api->sendMessage($chatId, "✏️ متن فعلی ({$len}/۲۰۰۰ حرف):\n\n{$note->body}\n\n───\nمتن جدید رو بفرست:\n(حداکثر ۲۰۰۰ کاراکتر — برای لغو /cancel)");
    }

    /** ساخت نهایی روتین در انتهای ویزارد — $remindAt به وقت تهران (HH:MM) یا null؛ $silent = سایلنت (بدون صدا). */
    private function finishRoutineWizard(string $chatId, string $platform, array $data, ?string $remindAt, bool $silent = false): void
    {
        $startsOn = $data['routine_starts_on'] ?? Carbon::today()->toDateString();
        $endsOn = $data['routine_ends_on'] ?? $startsOn;
        $routine = Routine::create([
            'chat_id' => $chatId,
            'platform' => $platform,
            'title' => $data['routine_title'],
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'remind_at' => $remindAt,
            'silent_remind' => $remindAt !== null && $silent,
            'is_active' => true,
        ]);
        $this->clearState($chatId, $platform);
        $shamsiStart = \App\Helpers\ShamsiDateHelper::dateOnly(Carbon::parse($startsOn));
        $shamsiEnd = \App\Helpers\ShamsiDateHelper::dateOnly(Carbon::parse($endsOn));
        $msg = "✅ روتین «{$routine->title}» فعال شد!\n📅 {$shamsiStart} تا {$shamsiEnd}\n";
        if ($remindAt !== null) {
            $msg .= $silent ? "⏰ یادآوری روزانه ساعت {$remindAt} 🔕 سایلنت (به وقت تهران)\n" : "⏰ یادآوری روزانه ساعت {$remindAt} 🔔 با صدا (به وقت تهران)\n";
        }
        $msg .= "\nتا وقتی فعاله، موقع ثبت روزانه (/log) ازت می‌پرسم انجامش دادی یا نه (با دکمه بله/خیر + توضیح).";
        $this->api->sendMessage($chatId, $msg);
        $this->handleRoutines($chatId, $platform);
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
    private function askPositiveTrigger(string $chatId, string $platform, array $data): void
    {
        $this->setState($chatId, $platform, 'waiting_positive_trigger', $data);
        $dw = $this->dayWord($data);
        $this->api->sendMessage($chatId, "۹/۱۰ — 🌟 چه چیزی {$dw} حالت را بالا برد؟\nیک جمله بنویس (مثلا: دیت خوب، صحبت با دوستم).\nاگر اتفاق مثبتی نبود /skip بفرست.");
    }

    /** شدت اثر عامل مثبت: «9»، «9/10»، «9 از 10»، ارقام فارسی هم قبول است. خروجی 1-10 یا null. */
    private function parseIntensity(string $input): ?int
    {
        $fa = str_replace(
            ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],
            ['0','1','2','3','4','5','6','7','8','9'],
            trim($input)
        );
        if (preg_match('/(\d{1,2})/', $fa, $m)) {
            $n = (int) $m[1];
            if ($n >= 1 && $n <= 10) return $n;
        }
        return null;
    }

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
        $dw = $this->dayWord($data);

        $this->api->sendMessageWithInlineKeyboard($chatId,
            "🔁 روتین «{$title}» (" . ($idx + 1) . " از {$total})\n{$dw} انجامش دادی؟",
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

        $this->api->sendMessageWithKeyboard($chatId, $this->formatEntry($entry->refresh(), "✅ ثبت شد!"), $this->mainMenuKeyboard($chatId, $platform));
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
            // username فقط وقتی آپدیت شود که واقعا آمده باشد — وگرنه (مثل کال‌بک دکمه‌ها) مقدار قبلی می‌ماند
            $attrs = ['last_seen_at' => now()];
            if ($username !== null && trim($username) !== '') {
                $attrs['username'] = ltrim(trim($username), '@');
            }
            BotSubscriber::updateOrCreate(
                ['chat_id' => $chatId, 'platform' => $platform],
                $attrs
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
     * Accepts: 23:30, 23.30, 6 صبح, 6:30 صبح, ۶ صبح, 7 عصر, 6 شب, 12 ظهر, 6, 06, 18, 6am, 6pm,
     *          1 و نیم ظهر (=13:30), 9 نیم شب (=21:30)
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
        $explicitMinute = isset($m[2]) && $m[2] !== '';
        $minute = $explicitMinute ? (int)$m[2] : 0;

        // «نیم» بدون دقیقه‌ی صریح یعنی ربعِ ساعتِ بعد (:30) — مثلا «1 و نیم ظهر» = 13:30
        if (!$explicitMinute && mb_strpos($lower, 'نیم') !== false) {
            $minute = 30;
        }

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

    /** کلمه‌ی روز برای پیام‌های فلو: «امروز» / «دیروز» / تاریخ کوتاه شمسی برای روزهای دیگر. */
    private function dayWord(array $data): string
    {
        $target = $data['entry_date'] ?? Carbon::today()->toDateString();
        try { $target = Carbon::parse($target)->toDateString(); } catch (\Throwable $e) { $target = Carbon::today()->toDateString(); }
        if ($target === Carbon::today()->toDateString()) return 'امروز';
        if ($target === Carbon::yesterday()->toDateString()) return 'دیروز';
        return \App\Helpers\ShamsiDateHelper::shortDate(Carbon::parse($target)) ?: $target;
    }

    /** شروع مستقیم ثبت برای یک تاریخ بدون نمایش انتخاب‌گر (برای دکمه‌ی «📝 دیروز» در منوی اصلی). */
    private function startLoggingForDate(string $chatId, string $platform, string $dateYmd): void
    {
        try { $dateYmd = Carbon::parse($dateYmd)->toDateString(); } catch (\Throwable $e) { $dateYmd = Carbon::today()->toDateString(); }
        $this->setState($chatId, $platform, 'waiting_sleep', ['entry_date' => $dateYmd]);
        $shamsi = \App\Helpers\ShamsiDateHelper::dateWithDay(Carbon::parse($dateYmd));
        $label = $dateYmd === Carbon::today()->toDateString() ? 'ثبت امروز' : ($dateYmd === Carbon::yesterday()->toDateString() ? 'ثبت دیروز' : "ثبت {$shamsi}");
        $this->api->sendMessage($chatId, "شروع می‌کنیم! 📝\n📅 {$shamsi} — {$label}\n\n۱/۱۰ — ساعت خوابت کی بود؟\nمثال: 23:30 یا 6 صبح یا 7 عصر\n(برای لغو /cancel)");
    }
}
