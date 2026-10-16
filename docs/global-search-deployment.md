# راهنمای انتشار و بازگشتِ نسخهٔ 1.0.5 (Global Search، Workflow، CRM) — سرورِ ویندوزیِ شرکت

**محیطِ هدف:** ویندوز؛ Laravel با **Nginx** و **PHP FastCGI** که هر دو به‌صورتِ **سرویسِ خودکارِ ویندوز** اجرا می‌شوند؛ دسترسی از پورتِ **8080**؛ مسیرِ برنامه `C:\Project\Dashboard_New`. **IIS استفاده نمی‌شود و تغییر نمی‌کند.**

قواعدِ این راهنما:
- هر دستور برچسب دارد: **[فقط‌خواندنی]** هیچ چیز را تغییر نمی‌دهد؛ **[تغییردهنده]** چیزی را تغییر می‌دهد و فقط در پنجرهٔ انتشار و پس از بکاپ اجرا شود.
- هر جا «**توقف**» آمده، ادامه ندهید و موضوع را بررسی/گزارش کنید. هیچ پچی کورکورانه اجرا نمی‌شود.
- هیچ رمز یا مقدارِ محرمانه‌ای در این فایل یا در Git نیست؛ مقادیرِ حساس فقط در `.env` همان سرور.
- مقادیرِ داخلِ `<...>` باید با مقدارِ واقعیِ سرور جایگزین شوند (بخشِ ۲ آن‌ها را مشخص می‌کند؛ `<PREVIOUS_COMMIT>` در بخشِ ۳ و `<RELEASE_COMMIT>` در بخشِ ۴-۱).
- همهٔ شرط‌هایِ توقفِ کد، Composer و پچ‌ها در بخشِ ۴-۱ و **پیش از اولین تغییر** بررسی می‌شوند.

## ۱. خلاصه و تصمیمِ کلیدی

- **مرحلهٔ ۱ — انتشارِ کد با Global Searchِ خاموش (بخش‌هایِ ۲ تا ۴):** فقط **پس از گذراندنِ همهٔ بررسی‌هایِ سرور** (بخشِ ۲)، بکاپِ Verifyشده (بخشِ ۳) و همهٔ شرط‌هایِ توقفِ بخشِ ۴ مجاز است. در این حالت به Redis نیازی نیست؛ نسخه 1.0.5 نمایش داده می‌شود، Ctrl+K فقط منوها را جست‌وجو می‌کند، و قابلیت‌هایِ جدیدِ Workflow و CRM (جدولِ زیر) فعال می‌شوند.
- **مرحلهٔ ۲ — روشن‌کردنِ Global Search (بخش‌هایِ ۵ تا ۷):** **مجاز نیست** تا زمانی که Redisِ مستقل و پیش‌نیازهایِ **واقعیِ همان سرور** (Docker با کانتینرِ لینوکسی، اجرایِ خودکارِ Docker پس از Restart، سالم‌بودنِ `gs-redis` و آزمونِ اتصالِ برنامه) بررسی و **جداگانه تأیید** شوند. انجامِ مرحلهٔ ۱ به‌معنایِ اجازهٔ مرحلهٔ ۲ نیست. تنها روشِ آزموده‌شده در این پروژه سرویسِ `gs-redis` در `docker-compose.yml` است (بخشِ ۶)؛ اگر این پیش‌نیازها وجود ندارند یا تأیید نشده‌اند، Global Search خاموش می‌ماند.
- بدونِ Redis، اگر قابلیت به اشتباه روشن شود، جست‌وجو با **503** متوقف می‌شود (fail-closed) و بقیهٔ سایت سالم می‌ماند.

| جزء | محل | نکته |
|---|---|---|
| کد | `app/Services/GlobalSearch`، `GlobalSearchController`، دو Middleware، `config/global_search.php`، `routes/web.php` | مسیرِ `POST /global-search` با CSRF؛ تغییری در Nginx لازم نیست |
| UI | `resources/js/Components/GlobalSearch/*`، `Header.tsx`، `public/build` (در Git) | Build رویِ سرور لازم نیست؛ **Vite اجرا نشود** |
| SQL — Global Search | اتصالِ `sqlsrv_search` (همان `DB_*`، بدونِ Connection Pooling) + پچ‌هایِ `085` و `086` | ۱ تابع و ۴ رویهٔ جدید؛ بدونِ تغییرِ جدول یا داده |
| SQL — Workflow | پچ‌هایِ `064` تا `069` | تغییرِ رویه‌هایِ موجود (از جمله `sp_GetMessages`، `sp_Wf_CreateTaskMessage`، `sp_Wf_GetStepTaskDetail`) و ۱ رویهٔ جدید؛ **رفتارِ صندوقِ نامه و فرایندهایِ در حالِ اجرا تغییر می‌کند** |
| SQL — CRM و مرتب‌سازی | پچ‌هایِ `080` تا `084` | **۳ جدولِ جدید** (`CrmPartyRoles`، `CrmPartyPositions`، `CrmPartyPersonRelationPositions`)، رویه‌هایِ جدید و تغییرِ ۱۹ رویهٔ فهرست‌ها (`081`)؛ پچِ `083` دسترسیِ `CRM_DELETE` را می‌سازد و **به نقشِ ۱ (ادمین) می‌دهد** — حذفِ دائمیِ رکوردهایِ CRM (فقط وقتی جایی استفاده نشده باشند) |
| Composer | `predis/predis` 2.4.1 | کلاینتِ Redis |
| SQL — دادهٔ نمایشی | پچِ `087` (DMLِ دستی، Idempotent) | عنوانِ پوشهٔ منویِ CRM → «مخاطبین و ارتباطات» و «فهرست‌های CRM» → «فهرست‌های مخاطبین و ارتباطات»؛ `SortOrder = 0` در ۱۶ جدولِ فهرست‌هایِ پایهٔ CRM — **ترتیبِ دلخواهِ فعلیِ مشتری در این فهرست‌ها بازنویسی می‌شود** (`CrmPartyImages` شامل نیست). نامِ مجوزها (مثلِ «حذفِ اطلاعاتِ CRM») تغییر نمی‌کند. |
| Rate Limit | سرویسِ `gs-redis` (Docker) | فقط هنگامِ روشن‌کردن |

## ۲. شناختِ سرور (پیش از روزِ انتشار؛ همه [فقط‌خواندنی])

این موارد در مخزن نیستند و **نباید فرض شوند**؛ خروجیِ هرکدام را ثبت کنید.

| مورد | دستور [فقط‌خواندنی] | انتظار / توقف |
|---|---|---|
| سرویس‌هایِ Nginx و PHP | `Get-CimInstance Win32_Service \| Where-Object { $_.Name -match 'nginx\|php' -or $_.PathName -match 'nginx\|php-cgi' } \| Select-Object Name, State, StartMode, PathName` | نامِ دو سرویس، `Running` و `Auto`؛ مسیرِ `nginx.exe` و `php-cgi.exe` را ثبت کنید (`<NGINX_SERVICE>`، `<PHP_SERVICE>`، `<NGINX_DIR>`، `<PHP_DIR>`) |
| پورتِ 8080 | `Get-NetTCPConnection -State Listen -LocalPort 8080 \| ForEach-Object { (Get-Process -Id $_.OwningProcess).Path }` | باید `nginx.exe` باشد |
| تنظیمِ Nginx | `& "<NGINX_DIR>\nginx.exe" -t -p "<NGINX_DIR>\"` | `test is successful`؛ در فایلِ کانفیگ `root` باید `C:/Project/Dashboard_New/public` و `fastcgi_pass` آدرسِ PHP FastCGI باشد |
| دستورِ واقعیِ سرویسِ PHP FastCGI | `PathName`ِ سرویس (ردیفِ اول)؛ اگر `PathName` یک Wrapper است (مثلاً `nssm.exe` یا WinSW)، دستورِ واقعی از تنظیماتِ Wrapper خوانده می‌شود (بلوکِ زیر) | ثبت کنید: `<PHP_CGI_EXE>` (مسیرِ دقیقِ `php-cgi.exe`)، `<PHP_CGI_INI_ARGS>` (آرگومانِ `-c <مسیر>` اگر هست؛ وگرنه خالی)، و درگاهِ `-b` (باید با `fastcgi_pass` در Nginx یکی باشد). اگر سرویس `PHPRC` دارد، همان مسیر را به‌صورتِ `-c <مسیر>` در `<PHP_CGI_INI_ARGS>` بگذارید |
| نسخه و افزونه‌هایِ PHP در **هر دو** محیط | CLI (برایِ artisan و Composer): `& "<PHP_DIR>\php.exe" -v`، `--ini`، `-m`؛ CGI (برایِ سایت): `& "<PHP_CGI_EXE>" <PHP_CGI_INI_ARGS> -v` و `-m` (بلوکِ زیر) | هر دو PHP **8.2+** و **یک نسخه**؛ همهٔ افزونه‌هایِ فهرستِ بلوکِ زیر در **هر دو** `True`. هر `False` یا نسخهٔ متفاوت = **توقف**. (PHP پیش از `php.ini` به‌دنبالِ `php-cli.ini` برایِ CLI و `php-cgi-fcgi.ini` برایِ CGI می‌گردد؛ پس حتی در یک پوشه ممکن است تنظیمات متفاوت باشد.) |
| php.ini و OPcacheِ سرویس | `& "<PHP_CGI_EXE>" <PHP_CGI_INI_ARGS> -i \| Select-String 'Loaded Configuration File','Scan this dir','opcache.enable','opcache.validate_timestamps'` | فایلِ ini همان باشد که سرویس استفاده می‌کند. اگر `opcache.validate_timestamps => Off` باشد، پس از به‌روزرسانیِ کد باید سرویسِ PHP یک‌بار Restart شود (بخشِ ۴-۶) |
| مخزنِ Git | `git -C C:\Project\Dashboard_New status -sb` و `git -C C:\Project\Dashboard_New remote -v` | پوشه یک clone با remoteِ GitHub و **بدونِ تغییرِ محلی** باشد؛ در غیرِ این صورت **توقف** |
| Composer | `& "<PHP_DIR>\php.exe" <COMPOSER_PHAR> --version` | Composer 2؛ اگر نیست یا اینترنت ندارد، **توقف** (بخشِ ۹) |
| نسخهٔ SQL Server | `sqlcmd -S <SQL_SERVER> <AUTH> -Q "SELECT SERVERPROPERTY('ProductVersion')"` | نسخهٔ اصلی **۱۴+** (SQL Server 2017+؛ پچِ 086 از `CONCAT_WS` استفاده می‌کند). کمتر = **توقف** |
| `APP_VERSION` در `.env` | `Get-Content C:\Project\Dashboard_New\.env \| Where-Object { $_ -match '^\s*APP_VERSION\s*=' }` (فقط همین کلید؛ محرمانه نیست) | **فقط خروجیِ خالی (کلید تعریف نشده)** پذیرفته است. هر خطِ پیداشده = **توقف** پیش از روزِ انتشار؛ تغییرِ آن تصمیمِ جداگانه است. دلیل: نسخه از `env('APP_VERSION', <پیش‌فرضِ کد>)` خوانده می‌شود و هر تعریفی پیش‌فرضِ کد را کنار می‌گذارد — مقدارِ قدیمی آزمونِ ۴-۷ را خراب می‌کند، `APP_VERSION=1.0.5` باعث می‌شود پس از Rollback هم 1.0.5 نمایش داده شود (گامِ ۶ بخشِ ۸)، و `APP_VERSION=` با مقدارِ خالی نسخه را رشتهٔ خالی نشان می‌دهد |
| دسترسیِ حسابِ برنامه در SQL | بلوکِ «دسترسیِ SQL» در زیر (با همان اتصالِ `.env` برنامه که `db:patch` استفاده می‌کند) | همهٔ مقادیر مطابقِ انتظارِ زیرِ بلوک **و** تأییدِ دستیِ صریحِ مسئولِ دیتابیس بر پایهٔ همین خروجی؛ هر انحراف یا نبودِ تأیید = **توقف** (این بررسی شرطِ لازم است، نه تضمینِ کافی) |
| Config/Route Cache | `Test-Path C:\Project\Dashboard_New\bootstrap\cache\config.php`؛ `Get-ChildItem C:\Project\Dashboard_New\bootstrap\cache -Filter 'routes-*.php'` | ثبت کنید که سرور از `config:cache`/`route:cache` استفاده می‌کند یا نه (بخشِ ۴-۶) |
| Docker (فقط برایِ روشن‌کردنِ Global Search) | `docker version` و `docker info --format '{{.OSType}}'` | باید `linux` باشد. نبودِ Docker یا `windows` = Global Search خاموش می‌ماند (بخشِ ۶) |
| فضایِ دیسک | `Get-PSDrive C, D` | فضایِ کافی برایِ بکاپِ دیتابیس و کپیِ پوشه |

