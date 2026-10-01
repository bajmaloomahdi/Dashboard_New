<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class RoleController extends Controller
{
    /**
     * نمایش صفحه لیست نقش‌ها
     */
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $roles = DB::select(
            'EXEC sp_GetRoles @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        return Inertia::render('Roles/Index', [
            'roles' => $roles,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * ایجاد نقش جدید
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'RoleName' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertRole 
                @RoleName = ?, 
                @Description = ?, 
                @CreateUser = ?',
            [
                $validated['RoleName'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'RoleName' => $response['Message'] ?? 'خطا در ایجاد نقش',
            ]);
        }

        return redirect()->route('roles.index')
            ->with('success', $response['Message']);
    }

    /**
     * ویرایش نقش
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'RoleName' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateRole 
                @RoleID = ?, 
                @RoleName = ?, 
                @Description = ?, 
                @ModifyUser = ?',
            [
                $id,
                $validated['RoleName'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'RoleName' => $response['Message'] ?? 'خطا در ویرایش نقش',
            ]);
        }

        return redirect()->route('roles.index')
            ->with('success', $response['Message']);
    }

    /**
     * تغییر وضعیت فعال/غیرفعال
     */
    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleRoleActive @RoleID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('roles.index')
            ->with('success', $response['Message']);
    }

    /**
     * نمایش صفحه مدیریت دسترسی‌های نقش
     */
/**
 * نمایش صفحه مدیریت دسترسی‌های نقش
 */
public function permissions(int $id, Request $request)
{
    $searchText = $request->input('search');

    // گرفتن اطلاعات نقش
    $roles = DB::select('EXEC sp_GetRoles @SearchText = NULL, @IsActive = NULL');
    $role = collect($roles)->firstWhere('RoleID', $id);

    if (!$role) {
        return redirect()->route('roles.index')
            ->with('error', 'نقش مورد نظر یافت نشد');
    }

    // دریافت لیست منوها با وضعیت دسترسی
    $roleMenus = DB::select(
        'EXEC sp_GetMenusForRole @RoleID = ?, @SearchText = ?',
        [$id, $searchText ?: null]
    );

    // دریافت لیست گزارشات با وضعیت دسترسی
    $roleReports = DB::select(
        'EXEC sp_GetReportsForRole @RoleID = ?, @SearchText = ?',
        [$id, $searchText ?: null]
    );

    // دریافت لیست دسترسی‌های عملیاتی با وضعیت
    $rolePermissions = DB::select(
        'EXEC sp_GetPermissionsForRole @RoleID = ?, @SearchText = ?',
        [$id, $searchText ?: null]
    );

    return Inertia::render('Roles/Permissions', [
        'role' => $role,
        'roleMenus' => $roleMenus,
        'roleReports' => $roleReports,
        'rolePermissions' => $rolePermissions,
        'filters' => [
            'search' => $searchText,
        ],
    ]);
}

    /**
     * ذخیره دسترسی‌های منوها
     */
    public function saveMenus(Request $request, int $id)
    {
        $validated = $request->validate([
            'menu_ids' => 'nullable|array',
            'menu_ids.*' => 'integer',
        ]);

        $menuIds = implode(',', $validated['menu_ids'] ?? []);

        $result = DB::select(
            'EXEC sp_SaveRoleMenus 
                @RoleID = ?, 
                @MenuIDs = ?, 
                @ModifyUser = ?',
            [$id, $menuIds, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره دسترسی منوها');
        }

        return back()->with('success', $response['Message']);
    }

    /**
     * ذخیره دسترسی‌های گزارشات
     */
    public function saveReports(Request $request, int $id)
    {
        $validated = $request->validate([
            'report_ids' => 'nullable|array',
            'report_ids.*' => 'integer',
        ]);

        $reportIds = implode(',', $validated['report_ids'] ?? []);

        $result = DB::select(
            'EXEC sp_SaveRoleReports
                @RoleID = ?,
                @ReportIDs = ?,
                @ModifyUser = ?',
            [$id, $reportIds, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره دسترسی گزارشات');
        }

        return back()->with('success', $response['Message']);
    }

    /**
     * ذخیره دسترسی‌های عملیاتی (Permissions) نقش
     */
    public function savePermissions(Request $request, int $id)
    {
        $validated = $request->validate([
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer',
        ]);

        $permissionIds = implode(',', $validated['permission_ids'] ?? []);

        $result = DB::select(
            'EXEC sp_SaveRolePermissions
                @RoleID = ?,
                @PermissionIDs = ?,
                @ModifyUser = ?',
            [$id, $permissionIds, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره دسترسی‌های عملیاتی');
        }

        return back()->with('success', $response['Message']);
    }

    /**
     * لیستِ کاربران برایِ مدیریتِ یک نقش (همانِ UserRoles، از سمتِ Role) — برایِ
     * نمایشِ «کاربرانِ این نقش» + Autocompleteِ افزودن، هر دو از یک پاسخ.
     */
    public function users(int $id, Request $request)
    {
        $searchText = $request->input('search');

        $roles = DB::select('EXEC sp_GetRoles @SearchText = NULL, @IsActive = NULL');
        $role = collect($roles)->firstWhere('RoleID', $id);

        if (!$role) {
            return redirect()->route('roles.index')
                ->with('error', 'نقش مورد نظر یافت نشد');
        }

        $roleUsers = DB::select(
            'EXEC sp_GetUsersForRole @RoleID = ?, @SearchText = ?',
            [$id, $searchText ?: null]
        );

        return Inertia::render('Roles/Users', [
            'role' => $role,
            'roleUsers' => $roleUsers,
            'filters' => [
                'search' => $searchText,
            ],
        ]);
    }

    /**
     * ذخیرهٔ کاربرانِ یک نقش — Bulk Assignment (افزودن/حذفِ هم‌زمانِ چند کاربر در یک
     * تراکنش): کلِ مجموعهٔ موردِنظر ارسال می‌شود، sp_SaveRoleUsers آن را با وضعیتِ
     * فعلیِ UserRoles مقایسه می‌کند (افزودنِ جدید، فعال‌سازیِ مجدد، غیرفعال‌کردنِ
     * حذف‌شده‌ها) — دقیقاً هم‌الگو با savePermissions/saveMenus بالا.
     */
    public function saveUsers(Request $request, int $id)
    {
        $validated = $request->validate([
            'user_ids' => 'nullable|array',
            // distinct: جلویِ UserIDِ تکراری در همان درخواست را می‌گیرد (قبل از رسیدن به SP).
            // exists: اگر UserID واقعاً در Users نباشد، همین‌جا رد می‌شود — بدونِ این، sp_SaveRoleUsers
            // تلاش می‌کرد ردیفی با FKِ نامعتبر درج کند و خطایِ خامِ SQL Server برمی‌گشت.
            'user_ids.*' => 'integer|distinct|exists:Users,UserID',
        ], [
            'user_ids.*.integer' => 'شناسهٔ کاربر نامعتبر است.',
            'user_ids.*.distinct' => 'یک کاربر نمی‌تواند بیش از یک‌بار در لیست انتخاب شود.',
            'user_ids.*.exists' => 'یکی از کاربرانِ انتخاب‌شده در سیستم یافت نشد.',
        ]);

        $userIds = implode(',', $validated['user_ids'] ?? []);

        $result = DB::select(
            'EXEC sp_SaveRoleUsers
                @RoleID = ?,
                @UserIDs = ?,
                @ModifyUser = ?',
            [$id, $userIds, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره کاربرانِ نقش');
        }

        return back()->with('success', $response['Message']);
    }
}