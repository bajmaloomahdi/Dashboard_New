<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MasterParameterController extends Controller
{
    /**
     * نمایش صفحه لیست پارامترهای Master
     */
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $masterParameters = DB::select(
            'EXEC sp_GetMasterParameters @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        return Inertia::render('MasterParameters/Index', [
            'masterParameters' => $masterParameters,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * ایجاد پارامتر Master جدید
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'ParameterName' => 'required|string|max:100',
            'ParameterCaption' => 'required|string|max:200',
            'DataType' => 'required|string|in:STRING,INT,BIGINT,DECIMAL,DATE,DATETIME,BIT',
            'ControlType' => 'required|string|in:TEXTBOX,NUMBER,DATE,SELECT,MULTISELECT,CHECKBOX',
            'LookupProcedure' => 'nullable|string|max:200',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertMasterParameter 
                @ParameterName = ?, 
                @ParameterCaption = ?, 
                @DataType = ?, 
                @ControlType = ?, 
                @LookupProcedure = ?, 
                @Description = ?, 
                @CreateUser = ?',
            [
                $validated['ParameterName'],
                $validated['ParameterCaption'],
                $validated['DataType'],
                $validated['ControlType'],
                $validated['LookupProcedure'] ?? null,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'ParameterName' => $response['Message'] ?? 'خطا در ایجاد پارامتر',
            ]);
        }

        return redirect()->route('master-parameters.index')
            ->with('success', $response['Message']);
    }

    /**
     * ویرایش پارامتر Master
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'ParameterName' => 'required|string|max:100',
            'ParameterCaption' => 'required|string|max:200',
            'DataType' => 'required|string|in:STRING,INT,BIGINT,DECIMAL,DATE,DATETIME,BIT',
            'ControlType' => 'required|string|in:TEXTBOX,NUMBER,DATE,SELECT,MULTISELECT,CHECKBOX',
            'LookupProcedure' => 'nullable|string|max:200',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateMasterParameter 
                @MasterParameterID = ?, 
                @ParameterName = ?, 
                @ParameterCaption = ?, 
                @DataType = ?, 
                @ControlType = ?, 
                @LookupProcedure = ?, 
                @Description = ?, 
                @ModifyUser = ?',
            [
                $id,
                $validated['ParameterName'],
                $validated['ParameterCaption'],
                $validated['DataType'],
                $validated['ControlType'],
                $validated['LookupProcedure'] ?? null,
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'ParameterName' => $response['Message'] ?? 'خطا در ویرایش پارامتر',
            ]);
        }

        return redirect()->route('master-parameters.index')
            ->with('success', $response['Message']);
    }

    /**
     * تغییر وضعیت فعال/غیرفعال
     */
    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleMasterParameterActive @MasterParameterID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('master-parameters.index')
            ->with('success', $response['Message']);
    }
}