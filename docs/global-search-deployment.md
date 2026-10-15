# راهنمای انتشار و بازگشتِ Global Search — نسخهٔ 1.0.5

این راهنما برای به‌روزرسانیِ سرورِ شرکت (`C:\Project\Dashboard_New`) از نسخهٔ 1.0.2 به **1.0.5** است.
هیچ رمز یا مقدارِ محرمانه‌ای در این فایل یا در Git نیست؛ همهٔ مقادیرِ حساس فقط در `.env` همان سرور قرار می‌گیرند.

## ۱. اجزایِ قابلیت

| جزء | محل | توضیح |
|---|---|---|
| کدِ PHP | `app/Services/GlobalSearch`، `GlobalSearchController`، دو Middleware، `config/global_search.php` | مسیرِ `POST /global-search` (با CSRF) |
| UI | `resources/js/Components/GlobalSearch/*`، `Header.tsx`، Build در `public/build` (در Git) | نیاز به `npm run build` رویِ سرور **ندارد** |
| اتصالِ SQL | اتصالِ `sqlsrv_search` در `config/database.php` (همان سرور/دیتابیسِ `sqlsrv`، بدونِ Pooling) | از همان `DB_*` در `.env` استفاده می‌کند |
| دیتابیس | `sql/085_global_search_procedures.sql`، `sql/086_global_search_crm_address_location.sql` | ۱ تابع + ۴ رویه؛ فقط اشیایِ جدید، بدونِ تغییرِ جدول یا داده |
| Composer | `predis/predis` 2.4.1 | کلاینتِ Redis برایِ Rate Limit |
| Rate Limit | سرویسِ `gs-redis` در `docker-compose.yml` (پروفایلِ `globalsearch`) + `docker/gs-redis/redis.conf` | Redisِ **مستقل** از Redisِ اصلی؛ اتمیک و fail-closed |

## ۲. پیش‌نیازها (پیش از روزِ انتشار بررسی شود)

1. **SQL Server 2017 یا بالاتر** (پچِ 086 از `CONCAT_WS` و پچِ 085 از `CREATE OR ALTER` استفاده می‌کند):
   `SELECT SERVERPROPERTY('ProductVersion');` → نسخهٔ اصلی باید ۱۴ یا بیشتر باشد.
2. پچ‌هایِ قبلیِ موجود در Git تا `079` رویِ دیتابیسِ شرکت اجرا شده باشند (`php artisan db:patch --status`).
   پچ‌هایِ 085/086 به جدول‌هایِ CRM، پیام‌ها، پروژه‌ها و Workflow (پچ‌هایِ 014، 039، 046، 055، 056 و …) و مجوزهایِ `CRM_VIEW` و `WORKFLOW_VIEW` وابسته‌اند.
3. حسابِ اتصالِ برنامه به SQL (همان `DB_USERNAME`) اجازهٔ ساختِ رویه و اجرایِ آن را داشته باشد (پچ‌هایِ پروژه هیچ `GRANT` جداگانه‌ای ندارند).
4. Docker Compose با همین `docker-compose.yml` (سرویس‌هایِ `app` و `nginx`). اگر سرورِ شرکت به‌جایِ Docker با IIS اجرا می‌شود، بخشِ ۸ را ببینید.
5. دسترسیِ اینترنت برایِ `composer` (Packagist/GitHub) و Docker Hub (ایمیج `redis:7.4.11-alpine`). اگر سرور اینترنت ندارد، بخشِ ۹ (انتقالِ آفلاین).

## ۳. بکاپ (اجباری؛ بدونِ بکاپِ Verifyشده ادامه ندهید)

