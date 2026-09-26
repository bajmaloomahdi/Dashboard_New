<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * برند (CrmBrands) — موجودیتِ مستقلِ CRM با نامِ یکتا — و ارتباطِ چندبه‌چندِ آن با طرف‌حساب که
 * فقط از طریقِ «تعریفِ برند برایِ طرف‌حساب» (party-brand-categories) ساخته می‌شود؛ شمارش‌ها از ردیف‌هایِ فعال.
 *
 * کاربران: 2 → RoleID=1 (مدیرِ سیستم)، شاملِ CRM_VIEW و CRM_MANAGE_PARTIES
 *          3 → بدونِ این دو Permission
 */
class CrmBrandTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function uniqueName(string $prefix = 'برندِ تست'): string
    {
        return $prefix . ' ' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function createBrand(?string $name = null): array
    {
        return $this->as(self::USER_FULL)->postJson('/crm/brands', [
            'name' => $name ?? $this->uniqueName(),
        ])->assertOk()->json();
    }

    private function createParty(array $overrides = []): array
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $this->as(self::USER_FULL)->postJson('/crm/parties', array_merge([
            'partyNature' => 'LEGAL',
            'officialName' => 'شرکتِ تستیِ برند',
            'identifierNumber' => $digits,
        ], $overrides))->assertOk()->json();
    }

    private function activeBrandCount(int $partyId): int
    {
        $parties = $this->as(self::USER_FULL)->getJson('/crm/parties-list')->assertOk()->json('items');

        return (int) collect($parties)->firstWhere('PartyID', (string) $partyId)['ActiveBrandCount'];
    }

    /* ==================================================================== */
    /*  CRUDِ برند                                                           */
    /* ==================================================================== */

    public function test_brand_can_be_created_listed_and_searched(): void
    {
        $name = $this->uniqueName();
        $created = $this->as(self::USER_FULL)->postJson('/crm/brands', ['name' => $name, 'description' => 'توضیحِ تست'])
            ->assertOk()->assertJson(['success' => true])->json();
        $this->assertIsInt($created['brandId']);

        $found = $this->as(self::USER_FULL)->getJson('/crm/brands?search=' . urlencode($name))->assertOk()->json('items');
        $this->assertCount(1, $found);
        $this->assertSame($name, $found[0]['Name']);
        $this->assertSame('توضیحِ تست', $found[0]['Description']);
        $this->assertSame(0, (int) $found[0]['ActivePartyCount']);
    }

    public function test_brand_edit_updates_in_place(): void
    {
        $brand = $this->createBrand();
        $newName = $this->uniqueName('برندِ ویرایش‌شده');

        $res = $this->as(self::USER_FULL)->postJson('/crm/brands', ['brandId' => $brand['brandId'], 'name' => $newName])->assertOk()->json();
        $this->assertSame($brand['brandId'], $res['brandId']);

        $found = $this->as(self::USER_FULL)->getJson('/crm/brands?search=' . urlencode($newName))->json('items');
        $this->assertCount(1, $found);
        $this->assertSame($brand['brandId'], (int) $found[0]['BrandID']);
    }

    public function test_brand_name_is_required(): void
    {
        $this->as(self::USER_FULL)->postJson('/crm/brands', ['name' => '   '])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_duplicate_brand_name_is_rejected_on_create_and_edit(): void
    {
        $name = $this->uniqueName();
        $this->createBrand($name);

        $this->as(self::USER_FULL)->postJson('/crm/brands', ['name' => $name])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->as(self::USER_FULL)->postJson('/crm/brands', ['name' => "  {$name}  "])
            ->assertStatus(422)->assertJson(['success' => false]);

        $other = $this->createBrand();
        $this->as(self::USER_FULL)->postJson('/crm/brands', ['brandId' => $other['brandId'], 'name' => $name])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_brand_toggle_and_status_filter(): void
    {
        $brand = $this->createBrand();

        $this->as(self::USER_FULL)->postJson("/crm/brands/{$brand['brandId']}/toggle")->assertOk()->assertJson(['success' => true]);

        $inactive = $this->as(self::USER_FULL)->getJson('/crm/brands?isActive=0')->json('items');
        $this->assertContains($brand['brandId'], collect($inactive)->pluck('BrandID')->map(fn ($v) => (int) $v)->all());
        $active = $this->as(self::USER_FULL)->getJson('/crm/brands?isActive=1')->json('items');
        $this->assertNotContains($brand['brandId'], collect($active)->pluck('BrandID')->map(fn ($v) => (int) $v)->all());
    }

    public function test_brand_pages_render(): void
    {
        $brand = $this->createBrand();

        $this->as(self::USER_FULL)->get('/crm/brands-page')->assertOk();
        $this->as(self::USER_FULL)->get("/crm/brands/{$brand['brandId']}")->assertOk();
        $this->as(self::USER_FULL)->get('/crm/brands/999999999')->assertNotFound();
    }

    /* ==================================================================== */
    /*  چندبه‌چند — فقط از طریقِ تعریفِ برند برایِ طرف‌حساب                  */
    /*  (قواعدِ کامل: tests/Feature/CrmPartyBrandDefinitionTest.php)          */
    /* ==================================================================== */

    private function defineBrand(int $partyId, int $brandId)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/party-brand-categories', ['partyId' => $partyId, 'brandId' => $brandId]);
    }

    public function test_brand_can_be_defined_for_many_parties_and_party_for_many_brands(): void
    {
        $nameA = $this->uniqueName();
        $brandA = $this->createBrand($nameA);
        $brandB = $this->createBrand();
        $party1 = $this->createParty();
        $party2 = $this->createParty();

        foreach ([[$party1, $brandA], [$party1, $brandB], [$party2, $brandA]] as [$party, $brand]) {
            $this->defineBrand($party['partyId'], $brand['brandId'])->assertOk()->assertJson(['success' => true]);
        }

        $this->assertSame(2, $this->activeBrandCount($party1['partyId']));
        $this->assertSame(1, $this->activeBrandCount($party2['partyId']));

        $brandAList = $this->as(self::USER_FULL)->getJson('/crm/brands?search=' . urlencode($nameA))->json('items');
        $this->assertSame(2, (int) $brandAList[0]['ActivePartyCount']);

        $this->as(self::USER_FULL)->get("/crm/brands/{$brandA['brandId']}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Brands/Show')->has('parties', 2));
    }

    public function test_inactive_brand_cannot_be_defined_for_a_party(): void
    {
        $brand = $this->createBrand();
        $party = $this->createParty();
        $this->as(self::USER_FULL)->postJson("/crm/brands/{$brand['brandId']}/toggle")->assertOk();

        $this->defineBrand($party['partyId'], $brand['brandId'])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_party_card_count_follows_active_rows(): void
    {
        $brand = $this->createBrand();
        $party = $this->createParty();
        $row = $this->defineBrand($party['partyId'], $brand['brandId'])->json('partyBrandCategoryId');
        $this->assertSame(1, $this->activeBrandCount($party['partyId']));

        $this->as(self::USER_FULL)->postJson("/crm/party-brand-categories/{$row}/toggle")->assertOk();
        $this->assertSame(0, $this->activeBrandCount($party['partyId']));
    }

    /* ==================================================================== */
    /*  Permission                                                           */
    /* ==================================================================== */

    public function test_brand_endpoints_require_permissions(): void
    {
        $brand = $this->createBrand();
        $party = $this->createParty();

        $this->as(self::USER_NOPERM)->get('/crm/brands-page')->assertStatus(403);
        $this->as(self::USER_NOPERM)->get("/crm/brands/{$brand['brandId']}")->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson('/crm/brands')->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson('/crm/brands', ['name' => $this->uniqueName()])->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson("/crm/brands/{$brand['brandId']}/toggle")->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson("/crm/party-brand-categories?partyId={$party['partyId']}")->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson('/crm/party-brand-categories', ['partyId' => $party['partyId'], 'brandId' => $brand['brandId']])->assertStatus(403);
    }
}