همان دستورها برایِ کپی (در جدول، نویسهٔ `|` برایِ Markdown به‌صورتِ `\|` آمده است) — همه **[فقط‌خواندنی]**:
```powershell
Get-CimInstance Win32_Service | Where-Object { $_.Name -match 'nginx|php' -or $_.PathName -match 'nginx|php-cgi' } | Select-Object Name, State, StartMode, PathName
Get-NetTCPConnection -State Listen -LocalPort 8080 | ForEach-Object { (Get-Process -Id $_.OwningProcess).Path }
& "<NGINX_DIR>\nginx.exe" -t -p "<NGINX_DIR>\"
git -C C:\Project\Dashboard_New status -sb
git -C C:\Project\Dashboard_New remote -v
& "<PHP_DIR>\php.exe" <COMPOSER_PHAR> --version
sqlcmd -S <SQL_SERVER> <AUTH> -C -Q "SELECT SERVERPROPERTY('ProductVersion')"
Get-Content C:\Project\Dashboard_New\.env | Where-Object { $_ -match '^\s*APP_VERSION\s*=' }
Test-Path C:\Project\Dashboard_New\bootstrap\cache\config.php
Get-ChildItem C:\Project\Dashboard_New\bootstrap\cache -Filter 'routes-*.php'
docker version
docker info --format '{{.OSType}}'
Get-PSDrive C, D
```

`<AUTH>` یعنی روشِ احرازِ هویتِ sqlcmd رویِ آن سرور (`-E` برایِ Windows Authentication یا `-U <user>` و دریافتِ رمز به‌صورتِ تعاملی). رمز را در دستور یا اسکریپت ننویسید.

**دستورِ واقعیِ سرویسِ PHP** — **[فقط‌خواندنی]**؛ فقط اگر `PathName` به یک Wrapper اشاره می‌کند. از متغیرهایِ محیطیِ سرویس فقط `PHPRC` و `PHP_INI_SCAN_DIR` را نمایش دهید:
```powershell
# NSSM:
Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Services\<PHP_SERVICE>\Parameters" | Select-Object Application, AppParameters, AppDirectory
@((Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Services\<PHP_SERVICE>\Parameters").AppEnvironmentExtra) | Where-Object { $_ -match '^(PHPRC|PHP_INI_SCAN_DIR)=' }
# WinSW (فایلِ XML کنارِ exeِ Wrapper با همان نام):
Select-String -Path "<WRAPPER_DIR>\<WRAPPER_NAME>.xml" -Pattern '<executable>|<arguments>|PHPRC|PHP_INI_SCAN_DIR'
# در هر حالت (متغیرِ محیطیِ خودِ سرویس و متغیرِ سیستمی):
@((Get-ItemProperty "HKLM:\SYSTEM\CurrentControlSet\Services\<PHP_SERVICE>").Environment) | Where-Object { $_ -match '^(PHPRC|PHP_INI_SCAN_DIR)=' }
[Environment]::GetEnvironmentVariable('PHPRC','Machine'); [Environment]::GetEnvironmentVariable('PHP_INI_SCAN_DIR','Machine')
```

**افزونه‌هایِ PHP در CLI و CGI** — **[فقط‌خواندنی]**؛ CGI با همان آرگومان‌هایِ سرویس:
```powershell
& "<PHP_DIR>\php.exe" -v
& "<PHP_DIR>\php.exe" --ini
& "<PHP_CGI_EXE>" <PHP_CGI_INI_ARGS> -v
& "<PHP_CGI_EXE>" <PHP_CGI_INI_ARGS> -i | Select-String 'Loaded Configuration File','Scan this dir','opcache.enable','opcache.validate_timestamps'   # خروجی HTML است؛ مقدار در همان خطِ پیداشده است
$need = 'pdo_sqlsrv','openssl','mbstring','fileinfo','tokenizer','ctype','dom','libxml','iconv','session','filter','hash','json','pcre'
$cli = @(& "<PHP_DIR>\php.exe" -m)
$cgi = @(& "<PHP_CGI_EXE>" <PHP_CGI_INI_ARGS> -m)
$need | ForEach-Object { '{0,-11} CLI={1,-5} CGI={2}' -f $_, ($cli -contains $_), ($cgi -contains $_) }
```
اگر سرویس `PHP_INI_SCAN_DIR` دارد، فقط برایِ دستورهایِ CGI همان مقدار را در همین پنجره تنظیم کنید (`$env:PHP_INI_SCAN_DIR = '<مقدار>'` پیش از آن‌ها و `$env:PHP_INI_SCAN_DIR = $null` پس از آن‌ها؛ تنظیمِ سیستم تغییر نمی‌کند). فهرستِ `$need` همان نیازهایِ `composer.lock` (بخشِ ۴-۲) به‌علاوهٔ `pdo_sqlsrv` است.

