<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\ConditionFieldService;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * فیلدهایِ شرط (WorkflowConditionFields) — Global Registry (Phase 2): CRUD،
 * تولیدِ خودکارِ Code (ساده‌سازیِ UX)، Uniquenessِ سراسری، Immutabilityِ کاملِ Code
 * پس از ایجاد، Toggleِ بدونِ Guardِ وابستگی، و سازگاریِ Runtime با فیلدهایِ Inactive.
 *
 * ساده‌سازیِ UX (دورِ Code): Code دیگر ورودیِ مستقیمِ کاربر/کلاینت نیست — Service آن
 * را از displayName (یا latinName، وقتی displayName حرفِ لاتینِ کافی ندارد) می‌سازد و
 * برایِ ویرایش همیشه همان Codeِ موجود را حفظ می‌کند (نگاه کن به ConditionFieldService::save()).
 *
 * کاربران: 2 → شاملِ WORKFLOW_MANAGE_CONDITION_FIELDS تازه
 *          3 → بدونِ هیچ دسترسیِ WORKFLOW_
 */
class WorkflowConditionFieldTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;
    private const USER_MGR7 = 4;

    private WorkflowDefinitionService $defs;
    private ConditionFieldService $conditionFields;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->conditionFields = $this->app->make(ConditionFieldService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
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
            'latinName' => $this->uniqueCode('CF_DEF'), 'name' => 'فرایندِ تستِ فیلد', 'entityType' => 'TEST_ENTITY',
        ], self::USER_FULL);

        return (int) $def->DefinitionID;
    }

    /**
     * displayName عمداً فارسی است (رفتارِ واقعیِ اکثرِ کاربرانِ این سیستم) — پس هر
     * تستی که به یک Codeِ مشخص نیاز دارد باید latinName بدهد؛ چون uniqueCode() از
     * قبل با الگویِ Code سازگار است، Codeِ نهایی همیشه دقیقاً برابرِ همان latinName می‌شود.
     */
    private function basePayload(?string $latinName = null): array
    {
        return [
            'latinName'   => $latinName ?? $this->uniqueCode('AMOUNT'),
            'displayName' => 'مبلغ',
            'dataType'    => 'DECIMAL',
        ];
    }

    /* ============================ CRUD پایه — Global Registry ============================ */

    public function test_manage_condition_fields_permission_is_granted_to_admin_role(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $this->basePayload());
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertNotNull($res->json('fieldId'));
    }

    /** توضیحِ اختیاری (ساده‌سازیِ UX) — نبودش خطا نیست، بودش رفت‌وبرگشت می‌کند. */
    public function test_description_is_optional_and_round_trips(): void
    {
        $withDesc = $this->uniqueCode('DESC');
        $res = $this->conditionFields->save($this->basePayload($withDesc) + ['description' => 'توضیحِ کوتاه.'], self::USER_FULL);
        $this->assertTrue((bool) $res->Success);
        $row = collect($this->conditionFields->list(true))->firstWhere('Code', $withDesc);
        $this->assertSame('توضیحِ کوتاه.', $row->Description);

        $withoutDesc = $this->uniqueCode('NODESC');
        $this->conditionFields->save($this->basePayload($withoutDesc), self::USER_FULL);
        $row2 = collect($this->conditionFields->list(true))->firstWhere('Code', $withoutDesc);
        $this->assertNull($row2->Description);
    }

    public function test_get_global_condition_fields_requires_workflow_view(): void
    {
        $latinName = $this->uniqueCode('LIST');
        $this->conditionFields->save($this->basePayload($latinName), self::USER_FULL);

        $this->as(self::USER_NOPERM)->getJson('/workflow/condition-fields')->assertStatus(403);

        $res = $this->as(self::USER_FULL)->getJson('/workflow/condition-fields');
        $res->assertOk();
        $this->assertTrue(collect($res->json('items'))->contains(fn ($f) => $f['Code'] === $latinName));
    }

    public function test_store_condition_field_requires_manage_permission(): void
    {
        $this->as(self::USER_NOPERM)
            ->postJson('/workflow/condition-fields', $this->basePayload())
            ->assertStatus(403);
    }

    /* ============================ تولیدِ خودکارِ Code ============================ */

    /** وقتی خودِ عنوان حرفِ لاتینِ کافی دارد، Code مستقیماً از رویِ همان ساخته می‌شود — نیازی به latinName نیست. */
    public function test_code_is_generated_directly_from_latin_display_name(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', [
            'displayName' => 'Test Amount Field',
            // اگر displayName خودش قابلِ‌استفاده باشد، حتی اگر latinName هم فرستاده شود، نادیده گرفته می‌شود.
            'latinName'   => 'SHOULD_BE_IGNORED',
            'dataType'    => 'DECIMAL',
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertSame('TEST_AMOUNT_FIELD', $res->json('code'));
    }

    /** عنوانِ کاملاً فارسی بدونِ latinName باید با پیامِ روشن رد شود، نه با یک Codeِ خالی/نامعتبر. */
    public function test_fully_persian_display_name_without_latin_name_is_rejected(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', [
            'displayName' => 'مبلغِ درخواست', 'dataType' => 'DECIMAL',
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
        $this->assertStringContainsString('نامِ لاتین', $res->json('message'));
    }

    /** latinNameِ نامعتبر (فقط عدد — بعدِ پاک‌سازی خالی می‌ماند) باید با پیامِ روشن رد شود. */
    public function test_invalid_latin_name_is_rejected(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', [
            'displayName' => 'یک عنوانِ کاملاً فارسیِ دیگر', 'latinName' => '99999', 'dataType' => 'STRING',
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
    }

    /**
     * برخوردِ Codeِ تکراری: به‌جایِ خطا یا Codeِ تصادفی/بی‌معنا، یک پسوندِ عددیِ
     * قابلِ‌پیش‌بینی امتحان می‌شود (AMOUNT، سپس AMOUNT_2، ...).
     */
    public function test_duplicate_latin_name_gets_a_predictable_numeric_suffix(): void
    {
        $latinName = $this->uniqueCode('DUP');

        $first = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $this->basePayload($latinName));
        $first->assertOk();
        $this->assertSame($latinName, $first->json('code'));

        $second = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $this->basePayload($latinName));
        $second->assertOk();
        $this->assertSame($latinName . '_2', $second->json('code'));
    }

    public function test_select_field_requires_allowed_values(): void
    {
        $payload = $this->basePayload();
        $payload['dataType'] = 'SELECT';

        $res = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $payload);
        $res->assertStatus(422);

        $payload['allowedValues'] = ['A', 'B'];
        $res2 = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $payload);
        $res2->assertOk();
        $this->assertTrue($res2->json('success'));
    }

    /** SourceType/SourceKey جزئیاتِ فنیِ صرفِ Backendاند — هرگز از ورودیِ کلاینت گرفته نمی‌شوند. */
    public function test_source_type_and_source_key_are_always_system_controlled(): void
    {
        $latinName = $this->uniqueCode('SRCFIXED');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $this->basePayload($latinName) + [
            'sourceType' => 'ENTITY_FIELD', 'sourceKey' => 'whatever',
        ]);
        $res->assertOk();

        $row = collect($this->conditionFields->list(true))->firstWhere('Code', $latinName);
        $this->assertSame('START_CONTEXT', $row->SourceType);
        $this->assertSame($latinName, $row->SourceKey);
    }

    /** سازگاریِ عقب‌رو — مسیرِ قدیمیِ definition-scoped هنوز باید کار کند (Designer UIِ فعلی از همین استفاده می‌کند). */
    public function test_legacy_definition_scoped_route_still_works(): void
    {
        $definitionId = $this->createDefinition();
        $latinName = $this->uniqueCode('LEGACY');

        $storeRes = $this->as(self::USER_FULL)->postJson(
            "/workflow/definitions/{$definitionId}/condition-fields",
            $this->basePayload($latinName)
        );
        $storeRes->assertOk();
        $this->assertTrue($storeRes->json('success'));

        $listRes = $this->as(self::USER_FULL)->getJson("/workflow/definitions/{$definitionId}/condition-fields");
        $listRes->assertOk();
        $this->assertTrue(collect($listRes->json('items'))->contains(fn ($f) => $f['Code'] === $latinName));
    }

    /* ============================ Toggle ============================ */

    public function test_toggle_condition_field_active_requires_manage_permission(): void
    {
        $field = $this->conditionFields->save($this->basePayload(), self::USER_FULL);

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/condition-fields/{$field->FieldID}/toggle")
            ->assertStatus(403);
    }

    public function test_toggle_active_inactive_has_no_dependency_guard(): void
    {
        // بر خلافِ WorkflowCategories، غیرفعال‌کردنِ فیلد همیشه مجاز است — حتی اگر
        // در Draftِ دیگری استفاده شده باشد (طبقِ تصمیمِ صریحِ Final Design).
        $field = $this->conditionFields->save($this->basePayload(), self::USER_FULL);

        $res = $this->as(self::USER_FULL)->postJson("/workflow/condition-fields/{$field->FieldID}/toggle");
        $res->assertOk();
        $this->assertTrue($res->json('success'));

        $items = collect($this->conditionFields->list(includeInactive: true));
        $this->assertSame(0, (int) $items->firstWhere('FieldID', (int) $field->FieldID)->IsActive);

        // برگرداندن به فعال هم با همان Endpoint کار می‌کند
        $res2 = $this->as(self::USER_FULL)->postJson("/workflow/condition-fields/{$field->FieldID}/toggle");
        $res2->assertOk();
        $items2 = collect($this->conditionFields->list(includeInactive: true));
        $this->assertSame(1, (int) $items2->firstWhere('FieldID', (int) $field->FieldID)->IsActive);
    }

    /**
     * فیلدِ Inactive باید در Registry (با includeInactive=true) قابلِ‌مشاهده بماند،
     * اما از فهرستِ پیش‌فرض (برایِ انتخاب در Conditionِ جدید) کنار گذاشته شود.
     */
    public function test_inactive_field_is_visible_with_includeInactive_but_excluded_from_default_listing(): void
    {
        $field = $this->conditionFields->save($this->basePayload(), self::USER_FULL);
        $this->conditionFields->toggleActive((int) $field->FieldID, self::USER_FULL);

        $default = collect($this->conditionFields->list());
        $this->assertNull($default->firstWhere('FieldID', (int) $field->FieldID), 'فیلدِ Inactive نباید در فهرستِ پیش‌فرض (برایِ Conditionِ جدید) باشد.');

        $all = collect($this->conditionFields->list(includeInactive: true));
        $this->assertNotNull($all->firstWhere('FieldID', (int) $field->FieldID), 'فیلدِ Inactive باید در Registry با includeInactive=true دیده شود.');
    }

    /* ============================ Immutabilityِ کاملِ Code ============================ */

    public function test_display_name_can_change_freely(): void
    {
        $field = $this->conditionFields->save($this->basePayload(), self::USER_FULL);

        $payload = $this->basePayload();
        $payload['fieldId'] = (int) $field->FieldID;
        $payload['displayName'] = 'نامِ جدید';

        $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $payload)->assertOk();
    }

    /**
     * قلبِ ساده‌سازیِ UX: Code پس از ایجاد هرگز تغییر نمی‌کند — چه فیلد در هیچ Ruleای
     * استفاده نشده باشد، چه در یک Definition/Version استفاده شده باشد. Service همیشه
     * Codeِ موجود را از DB می‌خواند و هر latinName/displayNameِ متفاوتی که کلاینت
     * بفرستد را برایِ Code نادیده می‌گیرد — نه خطا، فقط بی‌اثر برایِ Code (DisplayName
     * همچنان آزادانه به‌روزرسانی می‌شود). Guardِ Immutabilityِ SPِ قدیمی دست‌نخورده
     * مانده (لایهٔ دومِ دفاعی)، اما دیگر هرگز از طریقِ این Service فعال نمی‌شود.
     */
    public function test_code_never_changes_via_edit_regardless_of_rule_usage(): void
    {
        $definitionId = $this->createDefinition();
        $originalLatinName = $this->uniqueCode('LOCKEDCF');
        $field = $this->conditionFields->save($this->basePayload($originalLatinName), self::USER_FULL);

        $version = $this->defs->createDraft($definitionId, self::USER_FULL);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph($originalLatinName), self::USER_FULL);

        $payload = $this->basePayload('TOTALLY_DIFFERENT_LATIN_NAME');
        $payload['fieldId'] = (int) $field->FieldID;
        $payload['displayName'] = 'نامِ نمایشیِ جدید';

        $res = $this->as(self::USER_FULL)->postJson('/workflow/condition-fields', $payload);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertSame($originalLatinName, $res->json('code'));

        $stored = collect($this->conditionFields->list(true))->firstWhere('FieldID', (int) $field->FieldID);
        $this->assertSame($originalLatinName, $stored->Code);
        $this->assertSame('نامِ نمایشیِ جدید', $stored->DisplayName);
    }

    /* ============================ Runtime compatibility — فیلدِ Inactive ============================ */

    /**
     * قاعدهٔ کلیدی: غیرفعال‌کردنِ فیلدی که یک Versionِ منتشرشده از قبل در RuleJsonِ
     * خودش به آن ارجاع می‌دهد، نباید اجرایِ (Start) آن Version را خراب کند — فقط
     * ساختِ Ruleِ جدید با آن فیلد باید مسدود شود.
     */
    public function test_running_a_published_version_still_works_after_its_field_becomes_inactive(): void
    {
        $definitionId = $this->createDefinition();
        $latinName = $this->uniqueCode('RTOK');
        $field = $this->conditionFields->save($this->basePayload($latinName), self::USER_FULL);

        $version = $this->defs->createDraft($definitionId, self::USER_FULL);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph($latinName), self::USER_FULL);
        $this->defs->publish((int) $version->VersionID, self::USER_FULL);

        // اکنون فیلد را غیرفعال می‌کنیم — Versionِ منتشرشده از قبل به آن ارجاع می‌دهد
        $this->conditionFields->toggleActive((int) $field->FieldID, self::USER_FULL);

        $meta = $this->defs->getGraph((int) $version->VersionID)['meta'];

        // SourceKey همیشه برابرِ Code است (ساده‌سازیِ UX) — Contextِ ورودی هم باید با همان کلید باشد.
        $result = $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY',
            entityId: random_int(90000, 99999),
            startedByUserId: self::USER_FULL,
            definitionCode: $meta->DefinitionCode,
            context: [$latinName => '500'],
        ));

        $this->assertSame('COMPLETED', $result->instanceStatus);
        $this->assertSame('END_A', $result->enteredStepCode, 'Ruleِ فیلدِ Inactiveِ ارجاع‌شده در Version منتشرشده باید همچنان درست ارزیابی شود.');
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
