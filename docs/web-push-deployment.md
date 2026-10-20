# راهنمای راه‌اندازیِ اعلانِ گوشی و مرورگر (Web Push) — سرورِ ویندوزیِ مشتری

**محیطِ هدف:** ویندوز؛ Laravel با **Nginx** و **PHP FastCGI** به‌صورتِ سرویسِ ویندوز؛ مسیرِ برنامه `C:\Project\Dashboard_New`. **IIS استفاده نمی‌شود و تغییر نمی‌کند.** Docker لازم نیست.

قواعدِ این راهنما (مثلِ `docs/global-search-deployment.md`):
- **[فقط‌خواندنی]** چیزی را تغییر نمی‌دهد؛ **[تغییردهنده]** فقط پس از بکاپ و در پنجرهٔ تغییر.
- هر جا «**توقف**» آمده، ادامه ندهید.
- هیچ رمز یا کلیدی در این فایل یا Git نیست؛ کلیدهایِ VAPID فقط در `.env` همان سرور. **هرگز `.env` را نمایش ندهید یا جایی نفرستید.**
- `<PHP_DIR>` = پوشهٔ PHP همان سرور (همان PHPِ سرویسِ FastCGI)، `<SITE_URL>` = نشانیِ httpsِ سایت (مثلاً `https://crm.example.com`).
- همهٔ دستورها در PowerShell با دسترسیِ Administrator.

## ۱. خلاصه

اعلانِ پیام، ارجاعِ وظیفه، یادآوری و «ارسال اعلان عمومی» از صفِ `PushOutbox` با یک **زمان‌بندِ دقیقه‌ای** (`php artisan schedule:run` در Task Schedulerِ ویندوز) به سرویسِ Pushِ هر مرورگر (Google، Microsoft، Mozilla، Apple) فرستاده می‌شود. بدونِ زمان‌بند هیچ اعلانی ارسال نمی‌شود؛ بقیهٔ سایت عادی کار می‌کند.

| جزء | محل |
|---|---|
| جدول‌ها و رویه‌ها | پچ‌هایِ `103` تا `106` (`PushState`، `PushSubscriptions`، `PushOutbox`، `PushDeliveries`، `PushBroadcasts`، `sp_Push_*`) |
| دسترسی و منو | `PUSH_BROADCAST` و منویِ «ارسال اعلان عمومی» زیرِ «مدیریت سیستم» (برایِ نقشِ ادمین؛ پچِ `105`) |
| کتابخانه | `minishlink/web-push` 11 (در `composer.lock`) |
| تنظیمات | `.env`: `PUSH_ENABLED`، `VAPID_SUBJECT`، `VAPID_PUBLIC_KEY`، `VAPID_PRIVATE_KEY` |
| لاگ | `storage\logs\push-YYYY-MM-DD.log` |

## ۲. پیش‌نیازها — همه [فقط‌خواندنی]؛ نبودِ هرکدام = توقف

### ۲-۱. HTTPS با گواهیِ معتبر (مهم‌ترین شرط)
مرورگرها اعلان را **فقط رویِ نشانیِ امن** اجازه می‌دهند. کاربرانی که سایت را با `http://IP:8080` باز می‌کنند **نمی‌توانند** اعلان را فعال کنند (برنامه پنجرهٔ فعال‌سازی را اصلاً نشان نمی‌دهد). iPhone/iPad علاوه بر آن فقط وب‌اپِ اضافه‌شده به Home Screen (iOS/iPadOS 16.4+) را می‌پذیرد.
- اگر مشتری نشانیِ httpsِ معتبر (دامنه + گواهیِ مورداعتمادِ مرورگر) ندارد: **توقف**؛ اول HTTPS راه‌اندازی شود.

### ۲-۲. پچ‌هایِ دیتابیس و Composer
پچ‌هایِ `103_push_notifications`، `104_push_subscribed_at_tolerance`، `105_push_broadcast`، `106_push_broadcast_claim_priority` طبقِ روالِ معمولِ انتشار (با بکاپ و گام‌هایِ توقفِ همان روال) اجرا شده باشند و `composer install` انجام شده باشد. بررسی:
```powershell
Set-Location C:\Project\Dashboard_New
& "<PHP_DIR>\php.exe" -r "require 'vendor/autoload.php'; `$a = require 'bootstrap/app.php'; `$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo DB::table('sys.tables')->where('name', 'like', 'Push%')->count(), ' tables, ', DB::table('sys.procedures')->where('name', 'like', 'sp[_]Push[_]%')->count(), ' procs, minishlink=', class_exists('Minishlink\WebPush\WebPush') ? 'yes' : 'NO', PHP_EOL;"
```
انتظار: `6 tables, 20 procs, minishlink=yes`.

