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
| ۱. دستور اعمال پچ‌های دیتابیس روی سرور شرکت‌ها
|--------------------------------------------------------------------------
*/
Artisan::command('db:patch {--status : فقط نمایش وضعیت پچ‌ها بدون اجرا}', function () {
    $this->info('==================================================');
    $this->info('   بررسی و اعمال پچ‌های دیتابیس (SQL Server)    ');
    $this->info('==================================================');

    $tableExists = DB::select("
        SELECT 1 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_NAME = 'SysPatches'
    ");

    if (empty($tableExists)) {
        $this->warn('جدول SysPatches یافت نشد، در حال ساخت جدول رهگیری...');

        DB::unprepared("
            CREATE TABLE [dbo].[SysPatches] (
                [PatchID] INT IDENTITY(1,1) PRIMARY KEY,
                [PatchName] NVARCHAR(255) NOT NULL,
                [AppliedAt] DATETIME NOT NULL DEFAULT GETDATE(),
                [AppVersion] NVARCHAR(50) NULL,
                [ExecutionMs] INT NULL,
                [Status] NVARCHAR(20) NOT NULL,
                [ErrorMessage] NVARCHAR(MAX) NULL
            );
        ");

        $this->info('جدول SysPatches با موفقیت در دیتابیس ساخته شد.');
    }

    $patchPath = base_path('sql/patches');
    if (!File::exists($patchPath)) {
        $patchPath = base_path('sql');
    }

    if (!File::exists($patchPath)) {
        $this->error("پوشه اسکریپت‌های SQL در مسیر {$patchPath} یافت نشد.");
        return 1;
    }

    $files = collect(File::files($patchPath))
        ->filter(fn($file) => strtolower($file->getExtension()) === 'sql')
        ->sortBy(fn($file) => $file->getFilename())
        ->values();

    if ($files->isEmpty()) {
        $this->warn('هیچ فایل اسکریپت SQL برای اجرا در پوشه یافت نشد.');
        return 0;
    }

    $appliedPatches = DB::table('SysPatches')
        ->where('Status', 'SUCCESS')
        ->pluck('PatchName')
        ->toArray();

    if ($this->option('status')) {
        $rows = [];
        foreach ($files as $file) {
            $name = $file->getFilename();
            $isApplied = in_array($name, $appliedPatches);
            $rows[] = [$name, $isApplied ? '<fg=green>اجرا شده (Applied)</>' : '<fg=yellow>در انتظار اجرا (Pending)</>'];
        }
        $this->table(['نام فایل پچ', 'وضعیت'], $rows);
        return 0;
    }

    $appliedCount = 0;
    $skippedCount = 0;

    foreach ($files as $file) {
        $filename = $file->getFilename();

        if (in_array($filename, $appliedPatches)) {
            $this->line(" <fg=gray>[SKIP]</> {$filename} (قبلاً اجرا شده)");
            $skippedCount++;
            continue;
        }

        $this->info(" <fg=yellow>[RUNNING]</> در حال اجرای {$filename} ...");
        $startTime = microtime(true);

        $sqlContent = File::get($file->getRealPath());
        $batches = preg_split('/^\s*GO\s*$/mi', $sqlContent);

        try {
            foreach ($batches as $batch) {
                $cleanBatch = trim($batch);
                $cleanBatch = preg_replace('/^\s*USE\s+\[?[^\];\r\n]+\]?;?\s*$/mi', '', $cleanBatch);
                $cleanBatch = trim($cleanBatch);

                if (!empty($cleanBatch)) {
                    DB::unprepared($cleanBatch);
                }
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            DB::table('SysPatches')->insert([
                'PatchName'   => $filename,
                'AppliedAt'   => now(),
                'AppVersion'  => config('app.version', '1.0.0'),
                'ExecutionMs' => $durationMs,
                'Status'      => 'SUCCESS',
                'ErrorMessage'=> null,
            ]);

            $this->line(" <fg=green>[SUCCESS]</> {$filename} با موفقیت در {$durationMs}ms اجرا شد.");
            $appliedCount++;

        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            try {
                DB::table('SysPatches')->insert([
                    'PatchName'   => $filename,
                    'AppliedAt'   => now(),
                    'AppVersion'  => config('app.version', '1.0.0'),
                    'ExecutionMs' => $durationMs,
                    'Status'      => 'FAILED',
                    'ErrorMessage'=> substr($e->getMessage(), 0, 1000),
                ]);
            } catch (\Throwable $inner) {}

            $this->error(" <fg=red>[FAILED]</> خطا در اجرای {$filename}:");
            $this->error($e->getMessage());
            $this->error('عملیات متوقف شد تا دیتابیس دچار مغایرت نشود.');
            return 1;
        }
    }

    $this->info('--------------------------------------------------');
    $this->info("نتیجه: {$appliedCount} پچ جدید اجرا شد | {$skippedCount} پچ از قبل اجرا شده بود.");
    return 0;
})->purpose('اجرا و اعمال خودکار تغییرات جداول و Stored Procedureها روی دیتابیس شرکت');

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

    $hasTable = DB::select("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'DevChangeLog'");
    if (empty($hasTable)) {
        $this->error('ردیاب فعال نیست. لطفاً ابتدا دستور زیر را بزنید:');
        $this->line('docker exec dashboard_app php artisan db:init-tracker');
        return 1;
    }

    $changes = DB::table('DevChangeLog')
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
    $cleanName = Str::slug($name, '_');

    $patchPath = base_path('sql');
    if (!File::exists($patchPath)) {
        File::makeDirectory($patchPath, 0755, true);
    }

    $existingFiles = collect(File::files($patchPath))
        ->map(fn($f) => $f->getFilename())
        ->filter(fn($f) => preg_match('/^(\d+)_.*\.sql$/', $f))
        ->map(function ($f) {
            preg_match('/^(\d+)_/', $f, $m);
            return (int) $m[1];
        });

    $nextNumber = ($existingFiles->max() ?? 0) + 1;
    $numberPrefix = str_pad((string) $nextNumber, 3, '0', STR_PAD_LEFT);
    $filename = "{$numberPrefix}_{$cleanName}.sql";
    $fullPath = $patchPath . DIRECTORY_SEPARATOR . $filename;

    $content = "/* ==========================================================================\n";
    $content .= "   پچ خودکار شماره: {$numberPrefix} | نام: {$cleanName}\n";
    $content .= "   تاریخ: " . now()->toDateTimeString() . " | شامل {$changes->count()} دستور SQL\n";
    $content .= "   ========================================================================== */\n\n";

    $idsToUpdate = [];
    foreach ($changes as $change) {
        $idsToUpdate[] = $change->LogID;
        $content .= "-- [{$change->EventType}] روی {$change->ObjectType}: {$change->ObjectName}\n";

        $cleanCmd = trim($change->TSQLCommand);
        $cleanCmd = preg_replace('/^\s*USE\s+\[?[^\];\r\n]+\]?;?\s*$/mi', '', $cleanCmd);
        $cleanCmd = trim($cleanCmd);

        $content .= $cleanCmd . "\nGO\n\n";
    }

    File::put($fullPath, $content);

    // علامت‌گذاری تغییرات به عنوان اکسپورت شده
    DB::table('DevChangeLog')
        ->whereIn('LogID', $idsToUpdate)
        ->update([
            'IsExported' => 1,
            'ExportedAt' => now(),
        ]);

    // ثبت در SysPatches همین سیستم برای جلوگیری از اجرای مجدد در توسعه
    DB::table('SysPatches')->updateOrInsert(
        ['PatchName' => $filename],
        [
            'AppliedAt'   => now(),
            'AppVersion'  => config('app.version', '1.0.0'),
            'ExecutionMs' => 0,
            'Status'      => 'SUCCESS',
            'ErrorMessage'=> null,
        ]
    );

    $this->info(" <fg=green>[SUCCESS]</> فایل پچ با موفقیت به صورت خودکار ساخته شد:");
    $this->line(" <fg=yellow>sql/{$filename}</> (شامل {$changes->count()} تغییر دیتابیس)");
    $this->info('--------------------------------------------------');
    $this->info('حالا فقط کافیست برای ارسال به گیت‌هاب بنویسید:');
    $this->line(" git add sql/{$filename}");
    $this->line(" git commit -m \"db: {$name}\"");
    $this->line(" git push origin main");
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