<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('نمایش یک جمله الهام‌بخش');

/*
|--------------------------------------------------------------------------
| ۲. فعال‌سازی ردیاب خودکار تغییرات دیتابیس در محیط توسعه (DDL Trigger)
|--------------------------------------------------------------------------
*/
Artisan::command('db:init-tracker', function () {
    $this->info('در حال راه‌اندازی ردیاب خودکار تغییرات دیتابیس در SQL Server...');

    // ۱. جدول لاگ تغییرات
    DB::unprepared("
        IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'DevChangeLog')
        BEGIN
            CREATE TABLE [dbo].[DevChangeLog] (
                [LogID] INT IDENTITY(1,1) PRIMARY KEY,
                [PostTime] DATETIME NOT NULL DEFAULT GETDATE(),
                [EventType] NVARCHAR(100) NOT NULL,
                [ObjectName] NVARCHAR(255) NULL,
                [ObjectType] NVARCHAR(100) NULL,
                [TSQLCommand] NVARCHAR(MAX) NOT NULL,
                [IsExported] BIT NOT NULL DEFAULT 0,
                [ExportedAt] DATETIME NULL
            );
        END
    ");

    // ۲. تریگر پایگاه داده
    DB::unprepared("
        IF EXISTS (SELECT 1 FROM sys.triggers WHERE parent_class = 0 AND name = 'trg_DevTrackSchemaChanges')
        BEGIN
            DROP TRIGGER [trg_DevTrackSchemaChanges] ON DATABASE;
        END
    ");

    DB::unprepared("
        CREATE TRIGGER [trg_DevTrackSchemaChanges]
        ON DATABASE
        FOR DDL_DATABASE_LEVEL_EVENTS
        AS
        BEGIN
            SET NOCOUNT ON;
            DECLARE @data XML = EVENTDATA();
            DECLARE @objName NVARCHAR(255) = @data.value('(/EVENT_INSTANCE/ObjectName)[1]', 'NVARCHAR(255)');
            DECLARE @eventType NVARCHAR(100) = @data.value('(/EVENT_INSTANCE/EventType)[1]', 'NVARCHAR(100)');
            DECLARE @objType NVARCHAR(100) = @data.value('(/EVENT_INSTANCE/ObjectType)[1]', 'NVARCHAR(100)');
            DECLARE @cmd NVARCHAR(MAX) = @data.value('(/EVENT_INSTANCE/TSQLCommand/CommandText)[1]', 'NVARCHAR(MAX)');

            -- نادیده گرفتن جداول و تریگرهای سیستمی
            IF @objName IN ('DevChangeLog', 'SysPatches', 'trg_DevTrackSchemaChanges')
                RETURN;

            IF @eventType IN ('UPDATE_STATISTICS', 'CREATE_STATISTICS')
                RETURN;

            IF NULLIF(@cmd, '') IS NOT NULL
            BEGIN
                INSERT INTO [dbo].[DevChangeLog] (PostTime, EventType, ObjectName, ObjectType, TSQLCommand, IsExported)
                VALUES (GETDATE(), @eventType, @objName, @objType, @cmd, 0);
            END
        END
    ");

    $this->info(' <fg=green>[SUCCESS]</> ردیاب خودکار با موفقیت فعال شد! از این به بعد هر تغییری در دیتابیس بدهید خودکار ثبت می‌شود.');
})->purpose('فعال‌سازی سیستم ثبت خودکار تغییرات دیتابیس در محیط توسعه');

/*
|--------------------------------------------------------------------------
| ۳. تولید خودکار فایل پچ از تغییرات اخیر دیتابیس
|--------------------------------------------------------------------------
*/
Artisan::command('db:make-patch {name? : نام پچ به انگلیسی}', function () {
    $this->info('==================================================');
    $this->info('   تولید خودکار فایل پچ از تغییرات اخیر دیتابیس   ');
    $this->info('==================================================');

    $hasTable = DB::select("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'DevChangeLog'");
    if (empty($hasTable)) {
        $this->error('ردیاب فعال نیست. لطفاً ابتدا دستور زیر را بزنید:');
        $this->line('docker exec dashboard_app php artisan db:init-tracker');
        return 1;
    }

    $changes = DB::table('dbo.DevChangeLog')
        ->where('IsExported', 0)
        ->orderBy('LogID', 'asc')
        ->get();

    if ($changes->isEmpty()) {
        $this->warn('هیچ تغییر جدیدی در دیتابیس از آخرین بار ثبت نشده است.');
        return 0;
    }

    $name = $this->argument('name');
    if (!$name) {
        $name = $this->ask('یک نام کوتاه انگلیسی برای این پچ وارد کنید (مثلاً update_messages):', 'update_database');
    }
    $cleanName = Str::slug($name, '_') ?: 'update_database';
    $directory = storage_path('app/patch-drafts');
    File::ensureDirectoryExists($directory);
    $filename = $cleanName.'_'.Str::uuid().'.sql';
    $content = "-- Draft for review. Choose a release number only when publishing.\n\n";

    foreach ($changes as $change) {
        $content .= "-- DevChangeLog LogID: ".(int) $change->LogID."\n";
        $content .= rtrim($change->TSQLCommand)."\nGO\n\n";
    }

    if (File::put($directory.DIRECTORY_SEPARATOR.$filename, $content, true) !== strlen($content)) {
        $this->error('Draft could not be written.');
        return 1;
    }

    $this->info("Draft saved: {$directory}/{$filename}");
    return 0;
})->purpose('تولید خودکار فایل پچ از تغییرات اخیر دیتابیس');

/*
|--------------------------------------------------------------------------
| ۴. استخراج تمام Stored Procedureها در فایل‌های جداگانه برای نسخه و گیت
|--------------------------------------------------------------------------
*/
Artisan::command('db:dump-sps', function () {
    $this->info('در حال استخراج تمام Stored Procedureها در پوشه sql/procedures ...');

    $dir = base_path('sql/procedures');
    if (!File::exists($dir)) {
        File::makeDirectory($dir, 0755, true);
    }

    $sps = DB::select("
        SELECT 
            OBJECT_NAME(sm.object_id) AS ProcedureName,
            sm.definition AS Definition
        FROM sys.sql_modules sm
        JOIN sys.objects o ON sm.object_id = o.object_id
        WHERE o.type = 'P' AND o.is_ms_shipped = 0
        ORDER BY ProcedureName
    ");

    $count = 0;
    foreach ($sps as $sp) {
        $name = $sp->ProcedureName;
        $def = trim($sp->Definition);
        File::put("{$dir}/{$name}.sql", $def . "\nGO\n");
        $count++;
    }

    $this->info(" <fg=green>[SUCCESS]</> تعداد {$count} Stored Procedure در پوشه sql/procedures ذخیره شد.");
})->purpose('استخراج تمام Stored Procedureها برای نگهداری در گیت');
/*
|--------------------------------------------------------------------------
| ۵. Web Push — زمان‌بندی (فقط سرویسِ scheduler در docker-compose آن را اجرا می‌کند: php artisan schedule:work)
|--------------------------------------------------------------------------
| withoutOverlapping + onOneServer: قفلِ مشترک در Cache Storeِ دیتابیس (جدولِ cache_locks)، تا اجرایِ هم‌زمان در
| چند نمونهٔ برنامه رخ ندهد؛ Claimِ اتمیکِ Outbox در SQL لایهٔ دومِ حفاظت است.
*/
Illuminate\Support\Facades\Schedule::command('push:dispatch')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

Illuminate\Support\Facades\Schedule::command('push:cleanup')
    ->dailyAt('03:30')
    ->withoutOverlapping(30)
    ->onOneServer();
