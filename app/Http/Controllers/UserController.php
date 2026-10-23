<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserController extends Controller
{
    /**
     * نمایش صفحه لیست کاربران
     */
    public function index(Request $request)
    {
        $searchText = $request->input('search');
        $isActive = $request->input('is_active');

        $users = DB::select(
            'EXEC sp_GetUsers @SearchText = ?, @IsActive = ?',
            [
                $searchText ?: null,
                $isActive !== null && $isActive !== '' ? (int) $isActive : null,
            ]
        );

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => [
                'search' => $searchText,
                'is_active' => $isActive,
            ],
        ]);
    }

    /**
     * ایجاد کاربر جدید
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'UserName' => 'required|string|max:100',
            'Password' => 'required|string|min:6',
            'FirstName' => 'nullable|string|max:100',
            'LastName' => 'nullable|string|max:100',
            'Email' => 'nullable|email|max:200',
            'Mobile' => 'nullable|string|max:20',
            'Description' => 'nullable|string|max:500',
            ...self::CRM_LINK_RULES,
        ]);

        $passwordHash = Hash::make($validated['Password']);

        // ایجادِ کاربر و نوع/اتصالِ CRM با هم — خطایِ اتصال یعنی کاربر هم ساخته نشود
        try {
            $response = DB::transaction(function () use ($validated, $passwordHash) {
                $result = DB::select(
                    'EXEC sp_InsertUser
                        @UserName = ?, @PasswordHash = ?, @FirstName = ?, @LastName = ?,
                        @Email = ?, @Mobile = ?, @Description = ?, @CreateUser = ?',
                    [
                        $validated['UserName'],
                        $passwordHash,
                        $validated['FirstName'] ?? null,
                        $validated['LastName'] ?? null,
                        $validated['Email'] ?? null,
                        $validated['Mobile'] ?? null,
                        $validated['Description'] ?? null,
                        Auth::id(),
                    ]
                );
                $response = (array) ($result[0] ?? []);
                if (! empty($response['Success'])) {
                    $this->saveCrmLink((int) $response['UserID'], $validated);
                }

                return $response;
            });
        } catch (\DomainException $e) {
            return back()->withErrors(['UserType' => $e->getMessage()]);
        }

        if (empty($response['Success'])) {
            return back()->withErrors([
                'UserName' => $response['Message'] ?? 'خطا در ایجاد کاربر',
            ]);
        }

        return redirect()->route('users.index')
            ->with('success', $response['Message']);
    }

    /** نوعِ کاربر (پچِ ۱۲۱): داخلی، مخاطب (CrmPersonID) یا طرف‌حساب (CrmPartyID) */
    private const CRM_LINK_RULES = [
        'UserType' => 'sometimes|in:INTERNAL,PERSON,PARTY',
        'CrmPersonID' => 'nullable|integer',
        'CrmPartyID' => 'nullable|integer',
    ];

    /** ذخیرهٔ نوع/اتصالِ CRM با sp_SetUserCrmLink؛ خطا → DomainException (پیامِ فارسیِ SP) تا تراکنش برگردد */
    private function saveCrmLink(int $userId, array $validated): void
    {
        if (! array_key_exists('UserType', $validated)) {
            return;
        }
        $res = (array) (DB::select(
            'EXEC dbo.sp_SetUserCrmLink @UserID = ?, @UserType = ?, @CrmPersonID = ?, @CrmPartyID = ?, @ModifyUser = ?',
            [$userId, $validated['UserType'], $validated['CrmPersonID'] ?? null, $validated['CrmPartyID'] ?? null, Auth::id()]
        )[0] ?? []);
        if (empty($res['Success'])) {
            throw new \DomainException($res['Message'] ?? 'خطا در ذخیرهٔ نوعِ کاربر');
        }
    }

    /**
     * GET users/crm-options?type=PERSON|PARTY&search= — جست‌وجویِ مخاطب/طرف‌حساب برایِ فرمِ کاربر (حداکثر ۳۰ مورد)،
     * همراهِ نامِ کاربری که الان به هر مورد متصل است.
     */
    public function crmOptions(Request $request)
    {
        $type = $request->query('type') === 'PARTY' ? 'PARTY' : 'PERSON';
        $search = trim((string) $request->query('search', '')) ?: null;

        $rows = $type === 'PARTY'
            ? DB::select('EXEC dbo.sp_Crm_GetParties @SearchText = ?, @IsActive = 1', [$search])
            : DB::select('EXEC dbo.sp_Crm_GetPersons @SearchText = ?, @IsActive = 1', [$search]);
        $items = collect($rows)->take(30)->map(fn ($r) => [
            'id' => (int) ($type === 'PARTY' ? $r->PartyID : $r->PersonID),
            'name' => $type === 'PARTY' ? (string) $r->OfficialName : trim(($r->FirstName ?? '') . ' ' . $r->LastName),
        ])->values();

        $column = $type === 'PARTY' ? 'CrmPartyID' : 'CrmPersonID';
        $linked = DB::table('Users')->whereIn($column, $items->pluck('id')->all() ?: [0])
            ->get([$column, 'UserID', 'UserName', 'FullName'])->keyBy($column);

        return response()->json(['items' => $items->map(function ($i) use ($linked) {
            $u = $linked->get($i['id']);

            return $i + ['linkedUserId' => $u ? (int) $u->UserID : null, 'linkedUserName' => $u ? (trim((string) $u->FullName) ?: $u->UserName) : null];
        })->all()]);
    }

    /**
     * ویرایش کاربر
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'FirstName' => 'nullable|string|max:100',
            'LastName' => 'nullable|string|max:100',
            'Email' => 'nullable|email|max:200',
            'Mobile' => 'nullable|string|max:20',
            'Description' => 'nullable|string|max:500',
            ...self::CRM_LINK_RULES,
        ]);

        try {
            $response = DB::transaction(function () use ($validated, $id) {
                $result = DB::select(
                    'EXEC sp_UpdateUser
                        @UserID = ?, @FirstName = ?, @LastName = ?, @Email = ?,
                        @Mobile = ?, @Description = ?, @ModifyUser = ?',
                    [
                        $id,
                        $validated['FirstName'] ?? null,
                        $validated['LastName'] ?? null,
                        $validated['Email'] ?? null,
                        $validated['Mobile'] ?? null,
                        $validated['Description'] ?? null,
                        Auth::id(),
                    ]
                );
                $response = (array) ($result[0] ?? []);
                if (! empty($response['Success'])) {
                    $this->saveCrmLink($id, $validated);
                }

                return $response;
            });
        } catch (\DomainException $e) {
            return back()->withErrors(['UserType' => $e->getMessage()]);
        }

        if (empty($response['Success'])) {
            return back()->withErrors([
                'Email' => $response['Message'] ?? 'خطا در ویرایش کاربر',
            ]);
        }

        return redirect()->route('users.index')
            ->with('success', $response['Message']);
    }

    /**
     * تغییر وضعیت فعال/غیرفعال
     */
    public function toggleActive(int $id)
    {
        $result = DB::select(
            'EXEC sp_ToggleUserActive @UserID = ?, @ModifyUser = ?',
            [$id, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در تغییر وضعیت');
        }

        return redirect()->route('users.index')
            ->with('success', $response['Message']);
    }

    /**
     * بازنشانی پسورد کاربر به 123456
     */
    public function resetPassword(int $id)
    {
        $passwordHash = Hash::make('123456');

        $result = DB::select(
            'EXEC sp_ResetUserPassword @UserID = ?, @PasswordHash = ?, @ModifyUser = ?',
            [$id, $passwordHash, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در بازنشانی پسورد');
        }

        return redirect()->route('users.index')
            ->with('success', $response['Message']);
    }

    /**
     * نمایش صفحه مدیریت نقش‌های کاربر
     */
    public function roles(int $id)
    {
        $users = DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = NULL');
        $user = collect($users)->firstWhere('UserID', $id);

        if (!$user) {
            return redirect()->route('users.index')
                ->with('error', 'کاربر مورد نظر یافت نشد');
        }

        $roles = DB::select(
            'EXEC sp_GetRolesForUser @UserID = ?',
            [$id]
        );

        return Inertia::render('Users/Roles', [
            'user' => $user,
            'roles' => $roles,
        ]);
    }

    /**
     * ذخیره نقش‌های کاربر
     */
    public function saveRoles(Request $request, int $id)
    {
        $validated = $request->validate([
            'role_ids' => 'nullable|array',
            'role_ids.*' => 'integer',
        ]);

        $roleIds = implode(',', $validated['role_ids'] ?? []);

        $result = DB::select(
            'EXEC sp_SaveUserRoles
                @UserID = ?, @RoleIDs = ?, @ModifyUser = ?',
            [$id, $roleIds, Auth::id()]
        );

        $response = (array) ($result[0] ?? []);

        if (empty($response['Success'])) {
            return back()->with('error', $response['Message'] ?? 'خطا در ذخیره نقش‌ها');
        }

        return back()->with('success', $response['Message']);
    }
}