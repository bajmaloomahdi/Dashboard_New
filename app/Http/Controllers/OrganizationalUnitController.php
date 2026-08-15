<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OrganizationalUnitController extends Controller
{
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $units = DB::select(
            'EXEC sp_GetOrganizationalUnits @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        return Inertia::render('OrganizationalUnits/Index', [
            'units' => $units,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'UnitName' => 'required|string|max:200',
            'ParentUnitID' => 'nullable|integer',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertOrganizationalUnit
                @UnitName = ?, @ParentUnitID = ?, @Description = ?, @CreateUser = ?',
            [
                $validated['UnitName'],
                $validated['ParentUnitID'] ?? null,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'UnitName' => $response['Message'] ?? 'خطا در ایجاد واحد',
            ]);
        }

        return redirect()->route('organizational-units.index')
            ->with('success', $response['Message']);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'UnitName' => 'required|string|max:200',
            'ParentUnitID' => 'nullable|integer',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateOrganizationalUnit
                @UnitID = ?, @UnitName = ?, @ParentUnitID = ?, @Description = ?, @ModifyUser = ?',
            [
                $id,
                $validated['UnitName'],
                $validated['ParentUnitID'] ?? null,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'UnitName' => $response['Message'] ?? 'خطا در ویرایش واحد',
            ]);
        }

        return redirect()->route('organizational-units.index')
            ->with('success', $response['Message']);
    }

    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleOrganizationalUnitActive @UnitID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('organizational-units.index')
            ->with('success', $response['Message']);
    }
}