**دسترسیِ SQL حسابِ برنامه برایِ پچ‌هایِ این نسخه (064–069، 080–087)** — **[فقط‌خواندنی]**؛ با همان اتصالی که `db:patch` استفاده می‌کند، بدونِ نمایشِ نامِ کاربری یا رمز. هیچ DDL اجرا نمی‌کند و هیچ مجوزی را تغییر نمی‌دهد. بلوک را **بدونِ تغییر** کپی کنید:
```powershell
Set-Location C:\Project\Dashboard_New
$code = @'
require 'vendor/autoload.php';
$a = require 'bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$sql = <<<'SQL'
SELECT DB_NAME() AS db,
  HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'CREATE FUNCTION')       AS create_function,
  HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'CREATE PROCEDURE')      AS create_procedure,
  HAS_PERMS_BY_NAME('dbo', 'SCHEMA', 'ALTER')                       AS alter_schema_dbo,
  HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'CREATE TABLE')          AS create_table,
  HAS_PERMS_BY_NAME('dbo.Permissions', 'OBJECT', 'SELECT')          AS permissions_select,
  HAS_PERMS_BY_NAME('dbo.Permissions', 'OBJECT', 'INSERT')          AS permissions_insert,
  HAS_PERMS_BY_NAME('dbo.Roles', 'OBJECT', 'SELECT')                AS roles_select,
  HAS_PERMS_BY_NAME('dbo.RolePermissions', 'OBJECT', 'SELECT')      AS rolepermissions_select,
  HAS_PERMS_BY_NAME('dbo.RolePermissions', 'OBJECT', 'INSERT')      AS rolepermissions_insert,
  HAS_PERMS_BY_NAME('dbo.Menu', 'OBJECT', 'UPDATE')                 AS menu_update,
  HAS_PERMS_BY_NAME('dbo', 'SCHEMA', 'UPDATE')                      AS update_schema_dbo,
  HAS_PERMS_BY_NAME('dbo.fn_Gs_Normalize', 'OBJECT', 'ALTER')       AS alter_fn_Gs_Normalize,
  HAS_PERMS_BY_NAME('dbo.sp_Gs_Search', 'OBJECT', 'ALTER')          AS alter_sp_Gs_Search,
  HAS_PERMS_BY_NAME('dbo.sp_Gs_SearchMessages', 'OBJECT', 'ALTER')  AS alter_sp_Gs_SearchMessages,
  HAS_PERMS_BY_NAME('dbo.sp_Gs_SearchProjects', 'OBJECT', 'ALTER')  AS alter_sp_Gs_SearchProjects,
  HAS_PERMS_BY_NAME('dbo.sp_Gs_SearchProcesses', 'OBJECT', 'ALTER') AS alter_sp_Gs_SearchProcesses,
  HAS_PERMS_BY_NAME('dbo.SysPatches', 'OBJECT', 'SELECT')           AS syspatches_select,
  HAS_PERMS_BY_NAME('dbo.SysPatches', 'OBJECT', 'INSERT')           AS syspatches_insert,
  (SELECT COUNT(*) FROM sys.triggers WHERE parent_id = OBJECT_ID('dbo.SysPatches') AND is_disabled = 0) AS syspatches_enabled_triggers,
  HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'VIEW DEFINITION')       AS view_definition,
  (SELECT COUNT(*) FROM sys.triggers WHERE parent_class = 0 AND is_disabled = 0) AS enabled_db_ddl_triggers,
  HAS_PERMS_BY_NAME('dbo.DevChangeLog', 'OBJECT', 'INSERT')         AS devchangelog_insert,
  HAS_PERMS_BY_NAME(NULL, NULL, 'VIEW ANY DEFINITION')              AS view_any_definition,
  (SELECT COUNT(*) FROM sys.server_triggers WHERE is_disabled = 0)  AS enabled_server_triggers,
  HAS_PERMS_BY_NAME('dbo', 'SCHEMA', 'EXECUTE')                     AS execute_schema_dbo
SQL;
foreach ((array) Illuminate\Support\Facades\DB::selectOne($sql) as $k => $v) { echo $k, '=', var_export($v, true), PHP_EOL; }
'@
& "<PHP_DIR>\php.exe" -r $code; "php exit: $LASTEXITCODE"
```
عملیاتِ واقعیِ پچ‌ها در زمانِ اجرا (از رویِ فایل‌ها بررسی شده؛ `INSERT`/`UPDATE`/`DELETE`ِ داخلِ بدنهٔ رویه‌ها هنگامِ پچ اجرا نمی‌شوند):
- `085`/`086`: فقط `CREATE OR ALTER FUNCTION dbo.fn_Gs_Normalize ... WITH SCHEMABINDING` و `CREATE OR ALTER PROCEDURE` برایِ چهار رویهٔ `dbo.sp_Gs_*`. تابع به هیچ جدول یا شیئی ارجاع ندارد، پس `SCHEMABINDING` برایِ ساختنش به `REFERENCES` نیاز ندارد.
- `064`–`069`: `ALTER PROCEDURE`/`CREATE OR ALTER PROCEDURE` رویِ رویه‌هایِ موجودِ Workflow و `sp_GetMessages`، و یک رویهٔ جدید (`sp_Wf_AddTaskMessageRecipients`).
- `080`، `082`: `CREATE TABLE` (۳ جدول)، `CREATE INDEX` و `CREATE PROCEDURE` (بدونِ `OR ALTER` — اگر شیء از قبل باشد پچ خطا می‌دهد)، و `ALTER PROCEDURE` رویِ دو رویهٔ موجود.
- `081`: `ALTER PROCEDURE` رویِ ۱۹ رویهٔ موجود.
- `083`، `084`: `CREATE`/`ALTER PROCEDURE dbo.sp_Crm_DeleteRecord` (بدنه از `sp_executesql` با پارامتر استفاده می‌کند) و **درجِ داده**: ردیفِ `CRM_DELETE` در `dbo.Permissions` و اعطایِ آن به `RoleID = 1` در `dbo.RolePermissions` (هر دو با `IF NOT EXISTS`).
- `087`: فقط **`UPDATE`ِ داده** (Idempotent): `MenuTitle` در `dbo.Menu` (۲ ردیف) و `SortOrder = 0` در ۱۶ جدولِ فهرست‌هایِ پایهٔ CRM. هیچ ردیفی حذف یا اضافه نمی‌شود.
- همه در `dbo`، بدونِ `GRANT`، `EXECUTE AS` یا ارجاعِ بین‌دیتابیسی. در مجموع ۲۲ رویهٔ موجود با `ALTER PROCEDURE` تغییر می‌کنند. `db:patch` علاوه بر آن از `SysPatches` می‌خواند و در آن درج می‌کند. (sqlsrv ممکن است اعداد را به‌صورتِ `'1'` نشان دهد.)

مجوزهایِ ساخت و داده: `create_table`، `create_procedure`، `create_function` و `alter_schema_dbo` باید `1` باشند (ساختِ جدول/رویه/تابع به مجوزِ `CREATE ...` در دیتابیس **و** `ALTER` رویِ schema نیاز دارد)؛ `permissions_select`، `permissions_insert`، `roles_select`، `rolepermissions_select` و `rolepermissions_insert` هم باید `1` باشند (پچِ `083`)؛ `menu_update` و `update_schema_dbo` هم باید `1` باشند (پچِ `087`؛ یک `DENY UPDATE` رویِ یکی از ۱۶ جدولِ CRM را این بلوک نشان نمی‌دهد و جزوِ بررسیِ دستی است). تغییرِ ۲۲ رویهٔ موجود به `ALTER` رویِ همان رویه‌ها نیاز دارد؛ `alter_schema_dbo` آن را پوشش می‌دهد مگر یک `DENY` رویِ خودِ رویه باشد، که این بلوک آن را نشان نمی‌دهد — بخشی از بررسیِ دستیِ مسئولِ دیتابیس (پایین).

مجوزِ لازم بسته به این‌که شیء وجود دارد یا نه فرق می‌کند:
- **شیء وجود ندارد (حالتِ عادیِ این انتشار؛ گامِ ۲ بخشِ ۴-۳ آن را الزامی می‌کند):** ساختن به `CREATE FUNCTION`/`CREATE PROCEDURE`/`CREATE TABLE` در دیتابیس **و** `ALTER` رویِ schemaِ `dbo` نیاز دارد. انتظار: `create_function`، `create_procedure`، `create_table` و `alter_schema_dbo` همه `1`. **نبود یا بودنِ شیء را از ستون‌هایِ `alter_*` نتیجه نگیرید:** `HAS_PERMS_BY_NAME` برایِ شیئی که وجود ندارد (یا حساب آن را نمی‌بیند) `0` یا `NULL` برمی‌گرداند — رویِ نسخهٔ آزمایشیِ کاملِ دیتابیس با حسابِ sysadmin مقدارِ `0` دیده شد. وجود/نبودِ شیء فقط با پرس‌وجویِ `sys.objects` در گامِ ۲ بخشِ ۴-۳ تعیین می‌شود (با حسابِ اپراتور، و `view_definition = 1` برایِ حسابِ برنامه). اگر آن پرس‌وجو ردیفی برگرداند ← **توقف**.
- **شیء از قبل وجود دارد (اجرایِ مجدد؛ فقط با تأییدِ صریح):** تغییر به `ALTER` رویِ **خودِ همان شیء** نیاز دارد؛ ستونِ `alter_*` همان شیء باید `1` باشد. `alter_schema_dbo` به‌تنهایی **تضمینِ کافی نیست**: یک `DENY ALTER` رویِ خودِ شیء آن را بی‌اثر می‌کند، و تغییرِ تابعِ `WITH SCHEMABINDING` اگر شیءِ دیگری به آن schema-bound باشد شکست می‌خورد که `HAS_PERMS_BY_NAME` آن را نشان نمی‌دهد. پس شیءِ موجود همیشه بررسیِ دستیِ مسئولِ دیتابیس می‌خواهد.
- **`SysPatches`:** `syspatches_select` و `syspatches_insert` باید `1` و `syspatches_enabled_triggers` باید `0` باشد (تریگرِ فعال رویِ این جدول می‌تواند درجِ نتیجه را خراب کند ← **توقف** و بررسیِ دستی).
- **تریگرهایِ DDLِ دیتابیس:** تریگرِ DDL در **زمینهٔ حسابِ برنامه** همراهِ هر `CREATE OR ALTER` اجرا می‌شود و خطایِ آن پچ را متوقف می‌کند. `view_definition` باید `1` باشد؛ وگرنه شمارشِ تریگرها قابلِ اعتماد نیست (کاربر ممکن است تریگرِ موجود را نبیند) ← **توقف**. اگر `enabled_db_ddl_triggers` بیشتر از `0` است: نسخهٔ `trg_DevTrackSchemaChanges` در این مخزن (`routes/console.php`) فقط در `dbo.DevChangeLog` درج می‌کند و `EXECUTE AS` ندارد، پس `devchangelog_insert` باید `1` باشد؛ و چون تریگرِ سرور ممکن است با نسخهٔ مخزن فرق کند، متنِ هر تریگرِ فعال (`SELECT name, OBJECT_DEFINITION(object_id) FROM sys.triggers WHERE parent_class = 0 AND is_disabled = 0`) باید دستی بررسی شود.
- **تریگرهایِ سطحِ سرور:** تریگرِ DDLِ سرور هم می‌تواند رویِ ساختِ تابع/رویه اجرا شود. `view_any_definition` باید `1` و `enabled_server_triggers` باید `0` باشد؛ در غیرِ این صورت **توقف** و بررسیِ دستی.
- **`execute_schema_dbo`:** برایِ پچ لازم نیست؛ برایِ **روشن‌کردنِ** Global Search لازم است (بخشِ ۵).
- تریگرها را غیرفعال یا حذف نکنید؛ هیچ `GRANT` یا تغییرِ دسترسی در این روند انجام نمی‌شود.

