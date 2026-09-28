<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CoachingProfile extends Model
{
    protected $fillable = [
        'chat_id',
        'platform',
        'profile_json',
    ];

    /**
     * قالب اولیه‌ی پرونده کوچینگ — همان ساختار ثابت اسپک.
     */
    public static function defaultTemplate(): array
    {
        return [
            '_description' => 'پرونده مرجع کوچینگ کاربر. این فایل خلاصه‌ای از وضعیت کلی، اهداف، الگوها و مسیر فعلی زندگی کاربر است.',
            'profile' => [
                '_description' => 'اطلاعات نسبتاً ثابت و مهم درباره کاربر؛ مانند سن، شغل، شرایط زندگی، علایق مهم و ترجیحات مرتبط با کوچینگ.',
            ],
            'goals' => [
                '_description' => 'اهداف مهم کاربر در بازه بلندمدت و اهداف فعلی که اکنون باید روی آنها تمرکز شود.',
                'long_term' => [],
                'current' => [],
            ],
            'current_state' => [
                '_description' => 'تصویر فعلی کاربر؛ شامل وضعیت روحی، کاری، خواب، اجتماعی، رابطه‌ای، بدنی و سایر شرایطی که در حال حاضر روی زندگی او اثرگذارند.',
            ],
            'patterns' => [
                '_description' => 'الگوهای مهمی که از گزارش‌ها و تجربه‌های تکرارشونده کاربر مشاهده شده‌اند؛ نه تشخیص پزشکی یا روان‌شناختی قطعی.',
                'items' => [],
            ],
            'strategy' => [
                '_description' => 'استراتژی و مسیر فعلی کوچینگ؛ شامل مرحله فعلی، مهم‌ترین اولویت‌ها و اصولی که در این دوره باید رعایت شوند.',
                'current_phase' => '',
                'priorities' => [],
                'rules' => [],
            ],
            'work' => [
                '_description' => 'وضعیت کلی کار و کسب‌وکارهای کاربر، اولویت هر پروژه، فرصت‌ها، مشکلات مهم و اهداف کاری.',
            ],
            'personal' => [
                '_description' => 'وضعیت و اهداف رشد شخصی کاربر.',
                'emotional_independence' => new \stdClass(),
                'relationships_and_dating' => new \stdClass(),
                'social' => new \stdClass(),
                'body' => new \stdClass(),
                'lifestyle' => new \stdClass(),
                'personal_presence' => new \stdClass(),
            ],
            'cbt' => [
                '_description' => 'خلاصه الگوهای مهمی که از کار با CBT و ثبت افکار مشخص شده‌اند. جزئیات کامل Thought Recordها در بخش جداگانه ربات نگهداری می‌شوند.',
                'important_patterns' => [],
                'current_focus' => '',
            ],
            'history' => [
                '_description' => 'اتفاق‌ها و تغییرات مهم گذشته که برای درک وضعیت فعلی و تصمیم‌گیری‌های آینده هنوز اهمیت دارند.',
                'items' => [],
            ],
            'last_update' => [
                '_description' => 'اطلاعات مربوط به آخرین به‌روزرسانی پرونده کوچینگ.',
                'date' => '',
                'summary' => '',
            ],
        ];
    }

    /**
     * کلیدهای سطح بالای مورد انتظار — برای اعتبارسنجی حداقلی JSON دریافتی.
     */
    public static function expectedKeys(): array
    {
        return [
            'profile',
            'goals',
            'current_state',
            'patterns',
            'strategy',
            'work',
            'personal',
            'cbt',
            'history',
            'last_update',
        ];
    }

    /**
     * پرونده‌ی کاربر را بده؛ اگر نبود با قالب اولیه بساز.
     */
    public static function getOrCreate(string $chatId, string $platform): self
    {
        $existing = self::where('chat_id', $chatId)->where('platform', $platform)->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'chat_id' => $chatId,
            'platform' => $platform,
            'profile_json' => json_encode(self::defaultTemplate(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ]);
    }

    /**
     * محتوای JSON به صورت آرایه؛ اگر خراب بود قالب اولیه برمی‌گرداند.
     */
    public function toProfileArray(): array
    {
        try {
            $decoded = json_decode((string) $this->profile_json, true, 512, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : self::defaultTemplate();
        } catch (\Throwable $e) {
            return self::defaultTemplate();
        }
    }
}