```sql
-- COPY_ONLY: زنجیرهٔ بکاپِ جاری را تغییر نمی‌دهد
BACKUP DATABASE [Dashboard_DB] TO DISK = N'<مسیرِ امن خارج از پوشهٔ پروژه>\Dashboard_DB_pre_1.0.5.bak'
  WITH COPY_ONLY, CHECKSUM, COMPRESSION, INIT;
RESTORE VERIFYONLY FROM DISK = N'<همان مسیر>\Dashboard_DB_pre_1.0.5.bak' WITH CHECKSUM;
```
- از کلِ پوشهٔ پروژه (شاملِ `.env`) یک کپی خارج از پوشهٔ پروژه و خارج از مسیرهایِ وب بگیرید.
- از `vendor`ِ داخلِ کانتینر هم کپی بگیرید: `docker cp dashboard_app:/var/www/html/vendor <مسیرِ بکاپ>\container_vendor`.

## ۴. ترتیبِ انتشار

> قابلیت تا گامِ ۷ **خاموش** می‌ماند؛ کدِ جدید بدونِ کلیدهایِ `.env` دقیقاً مثلِ قبل رفتار می‌کند.

1. **کد** (در `C:\Project\Dashboard_New`):
   ```powershell
   git fetch origin
   git status            # Working Tree باید تمیز باشد؛ تغییرِ محلی را بدونِ بررسی Overwrite نکنید
   git merge --ff-only origin/main
   ```
   `public/build` در Git است؛ **Vite را اجرا نکنید** و `public/hot` نسازید.
2. **Composer داخلِ کانتینر** (vendor یک Volumeِ جداست و با `git pull` به‌روز نمی‌شود):
   ```powershell
   docker compose exec -w /var/www/html app composer install --dry-run --no-scripts
   # انتظار: «1 install, 0 updates, 0 removals — predis/predis (v2.4.1)». اگر حذف/به‌روزرسانیِ دیگری دیدید، متوقف شوید.
   docker compose exec -w /var/www/html app composer install --no-scripts
   ```
   (اگر `vendor`ِ کانتینر با `--no-dev` نصب شده، همین فلگ را هم اضافه کنید تا پکیجی حذف/اضافه نشود؛ ملاک خروجیِ dry-run است.)
3. **دیتابیس** — فقط با ابزارِ پروژه:
   ```powershell
   docker compose exec app php artisan db:patch --status   # 085 و 086 باید «Pending» باشند
   docker compose exec app php artisan db:patch
   ```
   - **گامِ توقف:** پیش از اجرا، خروجیِ `--status` باید **فقط** `085_global_search_procedures.sql` و `086_global_search_crm_address_location.sql` را «Pending» نشان دهد.
     اگر پچِ قدیمی‌تری هم Pending است (مثلاً به این دلیل که قبلاً دستی اجرا شده و در `SysPatches` ثبت نشده)، `db:patch` آن را **هم** اجرا می‌کند؛
     در این حالت اجرا نکنید و ابتدا وضعیتِ همان پچ‌ها را بررسی کنید (رویِ دیتابیسِ توسعه چنین موردی برایِ 005، 031، 033، 034، 035، 045 و 079 دیده شده است).
   - `db:patch` فایل‌ها را به ترتیبِ نام اجرا می‌کند، اجراشده‌ها را از `SysPatches` تشخیص می‌دهد و **دوباره اجرا نمی‌کند**؛ با اولین خطا متوقف می‌شود.
   - هر دو پچ `CREATE OR ALTER` هستند؛ اجرایِ مجددِ «085 سپس 086» بدونِ خطا و با همان نتیجهٔ نهایی است (آزموده‌شده).
   - **هرگز 085 را به‌تنهایی پس از 086 دستی اجرا نکنید** (رویهٔ `sp_Gs_Search` به نسخهٔ پیش از اصلاحِ جست‌وجویِ آدرس برمی‌گردد). اگر اجرایِ دستی لازم شد، همیشه 085 و سپس 086.
   - بررسی:
     ```sql
     SELECT name, type_desc FROM sys.objects
     WHERE name IN ('fn_Gs_Normalize','sp_Gs_Search','sp_Gs_SearchMessages','sp_Gs_SearchProjects','sp_Gs_SearchProcesses');
     SELECT PatchName, Status FROM dbo.SysPatches WHERE PatchName LIKE '08[56]%';
     ```
