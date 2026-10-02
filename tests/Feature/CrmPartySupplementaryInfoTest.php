<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * اطلاعاتِ تکمیلیِ طرف‌حساب (CrmPartySupplementaryInfo، ۱:۱) + Master Dataِ نوعِ مالکیت (CrmOwnershipTypes).
 *
 * تست‌هایِ Permission با کاربرانِ ایزولهٔ ساخته‌شده در همین تراکنش اجرا می‌شوند (نه UserID=3) تا به
 * Permission Driftِ شناخته‌شدهٔ DBِ Dev وابسته نباشند؛ همه‌چیز با DatabaseTransactions برمی‌گردد.
 */
class CrmPartySupplementaryInfoTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }

        return (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ اطلاعاتِ تکمیلی', 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    /** کاربرِ ایزوله با دقیقاً همین Permissionها (بدونِ هیچ Roleِ دیگری) */
    private function userWith(array $permissionCodes): int
    {
        $code = 'SUPI_' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert(
            "INSERT INTO dbo.Users (UserCode, UserName, PasswordHash, FirstName, LastName, IsActive, CreateDate, RowGuid, IsLocked, FailedLoginCount)
             VALUES (?, ?, ?, N'تستِ', N'اطلاعاتِ تکمیلی', 1, GETDATE(), NEWID(), 0, 0)",
            [$code, $code, bcrypt('x')]
        );
        $userId = (int) DB::selectOne('SELECT UserID FROM dbo.Users WHERE UserCode = ?', [$code])->UserID;

        if ($permissionCodes) {
            DB::insert('INSERT INTO dbo.Roles (RoleCode, RoleName) VALUES (?, ?)', [$code, 'نقشِ تستِ ' . $code]);
            $roleId = (int) DB::selectOne('SELECT RoleID FROM dbo.Roles WHERE RoleCode = ?', [$code])->RoleID;
            $placeholders = implode(',', array_fill(0, count($permissionCodes), '?'));
            DB::insert(
                "INSERT INTO dbo.RolePermissions (RoleID, PermissionID) SELECT ?, PermissionID FROM dbo.Permissions WHERE PermissionCode IN ({$placeholders})",
                array_merge([$roleId], $permissionCodes)
            );
            DB::insert('INSERT INTO dbo.UserRoles (UserID, RoleID) VALUES (?, ?)', [$userId, $roleId]);
        }

        $actual = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$userId]))->pluck('PermissionCode')->sort()->values()->all();
        $this->assertSame(collect($permissionCodes)->sort()->values()->all(), $actual, 'کاربرِ ایزوله باید دقیقاً همین Permissionها را داشته باشد.');

        return $userId;
    }

    private function newOwnershipType(string $name): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/directory/ownership-types', ['displayName' => $name])
            ->assertOk()->assertJson(['success' => true])->json('ownershipTypeId');
    }

    private function save(int $partyId, array $body, int $user = self::USER_FULL)
    {
        return $this->as($user)->postJson("/crm/parties/{$partyId}/supplementary-info", $body);
    }

    private function info(int $partyId): ?array
    {
        return $this->as(self::USER_FULL)->getJson("/crm/parties/{$partyId}/supplementary-info")->assertOk()->json('item');
    }

    private function showProps(int $partyId): array
    {
        return $this->as(self::USER_FULL)->get("/crm/parties/{$partyId}")->assertOk()->viewData('page')['props'];
    }

    /* ---------- Master Data ---------- */

    public function test_seed_ownership_types_exist_as_master_data(): void
    {
        $names = collect(DB::select('SELECT DisplayName FROM dbo.CrmOwnershipTypes'))->pluck('DisplayName')->all();
        $this->assertContains('مالک', $names);
        $this->assertContains('مستأجر', $names);
    }

    public function test_ownership_type_create_edit_toggle(): void
    {
        $id = $this->newOwnershipType('رهن');
        $row = DB::selectOne('SELECT Code, DisplayName, IsActive FROM dbo.CrmOwnershipTypes WHERE OwnershipTypeID = ?', [$id]);
        $this->assertGreaterThanOrEqual(101, (int) $row->Code, 'Code خودکارِ عددی از ۱۰۱ به بعد است.');
        $this->assertEquals(1, $row->IsActive);

        $this->as(self::USER_FULL)->postJson('/crm/directory/ownership-types', ['ownershipTypeId' => $id, 'displayName' => 'رهن و اجاره', 'sortOrder' => 5])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame('رهن و اجاره', DB::selectOne('SELECT DisplayName FROM dbo.CrmOwnershipTypes WHERE OwnershipTypeID = ?', [$id])->DisplayName);

        $this->as(self::USER_FULL)->postJson("/crm/directory/ownership-types/{$id}/toggle")->assertOk()->assertJson(['success' => true]);
        $inactive = $this->as(self::USER_FULL)->getJson('/crm/directory/ownership-types?isActive=0')->assertOk()->json('items');
        $this->assertContains($id, array_map('intval', array_column($inactive, 'OwnershipTypeID')));

        $this->as(self::USER_FULL)->postJson('/crm/directory/ownership-types', ['displayName' => '   '])->assertStatus(422);
    }

    public function test_ownership_type_endpoints_require_master_data_permission(): void
    {
        $existing = $this->newOwnershipType('نوعِ تستِ دسترسی');
        $partyManager = $this->userWith(['CRM_VIEW', 'CRM_MANAGE_PARTIES']);

        $this->as($partyManager)->getJson('/crm/directory/ownership-types')->assertStatus(403);
        $this->as($partyManager)->postJson('/crm/directory/ownership-types', ['displayName' => 'غیرمجاز'])->assertStatus(403);
        $this->as($partyManager)->postJson("/crm/directory/ownership-types/{$existing}/toggle")->assertStatus(403);

        $masterDataAdmin = $this->userWith(['CRM_MANAGE_MASTER_DATA']);
        $this->as($masterDataAdmin)->getJson('/crm/directory/ownership-types')->assertOk();
        $this->as($masterDataAdmin)->postJson('/crm/directory/ownership-types', ['displayName' => 'مجاز'])->assertOk()->assertJson(['success' => true]);
    }

    /* ---------- اطلاعاتِ تکمیلی ---------- */

    public function test_new_party_has_no_supplementary_info(): void
    {
        $partyId = $this->party();

        $this->assertNull($this->info($partyId));
        $this->assertNull($this->showProps($partyId)['supplementaryInfo']);
    }

    public function test_first_save_inserts_and_second_save_updates_without_duplicate(): void
    {
        $partyId = $this->party();
        $owner = $this->newOwnershipType('مالکِ تستی');
        $tenant = $this->newOwnershipType('مستأجرِ تستی');

        $this->save($partyId, ['ownershipTypeId' => $owner, 'areaSqm' => 120.5])->assertOk()->assertJson(['success' => true]);
        $first = $this->info($partyId);
        $this->assertEquals($owner, (int) $first['OwnershipTypeID']);
        $this->assertEquals('120.50', number_format((float) $first['AreaSqm'], 2, '.', ''));
        $this->assertNull($first['Date_LastUpdate']);

        $res = $this->save($partyId, ['ownershipTypeId' => $tenant, 'areaSqm' => 85]);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertEquals($tenant, (int) $res->json('item.OwnershipTypeID'), 'پاسخِ ذخیره، دادهٔ تازه را برمی‌گرداند.');

        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.CrmPartySupplementaryInfo WHERE PartyID = ?', [$partyId])->c);
        $second = $this->info($partyId);
        $this->assertEquals($tenant, (int) $second['OwnershipTypeID']);
        $this->assertEquals(85.0, (float) $second['AreaSqm']);
        $this->assertNotNull($second['Date_LastUpdate']);
        $creator = User::find(self::USER_FULL);
        $this->assertSame(trim($creator->FirstName . ' ' . $creator->LastName), $second['ModifiedByName']);
    }

    public function test_fields_can_be_cleared_to_null(): void
    {
        $partyId = $this->party();
        $type = $this->newOwnershipType('نوعِ پاک‌شدنی');
        $this->save($partyId, ['ownershipTypeId' => $type, 'areaSqm' => 50])->assertOk();

        $this->save($partyId, ['ownershipTypeId' => null, 'areaSqm' => null])->assertOk()->assertJson(['success' => true]);

        $info = $this->info($partyId);
        $this->assertNull($info['OwnershipTypeID']);
        $this->assertNull($info['AreaSqm']);
    }

    public function test_area_validation(): void
    {
        $partyId = $this->party();

        $this->save($partyId, ['areaSqm' => -1])->assertStatus(422);
        $this->save($partyId, ['areaSqm' => 'abc'])->assertStatus(422);
        $this->save($partyId, ['areaSqm' => 10.123])->assertStatus(422);
        $this->save($partyId, ['areaSqm' => 10000000000])->assertStatus(422);
        $this->assertNull($this->info($partyId), 'ورودیِ نامعتبر نباید ردیفی بسازد.');

        $this->save($partyId, ['areaSqm' => 0])->assertOk();
        $this->assertEquals(0.0, (float) $this->info($partyId)['AreaSqm']);
        $this->save($partyId, ['areaSqm' => 1234.56])->assertOk();
        $this->assertEquals('1234.56', number_format((float) $this->info($partyId)['AreaSqm'], 2, '.', ''));
    }

    public function test_nonexistent_ownership_type_is_rejected(): void
    {
        $partyId = $this->party();

        $this->save($partyId, ['ownershipTypeId' => 999999999])->assertStatus(422);
        $this->assertNull($this->info($partyId));
    }

    public function test_inactive_ownership_type_rejected_for_new_selection_but_kept_when_unchanged(): void
    {
        $partyA = $this->party();
        $partyB = $this->party();
        $type = $this->newOwnershipType('نوعِ قدیمی');
        $this->save($partyA, ['ownershipTypeId' => $type, 'areaSqm' => 70])->assertOk();

        $this->as(self::USER_FULL)->postJson("/crm/directory/ownership-types/{$type}/toggle")->assertOk();

        // طرف‌حسابِ A همان نوعِ (اکنون غیرفعالِ) فعلی را نگه می‌دارد و فقط متراژ را عوض می‌کند → مجاز
        $this->save($partyA, ['ownershipTypeId' => $type, 'areaSqm' => 75])->assertOk()->assertJson(['success' => true]);
        $infoA = $this->info($partyA);
        $this->assertEquals($type, (int) $infoA['OwnershipTypeID']);
        $this->assertEquals(0, (int) $infoA['OwnershipTypeIsActive']);
        $this->assertEquals(75.0, (float) $infoA['AreaSqm']);

        // طرف‌حسابِ B نمی‌تواند نوعِ غیرفعال را تازه انتخاب کند
        $this->save($partyB, ['ownershipTypeId' => $type])->assertStatus(422)->assertJson(['success' => false]);
        $this->assertNull($this->info($partyB));

        // گزینه‌هایِ صفحه فقط نوع‌هایِ فعال‌اند
        $optionIds = array_map('intval', array_column($this->showProps($partyB)['ownershipTypes'], 'OwnershipTypeID'));
        $this->assertNotContains($type, $optionIds);
    }

    public function test_view_and_edit_permissions(): void
    {
        $partyId = $this->party();

        $viewer = $this->userWith(['CRM_VIEW']);
        $this->as($viewer)->getJson("/crm/parties/{$partyId}/supplementary-info")->assertOk();
        $this->save($partyId, ['areaSqm' => 10], $viewer)->assertStatus(403);

        $nobody = $this->userWith([]);
        $this->as($nobody)->getJson("/crm/parties/{$partyId}/supplementary-info")->assertStatus(403);
        $this->save($partyId, ['areaSqm' => 10], $nobody)->assertStatus(403);
        $this->assertNull($this->info($partyId));

        $editor = $this->userWith(['CRM_VIEW', 'CRM_MANAGE_PARTIES']);
        $this->save($partyId, ['areaSqm' => 10], $editor)->assertOk()->assertJson(['success' => true]);
        $this->assertEquals(10.0, (float) $this->info($partyId)['AreaSqm']);
    }

    public function test_party_id_comes_from_route_not_body_and_unknown_party_fails(): void
    {
        $partyA = $this->party();
        $partyB = $this->party();

        $this->save($partyA, ['partyId' => $partyB, 'areaSqm' => 33])->assertOk();
        $this->assertEquals(33.0, (float) $this->info($partyA)['AreaSqm']);
        $this->assertNull($this->info($partyB));

        $this->save(999999999, ['areaSqm' => 1])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_party_images_and_other_party_data_unaffected(): void
    {
        Storage::fake('local');
        $partyId = $this->party();
        $this->as(self::USER_FULL)->post("/crm/parties/{$partyId}/images", [
            'images' => [UploadedFile::fake()->image('a.jpg', 50, 50)],
        ], ['Accept' => 'application/json'])->assertOk();
        $partyBefore = DB::selectOne('SELECT OfficialName, Description, Date_LastUpdate FROM dbo.CrmParties WHERE PartyID = ?', [$partyId]);

        $this->save($partyId, ['areaSqm' => 99])->assertOk();

        $props = $this->showProps($partyId);
        $this->assertCount(1, $props['partyImages']);
        $this->assertArrayHasKey('interactions', $props);
        $this->assertArrayHasKey('addresses', $props);
        $this->assertEquals(99.0, (float) $props['supplementaryInfo']->AreaSqm);

        $partyAfter = DB::selectOne('SELECT OfficialName, Description, Date_LastUpdate FROM dbo.CrmParties WHERE PartyID = ?', [$partyId]);
        $this->assertEquals($partyBefore, $partyAfter, 'ذخیرهٔ اطلاعاتِ تکمیلی نباید ردیفِ اصلیِ CrmParties را تغییر دهد.');
    }
}
