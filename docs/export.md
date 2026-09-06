# MyDaily — خروجی با تاریخ شمسی

همه‌ی خروجی‌ها تاریخ را **هم میلادی (`Y-m-d`) هم شمسی (`Y/m/d` فارسی)** می‌دهند — تاریخ شمسی با `App\Helpers\ShamsiDateHelper` (کپی از `E:\factorland_localhost\app\Helpers\ShamsiDateHelper.php`) و کتابخانه‌ی `morilog/jalali` ساخته می‌شود.

```
Helper:  App\Helpers\ShamsiDateHelper::dateOnly()        → 1405/06/15
         ::dateWithDay()       → یکشنبه 15 شهریور 1405
         ::dateWithMonth()     → 15 شهریور 1405
         ::fullDateTime()      → 1405/06/15 15:14
         ::dateWithDay() etc.
Source:  app/Helpers/ShamsiDateHelper.php  (کپی factorland)
Dep:     morilog/jalali 3.5 + phpoffice/phpspreadsheet 5.9
```

## فرمت‌ها

| فرمت | قابل خواندن با | ستون‌های تاریخ شمسی | کجا |
|---|---|---|---|
| **Excel `.xlsx`** | Excel, Google Sheets, LibreOffice | `تاریخ شمسی` (`A`), `روز هفته` (`B` = `ShamsiDateHelper::dateWithDay`), `تاریخ میلادی` (`C`) | ربات `/export` + `GET /api/export?format=excel` + `php artisan daily:export --format=excel` |
| **CSV** | Excel + هر هوش مصنوعی (UTF-8 BOM) | همان ۳ ستون | `GET /api/export?format=csv` + `artisan --format=csv` |
| **JSON** | هوش مصنوعی (بهترین) | `date_shamsi`, `date_shamsi_with_day`, `date_shamsi_fancy`, `created_at_shamsi` در کنار `date_miladi` | `GET /api/export?format=json` + `artisan --format=json` + پیش‌نمایش در ربات بعد از ارسال Excel |

همه با `ExportService` ساخته می‌شوند:

```
app/Services/ExportService.php
  → getEntries(chat_id, platform, from, to)
  → toArrayWithShamsi()      — JSON برای AI
  → generateExcel()          — PhpSpreadsheet, RTL, هدر فارسی, فریز + فیلتر, تاریخ شمسی
  → generateCsv()            — BOM + ستون‌های فارسی
```

## ربات — `/export`

```
کاربر: /export  (یا 📊 خروجی / 📥 اکسل)
ربات: ⏳ در حال ساخت خروجی با تاریخ شمسی...
ربات: [فایل] mydaily-12345-telegram-2026-09-06.xlsx (7181 bytes)
      caption: 📊 خروجی MyDaily — تعداد 3 — بازه: جمعه 13 شهریور 1405 تا یکشنبه 15 شهریور 1405
               تاریخ‌ها شمسی + میلادی (ستون‌های A-C)
ربات: 🤖 نمونه JSON برای هوش مصنوعی (۲ ردیف اول):
      ```json
      {
        "date_miladi": "2026-09-04",
        "date_shamsi": "1405/06/13",
        "date_shamsi_with_day": "جمعه 13 شهریور 1405",
        "gym": "بله", "mood": 8, ...
      }
      ```
      کل JSON via API: /api/export?format=json
```

- خروجی **مجزا برای هر `chat_id + platform`** — `111/telegram` و `111/bale` دو فایل جدا (مثل `daily_entries`).
- اگر رکوردی نباشد: `هنوز هیچ ثبتی نداری`.
- فایل‌ها در `storage/app/exports/mydaily-{chat_id}-{platform}-Y-m-d.xlsx` ذخیره می‌شوند.

## API — `GET /api/export`

