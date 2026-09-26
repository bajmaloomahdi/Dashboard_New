<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CRM Phase 1 — Master Data (۱۲ Registry): دپارتمان، نوع، فعالیت (زیرمجموعهٔ نوع)،
 * استان، شهرستان (زیرمجموعهٔ استان)، شهر (زیرمجموعهٔ شهرستان)، محله (زیرمجموعهٔ شهر)، منطقهٔ شهرداری، عنوانِ
 * فرد، سمت، نقش، نوعِ تماس، عنوانِ آدرس.
 *
 * همهٔ این‌ها از یک الگویِ مشترک (list/save/toggle) پیروی می‌کنند؛ به‌جایِ تکرارِ
 * تستِ کاملِ هر ۱۲ Registry، این فایل الگو را رویِ یک نمونهٔ مستقل (Department)
 * و یک نمونهٔ زیرمجموعه‌دار (PartyType→Activity) کامل می‌سنجد، به‌علاوهٔ Permission
 * و یک تستِ سطحیِ list برایِ هر Registریِ دیگر.
 *
 * کاربران: 2 → RoleID=1 (مدیرِ سیستم)، شاملِ CRM_MANAGE_MASTER_DATA
 *          3 → بدونِ CRM_MANAGE_MASTER_DATA
 */
class CrmMasterDataTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix . '_' . strtoupper(bin2hex(random_bytes(4)));
    }

    /* ==================================================================== */
    /*  Permission                                                           */
    /* ==================================================================== */

    public function test_manage_master_data_permission_is_granted_to_admin_role(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => 'بررسیِ دسترسی',
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_store_without_permission_is_403(): void
    {
        $this->as(self::USER_NOPERM)->postJson('/crm/classification/departments', [
            'displayName' => 'ن',
        ])->assertStatus(403);
    }

    public function test_toggle_without_permission_is_403(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => 'ن',
        ])->assertOk()->json();

        $this->as(self::USER_NOPERM)
            ->postJson("/crm/classification/departments/{$created['departmentId']}/toggle")
            ->assertStatus(403);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/crm/classification/departments')->assertStatus(401);
    }

    /* ==================================================================== */
    /*  دپارتمان — نمونهٔ کاملِ Registryِ مستقل                              */
    /* ==================================================================== */

    public function test_create_and_list_department(): void
    {
        $name = 'دپارتمانِ تستیِ ' . $this->uniqueCode('DEPT');

        $created = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => $name, 'sortOrder' => 5,
        ])->assertOk()->json();

        $this->assertTrue($created['success']);
        $this->assertIsInt($created['departmentId']);

        $list = $this->as(self::USER_FULL)->getJson('/crm/classification/departments?search=' . urlencode($name))
            ->assertOk()->json('items');

        $this->assertCount(1, $list);
        $this->assertSame($name, $list[0]['DisplayName']);
    }

    public function test_department_codes_are_auto_generated_sequentially_from_101(): void
    {
        // کد دیگر از کلاینت گرفته نمی‌شود؛ SP آن را طبقِ همان الگویِ
        // sp_InsertMenu/sp_InsertUser (MAX(عددی)+1، حداقلِ ۱۰۱) می‌سازد.
        $first = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => 'دپارتمانِ اول ' . $this->uniqueCode('SEQ'),
        ])->assertOk()->json();

        $second = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => 'دپارتمانِ دوم ' . $this->uniqueCode('SEQ'),
        ])->assertOk()->json();

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/classification/departments')->json('items'));
        $firstCode = (int) $list->firstWhere('DepartmentID', $first['departmentId'])['Code'];
        $secondCode = (int) $list->firstWhere('DepartmentID', $second['departmentId'])['Code'];

        $this->assertGreaterThanOrEqual(101, $firstCode);
        $this->assertGreaterThan($firstCode, $secondCode);
    }

    public function test_edit_department_changes_fields(): void
    {
        $originalName = 'نامِ اولیه ' . $this->uniqueCode('EDITDEPT');
        $newName = 'نامِ ویرایش‌شده ' . $this->uniqueCode('EDITDEPT');

        $created = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => $originalName,
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'departmentId' => $created['departmentId'], 'displayName' => $newName,
        ])->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/crm/classification/departments?search=' . urlencode($newName))->json('items');
        $this->assertSame($newName, collect($list)->first()['DisplayName']);
    }

    public function test_edit_department_does_not_change_its_code(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => 'کدِ ثابت ' . $this->uniqueCode('KEEPCODE'),
        ])->assertOk()->json();

        $before = collect($this->as(self::USER_FULL)->getJson('/crm/classification/departments')->json('items'))
            ->firstWhere('DepartmentID', $created['departmentId'])['Code'];

        $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'departmentId' => $created['departmentId'], 'displayName' => 'نامِ تغییریافته',
        ])->assertOk();

        $after = collect($this->as(self::USER_FULL)->getJson('/crm/classification/departments')->json('items'))
            ->firstWhere('DepartmentID', $created['departmentId'])['Code'];

        $this->assertSame($before, $after);
    }

    public function test_toggle_department_active_flips_state(): void
    {
        $name = 'تستِ تغییرِ وضعیت ' . $this->uniqueCode('TGLDEPT');

        $created = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => $name,
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/crm/classification/departments/{$created['departmentId']}/toggle")
            ->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/crm/classification/departments?search=' . urlencode($name))->json('items');
        $this->assertFalse((bool) collect($list)->first()['IsActive']);
    }

    public function test_department_missing_required_fields_is_422(): void
    {
        $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => '',
        ])->assertStatus(422);
    }

    /* ==================================================================== */
    /*  نوع → فعالیت — نمونهٔ کاملِ Registryِ زیرمجموعه‌دار                  */
    /* ==================================================================== */

    public function test_create_activity_under_party_type_and_filter_by_parent(): void
    {
        $type = $this->as(self::USER_FULL)->postJson('/crm/classification/party-types', [
            'displayName' => 'نوعِ تستی',
        ])->assertOk()->json();

        $activity = $this->as(self::USER_FULL)->postJson('/crm/classification/activities', [
            'partyTypeId' => $type['partyTypeId'], 'displayName' => 'فعالیتِ تستی',
        ])->assertOk()->json();

        $this->assertTrue($activity['success']);

        $list = $this->as(self::USER_FULL)
            ->getJson('/crm/classification/activities?partyTypeId=' . $type['partyTypeId'])
            ->assertOk()->json('items');

        $this->assertCount(1, $list);
        $this->assertSame('فعالیتِ تستی', $list[0]['DisplayName']);
        $this->assertSame('نوعِ تستی', $list[0]['PartyTypeName']);
    }

    public function test_activity_with_invalid_party_type_is_rejected(): void
    {
        // نامعتبر بودنِ partyTypeId قبل از رسیدن به SP، توسطِ Laravel Validation
        // (قانونِ exists:) رد می‌شود — قالبِ پاسخ استانداردِ Validation است، نه
        // {success:false} (که فقط برایِ خطاهایِ سطحِ Service/SP است).
        $res = $this->as(self::USER_FULL)->postJson('/crm/classification/activities', [
            'partyTypeId' => 999999, 'displayName' => 'ن',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors('partyTypeId');
    }

    public function test_toggle_party_type_is_blocked_when_active_activity_exists(): void
    {
        $type = $this->as(self::USER_FULL)->postJson('/crm/classification/party-types', [
            'displayName' => 'نوعِ دارایِ فعالیت',
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson('/crm/classification/activities', [
            'partyTypeId' => $type['partyTypeId'], 'displayName' => 'فعالیتِ فعال',
        ])->assertOk();

        // هم‌الگو با WorkflowCategoryTest::test_toggle_active_is_blocked_...: خطایِ
        // سطحِ SP از طریقِ CrmStore::write() به استثنا تبدیل و ۴۲۲ برمی‌گردد، نه ۲۰۰.
        $res = $this->as(self::USER_FULL)->postJson("/crm/classification/party-types/{$type['partyTypeId']}/toggle");

        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  استان → شهرستان → شهر → محله — سلسله‌مراتبِ جغرافیایی                */
    /* ==================================================================== */

    /** @return array{provinceId:int, countyId:int, cityId:int, neighborhoodId:int} */
    private function createGeoChain(): array
    {
        $province = $this->as(self::USER_FULL)->postJson('/crm/geography/provinces', [
            'displayName' => 'استانِ تستی',
        ])->assertOk()->json();

        $county = $this->as(self::USER_FULL)->postJson('/crm/geography/counties', [
            'provinceId' => $province['provinceId'], 'displayName' => 'شهرستانِ تستی',
        ])->assertOk()->json();

        $city = $this->as(self::USER_FULL)->postJson('/crm/geography/cities', [
            'countyId' => $county['countyId'], 'displayName' => 'شهرِ تستی',
        ])->assertOk()->json();

        $neighborhood = $this->as(self::USER_FULL)->postJson('/crm/geography/neighborhoods', [
            'cityId' => $city['cityId'], 'displayName' => 'محلهٔ تستی',
        ])->assertOk()->json();

        return [
            'provinceId' => $province['provinceId'], 'countyId' => $county['countyId'],
            'cityId' => $city['cityId'], 'neighborhoodId' => $neighborhood['neighborhoodId'],
        ];
    }

    public function test_geography_hierarchy_province_county_city_neighborhood(): void
    {
        $geo = $this->createGeoChain();

        $counties = $this->as(self::USER_FULL)->getJson('/crm/geography/counties?provinceId=' . $geo['provinceId'])->json('items');
        $this->assertCount(1, $counties);
        $this->assertSame('استانِ تستی', $counties[0]['ProvinceName']);

        $cities = $this->as(self::USER_FULL)->getJson('/crm/geography/cities?countyId=' . $geo['countyId'])->json('items');
        $this->assertCount(1, $cities);
        $this->assertSame('شهرستانِ تستی', $cities[0]['CountyName']);
        $this->assertSame('استانِ تستی', $cities[0]['ProvinceName']);

        $neighborhoods = $this->as(self::USER_FULL)->getJson('/crm/geography/neighborhoods?cityId=' . $geo['cityId'])->json('items');
        $this->assertCount(1, $neighborhoods);
        $this->assertSame('محلهٔ تستی', $neighborhoods[0]['DisplayName']);
        $this->assertSame('شهرِ تستی', $neighborhoods[0]['CityName']);
        $this->assertSame('شهرستانِ تستی', $neighborhoods[0]['CountyName']);
    }

    public function test_each_geography_level_requires_only_its_direct_parent(): void
    {
        $geo = $this->createGeoChain();

        // شهر با استان (بدونِ شهرستان) پذیرفته نمی‌شود — فقط والدِ مستقیم معتبر است
        $this->as(self::USER_FULL)->postJson('/crm/geography/cities', [
            'provinceId' => $geo['provinceId'], 'displayName' => 'شهرِ بی‌شهرستان',
        ])->assertStatus(422)->assertJsonValidationErrors('countyId');

        // شهرستان با شهر پذیرفته نمی‌شود؛ باید استان داشته باشد
        $this->as(self::USER_FULL)->postJson('/crm/geography/counties', [
            'cityId' => $geo['cityId'], 'displayName' => 'شهرستانِ بی‌استان',
        ])->assertStatus(422)->assertJsonValidationErrors('provinceId');

        // محله فقط شهر می‌خواهد
        $this->as(self::USER_FULL)->postJson('/crm/geography/neighborhoods', [
            'countyId' => $geo['countyId'], 'displayName' => 'محلهٔ بی‌شهر',
        ])->assertStatus(422)->assertJsonValidationErrors('cityId');
    }

    public function test_geography_parent_cannot_be_deactivated_while_it_has_active_children(): void
    {
        $geo = $this->createGeoChain();

        $this->as(self::USER_FULL)->postJson("/crm/geography/provinces/{$geo['provinceId']}/toggle")->assertStatus(422)->assertJson(['success' => false]);
        $this->as(self::USER_FULL)->postJson("/crm/geography/counties/{$geo['countyId']}/toggle")->assertStatus(422)->assertJson(['success' => false]);
        $this->as(self::USER_FULL)->postJson("/crm/geography/cities/{$geo['cityId']}/toggle")->assertStatus(422)->assertJson(['success' => false]);

        // از پایین به بالا غیرفعال‌سازی مجاز است
        $this->as(self::USER_FULL)->postJson("/crm/geography/neighborhoods/{$geo['neighborhoodId']}/toggle")->assertOk()->assertJson(['success' => true]);
        $this->as(self::USER_FULL)->postJson("/crm/geography/cities/{$geo['cityId']}/toggle")->assertOk()->assertJson(['success' => true]);
        $this->as(self::USER_FULL)->postJson("/crm/geography/counties/{$geo['countyId']}/toggle")->assertOk()->assertJson(['success' => true]);
        $this->as(self::USER_FULL)->postJson("/crm/geography/provinces/{$geo['provinceId']}/toggle")->assertOk()->assertJson(['success' => true]);
    }

    public function test_neighborhood_edit_updates_in_place(): void
    {
        $geo = $this->createGeoChain();

        $res = $this->as(self::USER_FULL)->postJson('/crm/geography/neighborhoods', [
            'neighborhoodId' => $geo['neighborhoodId'], 'cityId' => $geo['cityId'], 'displayName' => 'محلهٔ ویرایش‌شده',
        ])->assertOk()->json();
        $this->assertSame($geo['neighborhoodId'], $res['neighborhoodId']);

        $items = $this->as(self::USER_FULL)->getJson('/crm/geography/neighborhoods?cityId=' . $geo['cityId'])->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('محلهٔ ویرایش‌شده', $items[0]['DisplayName']);
    }

    public function test_neighborhood_endpoints_require_master_data_permission(): void
    {
        $this->as(self::USER_NOPERM)->getJson('/crm/geography/neighborhoods')->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson('/crm/geography/neighborhoods', ['cityId' => 1, 'displayName' => 'ن'])->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson('/crm/geography/neighborhoods/1/toggle')->assertStatus(403);
    }

    /* ==================================================================== */
    /*  فهرستِ سطحیِ باقیِ Registryها — فقط list/save/toggلِ پایه            */
    /* ==================================================================== */

    /** @return array<string, array{0:string}> */
    public static function simpleRegistryEndpoints(): array
    {
        return [
            'titles' => ['/crm/directory/titles'],
            'positions' => ['/crm/directory/positions'],
            'contact-roles' => ['/crm/directory/contact-roles'],
            'contact-types' => ['/crm/directory/contact-types'],
            'address-titles' => ['/crm/directory/address-titles'],
            'municipal-zones' => ['/crm/geography/municipal-zones'],
        ];
    }

    /** @dataProvider simpleRegistryEndpoints */
    public function test_simple_registry_create_list_toggle(string $endpoint): void
    {
        $name = 'ردیفِ تستیِ ' . $this->uniqueCode('SIMPLE');

        $created = $this->as(self::USER_FULL)->postJson($endpoint, [
            'displayName' => $name,
        ])->assertOk()->json();

        $this->assertTrue($created['success']);
        $idKey = collect($created)->keys()->first(fn ($k) => str_ends_with($k, 'Id') || str_ends_with($k, 'ID'));
        $this->assertNotNull($idKey, 'پاسخِ Save باید یک فیلدِ ID داشته باشد.');

        $list = $this->as(self::USER_FULL)->getJson($endpoint . '?search=' . urlencode($name))->assertOk()->json('items');
        $this->assertCount(1, $list);

        $toggleRes = $this->as(self::USER_FULL)->postJson("{$endpoint}/{$created[$idKey]}/toggle");
        $toggleRes->assertOk()->assertJson(['success' => true]);
    }
}
