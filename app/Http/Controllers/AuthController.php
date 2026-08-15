<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class AuthController extends Controller
{
    /**
     * نمایش صفحه لاگین
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return Inertia::render('Auth/Login');
    }

    /**
     * پردازش فرم لاگین با استفاده از Stored Procedure
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'UserName' => 'required|string',
            'Password' => 'required|string',
        ]);

        // ✅ اجرای SP برای گرفتن اطلاعات کاربر
        $results = DB::select(
            'EXEC sp_GetUserForLogin @UserName = ?',
            [$credentials['UserName']]
        );

        $user_data = $results[0] ?? null;

        if (!$user_data) {
            return back()->withErrors(['UserName' => 'نام کاربری یا کلمه عبور اشتباه است.']);
        }

        // تبدیل stdClass به آرایه برای استفاده راحت‌تر
        $user_data = (array) $user_data;

        // چک کردن فعال بودن
        if (!$user_data['IsActive']) {
            return back()->withErrors(['UserName' => 'حساب کاربری شما غیرفعال است.']);
        }

        // چک کردن قفل نبودن
        if ($user_data['IsLocked']) {
            return back()->withErrors(['UserName' => 'حساب کاربری شما قفل شده است.']);
        }

        // چک کردن کلمه عبور
        if (!Hash::check($credentials['Password'], $user_data['PasswordHash'])) {
            // ✅ اجرای SP برای ثبت تلاش ناموفق
            DB::statement(
                'EXEC sp_UpdateLoginStatus @UserID = ?, @Success = 0',
                [$user_data['UserID']]
            );

            // چک کنیم الان قفل شده یا نه
            $updated = DB::select(
                'EXEC sp_GetUserForLogin @UserName = ?',
                [$credentials['UserName']]
            );
            if (($updated[0]->IsLocked ?? false)) {
                return back()->withErrors(['UserName' => 'حساب شما به دلیل تلاش‌های ناموفق مکرر قفل شد.']);
            }

            return back()->withErrors(['UserName' => 'نام کاربری یا کلمه عبور اشتباه است.']);
        }

        // ✅ اجرای SP برای ثبت لاگین موفق
        DB::statement(
            'EXEC sp_UpdateLoginStatus @UserID = ?, @Success = 1, @IPAddress = ?',
            [$user_data['UserID'], $request->ip()]
        );

        // پیدا کردن Model User برای Auth::login
        $user = \App\Models\User::find($user_data['UserID']);
        
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * خروج از حساب
     */
    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}