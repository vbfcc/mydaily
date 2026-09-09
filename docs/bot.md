# MyDaily — راهنمای ربات

## پلتفرم‌ها

- **Telegram** — از طریق پراکسی ایران (`TELEGRAM_PROXY_URL=https://tm.factorland.ir/telegram-proxy.php`) چون `api.telegram.org` فیلتر است. وب‌هوک ورودی از طریق رله‌ی `https://tm.factorland.ir/daily-webhook-relay.php`.
- **Bale** — مستقیم (`https://tapi.bale.ai/botTOKEN/`) — در ایران فیلتر نیست، نیازی به پراکسی/رله ندارد.

> سرویس `app/Services/BotApi.php` دقیقاً منطق `E:\factorland_localhost\app\Services\TelegramApi\TelegramApi.php` را کپی کرده: `getCurrentPlatform()`، `getProxyUrl()`، `sanitizeBalePayload()`، `sendViaProxyWithRetry()`.

## دستورات

| دستور | توضیح |
|---|---|
| `/start` | معرفی + کیبورد اصلی |
| `/log` یا `📝 ثبت امروز` | شروع ثبت ۸ مرحله‌ای — اگر دیروز ثبت نشده باشد اول می‌پرسد «دیروز/امروز» |
| `/today` یا `📊 امروز` | نمایش رکورد امروز همین چت |
| `/week` یا `📅 هفته` | نمایش ۷ روز گذشته همین چت |
| `/export` یا `📊 خروجی` | خروجی **هر دو فرمت** Excel + JSON (شمسی+میلادی). ` /export json` فقط JSON، ` /export excel` فقط Excel |
| `📄 JSON` / `📊 اکسل` | دکمه‌های میانبر فرمت تکی |
| `/cancel` | لغو فلو جاری (هر مرحله) |
| `/help` | مثل `/start` |
| `/skip` | فقط در مرحله‌ی آخر — رد کردن محرک احساسی |

کیبورد دائمی بعد از `/start` و بعد از هر ثبت:

```
[ 📝 ثبت امروز | 📊 امروز ]
[ 📅 هفته      | 📊 خروجی ]
[ 📄 JSON      | 📊 اکسل ]
[ /help                  ]
```
- `📊 خروجی` / `/export` → **هر دو فایل** (`mydaily-*.xlsx` + `mydaily-*.json`) با کپشن شمسی
- `📄 JSON` / `/export json` → فقط `*.json` (آرایه `toArrayWithShamsi` — `date_shamsi`/`date_miladi` ready for AI)
- `📊 اکسل` / `/export excel` → فقط `*.xlsx` ( `ExportService::generateExcel` )
- API هم هست: `GET /api/export?format=json|excel|csv&chat_id=...&platform=telegram` (`ExportController`)

کیبوردهای موقت در فلو:

- مرحله‌ی انتخاب روز (فقط وقتی دیروز خالی است) → `[ دیروز | امروز ]`
- مرحله‌ی Gym / Social → `[ بله | خیر ]`

## فلو ۸ مرحله‌ای (`/log`)

استیت‌ها در `DailyBotService.php:26` و جدول `bot_states`:

```
(اختیاری) waiting_date_choice → waiting_sleep → waiting_wake → waiting_work → waiting_gym → waiting_gaming → waiting_social → waiting_mood → waiting_trigger → save
```

- `waiting_date_choice` فقط وقتی نمایش داده می‌شود که `daily_entries` برای `yesterday` خالی باشد. کاربر «دیروز» یا «امروز» را انتخاب می‌کند؛ `entry_date` در `bot_states.data` ذخیره و بقیه‌ی فلو روی همان تاریخ ذخیره می‌شود. اگر دیروز قبلاً پر باشد، این مرحله skip می‌شود و مستقیم `waiting_sleep` با `entry_date=today`.
- بعد از هر جواب معتبر، سوال بعدی بلافاصله ارسال می‌شود (تکمیل پشت‌سرهم) — نیاز به `/log` دوباره نیست.

| مرحله | سوال ربات | ولیدیشن (منعطف) | نمونه ورودی |
|---|---|---|---|
| 0 `waiting_date_choice` | `دیروز (۱۴ شهریور) رو ثبت نکردی. کدوم رو ثبت کنی؟ [دیروز|امروز]` | `دیروز/امروز` (contains) | `دیروز` یا `امروز` |
| 1 `waiting_sleep` | `۱/۸ — ساعت خوابت کی بود؟ مثال 23:30 یا 6 صبح یا 7 عصر` | منعطف → نرمال `HH:MM` | `23:30` / `6 صبح` / `۶ صبح` / `6` / `06` / `23.30` |
| 2 `waiting_wake` | `۲/۸ — ساعت بیداریت؟ مثال 07:00 یا 6 صبح` | منعطف → `HH:MM` | `07:00` / `7 عصر` / `12 شب` (=00:00) / `12 ظهر` (=12:00) |
| 3 `waiting_work` | `۳/۸ — چند ساعت کار مفید؟ 0-16` | `0 <= x <= 16` عدد | `6` یا `4.5` |
| 4 `waiting_gym` | `۴/۸ — باشگاه رفتی؟` | `بله/خیر`, `yes/no`, `آره/نه`, `1/0` | `بله` |
| 5 `waiting_gaming` | `۵/۸ — چند دقیقه گیم؟ 0-1440` | `0-1440` int | `45` |
| 6 `waiting_social` | `۶/۸ — تعامل اجتماعی داشتی؟` | `بله/خیر` | `خیر` |
| 7 `waiting_mood` | `۷/۸ — حالت از ۱۰ چند بود؟ 1-10` | `1-10` int | `8` |
| 8 `waiting_trigger` | `۸/۸ — محرک حالت چی بود؟ /skip اگر نبود` | `text ≤ 500` یا `/skip` | `استرس کاری` یا `/skip` |

