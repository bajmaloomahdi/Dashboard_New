<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MsgPriorityController extends Controller
{
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $priorities = DB::select(
            'EXEC sp_GetMsgPriorities @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        return Inertia::render('MsgPriorities/Index', [
            'priorities' => $priorities,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'Name' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertMsgPriority @Name = ?, @Description = ?, @CreateUser = ?',
            [
                $validated['Name'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'Name' => $response['Message'] ?? 'خطا در ایجاد اولویت',
            ]);
        }

        return redirect()->route('msg-priorities.index')
            ->with('success', $response['Message']);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'Name' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
            'SortOrder' => 'required|integer',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateMsgPriority
                @PriorityID = ?, @Name = ?, @Description = ?, @SortOrder = ?, @ModifyUser = ?',
            [
                $id,
                $validated['Name'],
                $validated['Description'] ?? null,
                $validated['SortOrder'],
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'Name' => $response['Message'] ?? 'خطا در ویرایش اولویت',
            ]);
        }

        return redirect()->route('msg-priorities.index')
            ->with('success', $response['Message']);
    }

    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleMsgPriorityActive @PriorityID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('msg-priorities.index')
            ->with('success', $response['Message']);
    }
}