### ۲-۳. افزونه‌هایِ PHP
```powershell
& "<PHP_DIR>\php.exe" -r "foreach (['openssl','curl','mbstring','json'] as `$e) echo `$e, '=', extension_loaded(`$e) ? 'ok' : 'MISSING', PHP_EOL;"
```
همه `ok`. (`gmp` لازم نیست.)

### ۲-۴. OpenSSLِ ویندوز — ساختِ کلیدِ EC (اجباری)
PHPِ ویندوز بدونِ مسیرِ `openssl.cnf` نمی‌تواند کلیدِ EC بسازد؛ کتابخانه برایِ **رمزنگاریِ هر اعلان** یک کلیدِ EC می‌سازد، پس بدونِ این تنظیم **همهٔ ارسال‌ها شکست می‌خورند** («Unable to create the local key»).
```powershell
Test-Path "<PHP_DIR>\extras\ssl\openssl.cnf"
$env:OPENSSL_CONF = "<PHP_DIR>\extras\ssl\openssl.cnf"
& "<PHP_DIR>\php.exe" -r "var_dump(openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]]) !== false);"
```
انتظار: `True` و سپس `bool(true)`. (`$env:` فقط همین پنجرهٔ PowerShell را تغییر می‌دهد.) اگر `openssl.cnf` در آن مسیر نیست، فایلِ `openssl.cnf`ِ همان بستهٔ رسمیِ PHP را پیدا کنید؛ بدونِ `bool(true)` **توقف**.

### ۲-۵. گواهی‌هایِ CA برایِ HTTPSِ خروجیِ PHP (اجباری)
PHPِ ویندوز به‌طورِ پیش‌فرض فهرستِ CA ندارد و اتصال به سرویس‌هایِ Push با «unable to get local issuer certificate» رد می‌شود.
```powershell
& "<PHP_DIR>\php.exe" -r "echo 'curl.cainfo=', ini_get('curl.cainfo'), PHP_EOL; `$c = curl_init('https://fcm.googleapis.com/fcm/send'); curl_setopt_array(`$c, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => 10]); curl_exec(`$c); echo curl_getinfo(`$c, CURLINFO_HTTP_CODE), ' [', curl_error(`$c), ']', PHP_EOL;"
```
- انتظار: یک کدِ HTTP (مثلاً `404` یا `405`) و **خطایِ خالی** `[]`.
- اگر `0 [SSL certificate ... unable to get local issuer certificate]`: **[تغییردهنده]** فایلِ رسمیِ `cacert.pem` (از `https://curl.se/ca/cacert.pem`) را در `<PHP_DIR>\extras\ssl\cacert.pem` بگذارید و در `<PHP_DIR>\php.ini` تنظیم کنید:
  ```ini
  curl.cainfo = "<PHP_DIR>\extras\ssl\cacert.pem"
  openssl.cafile = "<PHP_DIR>\extras\ssl\cacert.pem"
  ```
  سپس سرویسِ PHP FastCGI را Restart کنید (قطعِ کوتاه) و آزمون را تکرار کنید.
- اگر `0 [... timed out / Could not resolve host]`: مسیرِ شبکه بسته است → بخشِ ۲-۶.

### ۲-۶. دسترسیِ شبکهٔ خروجی (HTTPS 443)
سرور باید به این میزبان‌ها روی پورتِ 443 وصل شود (برنامه به هیچ میزبانِ دیگری ارسال نمی‌کند):
`fcm.googleapis.com` (Chrome/Android/Brave/Opera/Samsung)، `*.notify.windows.com` (Edge)، `updates.push.services.mozilla.com` (Firefox)، `web.push.apple.com` (iPhone/iPad/Safari).
```powershell
'fcm.googleapis.com','web.push.apple.com','updates.push.services.mozilla.com' | ForEach-Object { "{0} -> {1}" -f $_, (Test-NetConnection $_ -Port 443 -WarningAction SilentlyContinue).TcpTestSucceeded }
```
همه `True`. اگر فایروال/پروکسیِ سازمانی دارید، `*.notify.windows.com` را هم مجاز کنید. **گوشی/مرورگرِ کاربر** هم باید به سرویسِ Pushِ خودش دسترسی داشته باشد (VPN یا فیلترِ شبکهٔ کاربر ممکن است مانع شود).