**محدودیت و قاعدهٔ توقف:** `HAS_PERMS_BY_NAME` مجوزِ مؤثر (با عضویت در نقش‌ها، `db_owner`/`db_ddladmin`/`sysadmin` و `DENY`) را برایِ همان securableِ نام‌برده نشان می‌دهد، اما DDL را اجرا نمی‌کند، متنِ تریگرها را ارزیابی نمی‌کند، وابستگی‌هایِ schema-bound را نمی‌بیند و به دیدِ متادیتایِ حساب وابسته است. این راهنما بدونِ اتصال به دیتابیسِ سرور نوشته شده و این پرس‌وجو رویِ آن آزموده نشده است. بنابراین نتیجهٔ این بررسی **شرطِ لازم است، نه تضمینِ کافی**: `db:patch` (بخشِ ۴-۳، گامِ ۳) فقط پس از آن مجاز است که **مسئولِ دیتابیس** خروجیِ همین بلوک را دیده و **تأییدِ دستیِ صریح** داده باشد که حسابِ برنامه می‌تواند این عملیات را انجام دهد. هر مقدارِ خلافِ انتظارِ بالا، خطایِ اجرا، یا هر شکی = **توقف**.

## ۳. بکاپ (اجباری؛ بدونِ بکاپِ Verifyشده ادامه ندهید)

1. **[تغییردهنده — فقط فایلِ بکاپ می‌سازد، داده تغییر نمی‌کند]** بکاپِ کاملِ مستقل (`COPY_ONLY` زنجیرهٔ بکاپِ جاری را تغییر نمی‌دهد):
   ```powershell
   sqlcmd -S <SQL_SERVER> <AUTH> -C -b -Q "BACKUP DATABASE [Dashboard_DB] TO DISK = N'<BACKUP_DIR>\Dashboard_DB_pre_1.0.5.bak' WITH COPY_ONLY, CHECKSUM, COMPRESSION, INIT, STATS = 25;"
   ```
   `<BACKUP_DIR>` خارج از پوشهٔ پروژه و خارج از مسیرهایِ قابلِ دسترسیِ وب باشد و حسابِ سرویسِ SQL Server در آن اجازهٔ نوشتن داشته باشد. اگر SQL Server نسخهٔ Express است و فشرده‌سازی را پشتیبانی نمی‌کند، `COMPRESSION` را حذف کنید.
2. **[فقط‌خواندنی]** تأیید:
   ```powershell
   sqlcmd -S <SQL_SERVER> <AUTH> -C -b -Q "RESTORE VERIFYONLY FROM DISK = N'<BACKUP_DIR>\Dashboard_DB_pre_1.0.5.bak' WITH CHECKSUM;"
   ```
   انتظار: `The backup set on file 1 is valid.` — در غیرِ این صورت **توقف**.
3. **[تغییردهنده — فقط کپی می‌سازد]** کپیِ کاملِ پوشهٔ برنامه (شاملِ `.env` و `vendor`):
   ```powershell
   robocopy C:\Project\Dashboard_New <BACKUP_DIR>\Dashboard_New_pre_1.0.5 /E /COPY:DAT /R:1 /W:1 /XD C:\Project\Dashboard_New\node_modules
   ```
   کدِ خروجیِ ۰ تا ۷ یعنی موفق. (`/MIR` استفاده نکنید.)
4. **[فقط‌خواندنی]** commitِ فعلی را برایِ مقایسه و بازگشت ثبت کنید:
   ```powershell
   git -C C:\Project\Dashboard_New rev-parse HEAD
   git -C C:\Project\Dashboard_New branch --show-current
   ```
   هشِ کاملِ ۴۰ نویسه‌ایِ خروجیِ اول `<PREVIOUS_COMMIT>` است. خروجیِ دوم باید `main` باشد؛ در غیرِ این صورت **توقف**.

## ۴. انتشارِ نسخه (Global Search خاموش)

### ۴-۱. بررسی‌هایِ پیش از هر تغییر و دریافتِ نسخهٔ مشخص

**`<RELEASE_COMMIT>`** هشِ کاملِ ۴۰ نویسه‌ایِ commitی است که برایِ انتشار **بررسی و تأیید** شده است. برایِ 1.0.5 در زمانِ نگارشِ این راهنما:
`790c0f3b019bd4bbd40b182bef3ab732bd0190ba` — نسخهٔ **1.0.5** (Global Search `2b417fb` + آماده‌سازیِ استقرار `cca17f9` + Workflow `b3a388c` + CRM `05a551f` + مرتب‌سازی `278c889` + Build `2ee3602` + merge یِ `workflow-letter-condition-stage` در `50d42fe` + اصلاحِ تست‌ها `8dba9fa` + پچِ داده‌ایِ `087` در `790c0f3`). این مقصدِ **ثابتِ کد** است و همهٔ دستورهایِ این بخش و بخش‌هایِ ۴-۲، ۴-۳ و ۸ با همین مقدار اجرا می‌شوند. **هرگز `origin/main` یا «آخرین نسخه» را به‌جایِ آن به کار نبرید**؛ هیچ commitِ بررسی‌نشده‌ای منتشر نمی‌شود.

**نسخهٔ راهنما جدا از مقصدِ کد است.** نسخهٔ نهاییِ همین راهنما ممکن است در commitِ مستنداتیِ جدیدتری در `main` باشد (`<GUIDE_COMMIT>`؛ برایِ 1.0.5 commitِ مستنداتِ بعد از `790c0f3`) و از GitHub خوانده شود. این موضوع مقصدِ انتشار را تغییر نمی‌دهد: هشِ راهنما هرگز جایِ `<RELEASE_COMMIT>` نمی‌نشیند، و نسخهٔ راهنمایی که پس از merge در پوشهٔ سرور قرار می‌گیرد (نسخهٔ داخلِ `<RELEASE_COMMIT>`) ممکن است قدیمی‌تر باشد و نباید دنبال شود. **[فقط‌خواندنی]** پس از گامِ ۲ (`fetch`) تأیید کنید که commitهایِ مستندات کدی را تغییر نمی‌دهند:
```powershell
git -C C:\Project\Dashboard_New diff --name-only <RELEASE_COMMIT> <GUIDE_COMMIT>
```
انتظار: فقط `docs/global-search-deployment.md`. هر فایلِ دیگر = **توقف** (یعنی راهنما با کدِ دیگری نوشته شده و باید دوباره بررسی شود).

1. **[فقط‌خواندنی]** `git -C C:\Project\Dashboard_New status --porcelain` باید خالی باشد (تغییرِ محلیِ سرور را بدونِ بررسی Overwrite نکنید)، و `git -C C:\Project\Dashboard_New rev-parse HEAD` باید دقیقاً `<PREVIOUS_COMMIT>` باشد. در غیرِ این صورت **توقف**.
2. **[تغییردهنده — فقط refهایِ remote]** `git -C C:\Project\Dashboard_New fetch origin`
3. **[فقط‌خواندنی]** تأییدِ commitِ مقصد و مرورِ بازه:
   ```powershell
   Set-Location C:\Project\Dashboard_New
   git cat-file -t <RELEASE_COMMIT>                                          # commit
   git merge-base --is-ancestor <RELEASE_COMMIT> origin/main; $LASTEXITCODE   # 0 = در شاخهٔ رسمیِ main هست
   git merge-base --is-ancestor HEAD <RELEASE_COMMIT>; $LASTEXITCODE          # 0 = Fast-forward ممکن است
   git log --oneline <PREVIOUS_COMMIT>..<RELEASE_COMMIT>
   git diff --stat <PREVIOUS_COMMIT> <RELEASE_COMMIT>
   ```
   انتظار: `commit` و دو بار `0`. اگر سرور رویِ `3179c37` است، `git log` دقیقاً این ۱۶ commit را نشان می‌دهد: `790c0f3`، `8dba9fa`، `ccef39d`، `50d42fe`، `2ee3602`، `d890b27`، `278c889`، `05a551f`، `b3a388c`، `9295992`، `2126e4b`، `cca17f9`، `2b417fb`، `c3340f6`، `302ae35`، `3662a5c` (commitهایِ مستنداتِ بعدیِ راهنما در این بازه نمی‌آیند). اگر سرور عقب‌تر است، هر commitِ اضافه باید جزوِ انتشارهایِ قبلاً بررسی‌شده باشد. هر commitِ ناشناخته یا تأییدنشده، یا خروجیِ غیرِ `0` = **توقف**.
4. **[فقط‌خواندنی]** تغییرِ وابستگی‌ها در همین بازه (این فهرست «عملیاتِ مجازِ Composer» در بخشِ ۴-۲ است):
   ```powershell
   git diff <PREVIOUS_COMMIT> <RELEASE_COMMIT> -- composer.json composer.lock | Select-String '^[+-] {8}"[a-z0-9-]+/[a-z0-9.-]+": "|^[+-] {12}"(name|version)": '
   ```
   انتظار برایِ بازهٔ `3179c37` تا `790c0f3`: در `composer.json` خطِ `laravel/tinker` فقط به‌خاطرِ ویرگول یک‌بار با `-` و یک‌بار با `+` می‌آید و `+ "predis/predis": "2.4.1"` اضافه می‌شود؛ در `composer.lock` فقط `+ "name": "predis/predis"` و `+ "version": "v2.4.1"`. هر بستهٔ دیگر یا هر خطِ `-` در `composer.lock` = **توقف** (مگر جزوِ انتشارِ قبلاً بررسی‌شده‌ای در همین بازه باشد).
