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

## Production Path (Laravel API — Factorland)

```
Framework:       Laravel 10.x (laravel/framework 10.x, PHP 8.2 — requires ^8.1)
                 Local repo: E:\factorland_localhost (same codebase as server)
Server Path:     /var/www/html/api.factorland.ir
DocumentRoot:    /var/www/html/api.factorland.ir/public
Domain:          https://api.factorland.ir
DB:              MySQL factorchi on 127.0.0.1 (root / @Vbfc344334)
Queue:           database (jobs table), scheduler via Kernel.php
```

## MyDaily Project — Framework & Server Path

```
Framework:       Laravel 12.x (laravel/framework 12.69.*, PHP 8.2 — php -v 8.2.30 on server)
                 composer.json → "laravel/framework": "^12.0", "php": "^8.2"
                 Local PHP 8.2.31 / Composer 2.10.1 — Server PHP 8.2.30 / Composer 2.2.6
Repo:            https://github.com/vbfcc/mydaily.git  (branch main)
Server Path:     /var/www/html/my-daily
DocumentRoot:    /var/www/html/my-daily/public          (Apache DocumentRoot)
Local Path:      E:\mydayli
Domain:          https://daily.factorland.ir
Direct Webhooks (Iran server):
  Telegram:      https://daily.factorland.ir/api/webhook/telegram
  Bale:          https://daily.factorland.ir/api/webhook/bale
Relay Webhook (Telegram via foreign VPS — USE THIS):
  Telegram:      https://tm.factorland.ir/daily-webhook-relay.php
Health:          https://daily.factorland.ir/api/health
DB:              MySQL `mydaily` on 127.0.0.1:3306 (user root, pass @Vbfc344334 — same as factorchi)
                 Loکال: sqlite database/database.sqlite (pdo_sqlite فعال)
Apache vhost:    /etc/apache2/sites-available/daily.factorland.ir.conf
                 enabled → /etc/apache2/sites-enabled/daily.factorland.ir.conf
SSL:             Let's Encrypt via certbot --apache (requires DNS A -> 185.8.174.229)
Storage:         /var/www/html/my-daily/storage/*  +  bootstrap/cache  (chown www-data:www-data, chmod 775)
Env:             /var/www/html/my-daily/.env  (TELEGRAM_BOT_TOKEN, BALE_BOT_TOKEN, TELEGRAM_PROXY_URL, APP_URL, DB_*)
```

## Telegram Proxy / Webhook Relay (foreign VPS — هاست واسط)

> معماری دقیقاً مثل `api.factorland.ir` است — همان VPS خارجی (`tm` + `tg`) هم پراکسیِ خروجی و هم رله‌ی ورودی را برای MyDaily هم انجام می‌دهد.

ایران سرور (`185.8.174.229`) نمی‌تواند `api.telegram.org` را مستقیم صدا بزند و تلگرام هم بهتر است وب‌هوک را به یک هاست خارج از ایران بفرستد. برای همین **همه‌ی ترافیک تلگرام از یک هاست واسط خارجی** می‌گذرد.

> **مرجع کامل factorland (قبل از هر تغییری بخوان):**
> `E:\factorland_localhost\docs\telegram-proxy-connectivity.md`
> و `E:\factorland_localhost\docs\tm-factorland-ir-connection.md`
>
> **⚠️ روی پروداکشن `php artisan config:cache` نزن.** `TELEGRAM_BOT_TOKEN` و
> `TELEGRAM_PROXY_URL` با `env()` خوانده می‌شوند و در هیچ `config/` نیستند؛
> کش کردن آن‌ها را `null` می‌کند و کل ربات می‌خوابد (`cURL error 28`).
> برای برگرداندن: `php artisan config:clear`.

```
LIVE  : tm.factorland.ir  →  46.165.210.28   (مقدار TELEGRAM_PROXY_URL روی ایران سرور)
ALT   : tg.factorland.ir  →  103.75.196.85   (فالبکِ کامنت‌شده در .env ایران؛ دسترسی SSH زیر)
نام در کامنت‌های کد: "Gluta-EU"
```

