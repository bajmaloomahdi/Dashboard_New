<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'UserID' => $user->UserID,
                    'UserCode' => $user->UserCode,
                    'UserName' => $user->UserName,
                    'FirstName' => $user->FirstName,
                    'LastName' => $user->LastName,
                    'FullName' => $user->FullName,
                    'Email' => $user->Email,
                    'Mobile' => $user->Mobile,
                ] : null,
            ],
            'menus' => $user ? $this->getUserMenus($user->UserID) : [],
            'errors' => $request->session()->get('errors')
                ? $request->session()->get('errors')->getBag('default')->getMessages()
                : (object) [],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
            ],
            'unreadNotificationsCount' => function () {
                if (!auth()->check()) {
                    return 0;
                }
                $result = DB::select('EXEC sp_GetUnreadNotificationsCount @UserID = ?', [auth()->id()]);
                return (int) ($result[0]->UnreadCount ?? 0);
            },
        ];
    }

    /**
     * دریافت منوهای کاربر با استفاده از Stored Procedure
     */
    private function getUserMenus(int $userId): array
    {
        $menus = DB::select('EXEC sp_GetUserMenus @UserID = ?', [$userId]);

        return array_map(fn($menu) => (array) $menu, $menus);
    }
}