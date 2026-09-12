# MyDaily — نصب و دیپلوی

## پیش‌نیاز

- PHP 8.2 (سرور `8.2.30`, لوکال `8.2.31`), Composer 2, MySQL 8 (سرور) / sqlite (لوکال), Apache 2.4, Git

## نصب لوکال (XAMPP/WAMP)

```bash
cd E:\mydayli
composer install
copy .env.example .env
php artisan key:generate
# .env:
# TELEGRAM_BOT_TOKEN=...  # از @BotFather
# BALE_BOT_TOKEN=...      # از @BotFather بله
# TELEGRAM_PROXY_URL=     # لوکال خالی بگذار (مستقیم)
# DB_CONNECTION=sqlite
# DB_DATABASE=E:/mydayli/database/database.sqlite
php -m | findstr sqlite   # باید pdo_sqlite + sqlite3 فعال باشد (php.ini: extension=pdo_sqlite, extension=sqlite3)
php artisan migrate
php artisan serve          # http://127.0.0.1:8000  →  GET /api/health → {"ok":true}
```

### تست بدون تلگرام/بله

```bash
php C:\Users\Padidar\AppData\Local\Temp\opencode\test_isolation.php
# سه چت جدا (111/telegram, 222/telegram, 111/bale) هر کدام رکورد مجزا
```

## سرور پروداکشن

### مسیرها و فریمورک

```
Framework:     Laravel 12.x (laravel/framework 12.69.*, PHP 8.2)
Repo:          https://github.com/vbfcc/mydaily.git  (branch main)
Server Path:   /var/www/html/my-daily
DocumentRoot:  /var/www/html/my-daily/public
Domain:        https://daily.factorland.ir
Apache vhost:  /etc/apache2/sites-available/daily.factorland.ir.conf
DB:            MySQL mydaily @ 127.0.0.1:3306 (root / @Vbfc344334 — مشترک با factorchi)
Env:           /var/www/html/my-daily/.env
Storage:       storage/* + bootstrap/cache → chown www-data:www-data, chmod 775
```

### دیپلوی دستی (سیاست فعلی — مثل api.factorland.ir)

```bash
ssh Factorland-Iran
cd /var/www/html/my-daily
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
# هرگز config:cache نزن — توکن‌ها با env() خوانده می‌شوند و null می‌شوند
systemctl reload apache2
```

### .env پروداکشن

```ini
APP_NAME=Laravel
APP_ENV=production
APP_DEBUG=false
APP_URL=https://daily.factorland.ir
APP_KEY=base64:...  # php artisan key:generate
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mydaily
DB_USERNAME=root
DB_PASSWORD=@Vbfc344334
TELEGRAM_BOT_TOKEN=8765222783:AAGRyr_lMbUU02HxQfi3KwWX9aUNEzvauDM
BALE_BOT_TOKEN=
TELEGRAM_PROXY_URL=https://tm.factorland.ir/telegram-proxy.php
# فالبک کامنت: #TELEGRAM_PROXY_URL=https://tg.factorland.ir/telegram-proxy.php
```

> دیتابیس `mydaily` اگر نبود:
> ```bash
> mysql -u root -p'@Vbfc344334' -e 'CREATE DATABASE IF NOT EXISTS mydaily CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
> ```

### Apache vhost

فایل: `/etc/apache2/sites-available/daily.factorland.ir.conf`

```apache
<VirtualHost *:80>
    ServerAdmin webmaster@daily.factorland.ir
    ServerName daily.factorland.ir
    DocumentRoot /var/www/html/my-daily/public
    ErrorLog /var/log/apache2/daily.factorland.ir_error.log
    CustomLog /var/log/apache2/daily.factorland.ir_access.log combined
    <Directory /var/www/html/my-daily/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    RewriteEngine on
    RewriteCond %{SERVER_NAME} =daily.factorland.ir
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
```

فعال‌سازی:

```bash
a2ensite daily.factorland.ir.conf
apache2ctl configtest && systemctl reload apache2
apache2ctl -S | grep daily
```

### SSL (Let's Encrypt)

نیاز به DNS `A daily.factorland.ir → 185.8.174.229`

```bash
# فعلی DNS به 185.143.233.238/185.143.234.238 می‌رود → certbot با 404 فیل می‌شود
dig +short daily.factorland.ir   # باید 185.8.174.229 شود

# بعد از اصلاح DNS:
certbot --apache --non-interactive --agree-tos -m mute4030@gmail.com -d daily.factorland.ir --redirect
# یا تعاملی:
certbot --apache -d daily.factorland.ir
systemctl reload apache2
ls /etc/letsencrypt/live/daily.factorland.ir/
```

### وب‌هوک‌ها

> تلگرام حتماً روی رله‌ی خارجی ست شود.

```bash
ssh Factorland-Iran
cd /var/www/html/my-daily

# تلگرام → رله (USE THIS)
php artisan bot:webhook telegram set --url=https://tm.factorland.ir/daily-webhook-relay.php
php artisan bot:webhook telegram info
# انتظار: {"ok":true,"result":{"url":"https://tm.factorland.ir/daily-webhook-relay.php",...}}

# بله → مستقیم
php artisan bot:webhook bale set --url=https://daily.factorland.ir/api/webhook/bale
php artisan bot:webhook bale info

# حذف (سوئیچ به polling):
php artisan bot:webhook telegram delete
php artisan bot:webhook bale delete
```

هاست واسط (مشترک با factorland):

- `telegram-proxy.php` — `POST` پراکسی خروجی → `api.telegram.org`
- `daily-webhook-relay.php` — رله‌ی ورودی تلگرام → `daily.factorland.ir/api/webhook/telegram`
- `tapi.bale.ai` — بله مستقیم (بدون پراکسی/رله)

جزئیات کامل: `docs/connect2server.md` بخش **Telegram Proxy / Webhook Relay**

### مانیتورینگ

```bash
tail -f /var/www/html/my-daily/storage/logs/laravel.log
tail -f /var/log/apache2/daily.factorland.ir_error.log
# روی VPS:
# tm: FTP public_html/proxy.log , public_html/daily-webhook-relay.log
# tg: /var/www/html/tg.factorland.ir/proxy.log , daily-webhook-relay.log
```

### کرون یادآور ۱۲ شب (اجباری برای `bot:remind-midnight`)

اسکجول لاراول (`routes/console.php` → `dailyAt('00:00')->timezone('Asia/Tehran')`) فقط وقتی کار می‌کند که `schedule:run` هر دقیقه اجرا شود:

```bash
crontab -e -u www-data
# اضافه کن:
* * * * * cd /var/www/html/my-daily && php artisan schedule:run >> /dev/null 2>&1
```

تست دستی:

```bash
php artisan bot:remind-midnight --dry-run   # فقط لیست گیرندگان
php artisan schedule:list                   # باید bot:remind-midnight را نشان بدهد
```
