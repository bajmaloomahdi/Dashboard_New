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
        $userId = $user->id ?? $user->UserID ?? 0;
        $hasSession = $request->hasSession();

        return array_merge(parent::share($request), [
            // شماره نسخه فارسی برای هدر
            'appVersion' => config('app.version', '1.0.0'),

            // اطلاعات و لوگوی شرکت
            'company' => fn () => $this->getCompany(),

            // پیام‌های خوانده‌نشده
            'unreadNotificationsCount' => fn () => $user ? $this->getUnreadCount($userId) : 0,
            'unreadCount'              => fn () => $user ? $this->getUnreadCount($userId) : 0,

            // اطلاعات کاربر جاری
            'auth' => [
                'user' => $user,
            ],

            // منوهای کاربر
            'menus' => fn () => $user ? $this->getUserMenus($userId) : [],

            // پیام‌های فلش (ایمن در برابر عدم وجود سشن)
            'flash' => [
                'success' => fn () => $hasSession ? $request->session()->get('success') : null,
                'error'   => fn () => $hasSession ? $request->session()->get('error') : null,
            ],
        ]);
    }

    private function getCompany(): ?array
    {
        try {
            $company = DB::select('EXEC sp_GetCompany');
            return !empty($company) ? (array) $company[0] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getUnreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            return (int) DB::table('UserNotifications')
                ->where('UserID', $userId)
                ->where('IsRead', 0)
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

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