<?php

namespace App\Console\Commands;

use App\Models\Routine;
use App\Models\RoutineLog;
use App\Services\BotApi;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SendRoutineReminders extends Command
{
    protected $signature = 'bot:remind-routine
        {--dry-run : فقط لیست روتین‌های موعد را نشان بده، پیام نفرست}';

    protected $description = 'یادآوری ساعتی روتین‌ها: هر دقیقه اجرا می‌شود، روتین‌هایی که remind_at آن‌ها با ساعت فعلی تهران (HH:MM) برابر است و امروز انجام نشده‌اند را یادآوری می‌کند';

    public function handle(): int
    {
        // ⚠️ همه‌ی مقایسه‌ها به وقت تهران — سرور ممکن است UTC باشد
        $nowTehran = Carbon::now('Asia/Tehran');
        $today = $nowTehran->toDateString();
        $hm = $nowTehran->format('H:i');

        $routines = Routine::where('is_active', true)
            ->whereNotNull('remind_at')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->get()
            // remind_at در DB از نوع TIME است (HH:MM:SS) — فقط ساعت:دقیقه را با الان تهران مقایسه کن
            ->filter(fn (Routine $r) => mb_substr((string) $r->remind_at, 0, 5) === $hm);

        // روتین‌هایی که امروز قبلا انجام شده‌اند (done=true) را یادآوری نکن
        $due = $routines->reject(function (Routine $r) use ($today) {
            return RoutineLog::where('routine_id', $r->id)
                ->whereDate('entry_date', $today)
                ->where('done', true)
                ->exists();
        });

        if ($due->isEmpty()) {
            $this->info("روتین موعدی برای {$hm} تهران نیست.");
            return 0;
        }

        $this->info("روتین‌های موعد ({$hm} تهران): {$due->count()}");

        if ($this->option('dry-run')) {
            foreach ($due as $r) {
                $this->line(" - {$r->platform} / {$r->chat_id} — «{$r->title}» ⏰ {$hm}");
            }
            return 0;
        }

        $sent = 0; $failed = 0;
        foreach ($due->groupBy('platform') as $platform => $group) {
            $api = new BotApi($platform);
            foreach ($group as $r) {
                $text = "⏰ یادآوری: «{$r->title}»\n"
                    . "وقتشه انجامش بدی! (ساعت {$hm} به وقت تهران)\n"
                    . "برای ثبت از /log استفاده کن.";
                $res = $api->sendMessage($r->chat_id, $text);
                if (($res['ok'] ?? false) === true) {
                    $sent++;
                } else {
                    $failed++;
                    $this->warn("ارسال نشد: {$platform} / {$r->chat_id} — «{$r->title}»");
                }
                // مکث کوتاه تا ریت‌لیمیت نخوریم
                usleep(100000);
            }
        }

        $this->info("تمام شد: موفق {$sent} — ناموفق {$failed}");
        return $failed > 0 ? 1 : 0;
    }
}
