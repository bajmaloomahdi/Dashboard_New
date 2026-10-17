<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * حذفِ اطلاعاتِ CRM (POST crm/delete/{entity}/{id}، دسترسیِ واحدِ CRM_DELETE، sp_Crm_DeleteRecord — پچ 083).
 * رکوردِ استفاده‌شده حذف نمی‌شود؛ فقط انتخاب‌هایِ چندگانهٔ خودِ رکورد همراهش حذف می‌شوند.
 */
class CrmDeleteTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2; // CRM_VIEW + CRM_MANAGE_PARTIES + CRM_DELETE (نقشِ ادمین)

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function digits(int $length): string
    {
        $s = (string) random_int(1, 9);
        for ($i = 1; $i < $length; $i++) {
            $s .= (string) random_int(0, 9);
        }

        return $s;
    }

    private function party(array $extra = []): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/parties', array_merge([
            'partyNature' => 'LEGAL',
            'officialName' => 'شرکتِ آزمونِ حذف',
            'identifierNumber' => $this->digits(11),
        ], $extra))->assertOk()->json('partyId');
    }

    private function person(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'firstName' => 'آزمون', 'lastName' => 'حذف ' . $this->digits(4)])
            ->assertOk()->json('personId');
    }

    private function position(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/directory/positions', ['displayName' => 'سمتِ حذف ' . $this->digits(5)])
            ->assertOk()->json('positionId');
    }

    private function role(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/directory/contact-roles', ['displayName' => 'نقشِ حذف ' . $this->digits(5)])
            ->assertOk()->json('contactRoleId');
    }

    private function contact(int $partyId): int
    {
        $typeId = (int) DB::table('CrmContactTypes')->where('IsActive', 1)->value('ContactTypeID');

        return (int) $this->as(self::USER_FULL)->postJson('/crm/contacts', ['partyId' => $partyId, 'contactTypeId' => $typeId, 'contactValue' => '0912' . $this->digits(7)])
            ->assertOk()->json('contactId');
    }

    private function crmDelete(string $entity, int $id, int $userId = self::USER_FULL)
    {
        return $this->as($userId)->postJson("/crm/delete/{$entity}/{$id}");
    }

    /** کاربرِ آزمایشیِ داخلِ تراکنش با فقط همین دسترسی‌ها (نقشِ موقت) */
    private function userWith(array $permissionCodes): int
    {
        $code = 'QADL' . $this->digits(5);
        DB::insert(
            'INSERT INTO dbo.Users (UserCode, UserName, PasswordHash, FirstName, LastName, IsActive, CreateDate, RowGuid, IsLocked, FailedLoginCount) '
            . "VALUES (?, ?, N'x', N'آزمون', N'حذف', 1, GETDATE(), NEWID(), 0, 0)",
            [$code, strtolower($code)]
        );
        $userId = (int) DB::table('Users')->where('UserCode', $code)->value('UserID');
        if ($permissionCodes) {
            DB::select('EXEC dbo.sp_InsertRole @RoleName = ?, @Description = ?, @CreateUser = ?', [$code, 'temp test role', self::USER_FULL]);
            $roleId = (int) DB::table('Roles')->where('RoleName', $code)->value('RoleID');
            foreach (DB::table('Permissions')->whereIn('PermissionCode', $permissionCodes)->pluck('PermissionID') as $pid) {
                DB::insert('INSERT INTO dbo.RolePermissions (RoleID, PermissionID, CanAccess, IsActive, CreateUser) VALUES (?, ?, 1, 1, 2)', [$roleId, $pid]);
            }
            DB::insert('INSERT INTO dbo.UserRoles (UserID, RoleID, IsActive, CreateUser) VALUES (?, ?, 1, 2)', [$userId, $roleId]);
        }

        return $userId;
    }

    /* ==================================================================== */

    public function test_delete_permission_is_registered_in_crm_group(): void
    {
        $this->assertSame('CRM', DB::table('Permissions')->where('PermissionCode', 'CRM_DELETE')->value('PermissionGroup'));
    }

    public function test_delete_requires_crm_delete_permission_even_for_managers(): void
    {
        $partyId = $this->party();
        $manager = $this->userWith(['CRM_VIEW', 'CRM_MANAGE_PARTIES', 'CRM_MANAGE_MASTER_DATA']);

        $this->crmDelete('party', $partyId, $manager)->assertStatus(403);
        $this->assertSame(1, DB::table('CrmParties')->where('PartyID', $partyId)->count());

        $deleter = $this->userWith(['CRM_VIEW', 'CRM_DELETE']);
        $this->crmDelete('party', $partyId, $deleter)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(0, DB::table('CrmParties')->where('PartyID', $partyId)->count());
    }

    public function test_can_delete_prop_follows_permission(): void
    {
        $partyId = $this->party();
        $this->assertTrue($this->as(self::USER_FULL)->get("/crm/parties/{$partyId}")->assertOk()->viewData('page')['props']['canDelete']);

        $viewer = $this->userWith(['CRM_VIEW']);
        $this->assertFalse($this->as($viewer)->get("/crm/parties/{$partyId}")->assertOk()->viewData('page')['props']['canDelete']);
        $masterManager = $this->userWith(['CRM_VIEW', 'CRM_MANAGE_MASTER_DATA']);
        $this->assertFalse($this->as($masterManager)->get('/crm/directory')->assertOk()->viewData('page')['props']['canDelete']);
    }

    public function test_party_in_use_is_blocked_with_message_then_deletable_after_children_removed(): void
    {
        $partyId = $this->party();
        $contactId = $this->contact($partyId);

        $res = $this->crmDelete('party', $partyId)->assertStatus(422)->assertJson(['success' => false]);
        $this->assertStringContainsString('اطلاعاتِ تماس', $res->json('message'));
        $this->assertSame(1, DB::table('CrmParties')->where('PartyID', $partyId)->count());

        $this->crmDelete('contact', $contactId)->assertOk()->assertJson(['success' => true]);
        $this->crmDelete('party', $partyId)->assertOk();
        $this->assertSame(0, DB::table('CrmParties')->where('PartyID', $partyId)->count());
    }

    public function test_party_own_roles_and_positions_are_removed_with_it(): void
    {
        [$r1, $p1] = [$this->role(), $this->position()];
        $partyId = $this->party(['roleIds' => [$r1], 'positionIds' => [$p1]]);
        $this->assertSame(1, DB::table('CrmPartyRoles')->where('PartyID', $partyId)->count());

        $this->crmDelete('party', $partyId)->assertOk();
        $this->assertSame(0, DB::table('CrmPartyRoles')->where('PartyID', $partyId)->count());
        $this->assertSame(0, DB::table('CrmPartyPositions')->where('PartyID', $partyId)->count());
        // خودِ Master Data باقی می‌ماند و حالا آزاد است
        $this->crmDelete('contact-role', $r1)->assertOk();
        $this->crmDelete('position', $p1)->assertOk();
    }

    public function test_relation_delete_removes_its_roles_and_positions_and_frees_master_data(): void
    {
        $partyId = $this->party();
        $personId = $this->person();
        [$r1, $p1] = [$this->role(), $this->position()];
        $relationId = (int) $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $partyId, 'personId' => $personId, 'roleIds' => [$r1], 'positionIds' => [$p1],
        ])->assertOk()->json('relationId');

        $this->assertStringContainsString('نقش‌هایِ مخاطب', $this->crmDelete('contact-role', $r1)->assertStatus(422)->json('message'));
        $this->crmDelete('person', $personId)->assertStatus(422);

        $this->crmDelete('relation', $relationId)->assertOk();
        $this->assertSame(0, DB::table('CrmPartyPersonRelationRoles')->where('RelationID', $relationId)->count());
        $this->assertSame(0, DB::table('CrmPartyPersonRelationPositions')->where('RelationID', $relationId)->count());

        $this->crmDelete('contact-role', $r1)->assertOk();
        $this->crmDelete('position', $p1)->assertOk();
        $this->crmDelete('person', $personId)->assertOk();
        $this->crmDelete('party', $partyId)->assertOk();
    }

    public function test_last_brand_row_removes_internal_party_brand_link_so_brand_and_party_become_deletable(): void
    {
        $partyId = $this->party();
        $brandId = (int) $this->as(self::USER_FULL)->post('/crm/brands', ['name' => 'برندِ حذف ' . $this->digits(4)], ['Accept' => 'application/json'])
            ->assertOk()->json('brandId');
        $rowId = (int) $this->as(self::USER_FULL)->postJson('/crm/party-brand-categories', ['partyId' => $partyId, 'brandId' => $brandId, 'sharePercent' => 0])
            ->assertOk()->json('partyBrandCategoryId');
        $this->assertSame(1, DB::table('CrmPartyBrands')->where('PartyID', $partyId)->where('BrandID', $brandId)->count());

        $this->crmDelete('brand', $brandId)->assertStatus(422);
        $this->crmDelete('party', $partyId)->assertStatus(422);

        $this->crmDelete('party-brand-category', $rowId)->assertOk();
        $this->assertSame(0, DB::table('CrmPartyBrands')->where('PartyID', $partyId)->count());

        $this->crmDelete('brand', $brandId)->assertOk();
        $this->crmDelete('party', $partyId)->assertOk();
    }

    public function test_unknown_entity_and_missing_record(): void
    {
        $this->crmDelete('users', 1)->assertStatus(404);
        $this->crmDelete('party', 999999999)->assertStatus(422)->assertJson(['success' => false]);
    }
}
