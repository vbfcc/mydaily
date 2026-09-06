<?php

namespace App\Console\Commands;

use App\Services\BotApi;
use Illuminate\Console\Command;

class BotWebhookCommand extends Command
{
    protected $signature = 'bot:webhook
        {platform : telegram or bale}
        {action : set, delete, info}
        {--url= : Webhook URL (required for set)}';

    protected $description = 'Manage Telegram/Bale webhook (set/delete/info)';

    public function handle(): int
    {
        $platform = strtolower($this->argument('platform'));
        $action = strtolower($this->argument('action'));

        if (!in_array($platform, ['telegram', 'bale'])) {
            $this->error('Platform must be telegram or bale');
            return 1;
        }
        if (!in_array($action, ['set', 'delete', 'info'])) {
            $this->error('Action must be set, delete, or info');
            return 1;
        }

        $api = new BotApi($platform);

        if ($action === 'info') {
            $res = $api->getWebhookInfo();
            $this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        if ($action === 'delete') {
            $res = $api->deleteWebhook();
            $this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Webhook deleted for {$platform}");
            return 0;
        }

        // set
        $url = $this->option('url');
        if (!$url) {
            if ($platform === 'telegram') {
                $this->error('For telegram, use relay URL: --url=https://tm.factorland.ir/daily-webhook-relay.php');
                $this->line('  (relay → https://daily.factorland.ir/api/webhook/telegram)');
                $this->line('  Local/test: --url=https://YOUR_DOMAIN/api/webhook/telegram');
            } else {
                $this->error('For set action, provide --url=https://daily.factorland.ir/api/webhook/'.$platform);
            }
            return 1;
        }
        if ($platform === 'telegram' && str_contains($url, 'daily.factorland.ir/api/webhook/telegram')) {
            $this->warn('Heads-up: Telegram webhook is set directly to daily.factorland.ir');
            $this->warn('Prod should use relay: https://tm.factorland.ir/daily-webhook-relay.php');
            $this->warn('(Bale is fine direct; Telegram needs relay — see docs/connect2server.md)');
        }
        $res = $api->setWebhook($url);
        $this->line(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        if (($res['ok'] ?? false) === true) {
            $this->info("Webhook set for {$platform} → {$url}");
        } else {
            $this->error("Failed to set webhook for {$platform}");
        }
        return 0;
    }
}