4. **بررسیِ سلامتِ پایه (قابلیت هنوز خاموش):** صفحهٔ ورود 200، نسخهٔ نمایش‌داده‌شده 1.0.5، ورود/خروج و صفحه‌هایِ اصلی عادی؛ پنجرهٔ Ctrl+K فقط منوها را جست‌وجو می‌کند.
5. **Redis مستقلِ Rate Limit**:
   - یک رمزِ قویِ تصادفی بسازید (فقط `A-Z a-z 0-9 _ -`، حداقل ۳۲ نویسه)، مثلاً:
     ```powershell
     $b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
     [Convert]::ToBase64String($b).TrimEnd('=').Replace('+','-').Replace('/','_')
     ```
   - در `.env` سرور (نه در Git):
     ```
     GS_RATELIMIT_REDIS_HOST=gs-redis
     GS_RATELIMIT_REDIS_PORT=6379
     GS_RATELIMIT_REDIS_PASSWORD=<رمزِ ساخته‌شده>
     ```
   - راه‌اندازی (پایدار؛ با `restart: unless-stopped` پس از Restartِ سرور خودکار بالا می‌آید):
     ```powershell
     docker compose --profile globalsearch up -d gs-redis
     docker inspect dashboard_gs_redis --format "{{.State.Health.Status}}"   # healthy
     ```
   - Redisِ اصلیِ برنامه دست نمی‌خورد. `gs-redis` فقط رویِ شبکهٔ داخلیِ Compose و `127.0.0.1:6392` در دسترس است؛ ACL فقط کلیدهایِ `gs:rl:*` و دستورهایِ لازمِ Rate Limit را اجازه می‌دهد؛ ذخیره‌سازی ندارد.
