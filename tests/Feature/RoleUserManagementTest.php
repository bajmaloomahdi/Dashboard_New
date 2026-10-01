<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مدیریتِ کاربرانِ یک نقش از سمتِ Role (sp_GetUsersForRole/sp_SaveRoleUsers) — طرفِ
 * مقابلِ مسیرِ از‌قبل‌موجودِ Users/Roles.tsx (سمتِ User)؛ هر دو رویِ همان یک جدولِ
 * UserRoles کار می‌کنند، بدونِ هیچ رابطهٔ موازیِ جدید.
 *
 * کاربران: 2 → «مهدی باج مالو»، 3 → «علی باج مالو»، 4 → «محسن باج مالو» (کاربرانِ
 * واقعیِ از‌پیش‌موجود؛ RoleID برایِ هر تست تازه ساخته می‌شود، پس دست‌کاریِ عضویتِ
 * واقعی‌شان در Roleهایِ دیگر رخ نمی‌دهد).
 */
class RoleUserManagementTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function createRole(): int
    {
        $code = 'RUT_' . strtoupper(bin2hex(random_bytes(4)));
        $this->as(self::USER_FULL)->post('/roles', [
            'RoleName' => 'نقشِ تست ' . $code,
        ])->assertRedirect();

        $role = DB::selectOne('SELECT TOP 1 RoleID FROM dbo.Roles WHERE RoleName = ? ORDER BY RoleID DESC', ['نقشِ تست ' . $code]);

        return (int) $role->RoleID;
    }

    private function activeMembers(int $roleId): array
    {
        $rows = DB::select('SELECT UserID FROM dbo.UserRoles WHERE RoleID = ? AND IsActive = 1 ORDER BY UserID', [$roleId]);

        return array_map(fn ($r) => (int) $r->UserID, $rows);
    }

    public function test_get_users_for_role_lists_all_active_users_with_has_role_flag(): void
    {
        $roleId = $this->createRole();

        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [3]])->assertRedirect();

        $res = $this->as(self::USER_FULL)->get("/roles/{$roleId}/users")->assertOk();
        $props = $res->viewData('page')['props'];

        $this->assertEquals($roleId, $props['role']->RoleID);
        $rows = collect($props['roleUsers']);
        $this->assertEquals(1, $rows->firstWhere('UserID', 3)->HasRole, 'کاربرِ عضو باید HasRole=1 داشته باشد.');
        $this->assertEquals(0, $rows->firstWhere('UserID', 4)->HasRole, 'کاربرِ غیرِعضو باید HasRole=0 داشته باشد.');
    }

    public function test_bulk_add_multiple_users_to_role_in_one_call(): void
    {
        $roleId = $this->createRole();
        $this->assertSame([], $this->activeMembers($roleId));

        $this->as(self::USER_FULL)
            ->post("/roles/{$roleId}/users", ['user_ids' => [2, 3, 4]])
            ->assertRedirect();

        $this->assertSame([2, 3, 4], $this->activeMembers($roleId));
    }

    public function test_add_single_user_to_role(): void
    {
        $roleId = $this->createRole();

        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [3]])->assertRedirect();

        $this->assertSame([3], $this->activeMembers($roleId));
    }

    public function test_remove_one_user_keeps_the_rest(): void
    {
        $roleId = $this->createRole();
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [2, 3, 4]])->assertRedirect();

        // حذفِ کاربرِ 3 — فقط با نفرستادنش در مجموعهٔ جدید
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [2, 4]])->assertRedirect();

        $this->assertSame([2, 4], $this->activeMembers($roleId));

        // ردیفِ کاربرِ 3 باید IsActive=0 بماند (Soft، نه حذفِ فیزیکی — هم‌الگو با sp_SaveUserRoles)
        $row = DB::selectOne('SELECT IsActive FROM dbo.UserRoles WHERE RoleID = ? AND UserID = 3', [$roleId]);
        $this->assertNotNull($row, 'ردیفِ کاربرِ حذف‌شده نباید فیزیکاً حذف شود.');
        $this->assertEquals(0, $row->IsActive);
    }

    public function test_remove_all_users_from_role(): void
    {
        $roleId = $this->createRole();
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [2, 3]])->assertRedirect();

        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => []])->assertRedirect();

        $this->assertSame([], $this->activeMembers($roleId));
    }

    public function test_re_adding_a_previously_removed_user_reactivates_the_same_row_not_a_duplicate(): void
    {
        $roleId = $this->createRole();
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [3]])->assertRedirect();
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => []])->assertRedirect();
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [3]])->assertRedirect();

        $rowCount = DB::selectOne('SELECT COUNT(*) c FROM dbo.UserRoles WHERE RoleID = ? AND UserID = 3', [$roleId]);
        $this->assertEquals(1, $rowCount->c, 'نباید ردیفِ تکراری برایِ همان (RoleID,UserID) ساخته شود.');
        $this->assertSame([3], $this->activeMembers($roleId));
    }

    public function test_invalid_role_id_returns_failure_without_throwing(): void
    {
        $this->as(self::USER_FULL)
            ->post('/roles/999999/users', ['user_ids' => [2]])
            ->assertRedirect();

        // چیزی برایِ RoleIDِ ناموجود نباید درج شده باشد
        $row = DB::selectOne('SELECT COUNT(*) c FROM dbo.UserRoles WHERE RoleID = 999999');
        $this->assertEquals(0, $row->c);
    }

    public function test_added_user_immediately_gains_the_roles_permissions(): void
    {
        $roleId = $this->createRole();

        // یک Permissionِ کاملاً تازه و یکتا (مخصوصِ همین تست) — تا مطمئن باشیم کاربرِ ۳
        // از هیچ نقشِ دیگری از‌قبل آن را ندارد.
        $permCode = 'RUT_PERM_' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert(
            "INSERT INTO dbo.Permissions (PermissionCode, PermissionName, PermissionGroup, SortOrder, IsActive, CreateDate, RowGuid, CreateUser)
             VALUES (?, N'پرمیشنِ تست', N'تست', 0, 1, SYSDATETIME(), NEWID(), ?)",
            [$permCode, self::USER_FULL]
        );
        $perm = DB::selectOne('SELECT PermissionID, PermissionCode FROM dbo.Permissions WHERE PermissionCode = ?', [$permCode]);

        $this->as(self::USER_FULL)->post("/roles/{$roleId}/permissions", ['permission_ids' => [$perm->PermissionID]])->assertRedirect();

        $before = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [3]))->pluck('PermissionCode');
        $this->assertNotContains($perm->PermissionCode, $before->all());

        // افزودنِ کاربرِ ۳ به نقش از طریقِ Endpointِ جدید
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [3]])->assertRedirect();

        $after = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [3]))->pluck('PermissionCode');
        $this->assertContains($perm->PermissionCode, $after->all(), 'بعدِ افزودن به نقش، Permissionِ همان نقش باید بلافاصله به کاربر برسد.');
    }

    public function test_search_filters_users_by_name(): void
    {
        $roleId = $this->createRole();

        $res = $this->as(self::USER_FULL)->get("/roles/{$roleId}/users?search=" . urlencode('علی'))->assertOk();
        $rows = collect($res->viewData('page')['props']['roleUsers']);

        $this->assertTrue($rows->every(fn ($r) => str_contains((string) $r->FullName, 'علی')
            || str_contains((string) ($r->Email ?? ''), 'علی')
            || str_contains((string) $r->UserCode, 'علی')));
        $this->assertTrue($rows->firstWhere('UserID', 3) !== null, 'علی باج‌مالو باید در نتیجهٔ جستجویِ «علی» باشد.');
    }

    public function test_nonexistent_user_id_is_rejected_by_validation_before_reaching_the_sp(): void
    {
        $roleId = $this->createRole();

        $this->as(self::USER_FULL)
            ->post("/roles/{$roleId}/users", ['user_ids' => [999999]])
            ->assertSessionHasErrors('user_ids.0');

        // هیچ‌چیزی نباید در UserRoles درج شده باشد — درخواست قبل از رسیدن به SP رد شده است.
        $this->assertSame([], $this->activeMembers($roleId));
    }

    public function test_duplicate_user_id_in_same_request_is_rejected_by_validation(): void
    {
        $roleId = $this->createRole();

        $this->as(self::USER_FULL)
            ->post("/roles/{$roleId}/users", ['user_ids' => [3, 3]])
            ->assertSessionHasErrors();

        $this->assertSame([], $this->activeMembers($roleId));
    }

    public function test_mix_of_valid_and_invalid_user_ids_rejects_the_whole_request(): void
    {
        $roleId = $this->createRole();
        // یک عضوِ قبلیِ واقعی هم داشته باشیم تا تأیید شود این درخواستِ رد‌شده هیچ اثری
        // رویِ وضعیتِ موجود هم نمی‌گذارد (All-or-Nothing، نه فقط برایِ درخواستِ خالی).
        $this->as(self::USER_FULL)->post("/roles/{$roleId}/users", ['user_ids' => [4]])->assertRedirect();

        $this->as(self::USER_FULL)
            ->post("/roles/{$roleId}/users", ['user_ids' => [2, 999999]])
            ->assertSessionHasErrors();

        $this->assertSame([4], $this->activeMembers($roleId), 'درخواستِ نامعتبر نباید وضعیتِ موجود را هم تغییر دهد.');
    }
}
