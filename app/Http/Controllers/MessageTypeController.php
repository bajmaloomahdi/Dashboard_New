<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MessageTypeController extends Controller
{
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $messageTypes = DB::select(
            'EXEC sp_GetMessageTypes @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        return Inertia::render('MessageTypes/Index', [
            'messageTypes' => $messageTypes,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'MessageTypeName' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_InsertMessageType
                @MessageTypeName = ?, @Description = ?, @CreateUser = ?',
            [
                $validated['MessageTypeName'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'MessageTypeName' => $response['Message'] ?? 'خطا در ایجاد نوع پیام',
            ]);
        }

        return redirect()->route('message-types.index')
            ->with('success', $response['Message']);
    }

    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'MessageTypeName' => 'required|string|max:100',
            'Description' => 'nullable|string|max:500',
        ]);

        $result = DB::select(
            'EXEC sp_UpdateMessageType
                @MessageTypeID = ?, @MessageTypeName = ?, @Description = ?, @ModifyUser = ?',
            [
                $id,
                $validated['MessageTypeName'],
                $validated['Description'] ?? null,
                Auth::id(),
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->withErrors([
                'MessageTypeName' => $response['Message'] ?? 'خطا در ویرایش نوع پیام',
            ]);
        }

        return redirect()->route('message-types.index')
            ->with('success', $response['Message']);
    }

    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleMessageTypeActive @MessageTypeID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('message-types.index')
            ->with('success', $response['Message']);
    }
}