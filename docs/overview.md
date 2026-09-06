# MyDaily — معرفی کلی پروژه

## چیست؟
MyDaily یک پروژه‌ی بسیار ساده برای ثبت فعالیت‌های روزانه است. بدون صفحه‌ی وب — فقط یک **ربات تلگرام + بله** که با Laravel 12 نوشته شده و هر کاربر (`chat_id` + `platform`) می‌تواند روزی یک رکورد ثبت کند. ارسال دوباره در همان روز، رکورد قبلی را **overwrite** می‌کند.

> الگوبرداری سرویس ربات از `E:\factorland_localhost\app\Services\TelegramApi\TelegramApi.php` — همان منطق پراکسی/بله.

## فیلدهای Week0

| # | فیلد | کلید دیتابیس | نوع | توضیح | نمونه |
|---|---|---|---|---|---|
| 1 | Sleep | `sleep_time` | `HH:MM` | ساعت خواب | `23:30` |
| 2 | Wake | `wake_time` | `HH:MM` | ساعت بیداری | `07:00` |
| 3 | Work | `work_hours` | `decimal 4,1` | ساعت کار مفید | `6` / `4.5` |
| 4 | Gym | `gym` | `boolean` | باشگاه رفتی؟ | `بله / خیر` |
| 5 | Gaming | `gaming_minutes` | `int` | دقیقه گیم | `45` |
| 6 | Social | `social` | `boolean` | تعامل اجتماعی داشتی؟ | `بله / خیر` |
| 7 | Mood | `mood` | `tinyint 1-10` | حال از ۱۰ | `8` |
| 8 | Emotional trigger | `emotional_trigger` | `text` | مهم‌ترین محرک احساسی | `استرس کاری` یا `null` با `/skip` |

همراه با `chat_id`, `platform` (`telegram`|`bale`), `entry_date` (`date`), و `timestamps`.

## ویژگی‌های کلیدی

- **ایزولیشن کامل هر چت:** کلید یکتا `UNIQUE(chat_id, platform, entry_date)` — کاربر 111 روی تلگرام و کاربر 111 روی بله دو ردیف جدا دارند؛ کاربر 111 و 222 روی یک پلتفرم هم جدا.
- **استیت جدا برای هر چت:** `bot_states` با `UNIQUE(chat_id, platform)` — دو کاربر همزمان در مرحله‌های مختلف باشند تداخل نمی‌کنند.
- **overwrite روزانه:** یک روز = یک ردیف. لاگ دوم همان روز آپدیت است، نه Insert جدید.
- **دو پلتفرم با یک کد:** `BotApi` به‌صورت داینامیک پلتفرم را انتخاب می‌کند (`new BotApi('bale')` یا `app('current_platform')`) — بله مستقیم، تلگرام از طریق `TELEGRAM_PROXY_URL` (ایران فیلتر).
- **بدون وب UI:** فقط API وب‌هوک و دستورات ربات.

## تکنولوژی

- **Framework:** Laravel 12 (`laravel/framework` 12.x), PHP 8.2, Composer 2
- **DB:** لوکال `sqlite` (`database/database.sqlite`)، پروداکشن `MySQL` (`mydaily` روی `127.0.0.1`) — چون `pdo_sqlite` روی سرور فعال نیست
- **HTTP Client:** `Illuminate\Http` (Guzzle)
- **Web Server:** Apache 2.4 (`daily.factorland.ir` → `/var/www/html/my-daily/public`)
- **SSL:** Let's Encrypt `certbot --apache`
- **Repo:** `https://github.com/vbfcc/mydaily.git` — برنچ `main`

## ساختار کد

```
E:\mydayli\
├─ app/
│  ├─ Services/
│  │  ├─ BotApi.php              # پراکسی تلگرام + مستقیم بله (کپی منطق factorland)
│  │  └─ DailyBotService.php     # استیت‌ماشین ۸ مرحله‌ای، /today, /week
│  ├─ Http/Controllers/
│  │  └─ BotWebhookController.php # telegram() / bale() → DailyBotService
│  ├─ Models/
│  │  ├─ DailyEntry.php
│  │  └─ BotState.php
│  └─ Console/Commands/
│     └─ BotWebhookCommand.php   # php artisan bot:webhook {platform} {set|delete|info}
├─ database/migrations/
│  ├─ ..._create_daily_entries_table.php
│  └─ ..._create_bot_states_table.php
├─ routes/api.php                # POST /api/webhook/telegram, /api/webhook/bale, GET /api/health
├─ config/                       # بدون config اختصاصی برای توکن — env() مستقیم
├─ docs/
│  ├─ overview.md               # همین فایل
│  ├─ bot.md                    # جزئیات ربات و فلو
│  ├─ deployment.md             # نصب لوکال + دیپلوی سرور + SSL + وب‌هوک
│  ├─ database.md               # اسکیمای جداول
│  └─ connect2server.md         # SSH + مسیرها + دامنه
├─ public/                       # DocumentRoot
├─ storage/ & bootstrap/cache/   # نیاز به chown www-data
└─ .env / .env.example           # TELEGRAM_BOT_TOKEN, BALE_BOT_TOKEN, TELEGRAM_PROXY_URL
```

## جریان کلی

```
کاربر --/log--> Telegram/Bale --webhook--> Apache (daily.factorland.ir)
                                              │
                                              ▼
                                   BotWebhookController@telegram|bale
                                              │  app('current_platform')
                                              ▼
                                    DailyBotService::handle()
                                              │
                                   ┌──────────┴──────────┐
                                   │ BotState (per chat) │  waiting_sleep ... waiting_trigger
                                   └──────────┬──────────┘
                                              │ saveEntry()
                                              ▼
                                   DailyEntry (per chat+date) ──► BotApi::sendMessage()
                                                                              │
                                                                              ├─► bale: https://tapi.bale.ai/botTOKEN/
                                                                              └─► telegram: https://tm.factorland.ir/telegram-proxy.php → api.telegram.org
```

## مرتبط

- `docs/bot.md` — دستورات و فلو مرحله‌ای
- `docs/database.md` — جداول و ایندکس‌ها
- `docs/deployment.md` — نصب، Apache، SSL، وب‌هوک
- `docs/connect2server.md` — SSH و مسیر سرور
- `README.md` — خلاصه و راهنمای سریع
