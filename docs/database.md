# MyDaily — دیتابیس

## اتصال

- **لوکال:** `sqlite` — `database/database.sqlite` (`DB_CONNECTION=sqlite`, `DB_DATABASE=E:/mydayli/database/database.sqlite`), نیاز به `pdo_sqlite` + `sqlite3` در `php.ini`
- **سرور:** `MySQL 8` — `DB_HOST=127.0.0.1:3306`, `DB_DATABASE=mydaily`, `DB_USERNAME=root`, `DB_PASSWORD=@Vbfc344334` (مشترک با `factorchi`)

```bash
# ساخت DB روی سرور (اگر نبود)
mysql -u root -p'@Vbfc344334' -e 'CREATE DATABASE IF NOT EXISTS mydaily CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
```

## مایگریشن‌ها

```
database/migrations/
├─ 0001_01_01_000000_create_users_table.php
├─ 0001_01_01_000001_create_cache_table.php
├─ 0001_01_01_000002_create_jobs_table.php
├─ 2026_09_06_110824_create_personal_access_tokens_table.php  # sanctum
├─ 2026_09_06_110834_create_daily_entries_table.php
└─ 2026_09_06_110834_create_bot_states_table.php
```

اجرای:

```bash
php artisan migrate              # لوکال
php artisan migrate --force      # سرور
php artisan migrate:status
```

## جدول `daily_entries`

یک ردیف در روز به ازای هر `chat_id + platform`.

```sql
CREATE TABLE `daily_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(255) NOT NULL,
  `platform` varchar(255) NOT NULL DEFAULT 'telegram', -- telegram | bale
  `entry_date` date NOT NULL,
  `sleep_time` varchar(255) DEFAULT NULL,   -- HH:MM e.g. 23:30
  `wake_time` varchar(255) DEFAULT NULL,    -- HH:MM e.g. 07:00
  `work_hours` decimal(4,1) DEFAULT NULL,   -- 0.0 - 16.0
  `gym` tinyint(1) DEFAULT NULL,            -- 0/1
  `gaming_minutes` int DEFAULT '0',         -- 0-1440
  `social` tinyint(1) DEFAULT NULL,         -- 0/1
  `mood` tinyint DEFAULT NULL,              -- 1-10
  `emotional_trigger` text DEFAULT NULL,    -- ≤500 char, nullable
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `daily_unique_per_day` (`chat_id`,`platform`,`entry_date`),
  KEY `daily_entries_chat_id_platform_index` (`chat_id`,`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- **کلید یکتا** `daily_unique_per_day` تضمین می‌کند هر چت روزی یک رکورد دارد — لاگ دوم overwrite است (`DailyBotService::saveEntry` با `whereDate` چک و `update` می‌کند).
- ایزولیشن: `chat_id=111/platform=telegram` و `chat_id=111/platform=bale` دو ردیف جدا؛ `chat_id=111` و `222` روی یک پلتفرم هم جدا.
- مدل: `app/Models/DailyEntry.php` — `fillable` همه‌ی فیلدها، `casts: entry_date→date, gym/social→boolean, work_hours→decimal:1, mood/gaming_minutes→integer`

نمونه ردیف:

```json
{
  "id": 1,
  "chat_id": "12345",
  "platform": "telegram",
  "entry_date": "2026-09-06",
  "sleep_time": "23:30",
  "wake_time": "07:00",
  "work_hours": "6.0",
  "gym": true,
  "gaming_minutes": 45,
  "social": true,
  "mood": 8,
  "emotional_trigger": "استرس کاری"
}
```

کوئری‌های پرکاربرد:

```sql
-- امروز همین چت
SELECT * FROM daily_entries WHERE chat_id='123' AND platform='telegram' AND entry_date = CURDATE();
-- ۷ روز گذشته
SELECT * FROM daily_entries WHERE chat_id='123' AND platform='telegram' AND entry_date >= CURDATE() - INTERVAL 6 DAY ORDER BY entry_date;
```

## جدول `bot_states`

وضعیت فلو ۸ مرحله‌ای هر چت.

```sql
CREATE TABLE `bot_states` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(255) NOT NULL,
  `platform` varchar(255) NOT NULL DEFAULT 'telegram',
  `state` varchar(255) DEFAULT NULL, -- waiting_sleep, waiting_wake, waiting_work, waiting_gym, waiting_gaming, waiting_social, waiting_mood, waiting_trigger
  `data` json DEFAULT NULL,          -- {"sleep_time":"23:30", ...} تا مرحله‌ی فعلی
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bot_state_unique` (`chat_id`,`platform`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- هر چت یک ردیف دارد؛ شروع `/log` → `waiting_sleep` با `data={}`, هر مرحله `data` پر می‌شود، پایان → ردیف حذف (`clearState`).
- `/cancel` هم ردیف را حذف می‌کند.
- مدل: `app/Models/BotState.php` — `casts: data→array`

## نکات

- روی سرور `pdo_sqlite` فعال نیست — MySQL اجباری است؛ لوکال هر دو کار می‌کند.
- `entry_date` از نوع `date` است ولی Eloquent آن را با `date` cast به `Carbon` با `00:00:00` ذخیره می‌کند؛ کوئری‌ها حتماً `whereDate` استفاده می‌کنند تا `2026-09-06` با `2026-09-06 00:00:00` مچ شود.
- بک‌آپ ساده: `mysqldump -u root -p'@Vbfc344334' mydaily > mydaily.sql`

