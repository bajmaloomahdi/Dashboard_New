<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\LetterTemplateService;
use App\Services\Workflow\TemplateRenderer;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 3 — Phase C: LetterTemplates + TemplateRenderer + Message-from-Template.
 *
 * کاملاً مستقل از Condition Engine و MasterParameters (گزارش‌سازی) — اثباتِ
 * خودکار در test_phase_c_files_have_zero_condition_engine_or_master_parameter_dependency().
 *
 * کاربران: 2 → همهٔ دسترسی‌هایِ WORKFLOW_* (شاملِ WORKFLOW_MANAGE_TEMPLATES)
 *          3 → بدونِ هیچ دسترسیِ WORKFLOW_
 *
 * EntityTypeهایِ واقعیِ Registry (Phase A) استفاده‌شده: MESSAGE و PROJECT —
 * برایِ اثباتِ سازگاری/ناسازگاریِ EntityType بینِ Template و Definition به یک
 * EntityTypeِ دومِ واقعی (PROJECT) نیاز است، نه یک EntityTypeِ فقط‌تستی.
 */
class WorkflowLetterTemplateTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private LetterTemplateService $templates;
    private TemplateRenderer $renderer;
    private WorkflowDefinitionService $defs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->templates = $this->app->make(LetterTemplateService::class);
        $this->renderer = $this->app->make(TemplateRenderer::class);
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => $this->uniqueCode('LT'),
            'name' => 'قالبِ تست',
            'entityType' => 'MESSAGE',
            'subjectTemplate' => 'موضوعِ تست برایِ {{USER_FULL_NAME}}',
            'bodyTemplate' => 'با سلام {{USER_FULL_NAME}}، امروز {{TODAY}} است.',
        ], $overrides);
    }

    /** ایجادِ یک WorkflowDefinition واقعی و منتشرشده با EntityType/Codeِ مشخص. */
    private function createDefinition(string $entityType, ?string $code = null): int
    {
        $code ??= $this->uniqueCode('WD');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'فرایندِ تست ' . $code, 'entityType' => $entityType], self::USER_FULL);
        $definitionId = (int) $def->DefinitionID;

        $ver = $this->defs->createDraft($definitionId, self::USER_FULL);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 1],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'END', 'isDefault' => true],
            ],
        ], self::USER_FULL);
        $this->defs->publish((int) $ver->VersionID, self::USER_FULL);

        return $definitionId;
    }

    /* ==================================================================== */
    /*  CRUD / List / Search / Filter                                       */
    /* ==================================================================== */

    public function test_manage_templates_permission_is_granted_to_admin_role(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload());
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_create_and_list_letter_template(): void
    {
        $code = $this->uniqueCode('CRUD');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $code]))
            ->assertOk()->json();

        $this->assertTrue($created['success']);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/templates?search=' . $code)->assertOk()->json('items');
        $row = collect($list)->firstWhere('LetterTemplateID', $created['letterTemplateId']);
        $this->assertNotNull($row);
        $this->assertSame($code, $row['Code']);
        $this->assertSame('MESSAGE', $row['EntityType']);
    }

    public function test_filter_by_entity_type(): void
    {
        $code = $this->uniqueCode('ENTFILTER');
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $code, 'entityType' => 'PROJECT']))
            ->assertOk();

        $items = $this->as(self::USER_FULL)->getJson('/workflow/templates?entityType=PROJECT')->json('items');
        $this->assertContains($code, collect($items)->pluck('Code')->all());

        $itemsOther = $this->as(self::USER_FULL)->getJson('/workflow/templates?entityType=MESSAGE')->json('items');
        $this->assertNotContains($code, collect($itemsOther)->pluck('Code')->all());
    }

    public function test_filter_by_is_active(): void
    {
        $code = $this->uniqueCode('ACTIVEFILTER');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $code]))->json();
        $this->as(self::USER_FULL)->postJson("/workflow/templates/{$created['letterTemplateId']}/toggle")->assertOk();

        $activeOnly = $this->as(self::USER_FULL)->getJson('/workflow/templates?isActive=1&search=' . $code)->json('items');
        $this->assertEmpty($activeOnly);

        $inactiveOnly = $this->as(self::USER_FULL)->getJson('/workflow/templates?isActive=0&search=' . $code)->json('items');
        $this->assertNotEmpty($inactiveOnly);
    }

    public function test_update_existing_letter_template(): void
    {
        $code = $this->uniqueCode('EDIT');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $code, 'name' => 'اولیه']))->json();

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'letterTemplateId' => $created['letterTemplateId'], 'code' => $code, 'name' => 'ویرایش‌شده',
        ]))->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/templates?search=' . $code)->json('items');
        $this->assertSame('ویرایش‌شده', collect($list)->first()['Name']);
    }

    public function test_toggle_active(): void
    {
        $code = $this->uniqueCode('TGL');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $code]))->json();

        $this->as(self::USER_FULL)->postJson("/workflow/templates/{$created['letterTemplateId']}/toggle")
            ->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/templates?search=' . $code)->json('items');
        $this->assertFalse((bool) collect($list)->first()['IsActive']);
    }

    /* ==================================================================== */
    /*  Validation — Code / EntityType                                      */
    /* ==================================================================== */

    public function test_duplicate_code_is_rejected(): void
    {
        $code = $this->uniqueCode('DUP');
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $code]))->assertOk();

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $code]))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_invalid_code_format_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => '1_STARTS_WITH_DIGIT']))
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => 'HAS SPACE']))
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => 'A']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_lowercase_code_is_uppercased(): void
    {
        $code = $this->uniqueCode('lower');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => strtolower($code)]))
            ->assertOk()->json();

        $stored = $this->templates->find((int) $res['letterTemplateId']);
        $this->assertNotNull($stored);
        $this->assertSame(strtoupper($code), $stored->Code);
    }

    public function test_entity_type_is_required(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['entityType' => '']))
            ->assertStatus(422);
    }

    public function test_unknown_entity_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['entityType' => 'TOTALLY_UNKNOWN_ENTITY_XYZ']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  Definition ↔ EntityType consistency                                 */
    /* ==================================================================== */

    public function test_template_with_matching_definition_entity_type_is_accepted(): void
    {
        $definitionId = $this->createDefinition('MESSAGE');

        $res = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'entityType' => 'MESSAGE', 'definitionId' => $definitionId,
        ]))->assertOk()->json();

        $this->assertTrue($res['success']);
        $stored = $this->templates->find((int) $res['letterTemplateId']);
        $this->assertSame($definitionId, (int) $stored->DefinitionID);
    }

    public function test_template_with_mismatched_definition_entity_type_is_rejected(): void
    {
        $definitionId = $this->createDefinition('PROJECT');

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'entityType' => 'MESSAGE', 'definitionId' => $definitionId,
        ]))->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_definition_for_nonexistent_id_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'entityType' => 'MESSAGE', 'definitionId' => 99999999,
        ]))->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_null_definition_id_means_generic_template_for_entity_type(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['entityType' => 'MESSAGE']))
            ->assertOk()->json();

        $stored = $this->templates->find((int) $res['letterTemplateId']);
        $this->assertNull($stored->DefinitionID);
    }

    /* ==================================================================== */
    /*  Token validation (unknown / inactive / EntityType-mismatched)       */
    /* ==================================================================== */

    public function test_unknown_token_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'subjectTemplate' => 'موضوع با {{TOTALLY_UNKNOWN_TOKEN_XYZ}}',
        ]))->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_inactive_token_parameter_is_rejected(): void
    {
        $paramCode = $this->uniqueCode('INACTIVE_TP');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'latinName' => $paramCode, 'caption' => 'پارامترِ غیرفعال', 'groupCode' => 'FORM',
            'dataType' => 'STRING', 'sourceType' => 'FORM', 'sourceKey' => 'someField',
        ])->json();
        $this->as(self::USER_FULL)->postJson("/workflow/template-parameters/{$created['templateParameterId']}/toggle")->assertOk();

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'bodyTemplate' => 'متن با {{' . $paramCode . '}}',
        ]))->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_entity_type_mismatched_token_is_rejected(): void
    {
        $paramCode = $this->uniqueCode('PROJECT_ONLY_TP');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'latinName' => $paramCode, 'caption' => 'مخصوصِ پروژه', 'groupCode' => 'FORM', 'entityType' => 'PROJECT',
            'dataType' => 'STRING', 'sourceType' => 'FORM', 'sourceKey' => 'projectField',
        ])->assertOk();

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'entityType' => 'MESSAGE',
            'bodyTemplate' => 'متن با {{' . $paramCode . '}}',
        ]))->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_global_form_token_works_across_entity_types(): void
    {
        $paramCode = $this->uniqueCode('GLOBAL_TP');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'latinName' => $paramCode, 'caption' => 'سراسری', 'groupCode' => 'FORM',
            'dataType' => 'STRING', 'sourceType' => 'FORM', 'sourceKey' => 'globalField',
        ])->assertOk();

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'entityType' => 'PROJECT',
            'bodyTemplate' => 'متن با {{' . $paramCode . '}}',
        ]))->assertOk()->assertJson(['success' => true]);
    }

    /* ==================================================================== */
    /*  forDefinition — اختصاصی + عمومیِ همان EntityType                      */
    /* ==================================================================== */

    public function test_for_definition_returns_specific_and_generic_templates_only(): void
    {
        $definitionId = $this->createDefinition('MESSAGE');
        $otherDefinitionId = $this->createDefinition('MESSAGE');

        $genericCode = $this->uniqueCode('GENERIC');
        $specificCode = $this->uniqueCode('SPECIFIC');
        $otherCode = $this->uniqueCode('OTHERDEF');

        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $genericCode]))->assertOk();
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $specificCode, 'definitionId' => $definitionId]))->assertOk();
        $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload(['code' => $otherCode, 'definitionId' => $otherDefinitionId]))->assertOk();

        $items = $this->as(self::USER_FULL)->getJson("/workflow/definitions/{$definitionId}/templates")->assertOk()->json('items');
        $codes = collect($items)->pluck('Code')->all();

        $this->assertContains($genericCode, $codes);
        $this->assertContains($specificCode, $codes);
        $this->assertNotContains($otherCode, $codes);
    }

    /* ==================================================================== */
    /*  TemplateRenderer — Resolveِ USER/SYSTEM/FORM + Cast + Missing        */
    /* ==================================================================== */

    public function test_render_resolves_user_system_and_form_parameters(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'subjectTemplate' => '{{USER_FULL_NAME}} - {{USER_PERSONNEL_CODE}}',
            'bodyTemplate' => '{{USER_POSITION_TITLE}} در {{USER_UNIT_NAME}}. امروز {{TODAY}}. توضیح: {{GENERIC_DESCRIPTION}}',
        ]))->json();

        $result = $this->renderer->render((int) $created['letterTemplateId'], ['description' => 'یادداشتِ تست'], self::USER_FULL);

        $userRow = DB::selectOne('SELECT FullName, UserCode FROM dbo.Users WHERE UserID = ?', [self::USER_FULL]);

        $this->assertStringContainsString($userRow->FullName, $result['subject']);
        $this->assertStringContainsString($userRow->UserCode, $result['subject']);
        $this->assertStringContainsString('یادداشتِ تست', $result['body']);
        $this->assertMatchesRegularExpression('/^\d{4}\/\d{2}\/\d{2}$/', $this->extractTodayToken($result['body']));
    }

    private function extractTodayToken(string $body): string
    {
        preg_match('/امروز (\d{4}\/\d{2}\/\d{2})\./u', $body, $m);

        return $m[1] ?? '';
    }

    public function test_no_raw_token_remains_in_rendered_output(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload())->json();

        $result = $this->renderer->render((int) $created['letterTemplateId'], [], self::USER_FULL);

        $this->assertStringNotContainsString('{{', $result['subject']);
        $this->assertStringNotContainsString('{{', $result['body']);
        $this->assertStringNotContainsString('}}', $result['subject']);
        $this->assertStringNotContainsString('}}', $result['body']);
    }

    public function test_missing_required_form_value_returns_422(): void
    {
        $paramCode = $this->uniqueCode('REQUIRED_TP');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'latinName' => $paramCode, 'caption' => 'الزامی', 'groupCode' => 'FORM',
            'dataType' => 'STRING', 'sourceType' => 'FORM', 'sourceKey' => 'requiredField',
        ])->assertOk();

        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'bodyTemplate' => 'متن با {{' . $paramCode . '}}',
        ]))->json();

        $this->as(self::USER_FULL)
            ->postJson("/workflow/templates/{$created['letterTemplateId']}/render", ['formValues' => []])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_render_of_inactive_template_returns_422(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload())->json();
        $this->as(self::USER_FULL)->postJson("/workflow/templates/{$created['letterTemplateId']}/toggle")->assertOk();

        $this->as(self::USER_FULL)
            ->postJson("/workflow/templates/{$created['letterTemplateId']}/render", ['formValues' => []])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_render_second_layer_defense_when_parameter_deactivated_after_save(): void
    {
        $paramCode = $this->uniqueCode('LATER_INACTIVE');
        $param = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'latinName' => $paramCode, 'caption' => 'بعداً غیرفعال', 'groupCode' => 'FORM',
            'dataType' => 'STRING', 'sourceType' => 'FORM', 'sourceKey' => 'laterField',
        ])->json();

        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'bodyTemplate' => 'متن با {{' . $paramCode . '}}',
        ]))->json();

        $this->as(self::USER_FULL)->postJson("/workflow/template-parameters/{$param['templateParameterId']}/toggle")->assertOk();

        $this->as(self::USER_FULL)
            ->postJson("/workflow/templates/{$created['letterTemplateId']}/render", ['formValues' => ['laterField' => 'x']])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  Message-from-Template — E2E                                         */
    /* ==================================================================== */

    public function test_message_from_template_creates_message_successfully(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'subjectTemplate' => 'ابلاغ برایِ {{USER_FULL_NAME}}',
            'bodyTemplate' => 'متنِ نامه برایِ {{USER_FULL_NAME}} در تاریخِ {{TODAY}}.',
        ]))->json();

        $res = $this->as(self::USER_FULL)->postJson('/messages/from-template', [
            'letterTemplateId' => $created['letterTemplateId'],
            'formValues' => [],
            'MessageTypeID' => 1,
            'msgPriorityID' => 1,
            'RecipientType' => 1,
            'RecipientUserIDs' => [self::USER_NOPERM],
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertNotEmpty($res['messageId']);

        $header = DB::selectOne('EXEC sp_GetMessageHeader @MessageID = ?', [$res['messageId']]);
        $this->assertNotNull($header);
        $userRow = DB::selectOne('SELECT FullName FROM dbo.Users WHERE UserID = ?', [self::USER_FULL]);
        $this->assertStringContainsString($userRow->FullName, $header->Subject);
        $this->assertStringNotContainsString('{{', $header->Subject);
        $this->assertStringNotContainsString('{{', $header->MessageText);
    }

    public function test_message_from_template_missing_form_value_returns_422_and_creates_no_message(): void
    {
        $paramCode = $this->uniqueCode('MSGREQUIRED');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'latinName' => $paramCode, 'caption' => 'الزامی برایِ پیام', 'groupCode' => 'FORM',
            'dataType' => 'STRING', 'sourceType' => 'FORM', 'sourceKey' => 'msgRequiredField',
        ])->assertOk();

        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload([
            'bodyTemplate' => 'متن با {{' . $paramCode . '}}',
        ]))->json();

        $countBefore = DB::selectOne('SELECT COUNT(*) AS C FROM dbo.Messages')->C;

        $this->as(self::USER_FULL)->postJson('/messages/from-template', [
            'letterTemplateId' => $created['letterTemplateId'],
            'formValues' => [],
            'MessageTypeID' => 1,
            'msgPriorityID' => 1,
            'RecipientType' => 1,
            'RecipientUserIDs' => [self::USER_NOPERM],
        ])->assertStatus(422)->assertJson(['success' => false]);

        $countAfter = DB::selectOne('SELECT COUNT(*) AS C FROM dbo.Messages')->C;
        $this->assertSame($countBefore, $countAfter);
    }

    public function test_message_from_template_requires_authentication(): void
    {
        $this->postJson('/messages/from-template', [
            'letterTemplateId' => 1,
            'MessageTypeID' => 1,
            'msgPriorityID' => 1,
            'RecipientType' => 1,
        ])->assertStatus(401);
    }

    /* ==================================================================== */
    /*  Permission                                                           */
    /* ==================================================================== */

    public function test_templates_store_without_manage_permission_is_403(): void
    {
        $this->as(self::USER_NOPERM)->postJson('/workflow/templates', $this->validPayload())
            ->assertStatus(403);
    }

    public function test_templates_toggle_without_manage_permission_is_403(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/templates', $this->validPayload())->json();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/templates/{$created['letterTemplateId']}/toggle")
            ->assertStatus(403);
    }

    public function test_templates_index_requires_authentication(): void
    {
        $this->getJson('/workflow/templates')->assertStatus(401);
    }

    /* ==================================================================== */
    /*  اثباتِ خودکارِ استقلال از Condition Engine و MasterParameters          */
    /* ==================================================================== */

    public function test_phase_c_files_have_zero_condition_engine_or_master_parameter_dependency(): void
    {
        // فقط وابستگیِ واقعیِ کد را می‌سنجیم (use/فراخوانیِ استاتیک/new)، نه توضیحِ
        // فارسیِ داخلِ کامنت‌ها که عمداً همین کلاس‌ها را به‌عنوانِ «استفاده‌نشده»
        // نام می‌برند (مثلاً در TemplateRenderer.php).
        $forbidden = [
            '/\buse\s+App\\\\Services\\\\Workflow\\\\(ConditionContextBuilder|WorkflowConditionFields)\b/',
            '/\buse\s+App\\\\Services\\\\Workflow\\\\Support\\\\ConditionDataTypeCaster\b/',
            '/\bConditionDataTypeCaster::/',
            '/\bConditionContextBuilder\s*\(/',
            '/\bnew\s+WorkflowConditionFields\b/',
            '/\bMasterParameterController\b/',
            '/EXEC\s+sp_GetMasterParameters/i',
        ];

        $files = [
            base_path('app/Services/Workflow/TemplateRenderer.php'),
            base_path('app/Services/Workflow/LetterTemplateService.php'),
            base_path('app/Http/Controllers/Workflow/WorkflowLetterTemplateController.php'),
            base_path('app/Http/Controllers/Process/ProcessLetterTemplateController.php'),
            base_path('app/Http/Controllers/MessageTemplateController.php'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $source = file_get_contents($file);

            foreach ($forbidden as $pattern) {
                $this->assertDoesNotMatchRegularExpression($pattern, $source, "{$file} نباید به Condition Engine/MasterParameters وابسته باشد ({$pattern}).");
            }
        }
    }
}
