<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class ProfileController extends Controller
{
    /**
     * نمایش صفحه تغییر کلمه عبور
     */
    public function showChangePassword()
    {
        return Inertia::render('Profile/ChangePassword');
    }

    /**
     * تغییر کلمه عبور
     */
    public function changePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6',
            'confirm_password' => 'required|string|same:new_password',
        ], [
            'current_password.required' => 'کلمه عبور فعلی الزامی است',
            'new_password.required' => 'کلمه عبور جدید الزامی است',
            'new_password.min' => 'کلمه عبور جدید باید حداقل 6 کاراکتر باشد',
            'confirm_password.required' => 'تکرار کلمه عبور الزامی است',
            'confirm_password.same' => 'تکرار کلمه عبور با کلمه عبور جدید مطابقت ندارد',
        ]);

        $user = Auth::user();

        // چک کردن کلمه عبور فعلی
        if (!Hash::check($validated['current_password'], $user->PasswordHash)) {
            return back()->withErrors([
                'current_password' => 'کلمه عبور فعلی اشتباه است',
            ]);
        }

        // چک کردن تفاوت رمز جدید با فعلی
        if (Hash::check($validated['new_password'], $user->PasswordHash)) {
            return back()->withErrors([
                'new_password' => 'کلمه عبور جدید نباید با کلمه عبور فعلی یکسان باشد',
            ]);
        }

        // هش کردن رمز جدید
        $newPasswordHash = Hash::make($validated['new_password']);

        // ✅ اجرای SP
        $result = DB::select(
            'EXEC sp_ChangeUserPassword 
                @UserID = ?, 
                @NewPasswordHash = ?, 
                @ModifyUser = ?',
            [
                $user->UserID,
                $newPasswordHash,
                $user->UserID,
            ]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر کلمه عبور');
        }

        return back()->with('success', $response['Message']);
    }
}