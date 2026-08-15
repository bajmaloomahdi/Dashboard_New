<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MenuController extends Controller
{
    /**
     * نمایش صفحه لیست منوها
     */
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        // ✅ اجرای SP برای گرفتن لیست منوها
        $allMenus = DB::select(
            'EXEC sp_GetMenus @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        // دریافت لیست منوهای Parent قابل انتخاب
        $parentOptions = DB::select('EXEC sp_GetParentMenuOptions @ExcludeMenuID = NULL');

        return Inertia::render('Menus/Index', [
            'allMenus' => $allMenus,
            'parentOptions' => $parentOptions,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * دریافت لیست Parent های قابل انتخاب برای ویرایش
     */
    public function parentOptions(Request $request)
    {
        $excludeMenuId = $request->input('exclude_menu_id');

        $options = DB::select(
            'EXEC sp_GetParentMenuOptions @ExcludeMenuID = ?',
            [$excludeMenuId ?: null]
        );

        return response()->json($options);
    }

    /**
     * ایجاد منوی جدید
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'ParentID' => 'nullable|integer',
            'MenuTitle' => 'required|string|max:200',
            'MenuKind' => 'required|string|in:PAGE,FOLDER,REPORT',
            'Url' => 'nullable|string|max:500',
            'Icon' => 'nullable|string|max:100',
            'SortOrder' => 'nullable|integer',
            'OpenInNewTab' => 'boolean',
            'IsVisible' => 'boolean',
            'Description' => 'nullable|string|max:500',
        ]);

        // ✅ اجرای SP
        $result = DB::select(
            'EXEC sp_InsertMenu 
                @ParentID = ?, 
                @MenuTitle = ?, 
                @MenuKind = ?, 
                @Url = ?, 
                @Icon = ?, 
                @SortOrder = ?, 
                @OpenInNewTab = ?, 
                @IsVisible = ?, 
                @Description = ?, 
                @CreateUser = ?',
            [
                $validated['ParentID'] ?? null,
                $validated['MenuTitle'],
                $validated['MenuKind'],
                $validated['Url'] ?? null,
                $validated['Icon'] ?? null,
                $validated['SortOrder'] ?? null,
                $validated['OpenInNewTab'] ?? false,
                $validated['IsVisible'] ?? true,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'MenuTitle' => $response['Message'] ?? 'خطا در ایجاد منو',
            ]);
        }

        return redirect()->route('menus.index')
            ->with('success', $response['Message']);
    }

    /**
     * ویرایش منو
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'ParentID' => 'nullable|integer',
            'MenuTitle' => 'required|string|max:200',
            'MenuKind' => 'required|string|in:PAGE,FOLDER,REPORT',
            'Url' => 'nullable|string|max:500',
            'Icon' => 'nullable|string|max:100',
            'SortOrder' => 'nullable|integer',
            'OpenInNewTab' => 'boolean',
            'IsVisible' => 'boolean',
            'Description' => 'nullable|string|max:500',
        ]);

        // ✅ اجرای SP
        $result = DB::select(
            'EXEC sp_UpdateMenu 
                @MenuID = ?, 
                @ParentID = ?, 
                @MenuTitle = ?, 
                @MenuKind = ?, 
                @Url = ?, 
                @Icon = ?, 
                @SortOrder = ?, 
                @OpenInNewTab = ?, 
                @IsVisible = ?, 
                @Description = ?, 
                @ModifyUser = ?',
            [
                $id,
                $validated['ParentID'] ?? null,
                $validated['MenuTitle'],
                $validated['MenuKind'],
                $validated['Url'] ?? null,
                $validated['Icon'] ?? null,
                $validated['SortOrder'] ?? null,
                $validated['OpenInNewTab'] ?? false,
                $validated['IsVisible'] ?? true,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'MenuTitle' => $response['Message'] ?? 'خطا در ویرایش منو',
            ]);
        }

        return redirect()->route('menus.index')
            ->with('success', $response['Message']);
    }

    /**
     * تغییر وضعیت فعال/غیرفعال
     */
    public function toggleActive(int $id)
    {
        // ✅ اجرای SP
        $result = DB::select(
            'EXEC sp_ToggleMenuActive @MenuID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('menus.index')
            ->with('success', $response['Message']);
    }
}