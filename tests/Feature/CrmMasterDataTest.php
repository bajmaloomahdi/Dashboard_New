<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CRM Phase 1 — Master Data (۱۲ Registry): دپارتمان، نوع، فعالیت (زیرمجموعهٔ نوع)،
 * استان، شهر (زیرمجموعهٔ استان)، شهرستان (زیرمجموعهٔ شهر)، منطقهٔ شهرداری، عنوانِ
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
    /*  استان → شهر → شهرستان — سلسله‌مراتبِ جغرافیایی                       */
    /* ==================================================================== */

    public function test_geography_hierarchy_province_city_county(): void
    {
        $province = $this->as(self::USER_FULL)->postJson('/crm/geography/provinces', [
            'displayName' => 'استانِ تستی',
        ])->assertOk()->json();

        $city = $this->as(self::USER_FULL)->postJson('/crm/geography/cities', [
            'provinceId' => $province['provinceId'], 'displayName' => 'شهرِ تستی',
        ])->assertOk()->json();

        $county = $this->as(self::USER_FULL)->postJson('/crm/geography/counties', [
            'cityId' => $city['cityId'], 'displayName' => 'شهرستانِ تستی',
        ])->assertOk()->json();

        $this->assertTrue($county['success']);

        $cities = $this->as(self::USER_FULL)->getJson('/crm/geography/cities?provinceId=' . $province['provinceId'])->json('items');
        $this->assertSame('استانِ تستی', collect($cities)->first()['ProvinceName']);

        $counties = $this->as(self::USER_FULL)->getJson('/crm/geography/counties?cityId=' . $city['cityId'])->json('items');
        $this->assertSame('شهرِ تستی', collect($counties)->first()['CityName']);
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
