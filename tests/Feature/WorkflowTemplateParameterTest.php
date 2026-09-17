<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\TemplateParameterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3 — Phase B: Registryِ پارامترهایِ Template (TemplateParameters).
 *
 * کاملاً مستقل از Condition Engine — این فایل هیچ‌جا WorkflowConditionFields،
 * ConditionContextBuilder یا ConditionDataTypeCaster را import/فراخوانی نمی‌کند؛
 * فقط از EntityTypeِ واقعیِ Phase A (`MESSAGE`) برایِ اثباتِ سازگاری با
 * WorkflowEntityTypes استفاده می‌شود.
 *
 * کاربران: 2 → هر ۱۲ دسترسیِ WORKFLOW_* (شاملِ WORKFLOW_MANAGE_TEMPLATE_PARAMETERS تازه)
 *          3 → بدونِ هیچ دسترسیِ WORKFLOW_
 */
class WorkflowTemplateParameterTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private TemplateParameterService $params;

    protected function setUp(): void
    {
        parent::setUp();
        $this->params = $this->app->make(TemplateParameterService::class);
    }

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function uniqueCode(string $prefix): string
    {
        return $prefix . '_' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => $this->uniqueCode('TP'),
            'caption' => 'پارامترِ تست',
            'groupCode' => 'FORM',
            'dataType' => 'STRING',
            'sourceType' => 'FORM',
            'sourceKey' => 'testField',
        ], $overrides);
    }

    /* ==================================================================== */
    /*  CRUD / List / Search / Filter                                        */
    /* ==================================================================== */

    public function test_manage_template_parameters_permission_is_granted_to_admin_role(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload());
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_create_and_list_template_parameter(): void
    {
        $code = $this->uniqueCode('CRUD');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code]))
            ->assertOk()->json();

        $this->assertTrue($created['success']);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?search=' . $code)->assertOk()->json('items');
        $row = collect($list)->firstWhere('TemplateParameterID', $created['templateParameterId']);
        $this->assertNotNull($row);
        $this->assertSame($code, $row['Code']);
    }

    public function test_filter_by_group_code(): void
    {
        $code = $this->uniqueCode('GROUPFILTER');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code, 'groupCode' => 'SYSTEM', 'sourceType' => 'SYSTEM', 'dataType' => 'DATE', 'sourceKey' => 'Now']))
            ->assertOk();

        $items = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?groupCode=SYSTEM')->json('items');
        $this->assertContains($code, collect($items)->pluck('Code')->all());

        $itemsOtherGroup = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?groupCode=USER')->json('items');
        $this->assertNotContains($code, collect($itemsOtherGroup)->pluck('Code')->all());
    }

    public function test_filter_by_is_active(): void
    {
        $code = $this->uniqueCode('ACTIVEFILTER');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code]))->json();
        $this->as(self::USER_FULL)->postJson("/workflow/template-parameters/{$created['templateParameterId']}/toggle")->assertOk();

        $activeOnly = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?isActive=1&search=' . $code)->json('items');
        $this->assertEmpty($activeOnly);

        $inactiveOnly = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?isActive=0&search=' . $code)->json('items');
        $this->assertNotEmpty($inactiveOnly);
    }

    public function test_update_existing_template_parameter(): void
    {
        $code = $this->uniqueCode('EDIT');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code, 'caption' => 'اولیه']))->json();

        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'templateParameterId' => $created['templateParameterId'], 'code' => $code, 'caption' => 'ویرایش‌شده',
        ]))->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?search=' . $code)->json('items');
        $this->assertSame('ویرایش‌شده', collect($list)->first()['Caption']);
    }

    public function test_toggle_active(): void
    {
        $code = $this->uniqueCode('TGL');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code]))->json();

        $this->as(self::USER_FULL)->postJson("/workflow/template-parameters/{$created['templateParameterId']}/toggle")
            ->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?search=' . $code)->json('items');
        $this->assertFalse((bool) collect($list)->first()['IsActive']);
    }

    public function test_get_by_code_exact_lookup(): void
    {
        $row = $this->params->getByCode('USER_FULL_NAME');
        $this->assertNotNull($row);
        $this->assertSame('USER_FULL_NAME', $row->Code);
        $this->assertSame('FullName', $row->SourceKey);

        $this->assertNull($this->params->getByCode('TOTALLY_UNKNOWN_CODE_XYZ'));
    }

    /* ==================================================================== */
    /*  Validation                                                           */
    /* ==================================================================== */

    public function test_duplicate_code_is_rejected(): void
    {
        $code = $this->uniqueCode('DUP');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code]))->assertOk();

        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code]))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_invalid_code_format_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => '1_STARTS_WITH_DIGIT']))
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => 'HAS SPACE']))
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => 'A']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_lowercase_code_is_uppercased(): void
    {
        $code = $this->uniqueCode('lower');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => strtolower($code)]))
            ->assertOk()->json();

        $stored = $this->params->getByCode(strtoupper($code));
        $this->assertNotNull($stored);
        $this->assertSame((int) $stored->TemplateParameterID, (int) $res['templateParameterId']);
    }

    public function test_invalid_group_code_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['groupCode' => 'NOT_A_GROUP']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_invalid_source_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['sourceType' => 'NOT_A_SOURCE']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_invalid_data_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['dataType' => 'BOOLEAN']))
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['dataType' => 'SELECT']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  EntityType — سازگاری با WorkflowEntityTypesِ فازِ A                     */
    /* ==================================================================== */

    public function test_valid_entity_type_from_registry_is_accepted(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['entityType' => 'MESSAGE']))
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_unknown_entity_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['entityType' => 'TOTALLY_UNKNOWN_ENTITY_XYZ']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_null_entity_type_means_global_and_is_accepted(): void
    {
        $code = $this->uniqueCode('GLOBAL');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['code' => $code]))
            ->assertOk()->json();
        $this->assertTrue($res['success']);

        $stored = $this->params->getByCode($code);
        $this->assertNull($stored->EntityType);
    }

    /* ==================================================================== */
    /*  Permission                                                           */
    /* ==================================================================== */

    public function test_store_without_manage_permission_is_403(): void
    {
        $this->as(self::USER_NOPERM)->postJson('/workflow/template-parameters', $this->validPayload())
            ->assertStatus(403);
    }

    public function test_toggle_without_manage_permission_is_403(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload())->json();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/template-parameters/{$created['templateParameterId']}/toggle")
            ->assertStatus(403);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/workflow/template-parameters')->assertStatus(401);
    }

    /* ==================================================================== */
    /*  Seedِ Phase B                                                        */
    /* ==================================================================== */

    public function test_seed_data_exists_with_expected_values(): void
    {
        $expected = [
            'USER_FULL_NAME' => ['USER', 'STRING', 'USER', 'FullName'],
            'USER_PERSONNEL_CODE' => ['USER', 'STRING', 'USER', 'UserCode'],
            'USER_POSITION_TITLE' => ['USER', 'STRING', 'USER', 'PositionName'],
            'USER_UNIT_NAME' => ['USER', 'STRING', 'USER', 'UnitName'],
            'TODAY' => ['SYSTEM', 'DATE', 'SYSTEM', 'Today'],
            'GENERIC_DESCRIPTION' => ['FORM', 'STRING', 'FORM', 'description'],
        ];

        foreach ($expected as $code => [$group, $dataType, $sourceType, $sourceKey]) {
            $row = $this->params->getByCode($code);
            $this->assertNotNull($row, "Seedِ {$code} یافت نشد.");
            $this->assertSame($group, $row->GroupCode, "{$code}: GroupCode");
            $this->assertSame($dataType, $row->DataType, "{$code}: DataType");
            $this->assertSame($sourceType, $row->SourceType, "{$code}: SourceType");
            $this->assertSame($sourceKey, $row->SourceKey, "{$code}: SourceKey");
            $this->assertNull($row->EntityType, "{$code}: باید سراسری (EntityType=NULL) باشد.");
            $this->assertTrue((bool) $row->IsActive, "{$code}: باید فعال باشد.");
        }
    }
}
