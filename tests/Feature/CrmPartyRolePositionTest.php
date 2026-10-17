<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * نقش‌ها و سمت‌هایِ خودِ طرف‌حساب (چندگانه؛ CrmPartyRoles / CrmPartyPositions، پچ 080).
 * Master Data همان «نقش» (CrmContactRoles) و «سمت» (CrmPositions)ِ «فهرست‌هایِ CRM» است؛
 * نقش/سمتِ مخاطب در رابطه (CrmPartyPersonRelations / CrmPartyPersonRelationRoles) کاملاً مستقل می‌ماند.
 *
 * کاربران: 2 → CRM_VIEW + CRM_MANAGE_PARTIES؛ کاربرِ بدونِ دسترسی داخلِ خودِ تست ساخته می‌شود
 * (کاربرِ 3 در دادهٔ فعلی از طریقِ نقش‌هایِ 3065/3066 دسترسیِ CRM دارد).
 */
class CrmPartyRolePositionTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function uniqueDigits(int $length): string
    {
        $s = (string) random_int(1, 9);
        for ($i = 1; $i < $length; $i++) {
            $s .= (string) random_int(0, 9);
        }

        return $s;
    }

    private function role(string $name): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/directory/contact-roles', ['displayName' => $name . ' ' . $this->uniqueDigits(4)])
            ->assertOk()->json('contactRoleId');
    }

    private function position(string $name): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/directory/positions', ['displayName' => $name . ' ' . $this->uniqueDigits(4)])
            ->assertOk()->json('positionId');
    }

    private function saveParty(array $overrides = []): array
    {
        return $this->as(self::USER_FULL)->postJson('/crm/parties', array_merge([
            'partyNature' => 'LEGAL',
            'officialName' => 'شرکتِ نقش‌وسمت',
            'identifierNumber' => $this->uniqueDigits(11),
        ], $overrides))->assertOk()->assertJson(['success' => true])->json();
    }

    /** @return int[] */
    private function roleIdsOf(int $partyId): array
    {
        return array_map(fn ($r) => (int) $r->RoleID, DB::select('EXEC dbo.sp_Crm_GetPartyRoles @PartyID = ?', [$partyId]));
    }

    /** @return int[] */
    private function positionIdsOf(int $partyId): array
    {
        return array_map(fn ($r) => (int) $r->PositionID, DB::select('EXEC dbo.sp_Crm_GetPartyPositions @PartyID = ?', [$partyId]));
    }

    private function showProps(int $partyId): array
    {
        return $this->as(self::USER_FULL)->get("/crm/parties/{$partyId}")->assertOk()->viewData('page')['props'];
    }

    /* ==================================================================== */

    public function test_create_party_with_multiple_roles_and_positions(): void
    {
        $r1 = $this->role('نقش‌الف');
        $r2 = $this->role('نقش‌ب');
        $p1 = $this->position('سمت‌الف');
        $p2 = $this->position('سمت‌ب');
        $p3 = $this->position('سمت‌ج');

        $party = $this->saveParty(['roleIds' => [$r1, $r2], 'positionIds' => [$p1, $p2, $p3]]);

        $this->assertEqualsCanonicalizing([$r1, $r2], $this->roleIdsOf($party['partyId']));
        $this->assertEqualsCanonicalizing([$p1, $p2, $p3], $this->positionIdsOf($party['partyId']));
    }

    public function test_roles_and_positions_are_independent_sets(): void
    {
        $r1 = $this->role('فقط‌نقش');
        $p1 = $this->position('سمت‌۱');
        $p2 = $this->position('سمت‌۲');

        $party = $this->saveParty(['roleIds' => [$r1], 'positionIds' => [$p1, $p2]]);

        $this->assertSame(1, DB::table('CrmPartyRoles')->where('PartyID', $party['partyId'])->count());
        $this->assertSame(2, DB::table('CrmPartyPositions')->where('PartyID', $party['partyId'])->count());

        // تغییرِ فقط سمت‌ها، نقش‌ها را دست نمی‌زند
        $this->saveParty(['partyId' => $party['partyId'], 'identifierNumber' => DB::table('CrmParties')->where('PartyID', $party['partyId'])->value('IdentifierNumber'), 'positionIds' => [$p2]]);
        $this->assertSame([$r1], $this->roleIdsOf($party['partyId']));
        $this->assertSame([$p2], $this->positionIdsOf($party['partyId']));
    }

    public function test_edit_replaces_selection_and_survives_resave_and_reload(): void
    {
        $r1 = $this->role('ر۱');
        $r2 = $this->role('ر۲');
        $r3 = $this->role('ر۳');
        $p1 = $this->position('س۱');
        $p2 = $this->position('س۲');

        $party = $this->saveParty(['roleIds' => [$r1, $r2], 'positionIds' => [$p1]]);
        $identifier = DB::table('CrmParties')->where('PartyID', $party['partyId'])->value('IdentifierNumber');

        // ویرایش: r1 حذف، r3 اضافه؛ سمت‌ها کامل جایگزین
        $this->saveParty(['partyId' => $party['partyId'], 'identifierNumber' => $identifier, 'roleIds' => [$r2, $r3], 'positionIds' => [$p2, $p1]]);
        $this->assertEqualsCanonicalizing([$r2, $r3], $this->roleIdsOf($party['partyId']));
        $this->assertEqualsCanonicalizing([$p1, $p2], $this->positionIdsOf($party['partyId']));

        // ذخیرهٔ مجدد بدونِ کلیدهایِ نقش/سمت (مثلِ مسیرهایِ دیگرِ ذخیره) نباید انتخاب‌ها را پاک کند
        $this->saveParty(['partyId' => $party['partyId'], 'identifierNumber' => $identifier, 'description' => 'ذخیرهٔ مجدد']);
        $this->assertEqualsCanonicalizing([$r2, $r3], $this->roleIdsOf($party['partyId']));
        $this->assertEqualsCanonicalizing([$p1, $p2], $this->positionIdsOf($party['partyId']));

        // بارگذاریِ دوباره (endpointِ فرمِ ویرایش)
        $json = $this->as(self::USER_FULL)->getJson("/crm/parties/{$party['partyId']}/roles-positions")->assertOk()->json();
        $this->assertEqualsCanonicalizing([$r2, $r3], array_map('intval', array_column($json['roles'], 'RoleID')));
        $this->assertEqualsCanonicalizing([$p1, $p2], array_map('intval', array_column($json['positions'], 'PositionID')));
    }

    public function test_empty_arrays_clear_all_selections(): void
    {
        $party = $this->saveParty(['roleIds' => [$this->role('پاک‌شونده')], 'positionIds' => [$this->position('پاک‌شونده')]]);
        $identifier = DB::table('CrmParties')->where('PartyID', $party['partyId'])->value('IdentifierNumber');

        $this->saveParty(['partyId' => $party['partyId'], 'identifierNumber' => $identifier, 'roleIds' => [], 'positionIds' => []]);

        $this->assertSame([], $this->roleIdsOf($party['partyId']));
        $this->assertSame([], $this->positionIdsOf($party['partyId']));
    }

    public function test_show_page_lists_all_selected_roles_and_positions(): void
    {
        $r1 = $this->role('نمایش‌نقش‌۱');
        $r2 = $this->role('نمایش‌نقش‌۲');
        $p1 = $this->position('نمایش‌سمت‌۱');
        $p2 = $this->position('نمایش‌سمت‌۲');

        $party = $this->saveParty(['roleIds' => [$r1, $r2], 'positionIds' => [$p1, $p2]]);
        $props = $this->showProps($party['partyId']);

        $this->assertEqualsCanonicalizing([$r1, $r2], array_map(fn ($r) => (int) $r->RoleID, $props['partyRoles']));
        $this->assertEqualsCanonicalizing([$p1, $p2], array_map(fn ($p) => (int) $p->PositionID, $props['partyPositions']));
        foreach ($props['partyRoles'] as $r) {
            $this->assertNotEmpty($r->DisplayName);
        }
        // گزینه‌هایِ فرمِ ایجاد در صفحهٔ فهرست هم ارسال می‌شود
        $index = $this->as(self::USER_FULL)->get('/crm/parties')->assertOk()->viewData('page')['props'];
        $this->assertArrayHasKey('positions', $index);
        $this->assertArrayHasKey('contactRoles', $index);
    }

    public function test_invalid_or_duplicate_ids_are_rejected_and_nothing_is_saved(): void
    {
        $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ نامعتبر', 'identifierNumber' => $this->uniqueDigits(11),
            'roleIds' => [987654321],
        ])->assertStatus(422);

        $p = $this->position('تکراری');
        $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ تکراری', 'identifierNumber' => $this->uniqueDigits(11),
            'positionIds' => [$p, $p],
        ])->assertStatus(422);

        $this->assertSame(0, DB::table('CrmParties')->whereIn('OfficialName', ['شرکتِ نامعتبر', 'شرکتِ تکراری'])->count());
    }

    public function test_inactive_master_item_cannot_be_newly_added_but_existing_one_is_kept(): void
    {
        $role = $this->role('بعداً‌غیرفعال');
        $party = $this->saveParty(['roleIds' => [$role]]);
        $identifier = DB::table('CrmParties')->where('PartyID', $party['partyId'])->value('IdentifierNumber');

        $this->as(self::USER_FULL)->postJson("/crm/directory/contact-roles/{$role}/toggle")->assertOk();

        // ویرایشِ همان طرف‌حساب با نگه‌داشتنِ نقشِ (اکنون غیرفعالِ) قبلی مجاز است
        $this->saveParty(['partyId' => $party['partyId'], 'identifierNumber' => $identifier, 'roleIds' => [$role]]);
        $this->assertSame([$role], $this->roleIdsOf($party['partyId']));

        // افزودنِ همان نقشِ غیرفعال به طرف‌حسابِ دیگر رد می‌شود و طرف‌حساب هم ایجاد نمی‌شود (یک تراکنش)
        $res = $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ نقشِ غیرفعال', 'identifierNumber' => $this->uniqueDigits(11),
            'roleIds' => [$role],
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);
        $this->assertSame(0, DB::table('CrmParties')->where('OfficialName', 'شرکتِ نقشِ غیرفعال')->count());
    }

    public function test_party_roles_do_not_affect_contact_relation_roles_and_position(): void
    {
        $relRole = $this->role('نقشِ‌مخاطب');
        $relPosition = $this->position('سمتِ‌مخاطب');
        $partyRole = $this->role('نقشِ‌طرف‌حساب');
        $partyPosition = $this->position('سمتِ‌طرف‌حساب');

        $party = $this->saveParty(['roleIds' => [$partyRole], 'positionIds' => [$partyPosition]]);
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'firstName' => 'علی', 'lastName' => 'تستی'])->assertOk()->json();

        $relation = $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $party['partyId'], 'personId' => $person['personId'],
            'positionId' => $relPosition, 'roleIds' => [$relRole], 'isPrimaryContact' => true,
        ])->assertOk()->json();

        // رابطه فقط نقش/سمتِ خودش را دارد
        $relRoleIds = $this->as(self::USER_FULL)->getJson("/crm/relations/{$relation['relationId']}/roles")->json('roleIds');
        $this->assertSame([$relRole], array_map('intval', $relRoleIds));
        $relRow = collect($this->as(self::USER_FULL)->getJson("/crm/relations?partyId={$party['partyId']}")->json('items'))
            ->firstWhere('RelationID', $relation['relationId']);
        $this->assertStringContainsString('سمتِ‌مخاطب', $relRow['PositionName']);

        // طرف‌حساب هم فقط نقش/سمتِ خودش را دارد
        $this->assertSame([$partyRole], $this->roleIdsOf($party['partyId']));
        $this->assertSame([$partyPosition], $this->positionIdsOf($party['partyId']));

        // ویرایشِ نقش‌هایِ طرف‌حساب، نقش‌هایِ رابطه را تغییر نمی‌دهد
        $identifier = DB::table('CrmParties')->where('PartyID', $party['partyId'])->value('IdentifierNumber');
        $this->saveParty(['partyId' => $party['partyId'], 'identifierNumber' => $identifier, 'roleIds' => [], 'positionIds' => []]);
        $relRoleIds = $this->as(self::USER_FULL)->getJson("/crm/relations/{$relation['relationId']}/roles")->json('roleIds');
        $this->assertSame([$relRole], array_map('intval', $relRoleIds));
    }

    public function test_roles_positions_endpoint_requires_view_permission(): void
    {
        $party = $this->saveParty();

        // کاربرِ بدونِ هیچ نقشی (داخلِ همین تراکنشِ تست؛ پس از تست Rollback می‌شود) — مستقل از دادهٔ واقعیِ نقش‌ها
        $code = 'QARP' . $this->uniqueDigits(5);
        DB::insert(
            'INSERT INTO dbo.Users (UserCode, UserName, PasswordHash, FirstName, LastName, IsActive, CreateDate, RowGuid, IsLocked, FailedLoginCount) '
            . "VALUES (?, ?, N'x', N'بی', N'دسترسی', 1, GETDATE(), NEWID(), 0, 0)",
            [$code, strtolower($code)]
        );
        $noPermUser = (int) DB::table('Users')->where('UserCode', $code)->value('UserID');

        $this->as($noPermUser)->getJson("/crm/parties/{$party['partyId']}/roles-positions")->assertStatus(403);
        $this->as($noPermUser)->postJson('/crm/parties', [
            'partyId' => $party['partyId'], 'partyNature' => 'LEGAL', 'officialName' => 'x', 'roleIds' => [],
        ])->assertStatus(403);
    }
}