6. **بررسیِ اتصالِ برنامه به Redis** (بدونِ چاپِ رمز):
   ```powershell
   docker compose exec app php -r "require 'vendor/autoload.php'; `$a = require 'bootstrap/app.php'; `$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); var_dump(`$a->make(App\Services\GlobalSearch\RateLimit\SearchRateLimiter::class)->attempt('deploy-check', 20, 10)->allowed);"
   # انتظار: bool(true)
   ```
7. **فعال‌سازی** — در `.env`:
   ```
   GLOBAL_SEARCH_ENABLED=true
   GLOBAL_SEARCH_CRM_ENABLED=true
   GLOBAL_SEARCH_MESSAGES_ENABLED=true
   GLOBAL_SEARCH_PROJECTS_ENABLED=true
   GLOBAL_SEARCH_PROCESSES_ENABLED=true
   ```
   فقط مقدارِ دقیقِ `true` روشن می‌کند. اگر رویِ سرور `php artisan config:cache` استفاده می‌شود، پس از تغییرِ `.env` دوباره اجرا شود (در این پروژه Config Cache وجود ندارد و تغییر از درخواستِ بعدی اعمال می‌شود).

## ۵. بررسیِ سلامت پس از فعال‌سازی

- صفحهٔ ورود حاویِ `"globalSearch":true` است.
- با یک حسابِ دارایِ `CRM_VIEW` و `WORKFLOW_VIEW`: Ctrl+K → «تهران» یا نامِ یک طرف‌حساب → گروه‌هایِ طرف‌حساب، نامه‌ها، پروژه‌ها و فرایندها.
- با حسابِ بدونِ `CRM_VIEW`: گروهِ CRM نمایش داده **نمی‌شود**؛ نامه‌ها فقط برایِ فرستنده/گیرنده/رونوشت؛ پروژه‌ها فقط برایِ سازنده/عضوِ فعال.
- درخواستِ بدونِ ورود: 401/419. بیش از ۲۰ جست‌وجو در ۱۰ ثانیه: 429.
- قطعِ `gs-redis`: جست‌وجو با 503 متوقف می‌شود و بقیهٔ سایت سالم می‌ماند (fail-closed).
- لاگ (`storage/logs/laravel.log`) متنِ جست‌وجو را ثبت نمی‌کند؛ هشدارهایِ `global_search.*` فقط نامِ کلاسِ خطا دارند.

## ۶. خاموش‌کردنِ سریع

در `.env`: `GLOBAL_SEARCH_ENABLED=false` — از درخواستِ بعدی، مسیر 404 می‌شود و Ctrl+K فقط منوها را جست‌وجو می‌کند. نیازی به Restart، Build یا تغییرِ دیتابیس نیست.

## ۷. بازگشت (Rollback)

1. `GLOBAL_SEARCH_ENABLED=false` (کافی برایِ توقفِ کاملِ قابلیت).
2. در صورتِ نیاز به بازگشتِ کد: `git checkout <commitِ نسخهٔ قبل>` (پیش از 1.0.5: `3179c37`) یا بازگرداندنِ کپیِ پوشه از بکاپ، سپس `composer install` داخلِ کانتینر با dry-run.
3. اشیایِ دیتابیسِ 085/086 فقط رویه/تابعِ جدید هستند و بدونِ قابلیت استفاده نمی‌شوند؛ نگه‌داشتنِ آن‌ها بی‌خطر است. بازگرداندنِ کاملِ دیتابیس از بکاپ فقط آخرین راه‌حل است (داده‌هایِ پس از بکاپ از دست می‌رود).
4. `gs-redis` را می‌توان متوقف کرد: `docker stop dashboard_gs_redis` (داده‌ای ندارد).

## ۸. اگر سرورِ شرکت با IIS اجرا می‌شود (نه Docker)

- `gs-redis` همچنان با Docker اجرا می‌شود و فقط رویِ `127.0.0.1:6392` منتشر است؛ در `.env`: `GS_RATELIMIT_REDIS_HOST=127.0.0.1` و `GS_RATELIMIT_REDIS_PORT=6392`.
- Composer با PHPِ میزبان: `php composer.phar install --dry-run --no-scripts` سپس بدونِ `--dry-run`؛ `db:patch` با `php artisan db:patch`.
- PHPِ IIS باید `pdo_sqlsrv` داشته باشد. اگر IIS و Docker هر دو از یک `.env` استفاده کنند، فقط یکی از دو مقدارِ Host قابلِ استفاده است؛ برایِ سرویس‌دهیِ دیگری جست‌وجو با 503 متوقف می‌شود (امن).

## ۹. انتقالِ آفلاین (اگر سرور اینترنت ندارد)

- ایمیج: رویِ ماشینِ توسعه `docker save redis:7.4.11-alpine -o redis-7.4.11-alpine.tar` و رویِ سرور `docker load -i redis-7.4.11-alpine.tar`.
- Predis: پوشهٔ `vendor/predis` و فایل‌هایِ `vendor/composer/*` از محیطی با همان `composer.lock`، یا راه‌اندازیِ یک Composer Cacheِ محلی. (پیش‌نهاد: پیش از روزِ انتشار دسترسیِ `composer` رویِ سرور آزموده شود.)

## ۱۰. نکته‌هایِ امنیتی

- `.env`، رمزِ Redis، فایل‌هایِ بکاپ و لاگ‌ها هرگز وارد Git نشوند.
- رمزِ Redis در متغیرِ محیطیِ کانتینر قرار می‌گیرد و برایِ کسی که دسترسیِ `docker inspect` دارد قابلِ مشاهده است؛ دسترسیِ Docker رویِ سرور را محدود نگه دارید.
- آزمون‌هایِ خودکار (`phpunit -c phpunit.globalsearch.xml`) فقط رویِ LocalDBِ ایزولهٔ توسعه اجرا می‌شوند و نباید رویِ سرورِ شرکت اجرا شوند.
