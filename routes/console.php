<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('نمایش یک جمله الهام‌بخش');

Artisan::command('db:patch {--status : فقط نمایش وضعیت پچ‌ها بدون اجرا}', function () {
    $this->info('==================================================');
    $this->info('   بررسی و اعمال پچ‌های دیتابیس (SQL Server)    ');
    $this->info('==================================================');

    // ۱. اطمینان از وجود جدول رهگیری در دیتابیس
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

    // ۲. مسیر پوشه اسکریپت‌های پچ
    $patchPath = base_path('sql/patches');
    if (!File::exists($patchPath)) {
        $patchPath = base_path('sql');
    }

    if (!File::exists($patchPath)) {
        $this->error("پوشه اسکریپت‌های SQL در مسیر {$patchPath} یافت نشد.");
        return 1;
    }

    // ۳. خواندن تمام فایل‌های .sql به ترتیب نام
    $files = collect(File::files($patchPath))
        ->filter(fn($file) => strtolower($file->getExtension()) === 'sql')
        ->sortBy(fn($file) => $file->getFilename())
        ->values();

    if ($files->isEmpty()) {
        $this->warn('هیچ فایل اسکریپت SQL برای اجرا در پوشه یافت نشد.');
        return 0;
    }

    // ۴. خواندن پچ‌هایی که قبلاً با موفقیت روی این دیتابیس اجرا شده‌اند
    $appliedPatches = DB::table('SysPatches')
        ->where('Status', 'SUCCESS')
        ->pluck('PatchName')
        ->toArray();

    // اگر فقط وضعیت خواسته شده
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

        // اگر قبلاً اجرا شده، رد شو
        if (in_array($filename, $appliedPatches)) {
            $this->line(" <fg=gray>[SKIP]</> {$filename} (قبلاً اجرا شده)");
            $skippedCount++;
            continue;
        }

        $this->info(" <fg=yellow>[RUNNING]</> در حال اجرای {$filename} ...");
        $startTime = microtime(true);

        $sqlContent = File::get($file->getRealPath());

        // تفکیک دستورات بر اساس GO (استاندارد SSMS)
        $batches = preg_split('/^\s*GO\s*$/mi', $sqlContent);

        try {
            foreach ($batches as $batch) {
                $cleanBatch = trim($batch);

                // حذف خطوط USE [DB_NAME] تا روی دیتابیس هر شرکتی مستقل اجرا شود
                $cleanBatch = preg_replace('/^\s*USE\s+\[?[^\];\r\n]+\]?;?\s*$/mi', '', $cleanBatch);
                $cleanBatch = trim($cleanBatch);

                if (!empty($cleanBatch)) {
                    DB::unprepared($cleanBatch);
                }
            }

            $durationMs = (int) round((microtime(true) - $startTime) * 1000);

            // ثبت در جدول رهگیری
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