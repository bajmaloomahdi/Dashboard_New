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
            // شماره نسخه برای نمایش در هدر
            'appVersion' => config('app.version', '1.0.1'),

            // قابلیت‌هایِ دارایِ Feature Flag — UI فقط وقتی true است آن‌ها را نشان می‌دهد
            'features' => [
                'globalSearch' => (bool) config('global_search.enabled'),
            ],

            // اطلاعات شرکت (لوگو و نام)
            'company' => fn () => $this->getCompany(),

            // تعداد پیام‌های خوانده‌نشده کاربر
            'unreadNotificationsCount' => fn () => $user ? $this->getUnreadCount($userId) : 0,
            'unreadCount'              => fn () => $user ? $this->getUnreadCount($userId) : 0,

            // اطلاعات کاربر جاری
            'auth' => [
                'user' => $user,
            ],

            // منوهای کاربر بر اساس دسترسی
            'menus' => fn () => $user ? $this->getUserMenus($userId) : [],

            // پیام‌های فلش
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
            // تعدادِ پیام‌هایِ دارایِ تغییرِ خوانده‌نشده (پیامِ تازه، توضیح/ضمیمهٔ تازه، ارجاع، …) — نه تعدادِ ردیف‌هایِ اعلان
            return (int) DB::table('UserNotifications')
                ->where('UserID', $userId)
                ->where('IsRead', 0)
                ->distinct()
                ->count('MessageID');
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