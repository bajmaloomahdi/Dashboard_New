<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تبِ «مالکِ پروژه»: طرف‌حساب (اختیاری؛ خالی = پروژهٔ داخلی) + برند. گزینه‌هایِ برند بدونِ طرف‌حساب
 * همهٔ برندهایِ فعال‌اند و با طرف‌حساب فقط برندهایِ همان طرف‌حساب (ساختارِ موجودِ CRM).
 */
class ProjectOwnerTest extends TestCase
{
    use DatabaseTransactions;

    private const RESPONSIBLE = 2;
    private const PLAIN_MEMBER = 3;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function uniq(string $prefix): string
    {
        return $prefix . ' ' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function createProject(): int
    {
        $title = $this->uniq('پروژهٔ تستِ مالک');
        $this->as(self::RESPONSIBLE)->post('/projects', [
            'ProjectTitle' => $title, 'ProjectStatusID' => 1, 'ResponsibleUserID' => self::RESPONSIBLE,
        ])->assertRedirect();
        $projectId = (int) DB::selectOne('SELECT TOP 1 ProjectID FROM dbo.Projects WHERE ProjectTitle = ? ORDER BY ProjectID DESC', [$title])->ProjectID;

        $this->as(self::RESPONSIBLE)->postJson("/projects/{$projectId}/members", ['UserID' => self::PLAIN_MEMBER, 'IsResponsible' => false])->assertOk();

        return $projectId;
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }

        return (int) $this->as(self::RESPONSIBLE)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => $this->uniq('طرف‌حسابِ مالک'), 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function brand(): int
    {
        return (int) $this->as(self::RESPONSIBLE)->postJson('/crm/brands', ['name' => $this->uniq('برندِ مالک')])->assertOk()->json('brandId');
    }

    /** تعریفِ برند برایِ طرف‌حساب از همان مسیرِ موجودِ CRM (ردیفِ فعالِ CrmPartyBrandCategories) */
    private function linkBrand(int $partyId, int $brandId): void
    {
        $categoryId = (int) $this->as(self::RESPONSIBLE)->postJson('/crm/product-categories', ['displayName' => $this->uniq('دستهٔ مالک')])
            ->assertOk()->json('productCategoryId');
        $this->as(self::RESPONSIBLE)->postJson('/crm/party-brand-categories', [
            'partyId' => $partyId, 'brandId' => $brandId, 'productCategoryId' => $categoryId,
            'entryDate' => '2025-01-01', 'exitDate' => null, 'sharePercent' => 10,
        ])->assertOk()->assertJson(['success' => true]);
    }

    private function props(int $projectId): array
    {
        return $this->as(self::RESPONSIBLE)->get("/projects/{$projectId}")->assertOk()->viewData('page')['props'];
    }

    private function saveOwner(int $projectId, ?int $partyId, ?int $brandId, int $user = self::RESPONSIBLE)
    {
        return $this->as($user)->postJson("/projects/{$projectId}/owner", ['OwnerPartyID' => $partyId, 'OwnerBrandID' => $brandId]);
    }

    private function partyBrandIds(int $projectId, int $partyId, int $user = self::RESPONSIBLE)
    {
        return $this->as($user)->getJson("/projects/{$projectId}/owner/party-brands?partyId={$partyId}");
    }

    public function test_new_project_has_no_owner_and_all_active_brands_are_offered(): void
    {
        $projectId = $this->createProject();
        $brandId = $this->brand();

        $props = $this->props($projectId);
        $this->assertNull($props['owner']->OwnerPartyID);
        $this->assertNull($props['owner']->OwnerBrandID);
        $this->assertContains($brandId, array_column($props['allBrands'], 'BrandID'));
        $this->assertArrayNotHasKey('LogoPath', $props['allBrands'][0], 'مسیرِ فایلِ لوگو نباید به Frontend برود.');
    }

    public function test_internal_project_saves_brand_without_party(): void
    {
        $projectId = $this->createProject();
        $brandId = $this->brand();

        $res = $this->saveOwner($projectId, null, $brandId);
        $res->assertOk()->assertJson(['success' => true]);
        $this->assertNull($res->json('owner.OwnerPartyID'));
        $this->assertEquals($brandId, (int) $res->json('owner.OwnerBrandID'));

        $row = DB::selectOne('SELECT OwnerPartyID, OwnerBrandID FROM dbo.Projects WHERE ProjectID = ?', [$projectId]);
        $this->assertNull($row->OwnerPartyID);
        $this->assertEquals($brandId, (int) $row->OwnerBrandID);
    }

    public function test_external_project_saves_party_and_its_brand(): void
    {
        $projectId = $this->createProject();
        $partyId = $this->party();
        $brandId = $this->brand();
        $this->linkBrand($partyId, $brandId);

        $this->saveOwner($projectId, $partyId, $brandId)->assertOk()->assertJson(['success' => true]);

        $owner = $this->props($projectId)['owner'];
        $this->assertEquals($partyId, (int) $owner->OwnerPartyID);
        $this->assertEquals($brandId, (int) $owner->OwnerBrandID);
        $this->assertNotEmpty($owner->OwnerPartyName);
        $this->assertNotEmpty($owner->OwnerBrandName);
    }

    public function test_party_brands_endpoint_returns_only_that_partys_brands(): void
    {
        $projectId = $this->createProject();
        $partyA = $this->party();
        $partyWithoutBrands = $this->party();
        $brandOfA = $this->brand();
        $otherBrand = $this->brand();
        $this->linkBrand($partyA, $brandOfA);

        $ids = array_column($this->partyBrandIds($projectId, $partyA)->assertOk()->json('brands'), 'BrandID');
        $this->assertSame([$brandOfA], array_map('intval', $ids));
        $this->assertNotContains($otherBrand, $ids);

        $this->assertSame([], $this->partyBrandIds($projectId, $partyWithoutBrands)->assertOk()->json('brands'));
    }

    public function test_owner_can_be_changed_and_cleared(): void
    {
        $projectId = $this->createProject();
        $partyId = $this->party();
        $brandId = $this->brand();
        $this->linkBrand($partyId, $brandId);
        $this->saveOwner($projectId, $partyId, $brandId)->assertOk();

        $this->saveOwner($projectId, null, null)->assertOk()->assertJson(['success' => true]);

        $row = DB::selectOne('SELECT OwnerPartyID, OwnerBrandID FROM dbo.Projects WHERE ProjectID = ?', [$projectId]);
        $this->assertNull($row->OwnerPartyID);
        $this->assertNull($row->OwnerBrandID);
    }

    public function test_only_project_responsible_can_set_owner_or_load_party_brands(): void
    {
        $projectId = $this->createProject();
        $partyId = $this->party();
        $brandId = $this->brand();

        $this->saveOwner($projectId, null, $brandId, self::PLAIN_MEMBER)->assertStatus(403);
        $this->partyBrandIds($projectId, $partyId, self::PLAIN_MEMBER)->assertStatus(403);

        $this->assertNull(DB::selectOne('SELECT OwnerBrandID FROM dbo.Projects WHERE ProjectID = ?', [$projectId])->OwnerBrandID);
    }

    public function test_unknown_party_or_brand_is_rejected(): void
    {
        $projectId = $this->createProject();

        $this->saveOwner($projectId, 999999999, null)->assertStatus(422);
        $this->saveOwner($projectId, null, 999999999)->assertStatus(422);
    }

    public function test_setting_owner_does_not_change_other_project_data(): void
    {
        $projectId = $this->createProject();
        $brandId = $this->brand();
        $before = DB::selectOne('SELECT ProjectTitle, ProjectStatusID, ProgressPercent, IsActive FROM dbo.Projects WHERE ProjectID = ?', [$projectId]);
        $membersBefore = count(DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$projectId]));

        $this->saveOwner($projectId, null, $brandId)->assertOk();

        $after = DB::selectOne('SELECT ProjectTitle, ProjectStatusID, ProgressPercent, IsActive FROM dbo.Projects WHERE ProjectID = ?', [$projectId]);
        $this->assertEquals($before, $after);
        $this->assertSame($membersBefore, count(DB::select('EXEC sp_GetProjectMembers @ProjectID = ?', [$projectId])));
    }
}