```
GET /api/export?format=json|excel|csv
    &chat_id=12345          # اگر ندهی → همه‌ی چت‌ها (ادمین)
    &platform=telegram      # telegram|bale (پیش‌فرض telegram)
    &from=2026-09-01         # میلادی Y-m-d (اختیاری)
    &to=2026-09-30           # میلادی Y-m-d (اختیاری)
```

نمونه‌ها:

```bash
# JSON (AI) — با تاریخ شمسی + میلادی
curl "https://daily.factorland.ir/api/export?format=json&chat_id=12345&platform=telegram"

# Excel — تاریخ شمسی در ستون A و B
curl -O "https://daily.factorland.ir/api/export?format=excel&chat_id=12345&platform=telegram"

# CSV
curl -O "https://daily.factorland.ir/api/export?format=csv&chat_id=12345&platform=telegram"

# همه‌ی چت‌ها (بدون chat_id)
curl "https://daily.factorland.ir/api/export?format=json&from=2026-09-01"
```

پاسخ JSON نمونه:

```json
{
  "ok": true,
  "count": 3,
  "generated_at": "2026-09-06T11:44:56+00:00",
  "generated_at_shamsi": "1405/06/15 15:14",
  "format": "json",
  "note": "تاریخ‌ها هم میلادی هم شمسی — ستون‌های date_miladi و date_shamsi",
  "data": [
    {
      "chat_id": "12345",
      "platform": "telegram",
      "date_miladi": "2026-09-04",
      "date_shamsi": "1405/06/13",
      "date_shamsi_with_day": "جمعه 13 شهریور 1405",
      "date_shamsi_fancy": "13 شهریور 1405",
      "sleep_time": "23:30",
      "wake_time": "07:00",
      "work_hours": 6,
      "gym": "بله",
      "gym_bool": true,
      "gaming_minutes": 30,
      "social": "بله",
      "social_bool": true,
      "mood": 8,
      "emotional_trigger": "تست",
      "created_at": "2026-09-06T11:44:22+00:00",
      "created_at_shamsi": "1405/06/15 15:14"
    }
  ]
}
```

Excel نمونه (هدر فارسی، RTL):

| تاریخ شمسی | روز هفته | تاریخ میلادی | ساعت خواب | ... | محرک |
|---|---|---|---|---|---|
| 1405/06/13 | جمعه 13 شهریور 1405 | 2026-09-04 | 23:30 | ... | تست |

- هدر خاکستری تیره + فونت سفید، فریز سطر ۱، فیلتر خودکار، ستون‌ها `autoSize`، ستون `K` (محرک) عریض‌تر.
- فایل `UTF-8` و قابل باز شدن در Excel بدون به‌هم‌ریختگی فارسی.

## Artisan — `php artisan daily:export`

```bash
php artisan daily:export --chat_id=12345 --platform=telegram --format=excel
# → Excel saved to: storage/app/exports/mydaily-12345-2026-09-06_...xlsx

php artisan daily:export --chat_id=12345 --platform=telegram --format=json
# → JSON چاپ می‌شود (یا --output=/tmp/out.json)

php artisan daily:export --chat_id=12345 --platform=telegram --format=csv --output=/tmp/mydaily.csv

php artisan daily:export --format=json --from=2026-09-01 --to=2026-09-30 > all.json
# بدون chat_id → همه‌ی چت‌ها
```

## نکات

- تاریخ شمسی با `ShamsiDateHelper` و `Jalalian::forge(Carbon::parse($date))` ساخته می‌شود — همان هلپر factorland.
- خروجی‌ها `chat_id + platform` را جدا نگه می‌دارند — `111/telegram` و `111/bale` تداخل ندارند.
- فایل‌های Excel در `storage/app/exports/` می‌مانند (تمیزکاری دستی). برای دانلود API، `->deleteFileAfterSend(false)` است — اگر خواستی خودت پاک کن.
- برای هوش مصنوعی JSON بهترین است (`date_shamsi` + `date_miladi` + `gym_bool/social_bool` برای تحلیل عددی).
