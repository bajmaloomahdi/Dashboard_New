<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class ReportController extends Controller
{
    /**
     * نمایش صفحه اجرای گزارش
     */
    public function show(string $code)
    {
        $userId = Auth::id();

        // اجرای SP - دو Result Set برمی‌گردونه
        // Result Set 1: اطلاعات گزارش
        // Result Set 2: پارامترها
        $reportInfo = null;
        $parameters = [];

        try {
            // Result Set اول (اطلاعات گزارش)
            $reports = DB::select(
                'EXEC sp_GetReportInfo @ReportCode = ?, @UserID = ?',
                [$code, $userId]
            );

            if (empty($reports)) {
                return redirect()->route('dashboard')
                    ->with('error', 'گزارش مورد نظر یافت نشد یا دسترسی ندارید');
            }

            $reportInfo = (array) $reports[0];

            // برای گرفتن Result Set دوم، دستور رو دستی اجرا می‌کنیم
            $pdo = DB::connection()->getPdo();
            $stmt = $pdo->prepare('EXEC sp_GetReportInfo @ReportCode = ?, @UserID = ?');
            $stmt->execute([$code, $userId]);

            // رد شدن از Result Set اول
            $stmt->nextRowset();

            // گرفتن Result Set دوم (پارامترها)
            $parameters = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        } catch (\Exception $e) {
            return redirect()->route('dashboard')
                ->with('error', 'خطا در بارگذاری گزارش: ' . $e->getMessage());
        }

        return Inertia::render('Reports/Show', [
            'report' => $reportInfo,
            'parameters' => $parameters,
        ]);
    }

    /**
     * اجرای گزارش با پارامترها
     */
    public function execute(Request $request, string $code)
    {
        $userId = Auth::id();

        // گرفتن اطلاعات گزارش
        $reports = DB::select(
            'EXEC sp_GetReportInfo @ReportCode = ?, @UserID = ?',
            [$code, $userId]
        );

        if (empty($reports)) {
            return response()->json([
                'success' => false,
                'message' => 'گزارش مورد نظر یافت نشد یا دسترسی ندارید',
            ], 403);
        }

        $report = (array) $reports[0];
        $procedureName = $report['ProcedureName'];
        $commandTimeout = $report['CommandTimeout'] ?? 120;

        // گرفتن پارامترها از request
        $inputParams = $request->input('parameters', []);

        try {
            // ساخت رشته پارامترها برای SP
            $paramPlaceholders = [];
            $paramValues = [];

            foreach ($inputParams as $paramName => $paramValue) {
                $paramPlaceholders[] = "@{$paramName} = ?";
                $paramValues[] = $paramValue;
            }

            // ساخت query کامل
            $sql = "EXEC {$procedureName}";
            if (!empty($paramPlaceholders)) {
                $sql .= ' ' . implode(', ', $paramPlaceholders);
            }

            // تنظیم timeout
            DB::statement("SET LOCK_TIMEOUT {$commandTimeout}000");

            // اجرای SP
            $results = DB::select($sql, $paramValues);

            // تبدیل به آرایه
            $data = array_map(fn($row) => (array) $row, $results);

            // گرفتن نام ستون‌ها از اولین ردیف
            $columns = [];
            if (!empty($data)) {
                $columns = array_keys($data[0]);
            }

            return response()->json([
                'success' => true,
                'data' => $data,
                'columns' => $columns,
                'total' => count($data),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اجرای گزارش: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * دریافت داده‌های LookupProcedure برای یک پارامترِ گزارش.
     *
     * قرارداد ورودی: report_code + report_parameter_id
     * نام Stored Procedure هرگز از Request خوانده نمی‌شود؛ فقط از metadata همان
     * ReportParameter (خروجی sp_GetReportInfo) استخراج و پس از اعتبارسنجی اجرا می‌شود.
     */
    public function lookup(Request $request)
    {
        $validated = $request->validate([
            'report_code'         => 'required|string|max:100',
            'report_parameter_id' => 'required|integer',
        ]);

        $userId           = Auth::id();
        $reportCode       = $validated['report_code'];
        $reportParameterId = (int) $validated['report_parameter_id'];

        // ۱. بررسی دسترسی کاربر به گزارش + دریافت لیست پارامترها (Result Set دوم)
        try {
            $pdo  = DB::connection()->getPdo();
            $stmt = $pdo->prepare('EXEC sp_GetReportInfo @ReportCode = ?, @UserID = ?');
            $stmt->execute([$reportCode, $userId]);

            $reportRows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (empty($reportRows)) {
                return response()->json([
                    'success' => false,
                    'message' => 'گزارش مورد نظر یافت نشد یا دسترسی ندارید',
                ], 403);
            }

            $stmt->nextRowset();
            $parameters = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            Log::error('Report lookup: failed to load report metadata', [
                'report_code'         => $reportCode,
                'report_parameter_id' => $reportParameterId,
                'user_id'             => $userId,
                'exception'           => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات گزارش',
            ], 500);
        }

        // ۲. یافتن پارامتر مورد نظر فقط از metadata همین گزارش
        $parameter = null;
        foreach ($parameters as $p) {
            if ((int) ($p['ReportParameterID'] ?? 0) === $reportParameterId) {
                $parameter = $p;
                break;
            }
        }

        if ($parameter === null) {
            return response()->json([
                'success' => false,
                'message' => 'پارامتر مورد نظر برای این گزارش یافت نشد',
            ], 404);
        }

        // ۳. خواندن LookupProcedure فقط از metadata پارامتر (نه از Request)
        $lookupProcedure = trim((string) ($parameter['LookupProcedure'] ?? ''));

        if ($lookupProcedure === '') {
            return response()->json([
                'success' => false,
                'message' => 'این پارامتر فاقد Stored Procedure برای Lookup است',
            ], 422);
        }

        // ۴. اعتبارسنجی سخت‌گیرانه‌ی نام SP قبل از اجرا
        if (!$this->isValidProcedureName($lookupProcedure)) {
            Log::warning('Report lookup: invalid LookupProcedure format blocked', [
                'report_code'         => $reportCode,
                'report_parameter_id' => $reportParameterId,
                'lookup_procedure'    => $lookupProcedure,
                'user_id'             => $userId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'پیکربندی Lookup این پارامتر معتبر نیست',
            ], 422);
        }

        // ۵. اجرا فقط پس از عبور از تمام اعتبارسنجی‌ها
        try {
            $results = DB::select("EXEC {$lookupProcedure}");
            $data    = array_map(fn ($row) => (array) $row, $results);

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('Report lookup: stored procedure execution failed', [
                'report_code'         => $reportCode,
                'report_parameter_id' => $reportParameterId,
                'lookup_procedure'    => $lookupProcedure,
                'user_id'             => $userId,
                'exception'           => $e,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت داده‌های پارامتر',
            ], 500);
        }
    }

    /**
     * اعتبارسنجی سخت‌گیرانه‌ی نام Stored Procedure برای Lookup.
     *
     * فقط این فرمت‌ها مجاز است:
     *   sp_Name | dbo.sp_Name | [dbo].[sp_Name]  (و ترکیب‌های براکت‌دار/بدون‌براکت)
     * هیچ فاصله، پارامتر، «;»، «--»، «@»، کوتیشن یا SQL اضافه‌ای مجاز نیست.
     */
    private function isValidProcedureName(string $name): bool
    {
        $identifier = '(?:\[[A-Za-z_][A-Za-z0-9_]*\]|[A-Za-z_][A-Za-z0-9_]*)';

        return (bool) preg_match('/^' . $identifier . '(?:\.' . $identifier . ')?$/', $name);
    }
}