### دو اسکریپت روی هاست واسط

سورسِ اصلیِ هر دو فایل داخل همین ریپو است: `app/Services/TelegramApi/`
— دیپلوی با دست به web rootِ VPS (خارج از گیت).

| URL روی هاست واسط | چه می‌کند | سورس در ریپو |
|---|---|---|
| `https://tm.factorland.ir/telegram-proxy.php` | `POST` (`method`, `bot_token`, `params` به JSON) → فوروارد به `https://api.telegram.org/bot<token>/<method>`. `GET` → تست سلامت `{proxy_status:online}`. فقط از `185.8.174.229` قبول می‌کند. مقدار `TELEGRAM_PROXY_URL` در `.env` ایران سرور. **مشترک بین factorland و MyDaily** — یک فایل برای همه‌ی بات‌ها. | `app/Services/TelegramApi/telegram-proxy.php` |
| `https://tm.factorland.ir/daily-webhook-relay.php` | وب‌هوک تلگرام را می‌گیرد و به `https://daily.factorland.ir/api/webhook/telegram` فوروارد می‌کند. تلگرام باید وب‌هوکش روی همین URL ست شود، نه مستقیم روی `daily.factorland.ir`. | `app/Services/TelegramApi/daily-webhook-relay.php` |

- لاگ روی VPS کنار هر اسکریپت: `proxy.log` / `daily-webhook-relay.log` (چرخش ۲ مگابایت، ‎`.bak`).
- پراکسی شاخه‌ی `downloadFile` را جدا هندل می‌کند — متد واقعی تلگرام نیست؛ با `GET https://api.telegram.org/file/bot<token>/<file_path>` فایل را استریم می‌کند.
- بله (Bale) نیازی به پراکسی/رله ندارد — در ایران فیلتر نیست. وب‌هوک Bale مستقیم می‌ماند:
  `https://daily.factorland.ir/api/webhook/bale`

### فایل‌ها کجای VPS هستند

```
# tm.factorland.ir (LIVE) — فقط FTP، بدون SSH پورت ۲۲
FTP:  ftp.tm.factorland.ir:21
User: h360018  (پسورد در .env با کلیدهای FTP_TM_PROXY_*)
Docroot: public_html/
  public_html/telegram-proxy.php
  public_html/daily-webhook-relay.php
  public_html/proxy.log
  public_html/daily-webhook-relay.log

# tg.factorland.ir (ALT) — SSH دارد
SSH:  Glutamarket-EU  (103.75.196.85, key gluta_server_sshkey)
Path: /var/www/html/tg.factorland.ir/
  telegram-proxy.php
  daily-webhook-relay.php
  proxy.log / daily-webhook-relay.log
Apache vhost: tg.factorland.ir.conf, DocumentRoot /var/www/html/tg.factorland.ir
```

### دیپلوی هاست واسط (با دست — فقط وقتی کاربر گفت)

```bash
# ۱) روی tm (LIVE) — از FTP
# بک‌آپ بگیر، فایل جدید را از app/Services/TelegramApi/ آپلود کن، php -l بگیر

# ۲) روی tg (ALT) — از SSH
ssh Glutamarket-EU
cp /var/www/html/tg.factorland.ir/telegram-proxy.php /var/www/html/tg.factorland.ir/telegram-proxy.php.bak.$(date +%Y%m%d)
cp /var/www/html/tg.factorland.ir/daily-webhook-relay.php /var/www/html/tg.factorland.ir/daily-webhook-relay.php.bak.$(date +%Y%m%d)
# سپس اس‌سی‌پی فایل‌های جدید
php -l /var/www/html/tg.factorland.ir/telegram-proxy.php
php -l /var/www/html/tg.factorland.ir/daily-webhook-relay.php
chown www-data:www-data /var/www/html/tg.factorland.ir/*.php

# ۳) تست از ایران سرور
ssh Factorland-Iran
curl -s https://tm.factorland.ir/telegram-proxy.php
# → {"proxy_status":"online","telegram_reachable":true}
curl -s https://tm.factorland.ir/daily-webhook-relay.php
# → {"status":"daily relay online","target":"https://daily.factorland.ir/api/webhook/telegram"}
```

