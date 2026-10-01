<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «پیمانکارِ پروژه» (ProjectContractors) — رابطهٔ چندبه‌چندِ Projects↔CrmParties؛ پیمانکار
 * موجودیتِ مستقلی نیست، مشخصاتش مستقیماً از CrmParties خوانده می‌شود. دقیقاً هم‌الگو با
 * ProjectMembers (Soft Remove با IsActive، Reactivate به‌جایِ درجِ تکراری، مجوز فقط برایِ
 * مسئولِ فعالِ پروژه — نه یک Permission Codeِ سراسری).
 *
 * کاربران: 2 → «مهدی باج‌مالو» (مسئولِ پروژهٔ تست)، 3 → «علی باج‌مالو» (عضوِ عادی/غیرمسئول).
 * طرف‌حساب‌هایِ واقعیِ از‌پیش‌موجود: 766 («شرکت پخش مکتاف دی راد»)، 1154 («شرکتِ تستِ تعامل»).
 */
class ProjectContractorTest extends TestCase
{
    use DatabaseTransactions;

    private const RESPONSIBLE = 2;
    private const PLAIN_MEMBER = 3;
    private const PARTY_A = 766;
    private const PARTY_B = 1154;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    /** ساختِ یک پروژهٔ واقعی با sp_InsertProject (از طریقِ Endpointِ واقعیِ Store) — مسئول = RESPONSIBLE. */
    private function createProject(): int
    {
        $title = 'پروژهٔ تستِ پیمانکار ' . strtoupper(bin2hex(random_bytes(4)));

        $this->as(self::RESPONSIBLE)->post('/projects', [
            'ProjectTitle' => $title,
            'ProjectStatusID' => 1,
            'ResponsibleUserID' => self::RESPONSIBLE,
        ])->assertRedirect();

        $project = DB::selectOne('SELECT TOP 1 ProjectID FROM dbo.Projects WHERE ProjectTitle = ? ORDER BY ProjectID DESC', [$title]);

        // عضوِ عادیِ (غیرِمسئول) را هم اضافه می‌کنیم تا سناریویِ «کاربرِ مجاز بدونِ مسئولیت» قابلِ‌تست باشد.
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$project->ProjectID}/members", [
            'UserID' => self::PLAIN_MEMBER,
            'IsResponsible' => false,
        ])->assertOk();

        return (int) $project->ProjectID;
    }

    private function activeContractorPartyIds(int $projectId): array
    {
        $rows = DB::select('SELECT PartyID FROM dbo.ProjectContractors WHERE ProjectID = ? AND IsActive = 1 ORDER BY PartyID', [$projectId]);

        return array_map(fn ($r) => (int) $r->PartyID, $rows);
    }

    public function test_add_a_single_contractor(): void
    {
        $projectId = $this->createProject();

        $res = $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A]);
        $res->assertOk()->assertJson(['success' => true]);

        $this->assertSame([self::PARTY_A], $this->activeContractorPartyIds($projectId));
    }

    public function test_add_multiple_contractors(): void
    {
        $projectId = $this->createProject();

        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A])->assertOk();
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_B])->assertOk();

        $this->assertSame([self::PARTY_A, self::PARTY_B], $this->activeContractorPartyIds($projectId));
    }

    public function test_duplicate_contractor_is_prevented_reactivates_same_row(): void
    {
        $projectId = $this->createProject();

        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A])->assertOk();
        $res2 = $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A]);
        $res2->assertOk()->assertJson(['success' => true]);

        $count = DB::selectOne('SELECT COUNT(*) c FROM dbo.ProjectContractors WHERE ProjectID = ? AND PartyID = ?', [$projectId, self::PARTY_A]);
        $this->assertEquals(1, $count->c, 'نباید ردیفِ تکراری برایِ همان (ProjectID,PartyID) ساخته شود.');
        $this->assertSame([self::PARTY_A], $this->activeContractorPartyIds($projectId));
    }

    public function test_remove_contractor_keeps_the_relation_row_as_inactive(): void
    {
        $projectId = $this->createProject();
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A])->assertOk();

        $res = $this->as(self::RESPONSIBLE)->deleteJson("/projects/{$projectId}/contractors?PartyID=" . self::PARTY_A);
        $res->assertOk()->assertJson(['success' => true]);

        $this->assertSame([], $this->activeContractorPartyIds($projectId));

        $row = DB::selectOne('SELECT IsActive FROM dbo.ProjectContractors WHERE ProjectID = ? AND PartyID = ?', [$projectId, self::PARTY_A]);
        $this->assertNotNull($row, 'ردیفِ رابطه نباید فیزیکاً حذف شود (Soft Remove).');
        $this->assertEquals(0, $row->IsActive);
    }

    public function test_same_party_can_be_contractor_of_two_different_projects(): void
    {
        $projectA = $this->createProject();
        $projectB = $this->createProject();

        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectA}/contractors", ['PartyID' => self::PARTY_A])->assertOk();
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectB}/contractors", ['PartyID' => self::PARTY_A])->assertOk();

        $this->assertSame([self::PARTY_A], $this->activeContractorPartyIds($projectA));
        $this->assertSame([self::PARTY_A], $this->activeContractorPartyIds($projectB));
    }

    public function test_inactive_crm_party_stays_in_relation_history_and_is_flagged(): void
    {
        $projectId = $this->createProject();

        // یک طرف‌حسابِ تستیِ تازه می‌سازیم تا IsActive آن را بدونِ اثر روی دادهٔ واقعی تغییر دهیم.
        $partyCode = 'TESTPARTY_' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert(
            "INSERT INTO dbo.CrmParties (PartyNature, OfficialName, IdentifierNumber, IsActive, Date_InsertFirst, RowGuid)
             VALUES (N'LEGAL', ?, ?, 1, SYSDATETIME(), NEWID())",
            [$partyCode, '00000' . rand(10000, 99999)]
        );
        $party = DB::selectOne('SELECT PartyID FROM dbo.CrmParties WHERE OfficialName = ?', [$partyCode]);

        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => $party->PartyID])->assertOk();

        // غیرفعال‌کردنِ طرف‌حساب در CRM (مستقیم، شبیه‌سازیِ اقدامِ یک کاربرِ دیگر در CRM)
        DB::update('UPDATE dbo.CrmParties SET IsActive = 0 WHERE PartyID = ?', [$party->PartyID]);

        $list = DB::select('EXEC sp_GetProjectContractors @ProjectID = ?', [$projectId]);
        $row = collect($list)->firstWhere('PartyID', $party->PartyID);

        $this->assertNotNull($row, 'رابطه نباید با غیرفعال‌شدنِ طرف‌حساب حذف شود.');
        $this->assertEquals(1, $row->IsActive, 'رابطهٔ پروژه‌/پیمانکار همچنان فعال می‌ماند.');
        $this->assertEquals(0, $row->PartyIsActive, 'وضعیتِ غیرفعالِ خودِ طرف‌حساب باید در خروجی مشخص باشد.');
    }

    public function test_unauthorized_user_cannot_add_or_remove_contractor(): void
    {
        $projectId = $this->createProject();
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A])->assertOk();

        // کاربرِ ۳: عضوِ عادیِ پروژه است (نه مسئول) — نباید اجازهٔ افزودن/حذفِ پیمانکار داشته باشد.
        $this->as(self::PLAIN_MEMBER)
            ->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_B])
            ->assertStatus(403);

        $this->as(self::PLAIN_MEMBER)
            ->deleteJson("/projects/{$projectId}/contractors?PartyID=" . self::PARTY_A)
            ->assertStatus(403);

        // وضعیت دست‌نخورده مانده
        $this->assertSame([self::PARTY_A], $this->activeContractorPartyIds($projectId));
    }

    public function test_user_without_crm_view_permission_can_still_use_project_contractor_feature(): void
    {
        // کاربرِ تستیِ کاملاً ایزوله و بدونِ هیچ Role/Permissionی (نه حتی CRM_VIEW) — تا این
        // تست به وضعیتِ واقعیِ (و ممکن است تغییرکنندهٔ) Roleهایِ کاربرانِ از‌پیش‌موجود وابسته نباشد.
        $code = 'PCT_NOCRM_' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert(
            "INSERT INTO dbo.Users (UserCode, UserName, PasswordHash, FirstName, LastName, IsActive, CreateDate, RowGuid, IsLocked, FailedLoginCount)
             VALUES (?, ?, ?, N'بدونِ', N'دسترسیِ CRM', 1, GETDATE(), NEWID(), 0, 0)",
            [$code, $code, bcrypt('x')]
        );
        $testUser = DB::selectOne('SELECT UserID FROM dbo.Users WHERE UserCode = ?', [$code]);
        $noCrmUserId = (int) $testUser->UserID;

        // مسئولِ یک پروژهٔ دوم همین کاربرِ تازه‌ساخته است — تا بدونِ CRM_VIEW، فقط از طریقِ
        // مسیرِ محدودِ پروژه بتواند طرف‌حساب جست‌وجو/مدیریت کند.
        $this->as(self::RESPONSIBLE)->post('/projects', [
            'ProjectTitle' => 'پروژهٔ دومِ تست ' . strtoupper(bin2hex(random_bytes(4))),
            'ProjectStatusID' => 1,
            'ResponsibleUserID' => $noCrmUserId,
        ])->assertRedirect();
        $project2 = DB::selectOne('SELECT TOP 1 ProjectID FROM dbo.Projects ORDER BY ProjectID DESC');

        // تأییدِ اینکه این کاربر واقعاً CRM_VIEW ندارد (پیش‌شرطِ معنادارِ این تست)
        $perms = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$noCrmUserId]))->pluck('PermissionCode');
        $this->assertNotContains('CRM_VIEW', $perms->all());

        // مسیرِ محدودِ جست‌وجو — باید کار کند، چون فقط به canManage(همین پروژه) وابسته است
        $search = $this->as($noCrmUserId)->getJson("/projects/{$project2->ProjectID}/contractors/search?search=" . urlencode('شرکت'));
        $search->assertOk();
        $this->assertNotEmpty($search->json('parties'), 'جست‌وجو باید حداقل یک طرف‌حساب برگرداند.');

        // افزودن هم باید کار کند
        $add = $this->as($noCrmUserId)->postJson("/projects/{$project2->ProjectID}/contractors", ['PartyID' => self::PARTY_A]);
        $add->assertOk()->assertJson(['success' => true]);

        // ولی مسیرِ عمومیِ CRM همچنان باید برایِ همین کاربر ۴۰۳ بدهد — یعنی دسترسیِ کاملِ CRM
        // به او داده نشده، فقط همین قابلیتِ محدودِ پروژه کار می‌کند.
        $this->as($noCrmUserId)->getJson('/crm/parties')->assertStatus(403);
    }

    /* ==================== Stage D — ثبتِ تعامل از Project → Contractor ==================== */

    private function typeId(string $code): int
    {
        return (int) DB::selectOne('SELECT InteractionTypeID FROM dbo.CrmInteractionTypes WHERE Code = ?', [$code])->InteractionTypeID;
    }

    public function test_responsible_can_save_interaction_for_project_contractor(): void
    {
        $projectId = $this->createProject();
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A])->assertOk();

        $res = $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors/" . self::PARTY_A . '/interactions', [
            'interactionTypeId' => $this->typeId('CALL'),
            'subject' => 'تعاملِ ثبت‌شده از پروژه',
            'interactionDate' => '2026-10-01 10:00:00',
        ]);
        $res->assertOk()->assertJson(['success' => true, 'message' => 'تعامل با موفقیت ثبت شد.']);

        $row = DB::selectOne('SELECT PartyID, ProjectID, Subject FROM dbo.CrmInteractions WHERE InteractionID = ?', [$res->json('interactionId')]);
        $this->assertEquals(self::PARTY_A, (int) $row->PartyID);
        $this->assertEquals($projectId, (int) $row->ProjectID);
        $this->assertSame('تعاملِ ثبت‌شده از پروژه', $row->Subject);
    }

    public function test_project_interaction_rejected_when_party_not_a_contractor(): void
    {
        $projectId = $this->createProject();
        // PartyِB هرگز پیمانکارِ این Project نشده است

        $res = $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors/" . self::PARTY_B . '/interactions', [
            'interactionTypeId' => $this->typeId('CALL'),
            'subject' => 'نباید ثبت شود',
            'interactionDate' => '2026-10-01 10:00:00',
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);

        $this->assertNull(DB::selectOne('SELECT 1 AS x FROM dbo.CrmInteractions WHERE Subject = ?', ['نباید ثبت شود']));
    }

    public function test_project_interaction_rejected_when_contractor_relation_inactive(): void
    {
        $projectId = $this->createProject();
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A])->assertOk();
        $this->as(self::RESPONSIBLE)->deleteJson("/projects/{$projectId}/contractors?PartyID=" . self::PARTY_A)->assertOk();

        $res = $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors/" . self::PARTY_A . '/interactions', [
            'interactionTypeId' => $this->typeId('CALL'),
            'subject' => 'نباید ثبت شود — رابطه غیرفعال',
            'interactionDate' => '2026-10-01 10:00:00',
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_user_without_crm_manage_parties_can_use_project_interaction_route_via_canmanage(): void
    {
        // دقیقاً هم‌الگو با تستِ بالا (کاربرِ بدونِ هیچ Role/Permissionی) — اما این‌بار برایِ ثبتِ تعامل.
        $code = 'PCTI_' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert(
            "INSERT INTO dbo.Users (UserCode, UserName, PasswordHash, FirstName, LastName, IsActive, CreateDate, RowGuid, IsLocked, FailedLoginCount)
             VALUES (?, ?, ?, N'بدونِ', N'دسترسیِ CRM', 1, GETDATE(), NEWID(), 0, 0)",
            [$code, $code, bcrypt('x')]
        );
        $noCrmUserId = (int) DB::selectOne('SELECT UserID FROM dbo.Users WHERE UserCode = ?', [$code])->UserID;

        $this->as(self::RESPONSIBLE)->post('/projects', [
            'ProjectTitle' => 'پروژهٔ تستِ تعاملِ بدونِ CRM ' . strtoupper(bin2hex(random_bytes(4))),
            'ProjectStatusID' => 1,
            'ResponsibleUserID' => $noCrmUserId,
        ])->assertRedirect();
        $project = DB::selectOne('SELECT TOP 1 ProjectID FROM dbo.Projects ORDER BY ProjectID DESC');

        $perms = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$noCrmUserId]))->pluck('PermissionCode');
        $this->assertNotContains('CRM_MANAGE_PARTIES', $perms->all());

        $this->as($noCrmUserId)->postJson("/projects/{$project->ProjectID}/contractors", ['PartyID' => self::PARTY_A])->assertOk();

        $res = $this->as($noCrmUserId)->postJson("/projects/{$project->ProjectID}/contractors/" . self::PARTY_A . '/interactions', [
            'interactionTypeId' => $this->typeId('MEETING'),
            'subject' => 'تعاملِ کاربرِ بدونِ CRM_MANAGE_PARTIES',
            'interactionDate' => '2026-10-01 10:00:00',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        // ولی مسیرِ عمومیِ ثبتِ تعاملِ CRM همچنان برایِ همین کاربر بسته است
        $this->as($noCrmUserId)->postJson('/crm/interactions', [
            'partyId' => self::PARTY_A,
            'interactionTypeId' => $this->typeId('CALL'),
            'subject' => 'x',
            'interactionDate' => '2026-10-01 10:00:00',
        ])->assertStatus(403);
    }

    public function test_user_without_project_manage_access_cannot_save_project_interaction(): void
    {
        $projectId = $this->createProject();
        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/contractors", ['PartyID' => self::PARTY_A])->assertOk();

        // کاربرِ ۳: عضوِ عادیِ همین پروژه است (نه مسئول) — نباید اجازهٔ ثبتِ تعامل از این مسیر را داشته باشد.
        $this->as(self::PLAIN_MEMBER)->postJson("/projects/{$projectId}/contractors/" . self::PARTY_A . '/interactions', [
            'interactionTypeId' => $this->typeId('CALL'),
            'subject' => 'نباید ثبت شود',
            'interactionDate' => '2026-10-01 10:00:00',
        ])->assertStatus(403);

        $this->assertNull(DB::selectOne('SELECT 1 AS x FROM dbo.CrmInteractions WHERE Subject = ?', ['نباید ثبت شود']));
    }
}