ساعت منعطف (`parseFlexibleTime`): ارقام فارسی/عربی → انگلیسی، جداکننده‌های `:` `.` `/` `-`، کلمات `صبح/بامداد` (=AM)، `عصر/شب/ظهر/بعدازظهر` (=PM)، حالت `12 شب→00:00` و `12 ظهر→12:00`، ورودی تک‌عددی مثل `6` → `06:00`. دیتابیس همچنان `HH:MM` ذخیره می‌کند — بدون تغییر اسکیما.

هر خطای ولیدیشن، ربات همان مرحله را با پیام کوتاه دوباره می‌پرسد (مثلاً ساعت: «ساعت رو درست متوجه نشدم — مثلا 23:30 یا 6 صبح»). `/cancel` در هر مرحله استیت را پاک می‌کند.

### مثال مکالمه کامل — بدون جاماندن دیروز (فلو پشت‌سرهم)

```
کاربر: /log
ربات: ۱/۸ — ساعت خوابت کی بود؟ مثال 23:30 یا 6 صبح
کاربر: 6 صبح          ← منعطف: نرمال می‌شود 06:00 و بلافاصله سوال بعدی
ربات: ۲/۸ — ساعت بیداریت؟ مثال 07:00 یا 6 صبح
کاربر: 7 عصر          ← می‌شود 19:00
ربات: ۳/۸ — چند ساعت کار مفید کردی؟ ...
کاربر: 6
ربات: ۴/۸ — باشگاه رفتی؟ [بله|خیر]
کاربر: بله
ربات: ۵/۸ — چند دقیقه گیم زدی؟
کاربر: 45
ربات: ۶/۸ — تعامل اجتماعی داشتی؟ [بله|خیر]
کاربر: بله
ربات: ۷/۸ — حالت امروز از ۱۰ چند بود؟
کاربر: 8
ربات: ۸/۸ — محرک حالت چی بود؟
کاربر: استرس کاری
ربات: ✅ ثبت شد! (2026-09-06)
       😴 خواب: 23:30 → 07:00
       💼 کار مفید: 6.0 ساعت
       🏋️ باشگاه: بله ✅
       🎮 گیم: 45 دقیقه
       👥 اجتماعی: بله ✅
       😊 حال: 8/10
       💭 محرک: استرس کاری
```

### مثال با «دیروز» (وقتی دیروز جامانده)

```
کاربر: /log
ربات: دیروز (۱۴ شهریور) رو ثبت نکردی. کدوم رو ثبت کنی؟ [دیروز|امروز]
کاربر: دیروز
ربات: ۱/۸ — ساعت خوابت کی بود؟ مثال 23:30 یا 6 صبح   ← از اینجا فلو عادی، ذخیره روی 2026-09-05
... (۶/۷/۸) ...
ربات: ✅ ثبت شد! (2026-09-05)   ← تاریخ دیروز
```

## ذخیره‌سازی

- `DailyBotService::saveEntry()` با `whereDate(entry_date, targetDate)` چک می‌کند (`targetDate = data['entry_date'] ?? today`؛ برای دیروز = yesterday). اگر ردیف همان تاریخ برای همان `chat_id+platform` وجود داشت `update`، وگرنه `create`. پس overwrite روزانه است، بدون تغییر اسکیما.
- نمایش بعد از ذخیره با `formatEntry()` و کیبورد اصلی.

## ایزولیشن هر چت

```
chat_id=111/platform=telegram  ──► ردیف جدا
chat_id=222/platform=telegram  ──► ردیف جدا
chat_id=111/platform=bale      ──► ردیف جدا (حتی اگر chat_id عددی یکسان باشد)
```

- جدول `daily_entries` کلید `UNIQUE(chat_id, platform, entry_date)` دارد.
- جدول `bot_states` کلید `UNIQUE(chat_id, platform)` دارد — دو کاربر همزمان در مراحل مختلف باشند تداخل نمی‌کنند.
- تست شده با `FakeApi` — سه چت همزمان، هر کدام `/today` مقدار خودش را دید.

## وب‌هوک‌ها

```
POST https://daily.factorland.ir/api/webhook/telegram  → BotWebhookController@telegram
POST https://daily.factorland.ir/api/webhook/bale      → BotWebhookController@bale
```

تلگرام روی پروداکشن حتماً باید روی رله ست شود (نه مستقیم):

```bash
php artisan bot:webhook telegram set --url=https://tm.factorland.ir/daily-webhook-relay.php
php artisan bot:webhook bale set --url=https://daily.factorland.ir/api/webhook/bale
```

## نکات پیاده‌سازی

- توکن‌ها با `env('TELEGRAM_BOT_TOKEN')` خوانده می‌شوند — هرگز `php artisan config:cache` نزن (مثل هشدار factorland).
- Bale متن HTML را `strip_tags` می‌کند (`BotApi::sanitizeBalePayload`).
- تلگرام با پراکسی ۲ بار تلاش + فالبک direct (`sendViaProxyWithRetry`).
- لاگ‌ها: `storage/logs/laravel.log` + روی VPS `proxy.log` / `daily-webhook-relay.log`.