5. **[فقط‌خواندنی]** تطبیقِ وضعیتِ واقعیِ `SysPatches` با پچ‌هایِ دو نسخه. با همان اتصالی که `db:patch` استفاده می‌کند (`.env` برنامه) و بدونِ نمایشِ هیچ تنظیمی؛ اگر `SysPatches` نباشد چیزی نمی‌سازد. بلوک را **بدونِ تغییر** کپی کنید (کدِ PHP نباید نویسهٔ `"` داشته باشد):
```powershell
Set-Location C:\Project\Dashboard_New
$prev = '<PREVIOUS_COMMIT>'; $rel = '<RELEASE_COMMIT>'
$code = @'
require 'vendor/autoload.php';
$a = require 'bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
echo 'DB=', $db->getDatabaseName(), PHP_EOL;
if (! $db->getSchemaBuilder()->hasTable('SysPatches')) { echo 'NO_SYSPATCHES', PHP_EOL; exit(2); }
foreach ($db->table('SysPatches')->orderBy('PatchID')->get(['PatchName', 'Status']) as $r) { echo $r->PatchName, '|', $r->Status, PHP_EOL; }
'@
$out = @(& "<PHP_DIR>\php.exe" -r $code); "php exit: $LASTEXITCODE"
$rows        = @($out | Where-Object { $_ -like '*|*' })
$ok          = @($rows | Where-Object { $_ -like '*|SUCCESS' } | ForEach-Object { ($_ -split '\|')[0] })
$recorded    = @($rows | ForEach-Object { ($_ -split '\|')[0] })
$prevPatches = @(git ls-tree --name-only $prev -- sql/ | Where-Object { $_ -like '*.sql' } | ForEach-Object { Split-Path $_ -Leaf })
$relPatches  = @(git ls-tree --name-only $rel  -- sql/ | Where-Object { $_ -like '*.sql' } | ForEach-Object { Split-Path $_ -Leaf })
$newPatches  = @($relPatches | Where-Object { $prevPatches -notcontains $_ })
'0. DB / table:                   ' + (@($out | Where-Object { $_ -notlike '*|*' }) -join ' ')
'1. new patches in range:         ' + ($newPatches -join ', ')
'2. existing patches changed:     ' + (@(git diff --name-status --no-renames $prev $rel -- sql/ | Where-Object { $_ -notmatch '^A\s' }) -join ', ')
'3. old patches without SUCCESS:  ' + (@($prevPatches | Where-Object { $ok -notcontains $_ }) -join ', ')
'4. new patches already recorded: ' + (@($newPatches | Where-Object { $recorded -contains $_ }) -join ', ')
'5. rows not SUCCESS:             ' + (@($rows | Where-Object { $_ -notlike '*|SUCCESS' }) -join '; ')
'6. SUCCESS rows with no file:    ' + (@($ok | Where-Object { $relPatches -notcontains $_ } | Select-Object -Unique) -join ', ')
'7. sql\patches folder:           ' + (Test-Path sql\patches) + ' / in release: ' + @(git ls-tree --name-only $rel -- sql/patches).Count
```
   انتظار — هر انحراف = **توقف** و بررسیِ جداگانه:
   - `php exit: 0` و `0.` برابرِ `DB=Dashboard_DB` (همان دیتابیسِ بکاپِ بخشِ ۳). `NO_SYSPATCHES` یعنی روندِ پچ رویِ این سرور با انتظار هم‌خوان نیست.
   - `1.` برایِ بازهٔ `3179c37` تا `790c0f3` دقیقاً این ۱۴ پچ به همین ترتیب: `064_workflow_clone_fix_and_auto_code.sql`، `065_workflow_auto_code_gap_fill.sql`، `066_workflow_instance_acted_assignees.sql`، `067_workflow_one_message_per_instance.sql`، `068_workflow_sent_status_tiebreak.sql`، `069_workflow_letter_condition_deferred.sql`، `080_crm_party_roles_positions.sql`، `081_sp_sort_order_code_rule.sql`، `082_crm_relation_multi_positions.sql`، `083_crm_delete_record.sql`، `084_crm_delete_record_party_brand_link.sql`، `085_global_search_procedures.sql`، `086_global_search_crm_address_location.sql`، `087_crm_display_and_sortorder_alignment.sql`. اگر سرور عقب‌تر است، هر پچِ اضافه باید متعلق به commitهایِ بررسی‌شدهٔ گامِ ۳ باشد.
   - `2.` تا `6.` خالی. (`3.` یعنی پچی که در نسخهٔ فعلی هست ولی `SUCCESS` ندارد؛ `db:patch` آن را **دوباره اجرا می‌کند** — ممکن است قبلاً دستی اجرا شده باشد. رویِ دیتابیسِ توسعه چنین وضعیتی برایِ 005، 031، 033، 034، 035، 045 و 079 دیده شده است. `2.` یعنی پچِ قبلاً اجراشده تغییر کرده و `db:patch` تغییرش را اجرا نمی‌کند.)
   - `7.` برابرِ `False / in release: 0` (اگر پوشهٔ `sql\patches` باشد، `db:patch` فقط همان را می‌خواند).
   - ردیف‌هایِ `SysPatches` را با دست اضافه، ویرایش یا حذف نکنید مگر با تأییدِ صریح و جداگانه.
6. **[تغییردهنده]** فقط پس از گذشتن از گام‌هایِ ۱ تا ۵:
   ```powershell
   git -C C:\Project\Dashboard_New merge --ff-only <RELEASE_COMMIT>
   git -C C:\Project\Dashboard_New rev-parse HEAD     # باید دقیقاً <RELEASE_COMMIT> باشد
   ```
   اگر Fast-forward ممکن نبود، **توقف** (از `reset --hard` یا `push --force` استفاده نکنید). شاخهٔ محلیِ `main` رویِ `<RELEASE_COMMIT>` می‌ماند، حتی اگر `origin/main` جلوتر باشد.
7. **[فقط‌خواندنی]** `Test-Path C:\Project\Dashboard_New\public\hot` باید `False` باشد (وجودِ آن = صفحهٔ سفید؛ **توقف**). `public\build\manifest.json` باید وجود داشته باشد.

### ۴-۲. وابستگی‌هایِ Composer
نصبِ Production همیشه با `--no-dev` است. همهٔ دستورها با PHPِ CLI (`<PHP_DIR>\php.exe`) اجرا می‌شوند.

1. **[فقط‌خواندنی]** نوعِ نصبِ فعلیِ vendor:
   ```powershell
   & "<PHP_DIR>\php.exe" -r "echo json_decode(file_get_contents('C:/Project/Dashboard_New/vendor/composer/installed.json'))->dev ? 'dev' : 'no-dev';"
   ```
   انتظار: `no-dev`. اگر `dev` بود، **توقف**: نصبِ `--no-dev` بسته‌هایِ توسعه را حذف می‌کند و با `--no-scripts` فایلِ `bootstrap\cache\packages.php` هنوز به Providerهایِ حذف‌شده اشاره می‌کند (خطایِ کلاسِ ناموجود). تغییرِ نوعِ نصبِ vendor تصمیمِ جداگانه‌ای است و جزوِ این انتشار نیست.
2. **[فقط‌خواندنی]** سازگاریِ PHPِ CLI با `composer.lock` نسخهٔ جدید (Composer 2.3+):
   ```powershell
   & "<PHP_DIR>\php.exe" <COMPOSER_PHAR> check-platform-reqs --lock --no-dev --no-interaction --working-dir=C:\Project\Dashboard_New
   ```
   انتظار: همهٔ ردیف‌ها `success`. هر `failed`/`missing` = **توقف** و بازگشتِ کد (بخشِ ۸، گامِ ۲). (برایِ CGI همان افزونه‌ها در بخشِ ۲ بررسی شده‌اند.)
3. **[فقط‌خواندنی]** شبیه‌سازی:
   ```powershell
   & "<PHP_DIR>\php.exe" <COMPOSER_PHAR> install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader --dry-run --working-dir=C:\Project\Dashboard_New
   ```
   انتظار: هیچ هشدارِ «lock file is not up to date»؛ عملیات دقیقاً همان فهرستِ گامِ ۴-۱-۴. برایِ بازهٔ `3179c37` تا `790c0f3`: **`Package operations: 1 install, 0 updates, 0 removals`** و فقط `Installing predis/predis (v2.4.1)`. هر عملیاتِ دیگر = **توقف**.
4. **[تغییردهنده]** همان دستور بدونِ `--dry-run`:
   ```powershell
   & "<PHP_DIR>\php.exe" <COMPOSER_PHAR> install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader --working-dir=C:\Project\Dashboard_New
   ```
   - `--no-dev`: بسته‌هایِ توسعه نصب نمی‌شوند. `--optimize-autoloader` و `--prefer-dist`: تنظیمِ Production (در `composer.json` هم آمده‌اند). `--no-interaction`: بدونِ پرسش.
   - `--no-scripts` عمدی است: predis پکیجِ Laravel نیست و نیازی به `package:discover` ندارد؛ هیچ دستورِ artisanی ضمنِ نصب اجرا نمی‌شود.
   - هرگز `composer update` اجرا نکنید؛ فقط `install` از رویِ `composer.lock`.

### ۴-۳. پچ‌هایِ دیتابیس (با گام‌هایِ توقف)
تطبیقِ کاملِ `SysPatches` پیش از merge انجام شده است (بخشِ ۴-۱، گامِ ۵). اگر آنجا توقف داشتید، به این بخش نرسیده‌اید.
1. **[فقط‌خواندنی]** تأییدِ دوباره با خودِ ابزار (جدولِ `SysPatches` در ۴-۱ وجودش تأیید شده، پس این دستور چیزی نمی‌سازد):
   ```powershell
   Set-Location C:\Project\Dashboard_New
   & "<PHP_DIR>\php.exe" artisan db:patch --status
   ```
   فهرستِ «در انتظار اجرا (Pending)» باید **دقیقاً** همان ردیفِ `1.` در ۴-۱-۵ باشد (برایِ این انتشار همان ۱۴ پچِ `064` تا `069` و `080` تا `087`). هر تفاوت = **توقف**.
