<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PositionController extends Controller
{
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $unitId = $request->input('unit_id');
        $isActive = $request->input('is_active');

        $positions = DB::select(
            'EXEC sp_GetPositions @SearchText = ?, @UnitID = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $unitId !== null && $unitId !== '' ? (int) $unitId : null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        $units = DB::select('EXEC sp_GetOrganizationalUnits @IsActive = ?', [1]);

        return Inertia::render('Positions/Index', [
            'positions' => $positions,
            'units' => $units,
            'filters' => [
                'search' => $searchText,
                'unit_id' => $unitId,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'PositionName' => 'required|string|max:200',
            'UnitID' => 'required|integer',
            'IsUnitManager' => 'nullable|boolean',
            'ParentPositionID' => 'nullable|integer',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertPosition
                @PositionName = ?, @UnitID = ?, @IsUnitManager = ?, @ParentPositionID = ?, @Description = ?, @CreateUser = ?',
            [
                $validated['PositionName'],
                $validated['UnitID'],
                !empty($validated['IsUnitManager']) ? 1 : 0,
                $validated['ParentPositionID'] ?? null,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'PositionName' => $response['Message'] ?? 'خطا در ایجاد سمت',
            ]);
        }

        return redirect()->route('positions.index')
            ->with('success', $response['Message']);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'PositionName' => 'required|string|max:200',
            'UnitID' => 'required|integer',
            'IsUnitManager' => 'nullable|boolean',
            'ParentPositionID' => 'nullable|integer',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdatePosition
                @PositionID = ?, @PositionName = ?, @UnitID = ?, @IsUnitManager = ?, @ParentPositionID = ?, @Description = ?, @ModifyUser = ?',
            [
                $id,
                $validated['PositionName'],
                $validated['UnitID'],
                !empty($validated['IsUnitManager']) ? 1 : 0,
                $validated['ParentPositionID'] ?? null,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'PositionName' => $response['Message'] ?? 'خطا در ویرایش سمت',
            ]);
        }

        return redirect()->route('positions.index')
            ->with('success', $response['Message']);
    }

    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_TogglePositionActive @PositionID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('positions.index')
            ->with('success', $response['Message']);
    }
}