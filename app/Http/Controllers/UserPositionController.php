<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class UserPositionController extends Controller
{
    public function index(Request $request, int $userId)
    {
        $users = DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = NULL');
        $user = collect($users)->firstWhere('UserID', $userId);

        if (!$user) {
            return redirect()->route('users.index')
                ->with('error', 'کاربر مورد نظر یافت نشد');
        }

        $positions = DB::select(
            'EXEC sp_GetUserPositions @UserID = ?',
            [$userId]
        );

        return Inertia::render('Users/Positions', [
            'user' => $user,
            'positions' => $positions,
        ]);
    }

    public function store(Request $request, int $userId)
    {
        $validated = $request->validate([
            'position_ids' => 'nullable|array',
            'position_ids.*' => 'integer',
        ]);

        $positionIds = implode(',', $validated['position_ids'] ?? []);

        $result = DB::select(
            'EXEC sp_SaveUserPositions
                @UserID = ?, @PositionIDs = ?, @ModifyUser = ?',
            [$userId, $positionIds, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره سمت‌ها');
        }

        return back()->with('success', $response['Message']);
    }
}