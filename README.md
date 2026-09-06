# MyDayli — ربات ثبت فعالیت روزانه

پروژه‌ی خیلی ساده با **Laravel 12** — بدون صفحه وب، فقط **ربات Telegram + Bale**.
هر کاربر یک رکورد در روز دارد (overwrite اگر دوباره ثبت کند).

## فیلدهای Week0

| فیلد | توضیح | نمونه |
|---|---|---|
| Sleep | ساعت خواب | 23:30 |
| Wake | ساعت بیداری | 07:00 |
| Work | ساعت کار مفید | 6 |
| Gym | باشگاه بله/خیر | بله |
| Gaming | دقیقه گیم | 45 |
| Social | تعامل اجتماعی بله/خیر | خیر |
| Mood | حال از ۱۰ | 8 |
| Emotional trigger | مهم‌ترین چیزی که حالت را تغییر داد | استرس کاری |

یک رکورد در روز به ازای هر `chat_id + platform` — ارسال دوباره همان روز، رکورد را overwrite می‌کند.

## ساختار

```
app/Services/BotApi.php          # مثل factorland TelegramApi.php — سوییچ خودکار telegram/bale + proxy
app/Services/DailyBotService.php # استیت‌ماشین ۸ مرحله‌ای (/log) + /today + /week
app/Http/Controllers/BotWebhookController.php
app/Models/DailyEntry.php        # یک ردیف در روز
app/Models/BotState.php          # وضعیت فعلی هر چت
routes/api.php                   # POST /api/webhook/telegram  و  /api/webhook/bale
```

## نصب لوکال (XAMPP/WAMP)

```bash
cd E:\mydayli
composer install
copy .env.example .env
php artisan key:generate
# .env را پر کن:
# TELEGRAM_BOT_TOKEN=xxx  (از @BotFather)
# BALE_BOT_TOKEN=xxx       (از @BotFather بله)
# TELEGRAM_PROXY_URL=      (لوکال خالی، روی سرور ایران اگر نیاز بود پر کن)
php artisan migrate
php artisan serve  # http://127.0.0.1:8000
```

DB پیش‌فرض **sqlite** است: `database/database.sqlite` (pdo_sqlite باید در php.ini فعال باشد).

## Webhook

```
Telegram: POST https://YOUR_DOMAIN/api/webhook/telegram
Bale:     POST https://YOUR_DOMAIN/api/webhook/bale
```

با artisan:

```bash
# ست کردن
php artisan bot:webhook telegram set --url=https://YOUR_DOMAIN/api/webhook/telegram
php artisan bot:webhook bale set --url=https://YOUR_DOMAIN/api/webhook/bale

# وضعیت
php artisan bot:webhook telegram info
php artisan bot:webhook bale info

# حذف (سوئیچ به polling)
php artisan bot:webhook telegram delete
```

> نکته‌ی پروکسی (مثل factorland): روی سرور ایران `TELEGRAM_PROXY_URL` به `https://tm.factorland.ir/telegram-proxy.php` اشاره می‌کند. لوکال نیازی نیست. کد `BotApi` هم proxy و هم direct را ساپورت می‌کند (منطق کپی‌شده از `E:\factorland_localhost\app\Services\TelegramApi\TelegramApi.php`).

## دستورات ربات

- `/start` — معرفی + کیبورد
- `/log` یا `📝 ثبت امروز` — شروع ثبت مرحله‌ای (۸ سوال)
- `/today` یا `📊 امروز` — نمایش امروز
- `/week` یا `📅 هفته` — ۷ روز گذشته
- `/cancel` — لغو
- `/skip` در مرحله‌ی آخر — رد کردن محرک احساسی

## تست بدون تلگرام

لوجیک ربات با FakeApi تست شد — `php test_bot.php` موجود در `C:\Users\Padidar\AppData\Local\Temp\opencode\test_bot.php` را می‌توان دوباره اجرا کرد. بعد از هر تست DB پاک می‌شود.
