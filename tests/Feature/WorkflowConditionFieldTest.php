<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * فیلدهایِ شرط (WorkflowConditionFields) — Definition-level: CRUD، Uniqueness،
 * Immutabilityِ Code پس از استفاده در یک Rule، و Toggleِ بدونِ Guardِ وابستگی.
 *
 * کاربران: 2 → شاملِ WORKFLOW_MANAGE_CONDITION_FIELDS تازه
 *          3 → بدونِ هیچ دسترسیِ WORKFLOW_
 */
class WorkflowConditionFieldTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private WorkflowDefinitionService $defs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
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

    private function createDefinition(): int
    {
        $def = $this->defs->save([
            'code' => $this->uniqueCode('CF_DEF'), 'name' => 'فرایندِ تستِ فیلد', 'entityType' => 'MESSAGE',
        ], self::USER_FULL);

        return (int) $def->DefinitionID;
    }

    private function basePayload(int $definitionId, ?string $code = null): array
    {
        return [
            'code'        => $code ?? $this->uniqueCode('AMOUNT'),
            'displayName' => 'مبلغ',
            'dataType'    => 'DECIMAL',
            'sourceType'  => 'START_CONTEXT',
            'sourceKey'   => 'amount',
        ];
    }

    /* ============================ CRUD پایه ============================ */

    public function test_manage_condition_fields_permission_is_granted_to_admin_role(): void
    {
        $definitionId = $this->createDefinition();

        $res = $this->as(self::USER_FULL)->postJson(
            "/workflow/definitions/{$definitionId}/condition-fields",
            $this->basePayload($definitionId)
        );
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertNotNull($res->json('fieldId'));
    }

    public function test_list_condition_fields_requires_workflow_view(): void
    {
        $definitionId = $this->createDefinition();
        $this->defs->saveConditionField($this->basePayload($definitionId) + ['definitionId' => $definitionId], self::USER_FULL);

        $this->as(self::USER_NOPERM)
            ->getJson("/workflow/definitions/{$definitionId}/condition-fields")
            ->assertStatus(403);

        $res = $this->as(self::USER_FULL)->getJson("/workflow/definitions/{$definitionId}/condition-fields");
        $res->assertOk();
        $this->assertCount(1, $res->json('items'));
    }

    public function test_store_condition_field_requires_manage_permission(): void
    {
        $definitionId = $this->createDefinition();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/definitions/{$definitionId}/condition-fields", $this->basePayload($definitionId))
            ->assertStatus(403);
    }

    public function test_store_condition_field_rejects_invalid_code_format(): void
    {
        $definitionId = $this->createDefinition();

        foreach (['amount', '1AMOUNT', 'AMOUNT-X', 'AM OUNT'] as $badCode) {
            $res = $this->as(self::USER_FULL)->postJson(
                "/workflow/definitions/{$definitionId}/condition-fields",
                $this->basePayload($definitionId, $badCode)
            );
            $res->assertStatus(422);
            $this->assertFalse($res->json('success'));
        }

        // رشتهٔ خالی از همان لایهٔ required بودنِ HTTP Validation رد می‌شود (شکلِ
        // پاسخِ متفاوتی دارد؛ همین 422 برایِ اثباتِ رد کافی است).
        $this->as(self::USER_FULL)->postJson(
            "/workflow/definitions/{$definitionId}/condition-fields",
            $this->basePayload($definitionId, '')
        )->assertStatus(422);
    }

    public function test_store_condition_field_rejects_duplicate_code_in_same_definition(): void
    {
        $definitionId = $this->createDefinition();
        $code = $this->uniqueCode('DUP');
        $this->defs->saveConditionField($this->basePayload($definitionId, $code) + ['definitionId' => $definitionId], self::USER_FULL);

        $res = $this->as(self::USER_FULL)->postJson(
            "/workflow/definitions/{$definitionId}/condition-fields",
            $this->basePayload($definitionId, $code)
        );
        $res->assertStatus(422);
        $this->assertStringContainsString('قبلاً استفاده شده', $res->json('message'));
    }

    public function test_same_code_is_allowed_across_different_definitions(): void
    {
        $defA = $this->createDefinition();
        $defB = $this->createDefinition();
        $code = $this->uniqueCode('SHARED');

        $this->defs->saveConditionField($this->basePayload($defA, $code) + ['definitionId' => $defA], self::USER_FULL);

        $res = $this->as(self::USER_FULL)->postJson(
            "/workflow/definitions/{$defB}/condition-fields",
            $this->basePayload($defB, $code)
        );
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_store_condition_field_rejects_unsupported_source_type(): void
    {
        $definitionId = $this->createDefinition();
        $payload = $this->basePayload($definitionId);
        $payload['sourceType'] = 'ENTITY_FIELD'; // فعلاً فقط START_CONTEXT در فاز ۱ مجاز است

        $res = $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/condition-fields", $payload);
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
    }

    public function test_select_field_requires_allowed_values(): void
    {
        $definitionId = $this->createDefinition();
        $payload = $this->basePayload($definitionId);
        $payload['dataType'] = 'SELECT';

        $res = $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/condition-fields", $payload);
        $res->assertStatus(422);

        $payload['allowedValues'] = ['A', 'B'];
        $res2 = $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/condition-fields", $payload);
        $res2->assertOk();
        $this->assertTrue($res2->json('success'));
    }

    /* ============================ Toggle ============================ */

    public function test_toggle_condition_field_active_requires_manage_permission(): void
    {
        $definitionId = $this->createDefinition();
        $field = $this->defs->saveConditionField($this->basePayload($definitionId) + ['definitionId' => $definitionId], self::USER_FULL);

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/condition-fields/{$field->FieldID}/toggle")
            ->assertStatus(403);
    }

    public function test_toggle_condition_field_active_has_no_dependency_guard(): void
    {
        // بر خلافِ WorkflowCategories، غیرفعال‌کردنِ فیلد همیشه مجاز است — حتی اگر
        // در Draftِ دیگری استفاده شده باشد (طبقِ تصمیمِ صریحِ Final Design).
        $definitionId = $this->createDefinition();
        $field = $this->defs->saveConditionField($this->basePayload($definitionId) + ['definitionId' => $definitionId], self::USER_FULL);

        $res = $this->as(self::USER_FULL)->postJson("/workflow/condition-fields/{$field->FieldID}/toggle");
        $res->assertOk();
        $this->assertTrue($res->json('success'));

        $items = collect($this->defs->listConditionFields($definitionId, includeInactive: true));
        $this->assertSame(0, (int) $items->firstWhere('FieldID', (int) $field->FieldID)->IsActive);
    }

    public function test_inactive_field_does_not_appear_in_default_listing(): void
    {
        $definitionId = $this->createDefinition();
        $field = $this->defs->saveConditionField($this->basePayload($definitionId) + ['definitionId' => $definitionId], self::USER_FULL);
        $this->defs->toggleConditionFieldActive((int) $field->FieldID, self::USER_FULL);

        $this->assertCount(0, $this->defs->listConditionFields($definitionId));
        $this->assertCount(1, $this->defs->listConditionFields($definitionId, includeInactive: true));
    }

    /* ============================ Immutabilityِ Code ============================ */

    public function test_display_name_can_change_freely_before_any_rule_usage(): void
    {
        $definitionId = $this->createDefinition();
        $field = $this->defs->saveConditionField($this->basePayload($definitionId) + ['definitionId' => $definitionId], self::USER_FULL);

        $payload = $this->basePayload($definitionId);
        $payload['fieldId'] = (int) $field->FieldID;
        $payload['displayName'] = 'نامِ جدید';

        $res = $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/condition-fields", $payload);
        $res->assertOk();
    }

    public function test_code_can_change_before_any_rule_uses_it(): void
    {
        $definitionId = $this->createDefinition();
        $oldCode = $this->uniqueCode('OLDC');
        $field = $this->defs->saveConditionField($this->basePayload($definitionId, $oldCode) + ['definitionId' => $definitionId], self::USER_FULL);

        $payload = $this->basePayload($definitionId, $this->uniqueCode('NEWC'));
        $payload['fieldId'] = (int) $field->FieldID;

        $res = $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/condition-fields", $payload);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_code_is_immutable_once_referenced_by_a_rule(): void
    {
        $definitionId = $this->createDefinition();
        $code = $this->uniqueCode('LOCKED');
        $field = $this->defs->saveConditionField($this->basePayload($definitionId, $code) + ['definitionId' => $definitionId], self::USER_FULL);

        // ساختِ یک Draft با گذاری که RuleJson اش به همین Code ارجاع می‌دهد (بدونِ Publish؛
        // Immutability از همان لحظهٔ Save در هر Draftی اعمال می‌شود، نه فقط بعدِ Publish).
        $version = $this->defs->createDraft($definitionId, self::USER_FULL);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph($code), self::USER_FULL);

        $payload = $this->basePayload($definitionId, $this->uniqueCode('TRY_RENAME'));
        $payload['fieldId'] = (int) $field->FieldID;

        $res = $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/condition-fields", $payload);
        $res->assertStatus(422);
        $this->assertStringContainsString('قابلِ‌تغییر نیست', $res->json('message'));

        // ولی DisplayName هنوز آزادانه قابلِ‌ویرایش است
        $payload2 = $this->basePayload($definitionId, $code);
        $payload2['fieldId'] = (int) $field->FieldID;
        $payload2['displayName'] = 'نامِ جدید بعدِ استفاده';
        $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/condition-fields", $payload2)->assertOk();
    }

    /** گرافِ START → COND → END_A/END_B با یک Ruleِ CONSTANT رویِ گذارِ COND→END_A. */
    private function conditionGraph(string $fieldCode): array
    {
        $rule = [
            'version' => 1, 'type' => 'GROUP', 'logic' => 'AND',
            'children' => [
                ['type' => 'CONDITION', 'field' => $fieldCode, 'operator' => 'GT', 'value' => ['kind' => 'CONSTANT', 'data' => '100']],
            ],
        ];

        return [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'END_A', 'name' => 'پایانِ الف', 'stepType' => 'END', 'sortOrder' => 2],
                ['code' => 'END_B', 'name' => 'پایانِ ب', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [],
            'assignments' => [],
            'transitions' => [
                ['code' => 'T_START', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T_RULE', 'fromStepCode' => 'COND', 'toStepCode' => 'END_A', 'priority' => 10, 'ruleJson' => $rule],
                ['code' => 'T_DEFAULT', 'fromStepCode' => 'COND', 'toStepCode' => 'END_B', 'priority' => 20, 'isDefault' => true],
            ],
        ];
    }
}