2. **[فقط‌خواندنی]** این اشیا نباید از قبل وجود داشته باشند (اگر وجود دارند، **توقف** و بررسی):
   ```powershell
   sqlcmd -S <SQL_SERVER> <AUTH> -C -d Dashboard_DB -Q "SELECT name FROM sys.objects WHERE name IN ('fn_Gs_Normalize','sp_Gs_Search','sp_Gs_SearchMessages','sp_Gs_SearchProjects','sp_Gs_SearchProcesses','sp_Wf_AddTaskMessageRecipients','CrmPartyRoles','CrmPartyPositions','CrmPartyPersonRelationPositions','sp_Crm_GetPartyRoles','sp_Crm_GetPartyPositions','sp_Crm_SavePartyRoles','sp_Crm_SavePartyPositions','sp_Crm_GetRelationPositionIds','sp_Crm_SaveRelationPositions','sp_Crm_DeleteRecord');"
   ```
   **[فقط‌خواندنی]** و برعکس، این ۲۱ رویهٔ موجود که پچ‌ها با `ALTER PROCEDURE` تغییرشان می‌دهند **باید** وجود داشته باشند (`sp_GetMsgPriorities` در هیچ پچی ساخته نمی‌شود و جزوِ ساختارِ پایهٔ دیتابیس است). خروجی باید **خالی** باشد؛ هر نامِ چاپ‌شده = **توقف**:
   ```powershell
   sqlcmd -S <SQL_SERVER> <AUTH> -C -d Dashboard_DB -Q "SELECT v.n AS missing FROM (VALUES ('sp_Crm_GetActivities'),('sp_Crm_GetAddressTitles'),('sp_Crm_GetCities'),('sp_Crm_GetContactRoles'),('sp_Crm_GetContactTypes'),('sp_Crm_GetCounties'),('sp_Crm_GetDepartments'),('sp_Crm_GetMunicipalZones'),('sp_Crm_GetNeighborhoods'),('sp_Crm_GetOwnershipTypes'),('sp_Crm_GetPartyRelations'),('sp_Crm_GetPartyTypes'),('sp_Crm_GetPersonRelations'),('sp_Crm_GetPositions'),('sp_Crm_GetProductCategories'),('sp_Crm_GetProvinces'),('sp_Crm_GetTitles'),('sp_GetMsgPriorities'),('sp_GetProjectPriorities'),('sp_Wf_CloneVersion'),('sp_Wf_SaveDefinition')) v(n) WHERE OBJECT_ID('dbo.' + v.n, 'P') IS NULL;"
   ```
3. **[تغییردهنده — دیتابیس]** فقط اگر بررسیِ «دسترسیِ SQL» در بخشِ ۲ بدونِ توقف گذشته **و** مسئولِ دیتابیس تأییدِ دستیِ صریح داده است: `& "<PHP_DIR>\php.exe" artisan db:patch` — همهٔ پچ‌هایِ Pending به ترتیبِ نام اجرا می‌شوند (به همین دلیل گامِ ۱ باید دقیقاً همان فهرست را نشان دهد)، با اولین خطا متوقف می‌شود و نتیجه در `SysPatches` ثبت می‌شود.
   اگر خطا داد: **توقف**؛ دوباره اجرا نکنید. ردیفِ `FAILED` ثبت می‌شود و بخش‌هایِ (batchهایِ) پیش از خطا ممکن است اعمال شده باشند؛ وضعیت گزارش و جداگانه تصمیم‌گیری شود.
4. **[فقط‌خواندنی]** بررسی:
   ```powershell
   sqlcmd -S <SQL_SERVER> <AUTH> -C -d Dashboard_DB -Q "SELECT name, type_desc FROM sys.objects WHERE name LIKE 'sp[_]Gs[_]%' OR name LIKE 'fn[_]Gs[_]%' OR name IN ('sp_Wf_AddTaskMessageRecipients','CrmPartyRoles','CrmPartyPositions','CrmPartyPersonRelationPositions','sp_Crm_GetPartyRoles','sp_Crm_GetPartyPositions','sp_Crm_SavePartyRoles','sp_Crm_SavePartyPositions','sp_Crm_GetRelationPositionIds','sp_Crm_SaveRelationPositions','sp_Crm_DeleteRecord'); SELECT PatchName, Status FROM dbo.SysPatches WHERE PatchName LIKE '06[4-9]%' OR PatchName LIKE '08[0-7]%'; SELECT PermissionCode FROM dbo.Permissions WHERE PermissionCode = 'CRM_DELETE'; SELECT MenuTitle FROM dbo.Menu WHERE Url = '/crm/directory' OR MenuID IN (SELECT ParentID FROM dbo.Menu WHERE Url = '/crm/parties');"
   ```
   انتظار: ۱۶ شیء (۵ شیءِ Global Search + `sp_Wf_AddTaskMessageRecipients` + ۳ جدول و ۷ رویهٔ CRM)، ۱۴ ردیفِ `SUCCESS`، یک ردیفِ `CRM_DELETE`، و عنوان‌هایِ منو «مخاطبین و ارتباطات» و «فهرست‌های مخاطبین و ارتباطات» (اگر سرورِ مشتری عنوانِ پوشه را قبلاً دستی عوض کرده باشد، `087` به آن دست نمی‌زند).

نکته‌هایِ ایمنی:
- پچ‌هایِ Global Search (`085`، `086`) هر دو `CREATE OR ALTER` هستند؛ اجرایِ مجددِ «085 سپس 086» بدونِ خطا و با همان نتیجه است (در محیطِ ایزوله آزموده شد).
- **هرگز 085 را به‌تنهایی پس از 086 اجرا نکنید** (اصلاحِ جست‌وجویِ آدرس برمی‌گردد). اجرایِ دستی فقط به ترتیبِ 085 سپس 086.
- پچ‌هایِ `067`، `068` و `069` رفتارِ نامه‌هایِ فرایندی و فهرستِ صندوقِ نامه (`sp_GetMessages`) را برایِ همهٔ کاربران تغییر می‌دهند؛ پس از انتشار، صندوقِ نامه و یک فرایندِ نمونه را با یک حسابِ واقعی بررسی کنید.
- پچِ `083` دسترسیِ `CRM_DELETE` (حذفِ دائمیِ رکوردهایِ CRM که جایی استفاده نشده‌اند) را به **نقشِ ۱** می‌دهد؛ اگر نقشِ ۱ رویِ سرورِ مشتری کاربرانِ زیادی دارد، پیش از انتشار تصمیم بگیرید و پس از انتشار دسترسی‌ها را بررسی کنید.
- پچِ `087` عنوانِ منویِ CRM را عوض می‌کند و `SortOrder`ِ فهرست‌هایِ پایهٔ CRM را صفر می‌کند؛ اگر مشتری ترتیبِ دلخواهی تعریف کرده، پس از انتشار ترتیب بر اساسِ Code (قاعدهٔ `081`) می‌شود. مقادیرِ قبلی فقط از بکاپِ بخشِ ۳ برمی‌گردند.
- پچ‌هایِ `080`/`082`/`083` با `CREATE` (بدونِ `OR ALTER`) شیء می‌سازند؛ اجرایِ دوباره‌شان پس از موفقیت خطا می‌دهد — `db:patch` به‌خاطرِ `SysPatches` آن‌ها را دوباره اجرا نمی‌کند؛ دستی اجرا نکنید.
- اگر سرور تریگرِ DDLِ `trg_DevTrackSchemaChanges` دارد، این پچ‌ها در `DevChangeLog` هم ثبت می‌شوند؛ این ثبت بی‌اثر است، ولی حسابِ برنامه باید اجازهٔ درج در `DevChangeLog` را داشته باشد (بررسیِ «دسترسیِ SQL» در بخشِ ۲).
### ۴-۴. `.env` برایِ این مرحله
هیچ کلیدِ Global Search لازم نیست (پیش‌فرضِ کد خاموش است). اگر کلیدها از قبل وجود دارند، **[فقط‌خواندنی]** فقط کلیدهایِ `GLOBAL_SEARCH_*` و **مقدارشان** را بررسی کنید (این مقادیر فقط `true`/`false` و محرمانه نیستند):
```powershell
Get-Content C:\Project\Dashboard_New\.env | Where-Object { $_ -match '^\s*GLOBAL_SEARCH_' } | ForEach-Object { ($_ -split '=')[0] + ' = ' + ($_ -split '=', 2)[1] }
```
هیچ کلیدِ دیگری را نمایش ندهید (به‌ویژه `GS_RATELIMIT_REDIS_PASSWORD`، `DB_*` و `APP_KEY`؛ الگویِ بالا آن‌ها را نشان نمی‌دهد). در این مرحله همه باید `false` یا تعریف‌نشده باشند.
### ۴-۵. پاک‌سازیِ امنِ کشِ Laravel
**[تغییردهنده — فقط فایل‌هایِ کامپایل‌شده را حذف می‌کند؛ به داده دست نمی‌زند]**
```powershell
Set-Location C:\Project\Dashboard_New
& "<PHP_DIR>\php.exe" artisan config:clear
& "<PHP_DIR>\php.exe" artisan route:clear
& "<PHP_DIR>\php.exe" artisan view:clear
```
- اگر در بخشِ ۲ دیدید سرور از `config:cache`/`route:cache` استفاده می‌کرد، همان را دوباره بسازید: `artisan config:cache` و `artisan route:cache`.
- **`cache:clear` و `optimize:clear` را اجرا نکنید**؛ لازم نیستند و کلِ کشِ برنامه را پاک می‌کنند.

### ۴-۶. OPcacheِ PHP FastCGI
- اگر `opcache.validate_timestamps` روشن است (پیش‌فرضِ PHP)، فایل‌هایِ جدید ظرفِ چند ثانیه خوانده می‌شوند؛ اقدامی لازم نیست.
- اگر خاموش است: **[تغییردهنده — قطعِ کوتاه]** `Restart-Service <PHP_SERVICE>`. Nginx نیازی به Restart ندارد (کانفیگش تغییر نکرده است).

### ۴-۷. آزمونِ سلامت (قابلیت خاموش) — همه [فقط‌خواندنی]
```powershell
$r = Invoke-WebRequest http://localhost:8080/login -UseBasicParsing
$r.StatusCode                                                   # 200
[regex]::Match($r.Content, '"appVersion":"[^"]+"').Value         # "appVersion":"1.0.5"
[regex]::Match($r.Content, '"globalSearch":(true|false)').Value  # "globalSearch":false
```
سپس با یک حسابِ واقعی: ورود/خروج، داشبورد، CRM، نامه‌ها، پروژه‌ها و فرایندها عادی؛ Ctrl+K فقط منوها را نشان می‌دهد؛ `storage\logs\laravel.log` خطایِ جدید ندارد. **انتشارِ کد در این نقطه کامل است.**

