<?php

namespace App\Services;

use App\Helpers\ShamsiDateHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * CbtService — دفترچه فکر و احساس (Thought Record / CBT).
 *
 * پورت فیچر CBT از پروژه‌ی factorland برای ربات mydayli.
 * تفاوت‌ها با نسخه‌ی اصلی:
 *  - چندکاربره است: state در Cache با کلید جدا per (platform, chatId)
 *    و رکوردها در فایل جدا per کاربر: thought_record/{platform}_{chatId}/records.txt
 *  - خروجی PDF ندارد؛ view() یک فایل JSON می‌سازد و با sendDocument می‌فرستد
 *    (مطابق درخواست: «خروجی‌ها همون json باقی بمونه»).
 *  - به‌جای sendMessageWithGlassButton از BotApi::sendMessageWithInlineKeyboard
 *    استفاده می‌کند (نام کالبک‌ها عین نسخه‌ی اصلی: thought_record_*).
 *
 * ویزارد (۱۴ مرحله): رویداد (اختیاری) → فکر → خطاهای فکری → احساس →
 * نمره باور → نمره شدت → ۷ سوال سقراطی → نمره باور مجدد → نمره شدت مجدد → واکنش
 */
class CbtService
{
    private BotApi $api;

    protected const CACHE_PREFIX = 'cbt_step_';

    protected const CACHE_TTL_MINUTES = 30;

    protected const DISTORTIONS = [
        'ذهن خوانی',
        'پیشگویی',
        'فاجعه سازی',
        'فیلتر ذهنی منفی',
        'بی ارزش سازی',
        'تعمیم افراطی',
        'تفکر همه یا هیچ',
        'باید ها و نباید ها',
        'برچسب زدن',
        'مقصر دانستن خود یا دیگران',
    ];

    protected const DISTORTION_EMOJIS = [
        '🧠', '🔮', '💥', '🌧', '🚫',
        '🌐', '⚫', '📏', '🏷', '👤',
    ];

    /**
     * سوال‌های سقراطی بعد از نمره‌ی باور.
     * کلیدها هم نام فیلد در state/فایل ذخیره‌سازی هستند.
     */
    protected const QUESTIONS = [
        'evidence_for' => 'چه شواهد و مدارکی برای درستی فکرم دارم؟',
        'evidence_against' => 'چه شواهدی برای نادرستی فکرم دارم؟',
        'alternative_reasons' => 'برای این اتفاق چه توجیهات و دلایل دیگری وجود دارد؟',
        'others_agree' => 'آیا دیگران نیز مانند من فکر میکنند؟',
        'pros_cons' => 'سود و زیان داشتن این فکر چیست؟',
        'testable' => 'آیا قابل آزمایش کردن هست؟',
        'best_friend' => 'اگر بهترین دوست من جای من بود و چنین فکری داشت، در مورد فکرش به او چه میگفتم؟',
    ];

    protected const RECORD_START = '###RECORD_START###';

    protected const RECORD_END = '###RECORD_END###';

    public function __construct(BotApi $api)
    {
        $this->api = $api;
    }

    // ── Storage path (per user, private) ──

    public static function storagePath(string $chatId, string $platform): string
    {
        $safeChat = preg_replace('/[^0-9A-Za-z_\-]/', '_', $chatId);
        $safePlatform = preg_replace('/[^0-9A-Za-z_\-]/', '_', $platform);

        return "thought_record/{$safePlatform}_{$safeChat}/records.txt";
    }

    // ── Menu ──

