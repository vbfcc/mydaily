# Connect to Production Server

## SSH Config

```
Host Factorland-Iran
  HostName 185.8.174.229
  User root
  IdentityFile C:/Users/Padidar/.ssh/id_factorland_iran
  ServerAliveInterval 20
  ServerAliveCountMax 3
  TCPKeepAlive yes
  ConnectTimeout 10
```

## Production Path (Laravel API)

```
/var/www/html/api.factorland.ir
```

## MyDaily Project

```
Repo:            https://github.com/vbfcc/mydaily.git
Server Path:     /var/www/html/my-daily
DocumentRoot:    /var/www/html/my-daily/public
Domain:          https://daily.factorland.ir
Webhooks:
  Telegram:      https://daily.factorland.ir/api/webhook/telegram
  Bale:          https://daily.factorland.ir/api/webhook/bale
Health:          https://daily.factorland.ir/api/health
DB:              MySQL `mydaily` on 127.0.0.1 (user root, same pass as factorchi DB)
Apache vhost:    /etc/apache2/sites-available/daily.factorland.ir.conf
SSL:             Let's Encrypt via certbot --apache (requires DNS A -> 185.8.174.229)
```

### Deploy (manual, same policy as api.factorland.ir)

```bash
ssh Factorland-Iran
cd /var/www/html/my-daily
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
chown -R www-data:www-data storage bootstrap/cache
# Do NOT run config:cache (BOT_TOKEN via env() would become null)
systemctl reload apache2
```

### Bot tokens (.env)

```
TELEGRAM_BOT_TOKEN=
BALE_BOT_TOKEN=
TELEGRAM_PROXY_URL=   # leave empty locally; on Iran server set if needed (same proxy as api.factorland.ir)
```

Set in `/var/www/html/my-daily/.env` then:

```bash
php artisan bot:webhook telegram set --url=https://daily.factorland.ir/api/webhook/telegram
php artisan bot:webhook bale set --url=https://daily.factorland.ir/api/webhook/bale
php artisan bot:webhook telegram info
php artisan bot:webhook bale info
```

### Apache + SSL setup (already done on server)

```bash
# vhost created: /etc/apache2/sites-available/daily.factorland.ir.conf
a2ensite daily.factorland.ir.conf
apache2ctl configtest && systemctl reload apache2

# SSL - requires DNS A for daily.factorland.ir -> 185.8.174.229
# Current DNS points to 185.143.233.238/185.143.234.238 (not this server) -> certbot failed (404).
# Fix DNS first, then:
certbot --apache --non-interactive --agree-tos -m mute4030@gmail.com -d daily.factorland.ir --redirect
# or: certbot --apache -d daily.factorland.ir
```

### Notes

- DB is MySQL `mydaily` (not sqlite, because php sqlite driver not available on server).
- `bot_states` holds per-chat flow state, `daily_entries` holds one row per chat_id+platform+date (overwrite).
- No web UI, only bot (Telegram + Bale) - same BotApi pattern as `app/Services/TelegramApi/TelegramApi.php` in factorland.
