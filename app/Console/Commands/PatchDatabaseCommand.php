<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PatchDatabaseCommand extends Command
{
    /**
     * نام و پارامترهای دستور در Artisan
     */
    protected $signature = 'db:patch {--status : فقط نمایش وضعیت پچ‌ها بدون اجرا}';

    /**
     * توضیحات دستور
     */
    protected $description = 'اجرا و اعمال خودکار تغییرات جداول و Stored Procedureها روی دیتابیس شرکت';

    public function handle()
    {
        $this->info('==================================================');
        $this->info('   بررسی و اعمال پچ‌های دیتابیس (SQL Server)    ');
        $this->info('==================================================');

        // ۱. اطمینان از وجود جدول رهگیری در دیتابیس
        $this->ensurePatchTableExists();

        // ۲. مسیر پوشه اسکریپت‌های پچ
        $patchPath = base_path('sql/patches');
        if (!File::exists($patchPath)) {
            // اگر پوشه patches نبود، پوشه sql اصلی را نگاه کن
            $patchPath = base_path('sql');
        }

        if (!File::exists($patchPath)) {
            $this->error("پوشه اسکریپت‌های SQL در مسیر {$patchPath} یافت نشد.");
            return 1;
        }

        // ۳. خواندن تمام فایل‌های .sql به ترتیب الفبا/شماره
        $files = collect(File::files($patchPath))
            ->filter(fn($file) => strtolower($file->getExtension()) === 'sql')
            ->sortBy(fn($file) => $file->getFilename())
            ->values();

        if ($files->isEmpty()) {
            $this->warn('هیچ فایل اسکریپت SQL برای اجرا یافت نشد.');
            return 0;
        }

        // ۴. خواندن پچ‌هایی که قبلاً روی این دیتابیس با موفقیت اجرا شده‌اند
        $appliedPatches = DB::table('SysPatches')
            ->where('Status', 'SUCCESS')
            ->pluck('PatchName')
            ->toArray();

        // اگر کاربر فقط وضعیت خواست (--status)
        if ($this->option('status')) {
            $rows = [];
            foreach ($files as $file) {
                $name = $file->getFilename();
                $isApplied = in_array($name, $appliedPatches);
                $rows[] = [$name, $isApplied ? 'اجرا شده (Applied)' : 'در انتظار اجرا (Pending)'];
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

            // جدا کردن بچ‌های SQL که با دستور GO در SSMS جدا شده‌اند
            $batches = preg_split('/^\s*GO\s*$/mi', $sqlContent);

            try {
                foreach ($batches as $batch) {
                    $cleanBatch = trim($batch);
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

                // ثبت شکست در جدول
                try {
                    DB::table('SysPatches')->insert([
                        'PatchName'   => $filename,
                        'AppliedAt'   => now(),
                        'AppVersion'  => config('app.version', '1.0.0'),
                        'ExecutionMs' => $durationMs,
                        'Status'      => 'FAILED',
                        'ErrorMessage'=> substr($e->getMessage(), 0, 1000),
                    ]);
                } catch (\Throwable $inner) {
                    // نادیده گرفتن خطای ثبت لاگ
                }

                $this->error(" <fg=red>[FAILED]</> خطا در اجرای {$filename}:");
                $this->error($e->getMessage());
                $this->error('عملیات متوقف شد تا دیتابیس دچار مغایرت نشود.');
                return 1;
            }
        }

        $this->info('--------------------------------------------------');
        $this->info("نتیجه: {$appliedCount} پچ جدید اجرا شد | {$skippedCount} پچ از قبل اجرا شده بود.");
        return 0;
    }

    /**
     * ساخت جدول SysPatches در صورت عدم وجود+
     */
    private function ensurePatchTableExists(): void
    {
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
    }
}