    public function menu(string $chatId, string $platform): void
    {
        $text = "🧠 دفترچه فکر و احساس (CBT)\n\n"
            . "روانشناس گفته هر بار حس منفی داشتی، این فرم رو پر کن:\n"
            . "رویداد (اختیاری) → فکر → احساس → بررسی افکار → واکنش";

        $this->api->sendMessageWithInlineKeyboard($chatId, $text, [
            [['text' => '➕ ثبت رکورد جدید', 'callback_data' => 'thought_record_new']],
            [['text' => '📄 دریافت جدول کامل (JSON)', 'callback_data' => 'thought_record_view']],
            [['text' => '🗑 پاک کردن کامل لیست', 'callback_data' => 'thought_record_reset']],
            [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'thought_record_home']],
        ]);
    }

    public function resetPrompt(string $chatId, string $platform): void
    {
        $text = "⚠️ مطمئنی؟\nاین کار همه‌ی رکوردهای ثبت‌شده رو برای همیشه پاک میکنه و برگشت‌پذیر نیست.";

        $this->api->sendMessageWithInlineKeyboard($chatId, $text, [
            [['text' => '✅ بله، کامل پاک کن', 'callback_data' => 'thought_record_reset_confirm']],
            [['text' => '❌ نه، بازگشت', 'callback_data' => 'thought_record_menu']],
        ]);
    }

    public function resetConfirmed(string $chatId, string $platform): void
    {
        $path = self::storagePath($chatId, $platform);
        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        $this->api->sendMessageWithInlineKeyboard($chatId, '🗑 لیست با موفقیت کامل پاک شد.', [
            [['text' => '➕ ثبت رکورد جدید', 'callback_data' => 'thought_record_new']],
            [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'thought_record_home']],
        ]);
    }

    // ── Wizard entry ──

    public function startNew(string $chatId, string $platform): void
    {
        $this->setState($chatId, $platform, ['step' => 'event']);

        $this->api->sendMessageWithInlineKeyboard($chatId, "1️⃣ رویداد چی بود؟\n(این مورد اختیاریه، میتونی رد کنی)", [
            [['text' => '⏭️ رد شدن از این مرحله', 'callback_data' => 'thought_record_skip_event']],
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    public function skipEvent(string $chatId, string $platform): void
    {
        $state = $this->getState($chatId, $platform);
        if (! $state) {
            $this->startNew($chatId, $platform);

            return;
        }

        $state['event'] = null;
        $state['step'] = 'thought';
        $this->setState($chatId, $platform, $state);

        $this->askThought($chatId);
    }

    public function cancel(string $chatId, string $platform): void
    {
        $this->clearState($chatId, $platform);
        $this->menu($chatId, $platform);
    }

    // ── Text input mid-wizard ──

    public function handleStep(string $chatId, string $platform, string $text): void
    {
        $state = $this->getState($chatId, $platform);
        if (! $state) {
            $this->menu($chatId, $platform);

            return;
        }

        switch ($state['step']) {
            case 'event':
                $state['event'] = $text;
                $state['step'] = 'thought';
                $this->setState($chatId, $platform, $state);
                $this->askThought($chatId);
                break;

            case 'thought':
                $state['thought'] = $text;
                $state['step'] = 'distortion';
                $state['distortions'] = [];
                $this->setState($chatId, $platform, $state);
                $this->askDistortion($chatId, $platform);
                break;

            case 'distortion':
                // تایپ دستی یک خطای فکری (علاوه بر دکمه‌ها)
                $state['distortions'][] = $text;
                $this->setState($chatId, $platform, $state);
                $this->askDistortion($chatId, $platform);
                break;

            case 'feeling':
                $state['feeling'] = $text;
                $state['step'] = 'score_thought';
                $this->setState($chatId, $platform, $state);
                $this->askScoreThought($chatId);
                break;

            case 'score_thought':
                $state['score_thought'] = $text;
                $state['step'] = 'score_feeling';
                $this->setState($chatId, $platform, $state);
                $this->askScoreFeeling($chatId);
                break;

            case 'score_feeling':
                $state['score_feeling'] = $text;
                $state['step'] = 'question';
                $state['question_index'] = 0;
                $state['answers'] = [];
                $this->setState($chatId, $platform, $state);
                $this->askQuestion($chatId, 0);
                break;

            case 'question':
                $keys = array_keys(self::QUESTIONS);
                $index = $state['question_index'] ?? 0;
                $key = $keys[$index] ?? null;

                if ($key) {
                    $state['answers'][$key] = $text;
                }

                $index++;
                if ($index < count($keys)) {
                    $state['question_index'] = $index;
                    $this->setState($chatId, $platform, $state);
                    $this->askQuestion($chatId, $index);
                    break;
                }

                $state['step'] = 'score_thought_after';
                $this->setState($chatId, $platform, $state);
                $this->askScoreThoughtAfter($chatId);
                break;

            case 'score_thought_after':
                $state['score_thought_after'] = $text;
                $state['step'] = 'score_feeling_after';
                $this->setState($chatId, $platform, $state);
                $this->askScoreFeelingAfter($chatId);
                break;

            case 'score_feeling_after':
                $state['score_feeling_after'] = $text;
                $state['step'] = 'reaction';
                $this->setState($chatId, $platform, $state);
                $this->askReaction($chatId);
                break;

            case 'reaction':
                $state['reaction'] = $text;
                $this->clearState($chatId, $platform);
                $this->saveRecord($chatId, $platform, $state);
                break;

            default:
                $this->clearState($chatId, $platform);
                $this->menu($chatId, $platform);
                break;
        }
    }

    // ── Distortion buttons ──

    public function addDistortion(string $chatId, string $platform, int $index): void
    {
        $state = $this->getState($chatId, $platform);
        if (! $state || ($state['step'] ?? null) !== 'distortion') {
            $this->menu($chatId, $platform);

            return;
        }

        $distortion = self::DISTORTIONS[$index] ?? null;
        if ($distortion) {
            $key = array_search($distortion, $state['distortions']);
            if ($key !== false) {
                unset($state['distortions'][$key]);
                $state['distortions'] = array_values($state['distortions']);
            } else {
                $state['distortions'][] = $distortion;
            }
            $this->setState($chatId, $platform, $state);
        }

        $this->askDistortion($chatId, $platform);
    }

    public function doneDistortion(string $chatId, string $platform): void
    {
        $state = $this->getState($chatId, $platform);
        if (! $state || ($state['step'] ?? null) !== 'distortion') {
            $this->menu($chatId, $platform);

            return;
        }

        $selected = $state['distortions'] ?? [];
        $state['distortion'] = empty($selected) ? '-' : implode('، ', $selected);
        $state['step'] = 'feeling';
        $this->setState($chatId, $platform, $state);

        $this->askFeeling($chatId);
    }

    // ── Questions ──

    protected function askThought(string $chatId): void
    {
        $this->api->sendMessageWithInlineKeyboard($chatId, "2️⃣ فکر (ذهنیت) شما در اون لحظه چی بود؟", [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askFeeling(string $chatId): void
    {
        $this->api->sendMessageWithInlineKeyboard($chatId, '3️⃣ چه احساسی داشتید؟', [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askScoreThought(string $chatId): void
    {
        $this->api->sendMessageWithInlineKeyboard($chatId, "4️⃣ چقدر (از ۰ تا ۱۰۰) به این فکر باور داری؟", [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askScoreFeeling(string $chatId): void
    {
        $this->api->sendMessageWithInlineKeyboard($chatId, "4.5️⃣ شدت این احساس رو از ۰ تا ۱۰۰ چند می‌ذاری؟", [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askQuestion(string $chatId, int $index): void
    {
        $keys = array_keys(self::QUESTIONS);
        $key = $keys[$index];
        $question = self::QUESTIONS[$key];
        $total = count($keys);

        $this->api->sendMessageWithInlineKeyboard($chatId, "🔍 سوال ".($index + 1)." از {$total}:\n{$question}", [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askScoreThoughtAfter(string $chatId): void
    {
        $this->api->sendMessageWithInlineKeyboard($chatId, "🔄 حالا که به این فکر نگاه دقیق‌تری انداختی، الان چقدر (از ۰ تا ۱۰۰) بهش باور داری؟", [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askScoreFeelingAfter(string $chatId): void
    {
        $this->api->sendMessageWithInlineKeyboard($chatId, "🔄 الان شدت اون احساس رو از ۰ تا ۱۰۰ چند می‌ذاری؟", [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askReaction(string $chatId): void
    {
        $this->api->sendMessageWithInlineKeyboard($chatId, '🎬 حالا که به فکرت نگاه دقیق‌تری انداختی، واکنش (یا واکنش جدید) شما چیه؟', [
            [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']],
        ]);
    }

    protected function askDistortion(string $chatId, string $platform): void
    {
        $state = $this->getState($chatId, $platform);
        $selected = $state['distortions'] ?? [];

        $selectedText = '';
        if (! empty($selected)) {
            $selectedText = "\n✅ انتخاب شده: ".implode('، ', $selected)."\n";
        }

        $text = "2.5️⃣ کدوم خطاهای فکری تو این فکر بود؟{$selectedText}\nمیتونی دکمه بزنی یا تایپ کنی.";

        $keyboard = [];
        for ($i = 0; $i < count(self::DISTORTIONS); $i += 2) {
            $row = [];
            $label0 = self::DISTORTION_EMOJIS[$i].' '.self::DISTORTIONS[$i];
            if (in_array(self::DISTORTIONS[$i], $selected)) {
                $label0 = '✅ '.$label0;
            }
            $row[] = ['text' => $label0, 'callback_data' => 'thought_record_dist_'.$i];

            if ($i + 1 < count(self::DISTORTIONS)) {
                $label1 = self::DISTORTION_EMOJIS[$i + 1].' '.self::DISTORTIONS[$i + 1];
                if (in_array(self::DISTORTIONS[$i + 1], $selected)) {
                    $label1 = '✅ '.$label1;
                }
                $row[] = ['text' => $label1, 'callback_data' => 'thought_record_dist_'.($i + 1)];
            }

            $keyboard[] = $row;
        }

        $keyboard[] = [['text' => '✅ تمام', 'callback_data' => 'thought_record_dist_done']];
        $keyboard[] = [['text' => '⭕ انصراف', 'callback_data' => 'thought_record_cancel']];

        $this->api->sendMessageWithInlineKeyboard($chatId, $text, $keyboard);
    }

    // ── Save ──

    protected function saveRecord(string $chatId, string $platform, array $state): void
    {
        $date = ShamsiDateHelper::fullDateTime(now());
        $answers = $state['answers'] ?? [];

        $block = self::RECORD_START."\n";
        $block .= 'DATE: '.$this->encodeField((string) $date)."\n";
        $block .= 'EVENT: '.$this->encodeField((string) ($state['event'] ?? '-'))."\n";
        $block .= 'THOUGHT: '.$this->encodeField((string) ($state['thought'] ?? '-'))."\n";
        $block .= 'DISTORTION: '.$this->encodeField((string) ($state['distortion'] ?? '-'))."\n";
        $block .= 'FEELING: '.$this->encodeField((string) ($state['feeling'] ?? '-'))."\n";
        $block .= 'SCORE_THOUGHT: '.$this->encodeField((string) ($state['score_thought'] ?? '-'))."\n";
        $block .= 'SCORE_FEELING: '.$this->encodeField((string) ($state['score_feeling'] ?? '-'))."\n";
        foreach (array_keys(self::QUESTIONS) as $key) {
            $block .= strtoupper($key).': '.$this->encodeField((string) ($answers[$key] ?? '-'))."\n";
        }
        $block .= 'SCORE_THOUGHT_AFTER: '.$this->encodeField((string) ($state['score_thought_after'] ?? '-'))."\n";
        $block .= 'SCORE_FEELING_AFTER: '.$this->encodeField((string) ($state['score_feeling_after'] ?? '-'))."\n";
        $block .= 'REACTION: '.$this->encodeField((string) ($state['reaction'] ?? '-'))."\n";
        $block .= self::RECORD_END."\n\n";

        Storage::disk('local')->append(self::storagePath($chatId, $platform), $block);

        $this->api->sendMessageWithInlineKeyboard($chatId, "✅ رکورد با موفقیت ثبت شد.\nتاریخ: {$date}", [
            [['text' => '➕ ثبت رکورد دیگر', 'callback_data' => 'thought_record_new']],
            [['text' => '📄 دریافت جدول کامل (JSON)', 'callback_data' => 'thought_record_view']],
            [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'thought_record_home']],
        ]);
    }

    // ── View: JSON export (no PDF) ──

    public function view(string $chatId, string $platform): void
    {
        $records = $this->readRecords($chatId, $platform);

        if (empty($records)) {
            $this->api->sendMessageWithInlineKeyboard($chatId, 'هنوز هیچ رکوردی ثبت نشده.', [
                [['text' => '➕ ثبت رکورد جدید', 'callback_data' => 'thought_record_new']],
                [['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => 'thought_record_home']],
            ]);

            return;
        }

        try {
            $payload = [
                'app' => 'mydayli-cbt',
                'chat_id' => $chatId,
                'platform' => $platform,
                'count' => count($records),
                'exported_at_miladi' => now()->toIso8601String(),
                'exported_at_shamsi' => ShamsiDateHelper::fullDateTime(now()),
                'records' => $records,
            ];

            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $fileName = "cbt-{$platform}-{$chatId}-".now()->format('Y-m-d_H-i-s').'.json';
            $filePath = storage_path("app/exports/{$fileName}");
            if (! is_dir(dirname($filePath))) {
                mkdir(dirname($filePath), 0755, true);
            }
            file_put_contents($filePath, $json);

            $caption = "📄 دفترچه فکر و احساس (JSON)\nتعداد رکورد: ".count($records);
            $result = $this->api->sendDocument($chatId, $filePath, $fileName, $caption);

            if (($result['ok'] ?? false) !== true) {
                Log::warning("[{$platform}] cbt json sendDocument failed", ['result' => $result]);
                $this->sendAsText($chatId, $records);
            }
        } catch (\Throwable $e) {
            Log::error("[{$platform}] cbt view error: ".$e->getMessage());
            $this->sendAsText($chatId, $records);
        }
    }

    protected function sendAsText(string $chatId, array $records): void
    {
        $text = "📋 دفترچه فکر و احساس:\n\n";
        foreach ($records as $index => $record) {
            $text .= '#'.($index + 1)."\n";
            $text .= "🗓 {$record['date']}\n";
            $text .= "📌 رویداد: {$record['event']}\n";
            $text .= "💭 فکر: {$record['thought']}\n";
            $text .= "🧠 خطاهای فکر: {$record['distortion']}\n";
            $text .= "❤️ احساس: {$record['feeling']}\n";
            $text .= "📊 نمره باور به فکر: {$record['score_thought']}\n";
            $text .= "📊 نمره شدت احساس: {$record['score_feeling']}\n";
            foreach (self::QUESTIONS as $key => $question) {
                $text .= "❓ {$question}\n{$record[$key]}\n";
            }
            $text .= "🔄 نمره باور به فکر (بعد از بررسی): {$record['score_thought_after']}\n";
            $text .= "🔄 نمره شدت احساس (بعد از بررسی): {$record['score_feeling_after']}\n";
            $text .= "🎬 واکنش: {$record['reaction']}\n";
            $text .= "──────────────\n";
        }

        $this->sendLongMessage($chatId, $text);
    }

    protected function sendLongMessage(string $chatId, string $text): void
    {
        // سقف تلگرام/بله ~۴۰۹۶ کاراکتر؛ با حاشیه‌ی امن تکه‌تکه می‌کنیم
        $chunks = mb_str_split($text, 3500);
        foreach ($chunks as $chunk) {
            $this->api->sendMessage($chatId, $chunk);
        }
    }

    // ── Read/parse ──

    /**
     * @return array<int, array<string, string>>
     */
    public function readRecords(string $chatId, string $platform): array
    {
        $path = self::storagePath($chatId, $platform);
        if (! Storage::disk('local')->exists($path)) {
            return [];
        }

        $content = Storage::disk('local')->get($path);

        preg_match_all(
            '/'.preg_quote(self::RECORD_START, '/').'(.*?)'.preg_quote(self::RECORD_END, '/').'/s',
            $content,
            $matches
        );

        $records = [];
        foreach ($matches[1] as $block) {
            $record = [
                'date' => $this->extractField($block, 'DATE'),
                'event' => $this->extractField($block, 'EVENT'),
                'thought' => $this->extractField($block, 'THOUGHT'),
                'distortion' => $this->extractField($block, 'DISTORTION'),
                'feeling' => $this->extractField($block, 'FEELING'),
                'score_thought' => $this->extractField($block, 'SCORE_THOUGHT'),
                'score_feeling' => $this->extractField($block, 'SCORE_FEELING'),
                'score_thought_after' => $this->extractField($block, 'SCORE_THOUGHT_AFTER'),
                'score_feeling_after' => $this->extractField($block, 'SCORE_FEELING_AFTER'),
                'reaction' => $this->extractField($block, 'REACTION'),
            ];

            foreach (array_keys(self::QUESTIONS) as $key) {
                $record[$key] = $this->extractField($block, strtoupper($key));
            }

            $records[] = $record;
        }

        return $records;
    }

    protected function extractField(string $block, string $key): string
    {
        if (preg_match('/^'.$key.': (.*?)$/m', $block, $m)) {
            return $this->decodeField(trim($m[1]));
        }

        return '-';
    }

    protected function encodeField(string $value): string
    {
        if ($value === '' || $value === null) {
            return '-';
        }

        return str_replace(["\r\n", "\n"], ['\\n', '\\n'], $value);
    }

    protected function decodeField(string $value): string
    {
        return str_replace('\\n', "\n", $value);
    }

    // ── State (Cache, independent from BotState daily flow) ──

    protected function cacheKey(string $chatId, string $platform): string
    {
        return self::CACHE_PREFIX.$platform.'_'.$chatId;
    }

    protected function getState(string $chatId, string $platform): ?array
    {
        return Cache::get($this->cacheKey($chatId, $platform));
    }

    protected function setState(string $chatId, string $platform, array $state): void
    {
        Cache::put($this->cacheKey($chatId, $platform), $state, now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    protected function clearState(string $chatId, string $platform): void
    {
        Cache::forget($this->cacheKey($chatId, $platform));
    }

    public function hasActiveState(string $chatId, string $platform): bool
    {
        return Cache::has($this->cacheKey($chatId, $platform));
    }

    public function clear(string $chatId, string $platform): void
    {
        $this->clearState($chatId, $platform);
    }
}