## ۵. پیش از روشن‌کردن: چه چیزی لازم است
همهٔ موارد زیر باید **پیش از** بخشِ ۷ تأیید شده باشند؛ نبودِ هرکدام = **توقف** و Global Search خاموش می‌ماند:
1. Redisِ مستقلِ آماده و سالم (بخشِ ۶).
2. **اجرایِ خودکارِ Docker پس از Restartِ سرور تأیید شده باشد:** پس از یک Restartِ برنامه‌ریزی‌شدهٔ سرور (که خودش تغییردهنده است و پنجره و تأییدِ جداگانه می‌خواهد)، **بدونِ ورودِ هیچ کاربری به ویندوز**، `docker inspect dashboard_gs_redis --format "{{.State.Health.Status}}"` باید `healthy` بدهد. تنظیمِ «شروع پس از ورودِ کاربر» در Docker Desktop یا `Automatic` بودنِ سرویسِ Docker به‌تنهایی کافی نیست؛ اگر این آزمون انجام نشده یا موفق نبوده، **توقف**.
3. در بررسیِ «دسترسیِ SQL» بخشِ ۲، `execute_schema_dbo` برابرِ `1` باشد (یا دسترسیِ اجرایِ چهار رویهٔ `dbo.sp_Gs_*` توسطِ مسئولِ دیتابیس تأیید شده باشد).
4. پنجرهٔ تغییرِ جداگانه و یک حسابِ دارایِ `CRM_VIEW`/`WORKFLOW_VIEW` و یک حسابِ بدونِ آن‌ها برایِ آزمون.

## ۶. Redisِ اختصاصیِ Global Search

**وابستگیِ صریح:** راهِ آزموده‌شده سرویسِ `gs-redis` در `docker-compose.yml` است (ایمیج `redis:7.4.11-alpine`، پروفایلِ `globalsearch`). این یعنی رویِ سرورِ شرکت باید **Docker با قابلیتِ اجرایِ کانتینرِ لینوکسی** (`docker info --format '{{.OSType}}'` → `linux`) نصب باشد.
- اگر Docker نیست یا فقط کانتینرِ ویندوزی اجرا می‌کند: **Global Search خاموش بماند.** در این پروژه هیچ جایگزینِ دیگری برایِ تولید آزموده و تأیید نشده است؛ هر گزینهٔ دیگر (از جمله اجرایِ Redis-compatible به‌صورتِ سرویسِ ویندوز) پیش از استفاده باید جداگانه ارزیابی، آزموده و تأیید شود.
- **پایداری پس از Restartِ سرور:** کانتینر `restart: unless-stopped` دارد، ولی فقط وقتی دوباره بالا می‌آید که **خودِ Docker** پس از Restart اجرا شود. Docker Desktop به‌طورِ معمول پس از ورودِ کاربر شروع می‌شود؛ اجرایِ خودکارِ Docker رویِ این سرور باید طبقِ بخشِ ۵ (موردِ ۲) **آزموده و تأیید** شود؛ تا آن زمان Global Search روشن نمی‌شود. اگر پس از روشن‌شدن Docker بالا نیاید، جست‌وجو با 503 متوقف می‌شود و بقیهٔ سایت سالم است.
- اتصالِ برنامه: PHPِ این سرور خارج از Docker اجرا می‌شود، پس از طریقِ loopbackِ منتشرشده وصل می‌شود: `127.0.0.1:6392`. این پورت فقط رویِ `127.0.0.1` منتشر است و از شبکه در دسترس نیست. اگر پورتِ 6392 رویِ سرور اشغال است، در `.env` مقدارِ `GS_REDIS_HOST_PORT` را عوض کنید و همان را در `GS_RATELIMIT_REDIS_PORT` بگذارید.
- Redisِ اصلیِ برنامه (اگر وجود دارد) هیچ نقشی ندارد و دست نمی‌خورد.
- **هشدار — Docker Compose رویِ سرورِ شرکت:** اجرایِ عمومیِ `docker compose up -d` (یا هر `up` بدونِ نامِ سرویس)، `docker compose down` و `--remove-orphans` **مجاز نیست**. `docker-compose.yml` سرویس‌هایِ `app` (PHP-FPM) و `nginx` (پورت‌هایِ 8080 و 443) را هم دارد که با Nginx و PHPِ ویندوزی تداخل می‌کنند. فقط پس از تأییدِ پیش‌نیازهایِ همین بخش، و فقط با هدف‌گرفتنِ صریحِ سرویسِ `gs-redis` (گامِ ۴ زیر).

گام‌ها:
1. **[فقط‌خواندنی]** `Get-NetTCPConnection -State Listen -LocalPort 6392 -ErrorAction SilentlyContinue` — باید خالی باشد.
2. **[فقط‌خواندنی]** ساختِ رمزِ قوی (فقط `A-Z a-z 0-9 _ -`، حداقل ۳۲ نویسه؛ خروجی را جایی جز `.env` ذخیره نکنید):
   ```powershell
   $b = New-Object byte[] 32; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($b)
   [Convert]::ToBase64String($b).TrimEnd('=').Replace('+','-').Replace('/','_')
   ```
3. **[تغییردهنده]** افزودن به `.env` سرور (نه Git):
   ```
   GS_RATELIMIT_REDIS_HOST=127.0.0.1
   GS_RATELIMIT_REDIS_PORT=6392
   GS_RATELIMIT_REDIS_PASSWORD=<رمزِ ساخته‌شده>
   ```
   پیش از افزودن بررسی کنید این کلیدها تکراری نشوند (در `.env` اولین تعریف معتبر است).
4. **[تغییردهنده]** راه‌اندازیِ فقط همین سرویس (نامِ `gs-redis` و `--no-deps` الزامی است؛ سرویس‌هایِ دیگرِ compose اجرا نمی‌شوند):
   ```powershell
   Set-Location C:\Project\Dashboard_New
   docker compose --profile globalsearch up -d --no-deps gs-redis
   ```
   اگر سرور به Docker Hub دسترسی ندارد، بخشِ ۹.
