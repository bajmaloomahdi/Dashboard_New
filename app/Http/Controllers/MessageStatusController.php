<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MessageStatusController extends Controller
{
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $messageStatuses = DB::select(
            'EXEC sp_GetMessageStatuses @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        return Inertia::render('MessageStatuses/Index', [
            'messageStatuses' => $messageStatuses,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'MessageStatusName' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertMessageStatus @MessageStatusName = ?, @Description = ?, @CreateUser = ?',
            [
                $validated['MessageStatusName'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'MessageStatusName' => $response['Message'] ?? 'خطا در ایجاد وضعیت',
            ]);
        }

        return redirect()->route('message-statuses.index')
            ->with('success', $response['Message']);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'MessageStatusName' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateMessageStatus
                @MessageStatusID = ?, @MessageStatusName = ?, @Description = ?, @ModifyUser = ?',
            [
                $id,
                $validated['MessageStatusName'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'MessageStatusName' => $response['Message'] ?? 'خطا در ویرایش وضعیت',
            ]);
        }

        return redirect()->route('message-statuses.index')
            ->with('success', $response['Message']);
    }

    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleMessageStatusActive @MessageStatusID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('message-statuses.index')
            ->with('success', $response['Message']);
    }
}