## ۳. بکاپ
**[تغییردهنده — فقط کپی]** از `.env` یک نسخه بگیرید (کلیدهایِ VAPID فقط آنجاست؛ گم‌شدنشان یعنی همهٔ دستگاه‌ها باید دوباره فعال‌سازی کنند):
```powershell
Copy-Item C:\Project\Dashboard_New\.env "C:\Project\Dashboard_New_env_backup_$(Get-Date -Format yyyyMMdd_HHmm).env"
```
محلِ نسخهٔ پشتیبان را خارج از مسیرِ وب و با دسترسیِ محدود نگه دارید.

## ۴. کلیدهایِ VAPID (یک‌بار برایِ هر نصب)
**[فقط‌خواندنی]** اگر کلید از قبل هست، دوباره نسازید:
```powershell
Select-String -Path C:\Project\Dashboard_New\.env -Pattern '^VAPID_(PUBLIC|PRIVATE)_KEY=.+' | ForEach-Object { ($_.Line -split '=')[0] + ' = (set)' }
```
- اگر هر دو `(set)` هستند: این بخش را رد کنید. **تعویضِ کلید همهٔ اشتراک‌هایِ فعلی را بی‌اثر می‌کند.**
- اگر نیستند: **[تغییردهنده]** (کلیدِ خصوصی فقط در `.env` نوشته و چاپ نمی‌شود):
  ```powershell
  Set-Location C:\Project\Dashboard_New
  $env:OPENSSL_CONF = "<PHP_DIR>\extras\ssl\openssl.cnf"
  & "<PHP_DIR>\php.exe" artisan push:vapid-keys --subject=<SITE_URL>
  ```
  `--subject` نشانیِ httpsِ سایت یا `mailto:` یک ایمیلِ مسئول است (اپل فقط این دو قالب را می‌پذیرد).

## ۵. `.env`
**[تغییردهنده]** (پیش از افزودن، تکراری نبودنِ کلید را بررسی کنید؛ در `.env` اولین تعریف معتبر است):
```
PUSH_ENABLED=true
```
`PUSH_MAX_AGE_MINUTES` را تعریف نکنید (خالی = بدونِ محدودیت). اگر سرور Config Cache دارد: `artisan config:cache`.

## ۶. زمان‌بند (Task Schedulerِ ویندوز)
زمان‌بند هر دقیقه `push:dispatch` (صف‌بندی و ارسال) و هر شب ساعتِ 03:30 `push:cleanup` (پاک‌سازیِ سوابقِ ۳۰ روز قبل) را اجرا می‌کند. اجرایِ هم‌زمان با قفلِ مشترکِ Cache (`withoutOverlapping` + `onOneServer`) و Claimِ اتمیک در SQL جلوگیری می‌شود. **فقط یک زمان‌بند** برایِ هر دیتابیس بسازید (نه رویِ IIS یا سرورِ دیگرِ متصل به همان دیتابیس).

1. **[فقط‌خواندنی]** حسابِ اجرایِ سرویسِ PHP FastCGI (زمان‌بند با همان حساب اجرا شود تا دسترسیِ نوشتن در `storage\logs` یکسان باشد):
   ```powershell
   Get-CimInstance Win32_Service | Where-Object { $_.PathName -like '*php*' } | Select-Object Name, StartName, State
   ```
2. **[تغییردهنده]** فایلِ اجرایی، **خارج از پوشهٔ برنامه**، مثلاً `C:\RagaScheduler\schedule-run.cmd`:
   ```bat
   @echo off
   set "OPENSSL_CONF=<PHP_DIR>\extras\ssl\openssl.cnf"
   cd /d C:\Project\Dashboard_New
   "<PHP_DIR>\php.exe" artisan schedule:run > NUL 2>&1
   ```
   (`OPENSSL_CONF` همین‌جا تنظیم می‌شود تا به متغیرِ محیطیِ سیستم و Restartِ سرویسِ Task Scheduler وابسته نباشد.)
