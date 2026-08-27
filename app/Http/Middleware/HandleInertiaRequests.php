<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            // نسخه برنامه برای نمایش در سایدبار/داشبورد
            'appVersion' => config('app.version', '1.0.0'),

            // اطلاعات کاربر لاگین‌شده
            'auth' => [
                'user' => $user,
            ],

            // دریافت خودکار منوهای کاربر بر اساس دسترسی
            'menus' => fn () => $user ? $this->getUserMenus($user->id ?? $user->UserID ?? 0) : [],

            // پیام‌های نوتیفیکیشن
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ]);
    }

    /**
     * دریافت منوهای کاربر با استفاده از Stored Procedure
     */
    private function getUserMenus(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $menus = DB::select('EXEC sp_GetUserMenus @UserID = ?', [$userId]);
            return array_map(fn($menu) => (array) $menu, $menus);
        } catch (\Throwable $e) {
            return [];
        }
    }
}