5. **[فقط‌خواندنی]** `docker inspect dashboard_gs_redis --format "{{.State.Health.Status}} {{.HostConfig.RestartPolicy.Name}}"` → `healthy unless-stopped`.
6. **[فقط‌خواندنی]** اتصالِ برنامه (بدونِ چاپِ رمز؛ فقط یک شمارندهٔ کوتاه‌عمر در خودِ gs-redis ثبت می‌شود):
   ```powershell
   Set-Location C:\Project\Dashboard_New
   & "<PHP_DIR>\php.exe" -r "require 'vendor/autoload.php'; `$a = require 'bootstrap/app.php'; `$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); var_dump(`$a->make(App\Services\GlobalSearch\RateLimit\SearchRateLimiter::class)->attempt('deploy-check', 20, 10)->allowed);"
   ```
   انتظار: `bool(true)`. خطا = Global Search روشن نشود.

## ۷. روشن‌کردنِ Global Search
1. **[تغییردهنده]** در `.env`:
   ```
   GLOBAL_SEARCH_ENABLED=true
   GLOBAL_SEARCH_CRM_ENABLED=true
   GLOBAL_SEARCH_MESSAGES_ENABLED=true
   GLOBAL_SEARCH_PROJECTS_ENABLED=true
   GLOBAL_SEARCH_PROCESSES_ENABLED=true
   ```
   فقط مقدارِ دقیقِ `true` روشن می‌کند.
2. اگر سرور Config Cache دارد: **[تغییردهنده]** `artisan config:cache`؛ وگرنه تغییر از درخواستِ بعدی اعمال می‌شود.
3. آزمون — همه [فقط‌خواندنی]:
   - صفحهٔ ورود: `"globalSearch":true`.
   - حسابِ دارایِ `CRM_VIEW` و `WORKFLOW_VIEW`: Ctrl+K → نامِ یک طرف‌حساب یا یک شهر (مثلاً «تهران») → گروه‌هایِ CRM، نامه‌ها، پروژه‌ها، فرایندها.
   - حسابِ بدونِ `CRM_VIEW`: گروهِ CRM **نمایش داده نمی‌شود**؛ نامه‌ها فقط برایِ فرستنده/گیرنده/رونوشت؛ پروژه‌ها فقط برایِ سازنده/عضوِ فعال؛ فرایندها فقط با `WORKFLOW_VIEW`.
   - بیش از ۲۰ جست‌وجو در ۱۰ ثانیه: پیامِ «تعداد درخواست‌ها زیاد است» (429).
   - `laravel.log` متنِ جست‌وجو را ثبت نمی‌کند؛ هشدارهایِ `global_search.*` فقط نامِ کلاسِ خطا دارند.

## ۸. خاموش‌کردنِ فوری و بازگشت

### خاموش‌کردنِ فوری (بدونِ قطعی)
1. **[تغییردهنده]** در `.env`: `GLOBAL_SEARCH_ENABLED=false`
2. اگر سرور Config Cache دارد: **[تغییردهنده]** `artisan config:cache` (یا `artisan config:clear`).
3. **[فقط‌خواندنی]** صفحهٔ ورود باید `"globalSearch":false` را نشان دهد. مسیرِ جست‌وجو 404 می‌شود و Ctrl+K فقط منوها را جست‌وجو می‌کند. نیازی به Restartِ سرویس، Build یا تغییرِ دیتابیس نیست.

### بازگشت به نسخهٔ قبل (فقط کد و vendor)

> **مهم: بازگشتِ کد، دیتابیس را به حالتِ پیش از انتشار برنمی‌گرداند و بازگشتِ کامل یا بدونِ اثر نیست.**
> - همهٔ تغییراتِ پچ‌هایِ `064`–`069` و `080`–`087` در `Dashboard_DB` **باقی می‌مانند**: ۳ جدولِ جدیدِ CRM (و هر داده‌ای که پس از انتشار در آن‌ها ثبت شده)، رویه‌هایِ جدید، ردیفِ `CRM_DELETE` و اعطایِ آن به نقشِ ۱، عنوان‌هایِ جدیدِ منو و `SortOrder`هایِ صفرشده (`087`؛ مقادیرِ قبلی فقط از بکاپ برمی‌گردند)، و **نسخهٔ جدیدِ ۲۲ رویهٔ موجود** (از جمله `sp_GetMessages` و رویه‌هایِ Workflow). کدِ قبلی با نسخهٔ جدیدِ این رویه‌ها آزموده نشده است؛ بازگشتِ کد ممکن است در Workflow و صندوقِ نامه رفتارِ ناسازگار داشته باشد و تنها راهِ بازگرداندنِ کاملِ آن‌ها Restore از بکاپ است.
> - ردیف‌هایِ `SUCCESS` این پچ‌ها در `SysPatches` **باقی می‌مانند**؛ در انتشارِ دوباره `db:patch` آن‌ها را اجرا نمی‌کند. در آن انتشار ردیفِ `4.` بررسیِ ۴-۱-۵ همین پچ‌ها را نشان می‌دهد، `db:patch --status` (۴-۳، گامِ ۱) آن‌ها را Pending نشان نمی‌دهد و گامِ ۲ بخشِ ۴-۳ اشیایِ آن‌ها را موجود می‌یابد؛ این انحرافِ شناخته‌شده باید پیش از ادامه صریحاً بررسی و تأیید شود.
> - اشیا یا ردیف‌هایِ `SysPatches` را با دست حذف یا ویرایش نکنید.
> - برگرداندنِ کاملِ دیتابیس فقط با Restore از بکاپِ بخشِ ۳ ممکن است: آخرین راه‌حل است، همهٔ داده‌هایِ ثبت‌شده پس از بکاپ را از بین می‌برد، به تأییدِ صریح و جداگانه نیاز دارد و جزوِ این روند نیست.

1. خاموش‌کردنِ فوری (بالا).
2. **[تغییردهنده]** کد:
   ```powershell
   git -C C:\Project\Dashboard_New checkout <PREVIOUS_COMMIT>
   git -C C:\Project\Dashboard_New rev-parse HEAD     # باید دقیقاً <PREVIOUS_COMMIT> باشد
   ```
   (همان مقدارِ بخشِ ۳-۴؛ تاریخچه را بازنویسی نکنید: نه `reset --hard`، نه `push --force`.) پس از این دستور مخزن در حالتِ **detached HEAD** است و شاخهٔ `main` هنوز به `<RELEASE_COMMIT>` اشاره می‌کند؛ این عمدی است. `git checkout main` خودش کدِ نسخهٔ جدید را برمی‌گرداند، پس فقط در یک انتشارِ مجددِ تأییدشده اجرا شود: بخش‌هایِ ۳ و ۴ با `<PREVIOUS_COMMIT>` برابرِ همین commitِ فعلی دنبال شوند، با دو تفاوت: در ۳-۴ خروجیِ `branch --show-current` خالی است (detached؛ قابلِ انتظار)، و در ۴-۱-۶ به‌جایِ `merge` دستورِ `git -C C:\Project\Dashboard_New checkout main` اجرا شود که پس از آن `rev-parse HEAD` باید دقیقاً `<RELEASE_COMMIT>` باشد؛ در غیرِ این صورت **توقف**.
3. **[فقط‌خواندنی سپس تغییردهنده]** Composer (همان گزینه‌هایِ Production در بخشِ ۴-۲):
   ```powershell
   & "<PHP_DIR>\php.exe" <COMPOSER_PHAR> install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --optimize-autoloader --dry-run --working-dir=C:\Project\Dashboard_New
   ```
   انتظار: دقیقاً عکسِ فهرستِ ۴-۱-۴؛ برایِ بازهٔ `3179c37` تا `790c0f3`: `Package operations: 0 installs, 0 updates, 1 removal` و فقط `Removing predis/predis (v2.4.1)`. هر عملیاتِ دیگر = **توقف**. سپس همان دستور بدونِ `--dry-run`.
4. **[تغییردهنده]** کش: `artisan config:clear`، `route:clear`، `view:clear` (و اگر سرور از قبل استفاده می‌کرد، `config:cache`/`route:cache`)؛ اگر OPcache بدونِ validate_timestamps است، `Restart-Service <PHP_SERVICE>`.
5. Redis (فقط اگر در بخشِ ۶ راه‌اندازی شده بود): **[تغییردهنده]** `docker stop dashboard_gs_redis` (داده‌ای ندارد؛ کانتینر حذف نمی‌شود). از `docker compose down` استفاده نکنید.
6. **[فقط‌خواندنی]** آزمونِ سلامتِ بخشِ ۴-۷: کدِ `200` و `appVersion` نسخهٔ قبلی (نه `1.0.5`).

## ۹. سرورِ بدونِ دسترسیِ اینترنت
- **Composer:** بدونِ دسترسی به Packagist/GitHub نصبِ predis ممکن نیست. یا دسترسیِ موقت فراهم شود، یا انتقالِ `vendor` از محیطی با همان `composer.lock` و همان نسخهٔ PHP — این روش رویِ سرورِ شرکت آزموده نشده و پیش از استفاده باید تأیید شود. تا آن زمان انتشار را متوقف کنید.
- **ایمیجِ Redis:** رویِ ماشینی که ایمیج را دارد `docker save redis:7.4.11-alpine -o redis-7.4.11-alpine.tar` و رویِ سرور `docker load -i redis-7.4.11-alpine.tar` (فقط اگر Docker رویِ سرور وجود دارد).

## ۱۰. نکته‌هایِ امنیتی
- `.env`، رمزِ Redis، بکاپ‌ها و لاگ‌ها هرگز وارد Git نشوند و در خروجیِ دستورها نمایش داده نشوند.
- رمزِ Redis در متغیرِ محیطیِ کانتینر قرار می‌گیرد و با `docker inspect` قابلِ مشاهده است؛ دسترسیِ Docker رویِ سرور را محدود نگه دارید.
- آزمون‌هایِ خودکار (`phpunit -c phpunit.globalsearch.xml`) فقط برایِ LocalDBِ ایزولهٔ توسعه هستند و **رویِ سرورِ شرکت اجرا نشوند**.
- IIS در این روند نقشی ندارد و نباید تغییر کند.
- رویِ سرورِ شرکت `docker compose up -d`ِ عمومی، `docker compose down` و `--remove-orphans` مجاز نیست؛ فقط `gs-redis` با نامِ صریح (بخشِ ۶).

## ۱۱. فهرستِ کنترلِ روزِ انتشار

| # | گام | نوع | توقف اگر |
|---|---|---|---|
| 1 | شناختِ سرور (بخشِ ۲) | فقط‌خواندنی | PHP < 8.2؛ نسخهٔ متفاوتِ CLI و CGI؛ نبودِ هر افزونهٔ فهرست در CLI **یا** CGI؛ SQL < 2017؛ Working Treeِ ناتمیز؛ نبودِ Composer؛ هر تعریفِ `APP_VERSION` در `.env`؛ خروجیِ «دسترسیِ SQL» خلافِ انتظار، تریگرِ بررسی‌نشده، یا نبودِ تأییدِ دستیِ مسئولِ دیتابیس |
| 2 | بکاپ + VERIFYONLY + کپیِ پوشه + ثبتِ `<PREVIOUS_COMMIT>` | ایجادِ فایل | VERIFYONLY ناموفق؛ شاخه `main` نباشد |
| 3 | تعیینِ `<RELEASE_COMMIT>`، `fetch`، مرورِ commitهایِ بازه | فقط‌خواندنی (+ refهایِ remote) | commitِ ناشناخته؛ مقصد در `origin/main` نباشد؛ Fast-forward ممکن نباشد |
| 4 | تغییراتِ `composer.json`/`composer.lock` در بازه | فقط‌خواندنی | بسته‌ای جز موارد بررسی‌شده (برایِ این انتشار فقط افزودنِ predis 2.4.1) |
| 5 | تطبیقِ `SysPatches` با پچ‌هایِ دو نسخه (۴-۱-۵) | فقط‌خواندنی | دیتابیسِ دیگر؛ نبودِ جدول؛ هر مقدار در ردیف‌هایِ ۲ تا ۶؛ پچِ جدیدِ توضیح‌داده‌نشده؛ پوشهٔ `sql\patches` |
| 6 | `merge --ff-only <RELEASE_COMMIT>` | تغییردهنده | HEAD برابرِ `<RELEASE_COMMIT>` نشود؛ `public\hot` وجود داشته باشد |
| 7 | Composer: نوعِ vendor، `check-platform-reqs`، dry-run سپس `install --no-dev` | تغییردهنده | vendorِ `dev`؛ نیازمندیِ ناموفق؛ عملیاتی جز فهرستِ گامِ ۴ |
| 8 | `db:patch --status`، نبودِ اشیایِ جدید، `db:patch`، بررسی | تغییردهنده (دیتابیس) | نبودِ تأییدِ دستیِ مسئولِ دیتابیس برایِ دسترسیِ SQL؛ Pending ≠ فهرستِ ۴-۱-۵؛ هر یک از اشیایِ جدید از قبل موجود؛ خطا، کمتر از ۱۶ شیء یا ۱۴ ردیفِ `SUCCESS` |
| 9 | پاک‌سازیِ کش (+ Restartِ PHP در صورتِ نیاز) | تغییردهنده | — |
| 10 | آزمونِ سلامت با قابلیتِ خاموش | فقط‌خواندنی | هر خطا → بازگشت (بخشِ ۸؛ دیتابیس برنمی‌گردد) |
| 11 | Redisِ مستقل (فقط اگر Docker لینوکسی هست؛ فقط سرویسِ `gs-redis`) | تغییردهنده | Docker نیست یا `healthy` نشود یا آزمونِ اتصال `true` ندهد |
| 12 | تأییدِ اجرایِ خودکارِ Docker پس از Restart (بخشِ ۵، موردِ ۲) و `execute_schema_dbo` | Restart: تغییردهنده با تأییدِ جداگانه | پس از Restart و بدونِ ورودِ کاربر `healthy` نشود، یا آزمون انجام نشده باشد؛ نبودِ دسترسیِ اجرایِ رویه‌ها |
| 13 | روشن‌کردن و آزمون با حساب‌هایِ مجاز/غیرمجاز | تغییردهنده | هر یک از ردیف‌هایِ ۱۱ و ۱۲ تأیید نشده؛ هر نشتِ اطلاعات → خاموش‌کردنِ فوری |