3. **[تغییردهنده]** ساختِ وظیفهٔ دقیقه‌ای (اگر حسابِ مرحلهٔ ۱ `LocalSystem` است `SYSTEM`؛ وگرنه همان حساب با `/RU <حساب> /RP`):
   ```powershell
   schtasks /Create /TN "Raga360\Scheduler" /SC MINUTE /MO 1 /TR "C:\RagaScheduler\schedule-run.cmd" /RU SYSTEM /RL HIGHEST /F
   ```
   این وظیفه بدونِ ورودِ کاربر به ویندوز و پس از Restart هم اجرا می‌شود.
4. **[فقط‌خواندنی]** بررسی:
   ```powershell
   Set-Location C:\Project\Dashboard_New
   & "<PHP_DIR>\php.exe" artisan schedule:list
   schtasks /Query /TN "Raga360\Scheduler" /V /FO LIST | Select-String 'Status','Last Run Time','Last Result'
   ```
   انتظار: `push:dispatch` (هر دقیقه) و `push:cleanup` (03:30) در فهرست؛ پس از یک دقیقه `Last Result: 0`.

## ۷. فعال‌سازیِ ارسال
**[تغییردهنده]** ثبتِ «مرزِ شروع»: فقط رویدادهایِ **بعد از** این لحظه اعلان می‌شوند (پیام‌هایِ قدیمی ناگهان ارسال نمی‌شوند):
```powershell
Set-Location C:\Project\Dashboard_New
& "<PHP_DIR>\php.exe" artisan push:activate
```
**[فقط‌خواندنی]** پس از یک تا دو دقیقه زمان‌بند باید Heartbeat ثبت کرده باشد:
```powershell
& "<PHP_DIR>\php.exe" -r "require 'vendor/autoload.php'; `$a = require 'bootstrap/app.php'; `$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); print_r(DB::selectOne('EXEC dbo.sp_Push_GetState'));"
```
انتظار: `IsEnabled = 1`، `ActivatedAt` پر، و `LastRunAt` در یکی-دو دقیقهٔ اخیر.

## ۸. آزمون رویِ دستگاهِ واقعی
1. با `<SITE_URL>` وارد شوید. پنجرهٔ «آیا می‌خواهید اعلان‌های راگا ۳۶۰ را فعال کنید؟» → «بله، فعال کن» → در پنجرهٔ مرورگر «Allow».
   (در iPhone: ابتدا Safari → Share → Add to Home Screen و ورود از همان آیکون.)
