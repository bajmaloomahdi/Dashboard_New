<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReportManagementController extends Controller
{
    /**
     * نمایش صفحه لیست گزارشات
     */
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $reports = DB::select(
            'EXEC sp_GetReports @SearchText = ?, @IsActive = ?, @MenuID = NULL',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        $menus = DB::select(
            'SELECT MenuID, MenuTitle, MenuKind, Level 
             FROM Menu 
             WHERE IsActive = 1 
             ORDER BY Level, SortOrder'
        );

        return Inertia::render('ReportsManage/Index', [
            'reports' => $reports,
            'availableMenus' => $menus,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * ایجاد گزارش جدید
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'MenuID' => 'required|integer',
            'ReportCode' => 'required|string|max:50',
            'ReportTitle' => 'required|string|max:200',
            'ProcedureName' => 'required|string|max:200',
            'CommandTimeout' => 'nullable|integer|min:30|max:600',
            'AllowExcel' => 'boolean',
            'AllowPdf' => 'boolean',
            'AllowPrint' => 'boolean',
            'CacheDuration' => 'nullable|integer|min:0',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertReport 
                @MenuID = ?, 
                @ReportCode = ?, 
                @ReportTitle = ?, 
                @ProcedureName = ?, 
                @CommandTimeout = ?, 
                @AllowExcel = ?, 
                @AllowPdf = ?, 
                @AllowPrint = ?, 
                @CacheDuration = ?, 
                @Description = ?, 
                @CreateUser = ?',
            [
                $validated['MenuID'],
                $validated['ReportCode'],
                $validated['ReportTitle'],
                $validated['ProcedureName'],
                $validated['CommandTimeout'] ?? 120,
                $validated['AllowExcel'] ?? true,
                $validated['AllowPdf'] ?? false,
                $validated['AllowPrint'] ?? false,
                $validated['CacheDuration'] ?? 0,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'ReportCode' => $response['Message'] ?? 'خطا در ایجاد گزارش',
            ]);
        }

        return redirect()->route('reports-manage.index')
            ->with('success', $response['Message']);
    }

    /**
     * ویرایش گزارش
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'MenuID' => 'required|integer',
            'ReportCode' => 'required|string|max:50',
            'ReportTitle' => 'required|string|max:200',
            'ProcedureName' => 'required|string|max:200',
            'CommandTimeout' => 'nullable|integer|min:30|max:600',
            'AllowExcel' => 'boolean',
            'AllowPdf' => 'boolean',
            'AllowPrint' => 'boolean',
            'CacheDuration' => 'nullable|integer|min:0',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateReport 
                @ReportID = ?, 
                @MenuID = ?, 
                @ReportCode = ?, 
                @ReportTitle = ?, 
                @ProcedureName = ?, 
                @CommandTimeout = ?, 
                @AllowExcel = ?, 
                @AllowPdf = ?, 
                @AllowPrint = ?, 
                @CacheDuration = ?, 
                @Description = ?, 
                @ModifyUser = ?',
            [
                $id,
                $validated['MenuID'],
                $validated['ReportCode'],
                $validated['ReportTitle'],
                $validated['ProcedureName'],
                $validated['CommandTimeout'] ?? 120,
                $validated['AllowExcel'] ?? true,
                $validated['AllowPdf'] ?? false,
                $validated['AllowPrint'] ?? false,
                $validated['CacheDuration'] ?? 0,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'ReportCode' => $response['Message'] ?? 'خطا در ویرایش گزارش',
            ]);
        }

        return redirect()->route('reports-manage.index')
            ->with('success', $response['Message']);
    }

    /**
     * تغییر وضعیت فعال/غیرفعال گزارش
     */
    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleReportActive @ReportID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('reports-manage.index')
            ->with('success', $response['Message']);
    }

    /**
     * نمایش صفحه اتصال پارامترها به گزارش
     */
    public function parameters(int $id)
    {
        $reports = DB::select(
            'EXEC sp_GetReports @SearchText = NULL, @IsActive = NULL, @MenuID = NULL'
        );
        $report = collect($reports)->firstWhere('ReportID', $id);

        if (!$report) {
            return redirect()->route('reports-manage.index')
                ->with('error', 'گزارش مورد نظر یافت نشد');
        }

        // دریافت پارامترهای Master با وضعیت اتصال
        $masterParameters = DB::select(
            'EXEC sp_GetMastersForReport @ReportID = ?',
            [$id]
        );

        return Inertia::render('ReportsManage/Parameters', [
            'report' => $report,
            'masterParameters' => $masterParameters,
        ]);
    }

    /**
     * ذخیره اتصالات پارامترها به گزارش
     */
    public function saveParameters(Request $request, int $id)
    {
        $validated = $request->validate([
            'master_ids' => 'nullable|array',
            'master_ids.*' => 'integer',
        ]);

        $masterIds = implode(',', $validated['master_ids'] ?? []);

        $result = DB::select(
            'EXEC sp_SaveReportParameters 
                @ReportID = ?, 
                @MasterParameterIDs = ?, 
                @ModifyUser = ?',
            [$id, $masterIds, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره پارامترها');
        }

        return back()->with('success', $response['Message']);
    }

    /**
     * به‌روزرسانی تنظیمات یک پارامتر خاص در گزارش
     */
    public function updateParameterSettings(Request $request, int $id, int $paramId)
    {
        $validated = $request->validate([
            'IsRequired' => 'boolean',
            'IsVisible' => 'boolean',
            'DefaultValue' => 'nullable|string|max:500',
            'SortOrder' => 'nullable|integer',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateReportParameterSettings 
                @ReportParameterID = ?, 
                @IsRequired = ?, 
                @IsVisible = ?, 
                @DefaultValue = ?, 
                @SortOrder = ?, 
                @Description = ?, 
                @ModifyUser = ?',
            [
                $paramId,
                $validated['IsRequired'] ?? false,
                $validated['IsVisible'] ?? true,
                $validated['DefaultValue'] ?? null,
                $validated['SortOrder'] ?? null,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره تنظیمات');
        }

        return back()->with('success', $response['Message']);
    }
}