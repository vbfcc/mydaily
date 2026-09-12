<?php

namespace App\Console\Commands;

use App\Models\BotSubscriber;
use App\Services\BotApi;
use App\Services\DailyBotService;
use Illuminate\Console\Command;

class SendMidnightReminder extends Command
{
    protected $signature = 'bot:remind-midnight
        {--dry-run : فقط لیست گیرندگان را نشان بده، پیام نفرست}';

    protected $description = 'یادآور ساعت ۱۲ شب: به همه‌ی مشترکین پیام «بیا گزارش امروز رو پر کن» می‌فرستد';

    public function handle(): int
    {
        $subs = BotSubscriber::orderBy('platform')->orderBy('chat_id')->get();

        if ($subs->isEmpty()) {
            $this->warn('مشترکی نیست (bot_subscribers خالی است).');
            return 0;
        }

        $this->info("گیرندگان: {$subs->count()}");

        if ($this->option('dry-run')) {
            foreach ($subs as $s) {
                $this->line(" - {$s->platform} / {$s->chat_id}");
            }
            return 0;
        }

        $sent = 0; $failed = 0;
        foreach ($subs->groupBy('platform') as $platform => $group) {
            $api = new BotApi($platform);
            foreach ($group as $s) {
                $text = DailyBotService::midnightReminderText($s->chat_id, $s->platform);
                $keyboard = [['📝 ثبت امروز', '📊 امروز'], ['🔁 روتین‌ها']];
                $res = $api->sendMessageWithKeyboard($s->chat_id, $text, $keyboard);
                if (($res['ok'] ?? false) === true) {
                    $sent++;
                } else {
                    $failed++;
                    $this->warn("ارسال نشد: {$platform} / {$s->chat_id}");
                }
                // مکث کوتاه تا ریت‌لیمیت نخوریم
                usleep(100000);
            }
        }

        $this->info("تمام شد: موفق {$sent} — ناموفق {$failed}");
        return $failed > 0 ? 1 : 0;
    }
}