2. منویِ کاربر ← «اعلان‌هایِ گوشی و مرورگر» ← بخشِ «وضعیتِ همین دستگاه»: مجوزِ مرورگر، Service Worker، اشتراک و «ثبت رویِ سرور» همه سبز.
3. «ارسالِ اعلانِ آزمایشی» → حدودِ یک دقیقه بعد اعلان می‌رسد.
4. روی Chrome/Edgeِ ویندوز، Chromeِ اندروید و وب‌اپِ iPhone تکرار کنید؛ سپس یک پیامِ واقعی به همان کاربر بفرستید.
5. **[فقط‌خواندنی]** نتیجهٔ واقعیِ هر دستگاه (بدونِ Endpoint و کلیدها):
   ```powershell
   & "<PHP_DIR>\php.exe" -r "require 'vendor/autoload.php'; `$a = require 'bootstrap/app.php'; `$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); foreach (DB::select('SELECT TOP 20 D.PushOutboxID, D.PushSubscriptionID, D.Status, D.HttpStatus, D.LastError, D.LastAttemptAt FROM dbo.PushDeliveries D ORDER BY D.PushDeliveryID DESC') as `$r) echo json_encode(`$r, JSON_UNESCAPED_UNICODE), PHP_EOL;"
   ```
   `SENT` + `201` = سرویسِ Push پذیرفت (نمایش رویِ دستگاه به تنظیماتِ همان دستگاه هم بستگی دارد).

## ۹. «ارسال اعلان عمومی»
- منو: «مدیریت سیستم ← ارسال اعلان عمومی» (پیش‌فرض فقط نقشِ ادمین). برایِ نقش‌هایِ دیگر: «نقش‌ها ← دسترسی‌هایِ عملیاتی ← ارسالِ اعلانِ عمومی» (`PUSH_BROADCAST`) و نمایشِ منو.
- فقط کاربرانِ فعالی که اعلان را فعال کرده‌اند دریافت می‌کنند؛ پیش از ارسال تعدادِ کاربران/دستگاه‌ها و متن نمایش داده و تأییدِ صریح گرفته می‌شود. در کارتابل/اعلان‌هایِ خوانده‌نشده ثبت نمی‌شود. متن رویِ صفحهٔ قفل هم دیده می‌شود.

## ۱۰. خاموش‌کردن و بازگشت
- **توقفِ ارسال (بدونِ قطعی):** `& "<PHP_DIR>\php.exe" artisan push:activate --disable` یا `PUSH_ENABLED=false` در `.env` (با Config Cache: `artisan config:cache`). اشتراک‌ها حفظ می‌شوند؛ با `push:activate` دوباره روشن می‌شود و رویدادهایِ دورهٔ توقف ارسال می‌شوند. اگر نمی‌خواهید: `push:activate --reset-boundary`.
- **توقفِ زمان‌بند:** `schtasks /Change /TN "Raga360\Scheduler" /DISABLE` (حذف نمی‌شود؛ برگشت با `/ENABLE`).
- کلیدهایِ VAPID را برایِ «خاموش‌کردن» عوض یا حذف نکنید.

## ۱۱. عیب‌یابی
| نشانه | علت/اقدام |
|---|---|
| `LastRunAt` به‌روز نمی‌شود | وظیفهٔ Task Scheduler اجرا نمی‌شود یا خطا می‌دهد (بخشِ ۶-۴)؛ دستی اجرا کنید: `C:\RagaScheduler\schedule-run.cmd` و `storage\logs\laravel.log` را ببینید |
| `LastError` = `Unable to create the local key` | `OPENSSL_CONF` در فایلِ اجرایی اشتباه است (بخشِ ۲-۴) |
| `LastError` شاملِ `SSL certificate` / `local issuer` | CA تنظیم نیست (بخشِ ۲-۵) |
| `LastError` = timeout / `Could not resolve host`، وضعیت `RETRY` | دسترسیِ خروجیِ 443 (بخشِ ۲-۶) |
| `HttpStatus` = 404 یا 410 (`EXPIRED`) | اشتراکِ آن دستگاه منقضی شده و خودکار غیرفعال می‌شود؛ کاربر دوباره فعال‌سازی کند |
| `HttpStatus` = 403 و `BadJwtToken` (اپل) | `VAPID_SUBJECT` باید `https://…` یا `mailto:…` باشد |
| `HttpStatus` = 400 و `BadWebPushTopic` (فقط iPhone/iPad) | نسخهٔ قدیمیِ کد؛ از نسخه‌ای که Topic را MD5ِ ۳۲نویسه‌ای می‌سازد استفاده کنید (`PushPayloadFactory::topic`) |
| پنجرهٔ فعال‌سازی نمایش داده نمی‌شود | نشانیِ http/IP است (بخشِ ۲-۱)، یا کاربر «فعلاً نه» زده (۷ روز)؛ از منویِ کاربر فعال کند |
| «اعلان عمومی»: هیچ کاربری اعلانِ فعال ندارد | هیچ دستگاهی ثبت نشده؛ بخشِ ۸ |

لاگِ اختصاصی: `storage\logs\push-YYYY-MM-DD.log` (فقط نامِ میزبانِ سرویس، نه Endpoint یا کلید).

## ۱۲. فهرستِ کنترل
- [ ] HTTPSِ معتبر (۲-۱)
- [ ] پچ‌هایِ 103–106 و `composer install` (۲-۲)
- [ ] افزونه‌ها، `OPENSSL_CONF` → `bool(true)`، CA → خطایِ خالی، شبکهٔ خروجی (۲-۳ تا ۲-۶)
- [ ] بکاپِ `.env` (۳) و کلیدهایِ VAPID (۴)
- [ ] `PUSH_ENABLED=true` (۵)
- [ ] Task Scheduler و `schedule:list` (۶)
- [ ] `push:activate` و `LastRunAt` به‌روز (۷)
- [ ] آزمونِ واقعی رویِ کامپیوتر، اندروید و iPhone (۸)
