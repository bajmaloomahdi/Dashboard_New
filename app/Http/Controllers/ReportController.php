<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * دریافت داده‌های LookupProcedure برای پارامترها
     */
    public function lookup(Request $request)
    {
        $procedureName = $request->input('procedure_name');

        if (!$procedureName) {
            return response()->json(['success' => false, 'message' => 'نام SP الزامی است'], 400);
        }

        try {
            $results = DB::select("EXEC {$procedureName}");
            $data = array_map(fn($row) => (array) $row, $results);

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت داده: ' . $e->getMessage(),
            ], 500);
        }
    }
}