### تنظیم وب‌هوک — ترتیب درست

```bash
ssh Factorland-Iran
cd /var/www/html/my-daily

# .env را چک کن — باید این مقدار باشد:
# TELEGRAM_PROXY_URL=https://tm.factorland.ir/telegram-proxy.php
# (فالبکِ کامنت‌شده: # TELEGRAM_PROXY_URL=https://tg.factorland.ir/telegram-proxy.php)

# تلگرام → حتماً روی رله‌ی tm ست کن (نه مستقیم روی daily.factorland.ir)
php artisan bot:webhook telegram set --url=https://tm.factorland.ir/daily-webhook-relay.php
php artisan bot:webhook telegram info
# انتظار: {"ok":true,"result":{"url":"https://tm.factorland.ir/daily-webhook-relay.php", ...}}

# بله → مستقیم (نیازی به رله ندارد)
php artisan bot:webhook bale set --url=https://daily.factorland.ir/api/webhook/bale
php artisan bot:webhook bale info

# تست دستی رله
curl -X POST https://tm.factorland.ir/daily-webhook-relay.php \
  -H "Content-Type: application/json" \
  -d '{"message":{"chat":{"id":123},"text":"/start"}}'
# سپس روی VPS: tail /var/www/html/tg.factorland.ir/daily-webhook-relay.log
# و روی ایران: tail /var/www/html/my-daily/storage/logs/laravel.log
```

### VPS access (Glutamarket-EU — فقط با اجازه‌ی کاربر)

```
Host Glutamarket-EU
  HostName 103.75.196.85
  User root
  IdentityFile C:/Users/Padidar/.ssh/gluta_server_sshkey
  ForwardAgent yes
  ServerAliveInterval 20
  ServerAliveCountMax 3
  TCPKeepAlive yes
  ConnectTimeout 10
```

> **⚠️ خیلی مهم — قبل از وصل شدن بخوان.**
> - این یک سرور third-party جدا ("Gluta-EU") است — مال ما نیست.
> - فقط وقتی کاربر صریحاً گفت و واقعاً لازم بود وصل شو.
> - فقط زیر `/var/www/html/tg.factorland.ir/` کار کن — به بقیه‌ی سایت‌ها/کانفیگ‌ها دست نزن.
> - پراکسیِ لایوی که ایران سرور واقعاً استفاده می‌کند `tm.factorland.ir` (۴۶.۱۶۵.۲۱۰.۲۸) است — یک باکس جدا بدون SSH. دیپلوی روی `tg` فقط وقتی اثر می‌کند که `TELEGRAM_PROXY_URL` ایران سرور به آن سوییچ شود.
> - هرگز دستور مخرب یا سراسری نزن. اگر شک کردی، بپرس.

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

### Bot tokens (.env روی ایران سرور)

```
TELEGRAM_BOT_TOKEN=         # از @BotFather
BALE_BOT_TOKEN=             # از @BotFather بله
TELEGRAM_PROXY_URL=https://tm.factorland.ir/telegram-proxy.php
# فالبک (کامنت):
# TELEGRAM_PROXY_URL=https://tg.factorland.ir/telegram-proxy.php
# لوکال: TELEGRAM_PROXY_URL را خالی بگذار (مستقیم به api.telegram.org)
```

Set in `/var/www/html/my-daily/.env` then set webhooks as above.

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
- **Telegram outgoing** always goes via `TELEGRAM_PROXY_URL` (`BotApi::sendViaProxyWithRetry`, ۲ تلاش + فالبک direct). **Telegram incoming** goes via `daily-webhook-relay.php` on `tm`. Bale هر دو جهت مستقیم است.
- Source of truth for proxy/relay is `app/Services/TelegramApi/` in this repo — deploy by hand to VPS web root.
