<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * تستِ لایهٔ API ماژولِ فرایند روی SQL Server واقعی.
 * هر تست در تراکنش اجرا و Rollback می‌شود (DatabaseTransactions).
 *
 * کاربران:  2  → دارای هر ۹ دسترسیِ WORKFLOW_*
 *           14 → دارای هر ۹ دسترسیِ WORKFLOW_*
 *           3  → بدونِ هیچ دسترسیِ WORKFLOW_ (نقشِ ۴ «کاربران اتوماسیون» بدونِ RolePermission)
 *
 * برای سناریوی جداسازیِ WORKFLOW_VIEW_ALL_TASKS، تست به‌صورتِ موقتی به نقشِ ۴ فقط
 * WORKFLOW_VIEW (PermissionID = 7) می‌دهد؛ این ردیف با Rollbackِ تست پاک می‌شود.
 */
class WorkflowApiTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER_FULL = 2;
    private const USER_FULL2 = 14;
    private const USER_NOPERM = 3;
    private const UNIT_MGR7 = 4;  // محسن — مدیرِ واحد ۷ (گیرندهٔ مجازِ وظیفهٔ عادی)

    private const PERM_WORKFLOW_VIEW = 7;
    private const ROLE_AUTOMATION = 4; // شاملِ کاربرانِ 3 و 4

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private WorkflowQueryService $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
        $this->query = $this->app->make(WorkflowQueryService::class);
    }

    /* ============================ کمکی‌ها ============================ */

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function grantAutomationPermission(int $permissionId): void
    {
        DB::table('RolePermissions')->insert([
            'RoleID'       => self::ROLE_AUTOMATION,
            'PermissionID' => $permissionId,
            'CanAccess'    => 1,
            'IsActive'     => 1,
        ]);
    }

    /** انتشارِ یک فرایندِ ساده START → REVIEW(APPROVAL) → END و بازگرداندنِ [definitionId, versionId, code] */
    private function publishSimpleFlow(
        int $reviewAssignee = self::USER_FULL,
        ?string $code = null,
        bool $allowForward = false,
        bool $allowDelegation = true
    ): array {
        $code ??= 'API_' . strtoupper(bin2hex(random_bytes(4)));

        $def = $this->defs->save(['code' => $code, 'name' => 'API ' . $code, 'entityType' => 'TEST_ENTITY'], self::USER_FULL);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::USER_FULL);
        $versionId = (int) $ver->VersionID;

        $this->defs->saveGraph($versionId, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'sortOrder' => 1,
                 'allowForward' => $allowForward, 'allowDelegation' => $allowDelegation],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
            ],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => $reviewAssignee],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::USER_FULL);

        $this->defs->publish($versionId, self::USER_FULL);

        return [$definitionId, $versionId, $code];
    }

    private function startInstance(string $code, int $entityId, int $startedBy = self::USER_FULL): int
    {
        return $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: $entityId, startedByUserId: $startedBy, definitionCode: $code,
        ))->instanceId;
    }

    /** MessageID پیامِ کارتابلیِ StepInstanceِ فعال. */
    private function openTaskId(int $instanceId): int
    {
        $open = array_values(array_filter(
            $this->query->instance($instanceId)['tasks'],
            fn ($t) => $t->Status === 'ACTIVE'
        ));

        return (int) $open[0]->MessageID;
    }

    /** فهرستِ MessageIDهای کارتابلِ دریافتیِ یک کاربر (مسیرِ موجودِ sp_GetMessages). */
    private function cartableMessageIds(int $userId): array
    {
        $rows = \Illuminate\Support\Facades\DB::select(
            'EXEC sp_GetMessages @UserID = ?, @Mode = 1, @IsArchive = 0, @SearchText = NULL, @MessageTypeID = NULL, @MessageStatusID = NULL, @FromDate = NULL, @ToDate = NULL, @msgPriorityID = NULL',
            [$userId]
        );

        return collect($rows)->pluck('MessageID')->map(fn ($v) => (int) $v)->all();
    }

    /* ==================================================================== */
    /*  Authentication / Authorization                                       */
    /* ==================================================================== */

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/workflow/definitions')->assertStatus(401);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $this->as(self::USER_NOPERM)
            ->getJson('/workflow/definitions')
            ->assertStatus(403);
    }

    public function test_user_with_permission_can_list_definitions(): void
    {
        $this->publishSimpleFlow();

        $this->as(self::USER_FULL)
            ->getJson('/workflow/definitions')
            ->assertOk()
            ->assertJson(['success' => true])
            ->assertJsonStructure(['success', 'items']);
    }

    public function test_publish_without_permission_is_403(): void
    {
        [, $versionId] = $this->buildDraft();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/versions/{$versionId}/publish")
            ->assertStatus(403);
    }

    public function test_publish_with_permission_succeeds(): void
    {
        [, $versionId] = $this->buildDraft(withEnd: true);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/versions/{$versionId}/publish")
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /* ==================================================================== */
    /*  Definition / Version                                                 */
    /* ==================================================================== */

    public function test_create_definition_and_draft_version(): void
    {
        $code = 'API_' . strtoupper(bin2hex(random_bytes(4)));

        $defRes = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => $code, 'name' => 'فرایندِ API', 'entityType' => 'TEST_ENTITY',
        ])->assertOk()->assertJson(['success' => true])->json();

        $definitionId = $defRes['definitionId'];

        $this->as(self::USER_FULL)
            ->postJson("/workflow/definitions/{$definitionId}/versions")
            ->assertOk()
            ->assertJsonStructure(['success', 'versionId', 'versionNo']);
    }

    public function test_create_definition_validation_error_is_422(): void
    {
        $this->as(self::USER_FULL)
            ->postJson('/workflow/definitions', ['name' => 'بدونِ کد'])
            ->assertStatus(422);
    }

    /* ---------- Update (Definition CRUD Gap Fix) ---------- */

    public function test_create_definition_still_works_without_definition_id(): void
    {
        // رگرسیون: مسیرِ ایجاد (بدونِ definitionId در بدنه) باید دقیقاً مثلِ قبل کار کند.
        $code = 'API_' . strtoupper(bin2hex(random_bytes(4)));

        $res = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => $code, 'name' => 'فرایندِ ایجادی', 'description' => 'توضیح', 'entityType' => 'TEST_ENTITY',
        ])->assertOk()->assertJson(['success' => true])->json();

        $definitionId = $res['definitionId'];

        $shown = $this->as(self::USER_FULL)->getJson("/workflow/definitions/{$definitionId}")->assertOk()->json();
        $this->assertSame($code, $shown['definition']['Code']);
        $this->assertSame('فرایندِ ایجادی', $shown['definition']['Name']);
        $this->assertTrue((bool) $shown['definition']['IsActive']);
    }

    public function test_update_definition_via_store_changes_fields(): void
    {
        $code = 'API_' . strtoupper(bin2hex(random_bytes(4)));
        $created = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => $code, 'name' => 'نامِ اولیه', 'description' => 'توضیحِ اولیه', 'entityType' => 'TEST_ENTITY', 'isActive' => true,
        ])->assertOk()->json();
        $definitionId = $created['definitionId'];

        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'definitionId' => $definitionId,
            'code'         => $code, // کد ثابت می‌ماند
            'name'         => 'نامِ ویرایش‌شده',
            'description'  => 'توضیحِ ویرایش‌شده',
            'entityType'   => 'PROJECT',
            'isActive'     => false,
        ])->assertOk()->assertJson(['success' => true, 'definitionId' => $definitionId]);

        $shown = $this->as(self::USER_FULL)->getJson("/workflow/definitions/{$definitionId}")->assertOk()->json();
        $this->assertSame('نامِ ویرایش‌شده', $shown['definition']['Name']);
        $this->assertSame('توضیحِ ویرایش‌شده', $shown['definition']['Description']);
        $this->assertSame('PROJECT', $shown['definition']['EntityType']);
        $this->assertFalse((bool) $shown['definition']['IsActive']);
        // خودِ کد دست‌نخورده — رفتارِ Upsert، نه یک ردیفِ جدید
        $this->assertSame($code, $shown['definition']['Code']);
    }

    public function test_update_definition_with_duplicate_code_is_422(): void
    {
        $codeA = 'API_' . strtoupper(bin2hex(random_bytes(4)));
        $codeB = 'API_' . strtoupper(bin2hex(random_bytes(4)));

        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => $codeA, 'name' => 'اول', 'entityType' => 'TEST_ENTITY',
        ])->assertOk();
        $defB = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => $codeB, 'name' => 'دوم', 'entityType' => 'TEST_ENTITY',
        ])->assertOk()->json();

        // تلاش برایِ تغییرِ کدِ B به همان کدِ A ⇒ رد (همان قاعدهٔ sp_Wf_SaveDefinition)
        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'definitionId' => $defB['definitionId'], 'code' => $codeA, 'name' => 'دوم', 'entityType' => 'TEST_ENTITY',
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_update_definition_with_invalid_definition_id_is_422(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'definitionId' => 999999, 'code' => 'API_X', 'name' => 'X', 'entityType' => 'TEST_ENTITY',
        ])->assertStatus(422);
    }

    public function test_update_definition_without_permission_is_403(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => 'API_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'ن', 'entityType' => 'TEST_ENTITY',
        ])->assertOk()->json();

        $this->as(self::USER_NOPERM)->postJson('/workflow/definitions', [
            'definitionId' => $created['definitionId'], 'code' => 'API_X2', 'name' => 'تغییرِ غیرمجاز', 'entityType' => 'TEST_ENTITY',
        ])->assertStatus(403);
    }

    /* ---------- Toggle Active (Definition CRUD Gap Fix) ---------- */

    public function test_toggle_definition_active_flips_state(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => 'API_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'ت', 'entityType' => 'TEST_ENTITY',
        ])->assertOk()->json();
        $definitionId = $created['definitionId'];

        $this->assertTrue((bool) $this->as(self::USER_FULL)
            ->getJson("/workflow/definitions/{$definitionId}")->assertOk()->json('definition.IsActive'));

        $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/toggle")
            ->assertOk()->assertJson(['success' => true, 'isActive' => false]);
        $this->assertFalse((bool) $this->as(self::USER_FULL)
            ->getJson("/workflow/definitions/{$definitionId}")->assertOk()->json('definition.IsActive'));

        $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/toggle")
            ->assertOk()->assertJson(['success' => true, 'isActive' => true]);
        $this->assertTrue((bool) $this->as(self::USER_FULL)
            ->getJson("/workflow/definitions/{$definitionId}")->assertOk()->json('definition.IsActive'));
    }

    public function test_toggle_definition_preserves_other_fields(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => 'API_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'حفظِ فیلدها', 'description' => 'توضیح', 'entityType' => 'PROJECT',
        ])->assertOk()->json();
        $definitionId = $created['definitionId'];

        $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/toggle")->assertOk();

        $shown = $this->as(self::USER_FULL)->getJson("/workflow/definitions/{$definitionId}")->assertOk()->json();
        $this->assertSame('حفظِ فیلدها', $shown['definition']['Name']);
        $this->assertSame('توضیح', $shown['definition']['Description']);
        $this->assertSame('PROJECT', $shown['definition']['EntityType']);
    }

    public function test_toggle_definition_without_permission_is_403(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => 'API_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'ن', 'entityType' => 'TEST_ENTITY',
        ])->assertOk()->json();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/definitions/{$created['definitionId']}/toggle")
            ->assertStatus(403);
    }

    public function test_toggle_missing_definition_is_404(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/definitions/999999/toggle')->assertStatus(404);
    }

    public function test_toggle_definition_requires_authentication(): void
    {
        $this->postJson('/workflow/definitions/1/toggle')->assertStatus(401);
    }

    /**
     * قاعدهٔ کسب‌وکار: IsActive فقط جلویِ Startِ نمونهٔ *جدید* را می‌گیرد؛ نمونه‌یِ
     * در‌حالِ‌اجرا (که به VersionID خودش قفل است) کاملاً دست‌نخورده و قابلِ ادامه می‌ماند.
     */
    public function test_toggle_inactive_blocks_new_starts_but_not_a_running_instance(): void
    {
        [$definitionId, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 8001);
        $taskId = $this->openTaskId($instanceId);

        // غیرفعال‌کردنِ Definition پس از شروعِ نمونه
        $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/toggle")
            ->assertOk()->assertJson(['isActive' => false]);

        // نمونهٔ در‌حالِ‌اجرا کاملاً قابلِ ادامه است
        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->json('task.RowVersion');
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", ['actionCode' => 'APPROVE', 'rowVersion' => $rv])
            ->assertOk()->assertJson(['success' => true, 'instanceStatus' => 'COMPLETED']);

        // اما Startِ نمونهٔ *جدید* برایِ همین Definition دیگر مجاز نیست
        $this->as(self::USER_FULL)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'TEST_ENTITY', 'entityId' => 8002,
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_publishing_an_invalid_graph_returns_422(): void
    {
        [, $versionId] = $this->buildDraft(withEnd: false); // بدونِ END → گراف نامعتبر

        $this->as(self::USER_FULL)
            ->postJson("/workflow/versions/{$versionId}/publish")
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_validate_endpoint_reports_graph_errors_with_200(): void
    {
        [, $versionId] = $this->buildDraft(withEnd: false);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/versions/{$versionId}/validate")
            ->assertOk()
            ->assertJson(['success' => true, 'ok' => false]);
    }

    public function test_save_graph_body_validation_is_422(): void
    {
        [, $versionId] = $this->buildDraft();

        $this->as(self::USER_FULL)
            ->putJson("/workflow/versions/{$versionId}/graph", ['actions' => []]) // بدونِ steps
            ->assertStatus(422);
    }

    public function test_version_graph_endpoint_returns_all_slices(): void
    {
        [, $versionId] = $this->publishSimpleFlow();

        $this->as(self::USER_FULL)
            ->getJson("/workflow/versions/{$versionId}/graph")
            ->assertOk()
            ->assertJsonStructure(['success', 'steps', 'actions', 'assignments', 'transitions']);
    }

    /* ==================================================================== */
    /*  Not Found                                                            */
    /* ==================================================================== */

    public function test_missing_resources_return_404(): void
    {
        $this->as(self::USER_FULL);
        $this->getJson('/workflow/definitions/999999')->assertStatus(404);
        $this->getJson('/workflow/versions/999999')->assertStatus(404);
        $this->getJson('/workflow/instances/999999')->assertStatus(404);
        $this->getJson('/workflow/messages/999999')->assertStatus(404);
    }

    /* ==================================================================== */
    /*  Runtime                                                              */
    /* ==================================================================== */

    public function test_start_workflow_succeeds(): void
    {
        [, , $code] = $this->publishSimpleFlow();

        $res = $this->as(self::USER_FULL)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'TEST_ENTITY', 'entityId' => 7001,
        ])->assertOk()->assertJson(['success' => true, 'instanceStatus' => 'RUNNING'])->json();

        $this->assertIsInt($res['instanceId']);
        $this->assertSame('REVIEW', $res['enteredStepCode']);
    }

    public function test_start_without_permission_is_403(): void
    {
        [, , $code] = $this->publishSimpleFlow();

        $this->as(self::USER_NOPERM)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'TEST_ENTITY', 'entityId' => 7002,
        ])->assertStatus(403);
    }

    public function test_duplicate_start_is_409(): void
    {
        [, , $code] = $this->publishSimpleFlow();

        $this->as(self::USER_FULL)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'TEST_ENTITY', 'entityId' => 7003,
        ])->assertOk();

        $this->as(self::USER_FULL)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'TEST_ENTITY', 'entityId' => 7003,
        ])->assertStatus(409)->assertJson(['success' => false]);
    }

    /**
     * Concurrency hardening (پچ 020): وقتی pre-checkِ hasActiveInstance «کور» می‌شود
     * (شبیه‌سازیِ پنجرهٔ TOCTOU — دقیقِ همان لحظه‌ای که Requestِ بازنده هنوز RUNNINGِ
     * تازه‌درج‌شدهٔ Requestِ برنده را نمی‌بیند) و تنها ایندکسِ یکتای فیلترشدهٔ
     * UX_WorkflowInstances_Running_Entity جلوی INSERT را می‌گیرد، endpoint باید همچنان
     * ۴۰۹ برگرداند — نه ۵۰۰. اثباتِ اینکه WorkflowStore::startInstance() خطای
     * UniqueConstraintViolationException را تا لایهٔ HTTP تمیز نگاشت می‌کند.
     */
    public function test_start_endpoint_returns_409_not_500_when_only_the_unique_index_blocks_the_race(): void
    {
        [$definitionId, $versionId, $code] = $this->publishSimpleFlow();
        $entityId = 9102;

        // بردِ رقیب از قبل در دیتابیس هست...
        DB::statement(
            "INSERT INTO dbo.WorkflowInstances (InstanceNumber, DefinitionID, VersionID, EntityType, EntityID, Status, StartedAt, Date_InsertFirst)
             VALUES (?, ?, ?, 'TEST_ENTITY', ?, N'RUNNING', SYSDATETIME(), SYSDATETIME())",
            ['WFI-RACE-' . uniqid(), $definitionId, $versionId, $entityId]
        );

        // ...ولی pre-check کور می‌شود تا Request واقعاً به INSERT برسد
        $this->partialMock(\App\Services\Workflow\Support\WorkflowStore::class, function ($mock) {
            $mock->shouldReceive('hasActiveInstance')->andReturn(null);
        });

        $this->as(self::USER_FULL)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'TEST_ENTITY', 'entityId' => $entityId,
        ])->assertStatus(409)->assertJson(['success' => false]);
    }

    public function test_start_missing_definition_selector_is_422(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/instances', [
            'entityType' => 'TEST_ENTITY', 'entityId' => 7004,
        ])->assertStatus(422);
    }

    public function test_instance_detail_and_history(): void
    {
        [, , $code] = $this->publishSimpleFlow();
        $instanceId = $this->startInstance($code, 7005);

        $this->as(self::USER_FULL)
            ->getJson("/workflow/instances/{$instanceId}")
            ->assertOk()
            ->assertJsonStructure(['success', 'instance', 'steps', 'tasks']);

        $this->as(self::USER_FULL)
            ->getJson("/workflow/instances/{$instanceId}/history")
            ->assertOk()
            ->assertJsonStructure(['success', 'history']);
    }

    /* ==================================================================== */
    /*  Cartable — یکپارچگی با Messages (کارتابلِ دوم وجود ندارد)             */
    /* ==================================================================== */

    public function test_workflow_task_appears_in_the_existing_cartable(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL2);
        $instanceId = $this->startInstance($code, 7101);
        $messageId = $this->openTaskId($instanceId);

        // پیامی از نوعِ «وظیفه» ساخته شده
        $msg = DB::selectOne('EXEC sp_GetMessageHeader @MessageID = ?', [$messageId]);
        $this->assertSame('وظیفه', $msg->MessageTypeName);

        // انجام‌دهنده (۱۴) آن را در کارتابلِ موجودِ خود می‌بیند
        $this->assertContains($messageId, $this->cartableMessageIds(self::USER_FULL2));
        // کسی که انجام‌دهنده نیست، نمی‌بیند
        $this->assertNotContains($messageId, $this->cartableMessageIds(self::USER_NOPERM));
    }

    public function test_normal_task_and_workflow_task_share_one_cartable(): void
    {
        // وظیفهٔ عادی از مسیرِ موجود (sp_InsertMessage) — فرستنده ۲ (مدیرِ واحد)، گیرنده ۴ (مدیرِ واحد ۷)
        $normal = DB::selectOne(
            'EXEC sp_InsertMessage @MessageTypeID = 2, @msgPriorityID = 1, @Subject = ?, @MessageText = NULL,
                 @RecipientType = 1, @RecipientUserIDs = ?, @CopyUserIDs = NULL, @CopyDescription = NULL,
                 @SenderUserID = ?, @Year = 1405, @CreateUser = ?, @DueDate = NULL',
            ['وظیفهٔ عادیِ تست', (string) self::UNIT_MGR7, self::USER_FULL, self::USER_FULL]
        );
        $this->assertSame(1, (int) $normal->Success, $normal->Message);
        $normalTaskId = (int) $normal->NewMessageID;

        // وظیفهٔ فرایند — انجام‌دهنده هم کاربر ۴
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::UNIT_MGR7);
        $wfTaskId = $this->openTaskId($this->startInstance($code, 7110));

        $cartable = $this->cartableMessageIds(self::UNIT_MGR7);
        $this->assertContains($normalTaskId, $cartable, 'وظیفهٔ عادی باید در کارتابل باشد.');
        $this->assertContains($wfTaskId, $cartable, 'وظیفهٔ فرایند باید در همان کارتابل باشد.');
    }

    public function test_no_separate_workflow_cartable_endpoint_exists(): void
    {
        $this->as(self::USER_FULL);
        $this->getJson('/workflow/tasks')->assertStatus(404);
        $this->getJson('/workflow/tasks?scope=all')->assertStatus(404);
        $this->getJson('/workflow/my-tasks')->assertStatus(404);
    }

    public function test_completed_workflow_task_leaves_the_cartable(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7115);
        $messageId = $this->openTaskId($instanceId);
        $this->assertContains($messageId, $this->cartableMessageIds(self::USER_FULL));

        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$messageId}")->json('task.RowVersion');
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/actions", ['actionCode' => 'APPROVE', 'rowVersion' => $rv])
            ->assertOk()->assertJson(['instanceStatus' => 'COMPLETED']);

        $this->assertNotContains($messageId, $this->cartableMessageIds(self::USER_FULL));
    }

    public function test_task_detail_returns_hex_rowversion_and_actions(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7103);
        $taskId = $this->openTaskId($instanceId);

        $body = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->assertOk()->json();

        $this->assertTrue($body['success']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{16}$/', $body['task']['RowVersion']);
        $this->assertNotEmpty($body['actions']);
        $this->assertTrue($body['canAct']);

        // ارتباطِ Task → Instance: فیلدی که WorkflowTaskCard برایِ لینک به Process Instance استفاده می‌کند
        $this->assertSame($instanceId, (int) $body['task']['InstanceID']);
    }

    public function test_task_detail_forbidden_for_non_assignee_without_all_tasks(): void
    {
        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW);

        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7104);
        $taskId = $this->openTaskId($instanceId);

        $this->as(self::USER_NOPERM)->getJson("/workflow/messages/{$taskId}")->assertStatus(403);
    }

    public function test_perform_action_succeeds_and_completes_instance(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7105);
        $taskId = $this->openTaskId($instanceId);

        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->json('task.RowVersion');

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", ['actionCode' => 'APPROVE', 'rowVersion' => $rv])
            ->assertOk()
            ->assertJson(['success' => true, 'instanceStatus' => 'COMPLETED']);
    }

    public function test_perform_action_with_stale_rowversion_is_409(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7106);
        $taskId = $this->openTaskId($instanceId);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", [
                'actionCode' => 'APPROVE',
                'rowVersion' => '0x0000000000000001',
            ])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'concurrency' => true]);
    }

    public function test_perform_action_by_non_assignee_is_rejected(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7107);
        $taskId = $this->openTaskId($instanceId);

        // کاربر ۱۴ دسترسیِ WORKFLOW_VIEW دارد ولی انجام‌دهندهٔ این تسک نیست
        $this->as(self::USER_FULL2)
            ->postJson("/workflow/messages/{$taskId}/actions", [
                'actionCode' => 'APPROVE',
                'rowVersion' => '0x0000000000000001',
            ])
            ->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    public function test_action_actor_is_always_the_authenticated_user(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7108);
        $taskId = $this->openTaskId($instanceId);
        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->json('task.RowVersion');

        // حتی اگر Client یک userId جعلی بفرستد، نادیده گرفته می‌شود
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", [
                'actionCode' => 'APPROVE', 'rowVersion' => $rv, 'userId' => self::USER_FULL2,
            ])
            ->assertOk();

        // Actor واقعی = کاربرِ احراز هویت‌شده (۲)، نه userIdِ جعلیِ Request (۱۴)
        $detail = $this->query->stepTaskDetail($taskId);
        $acted = collect($detail['assignees'])->firstWhere('UserID', self::USER_FULL);
        $this->assertNotNull($acted->Decision);
        $this->assertNull(collect($detail['assignees'])->firstWhere('UserID', self::USER_FULL2)); // ۱۴ اصلاً assignee نیست

        $completed = collect($this->query->instance($instanceId)['history'])->firstWhere('EventCode', 'TASK_COMPLETED');
        $this->assertSame(self::USER_FULL, (int) $completed->ActorUserID);
    }

    /* ==================================================================== */
    /*  چرخهٔ حیاتِ Instance  —  Cancel / Suspend / Resume                   */
    /* ==================================================================== */

    private function newInstance(int $entityId): array
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, $entityId);
        $taskId = $this->openTaskId($instanceId);

        return [$instanceId, $taskId];
    }

    private function instanceStatus(int $instanceId): string
    {
        return $this->query->instance($instanceId)['instance']->Status;
    }

    private function openTaskCount(int $instanceId): int
    {
        return count(array_filter(
            $this->query->instance($instanceId)['tasks'],
            fn ($t) => $t->Status === 'ACTIVE'
        ));
    }

    private function historyCodes(int $instanceId): array
    {
        return collect($this->query->instance($instanceId)['history'])->pluck('EventCode')->all();
    }

    // ---------- Cancel ----------

    public function test_cancel_endpoint_succeeds_and_closes_tasks(): void
    {
        [$instanceId] = $this->newInstance(7201);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/instances/{$instanceId}/cancel", ['reason' => 'منصرف شدیم'])
            ->assertOk()
            ->assertJson(['success' => true, 'instanceStatus' => 'CANCELLED']);

        $this->assertSame('CANCELLED', $this->instanceStatus($instanceId));
        $this->assertSame(0, $this->openTaskCount($instanceId));
        $this->assertContains('INSTANCE_CANCELLED', $this->historyCodes($instanceId));

        $cancelled = collect($this->query->instance($instanceId)['history'])->firstWhere('EventCode', 'INSTANCE_CANCELLED');
        $this->assertSame('منصرف شدیم', json_decode($cancelled->DetailJson, true)['reason']);
    }

    public function test_cancel_twice_is_409(): void
    {
        [$instanceId] = $this->newInstance(7202);
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/cancel")->assertOk();
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/cancel")
            ->assertStatus(409)->assertJson(['success' => false]);
    }

    public function test_action_after_cancel_is_409(): void
    {
        [$instanceId, $taskId] = $this->newInstance(7203);
        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->json('task.RowVersion');
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/cancel")->assertOk();

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", ['actionCode' => 'APPROVE', 'rowVersion' => $rv])
            ->assertStatus(409);
    }

    public function test_cancel_without_permission_is_403(): void
    {
        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW); // کاربر ۳ فقط VIEW دارد، نه CANCEL
        [$instanceId] = $this->newInstance(7204);

        $this->as(self::USER_NOPERM)->postJson("/workflow/instances/{$instanceId}/cancel")->assertStatus(403);
    }

    public function test_cancel_missing_instance_is_404(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/instances/999999/cancel')->assertStatus(404);
    }

    // ---------- Suspend ----------

    public function test_suspend_endpoint_succeeds_and_keeps_tasks(): void
    {
        [$instanceId, $taskId] = $this->newInstance(7211);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/instances/{$instanceId}/suspend", ['reason' => 'در انتظارِ بودجه'])
            ->assertOk()
            ->assertJson(['success' => true, 'instanceStatus' => 'SUSPENDED']);

        $this->assertSame('SUSPENDED', $this->instanceStatus($instanceId));
        $this->assertSame(1, $this->openTaskCount($instanceId));
        $this->assertContains('INSTANCE_SUSPENDED', $this->historyCodes($instanceId));
    }

    public function test_suspend_twice_is_409(): void
    {
        [$instanceId] = $this->newInstance(7212);
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/suspend")->assertOk();
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/suspend")->assertStatus(409);
    }

    public function test_action_during_suspend_is_409(): void
    {
        [$instanceId, $taskId] = $this->newInstance(7213);
        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->json('task.RowVersion');
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/suspend")->assertOk();

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", ['actionCode' => 'APPROVE', 'rowVersion' => $rv])
            ->assertStatus(409);
    }

    public function test_suspend_without_permission_is_403(): void
    {
        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW);
        [$instanceId] = $this->newInstance(7214);

        $this->as(self::USER_NOPERM)->postJson("/workflow/instances/{$instanceId}/suspend")->assertStatus(403);
    }

    // ---------- Resume ----------

    public function test_resume_endpoint_restores_running_and_reenables_actions(): void
    {
        [$instanceId, $taskId] = $this->newInstance(7221);
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/suspend")->assertOk();

        $this->as(self::USER_FULL)
            ->postJson("/workflow/instances/{$instanceId}/resume")
            ->assertOk()
            ->assertJson(['success' => true, 'instanceStatus' => 'RUNNING']);

        $this->assertSame('RUNNING', $this->instanceStatus($instanceId));
        $this->assertContains('INSTANCE_RESUMED', $this->historyCodes($instanceId));

        // تسکِ قبلی حفظ شده و اکنون قابلِ انجام است
        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->json('task.RowVersion');
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", ['actionCode' => 'APPROVE', 'rowVersion' => $rv])
            ->assertOk()
            ->assertJson(['instanceStatus' => 'COMPLETED']);
    }

    public function test_resume_running_instance_is_409(): void
    {
        [$instanceId] = $this->newInstance(7222);
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/resume")->assertStatus(409);
    }

    public function test_resume_cancelled_instance_is_409(): void
    {
        [$instanceId] = $this->newInstance(7223);
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/cancel")->assertOk();
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/resume")->assertStatus(409);
    }

    // ---------- Security ----------

    public function test_lifecycle_endpoints_require_authentication(): void
    {
        [$instanceId] = $this->newInstance(7231);
        $this->postJson("/workflow/instances/{$instanceId}/cancel")->assertStatus(401);
        $this->postJson("/workflow/instances/{$instanceId}/suspend")->assertStatus(401);
        $this->postJson("/workflow/instances/{$instanceId}/resume")->assertStatus(401);
    }

    public function test_lifecycle_actor_is_authenticated_user_and_request_actor_is_ignored(): void
    {
        [$instanceId] = $this->newInstance(7232);

        // بدنه شاملِ actorId/userId جعلی — باید نادیده گرفته شود
        $this->as(self::USER_FULL)
            ->postJson("/workflow/instances/{$instanceId}/suspend", ['actorId' => self::USER_FULL2, 'userId' => self::USER_FULL2])
            ->assertOk();

        $suspended = collect($this->query->instance($instanceId)['history'])->firstWhere('EventCode', 'INSTANCE_SUSPENDED');
        $this->assertSame(self::USER_FULL, (int) $suspended->ActorUserID);
    }

    // ---------- Integration ----------

    public function test_running_suspended_running_completed_cycle_over_api(): void
    {
        [$instanceId, $taskId] = $this->newInstance(7241);

        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/suspend")->assertOk();
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/resume")->assertOk();

        $rv = $this->as(self::USER_FULL)->getJson("/workflow/messages/{$taskId}")->json('task.RowVersion');
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$taskId}/actions", ['actionCode' => 'APPROVE', 'rowVersion' => $rv])
            ->assertOk()->assertJson(['instanceStatus' => 'COMPLETED']);

        $this->assertSame('COMPLETED', $this->instanceStatus($instanceId));
    }

    public function test_suspended_to_cancelled_over_api(): void
    {
        [$instanceId] = $this->newInstance(7242);
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/suspend")->assertOk();
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/cancel", ['reason' => 'x'])
            ->assertOk()->assertJson(['instanceStatus' => 'CANCELLED']);
        $this->assertSame(0, $this->openTaskCount($instanceId));
    }

    /* ==================================================================== */
    /*  Forward / Delegation / Revoke — endpoints (Step 7.2)                 */
    /* ==================================================================== */

    public function test_forward_endpoint_moves_task_to_target(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL, allowForward: true);
        $instanceId = $this->startInstance($code, 7301);
        $messageId = $this->openTaskId($instanceId);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/forward", ['targetUserId' => self::USER_FULL2, 'comment' => 'به شما'])
            ->assertOk()
            ->assertJson(['success' => true, 'instanceStatus' => 'RUNNING']);

        $this->assertContains($messageId, $this->cartableMessageIds(self::USER_FULL2));
        $this->assertNotContains($messageId, $this->cartableMessageIds(self::USER_FULL));

        $forwarded = collect($this->query->instance($instanceId)['history'])->firstWhere('EventCode', 'TASK_FORWARDED');
        $this->assertSame(self::USER_FULL, (int) $forwarded->ActorUserID);
    }

    public function test_forward_endpoint_requires_forward_permission(): void
    {
        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW); // ۳ فقط VIEW دارد
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_NOPERM, allowForward: true);
        $messageId = $this->openTaskId($this->startInstance($code, 7302));

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/messages/{$messageId}/forward", ['targetUserId' => self::USER_FULL])
            ->assertStatus(403);
    }

    public function test_forward_endpoint_validation_requires_a_valid_target(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL, allowForward: true);
        $messageId = $this->openTaskId($this->startInstance($code, 7303));

        $this->as(self::USER_FULL)->postJson("/workflow/messages/{$messageId}/forward", [])->assertStatus(422);
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/forward", ['targetUserId' => 987654])
            ->assertStatus(422); // exists:Users,UserID
    }

    public function test_forward_endpoint_ignores_request_actor(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL, allowForward: true);
        $instanceId = $this->startInstance($code, 7304);
        $messageId = $this->openTaskId($instanceId);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/forward", [
                'targetUserId' => self::USER_FULL2, 'actorUserId' => self::USER_NOPERM, 'userId' => self::USER_NOPERM,
            ])
            ->assertOk();

        $forwarded = collect($this->query->instance($instanceId)['history'])->firstWhere('EventCode', 'TASK_FORWARDED');
        $this->assertSame(self::USER_FULL, (int) $forwarded->ActorUserID);
        $detail = json_decode($forwarded->DetailJson, true);
        $this->assertSame(self::USER_FULL, (int) $detail['fromUserId']);
        $this->assertSame(self::USER_FULL2, (int) $detail['toUserId']);
    }

    public function test_forward_endpoint_on_suspended_instance_is_409(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL, allowForward: true);
        $instanceId = $this->startInstance($code, 7305);
        $messageId = $this->openTaskId($instanceId);
        $this->as(self::USER_FULL)->postJson("/workflow/instances/{$instanceId}/suspend")->assertOk();

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/forward", ['targetUserId' => self::USER_FULL2])
            ->assertStatus(409);
    }

    public function test_forward_endpoint_when_step_disallows_is_422(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL); // allowForward = false
        $messageId = $this->openTaskId($this->startInstance($code, 7306));

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/forward", ['targetUserId' => self::USER_FULL2])
            ->assertStatus(422);
    }

    public function test_delegate_and_revoke_endpoints_round_trip(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $instanceId = $this->startInstance($code, 7311);
        $messageId = $this->openTaskId($instanceId);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/delegate", ['targetUserId' => self::USER_FULL2])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertContains($messageId, $this->cartableMessageIds(self::USER_FULL2));
        $this->assertNotContains($messageId, $this->cartableMessageIds(self::USER_FULL));

        // بدونِ ارسالِ delegateUserId — از رویِ context حل می‌شود
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/revoke-delegation", [])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertContains($messageId, $this->cartableMessageIds(self::USER_FULL));
        $this->assertNotContains($messageId, $this->cartableMessageIds(self::USER_FULL2));

        $codes = $this->historyCodes($instanceId);
        $this->assertContains('TASK_DELEGATED', $codes);
        $this->assertContains('TASK_DELEGATION_REVOKED', $codes);
    }

    public function test_delegate_endpoint_requires_delegate_permission(): void
    {
        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW);
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_NOPERM);
        $messageId = $this->openTaskId($this->startInstance($code, 7312));

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/messages/{$messageId}/delegate", ['targetUserId' => self::USER_FULL])
            ->assertStatus(403);
    }

    public function test_revoke_endpoint_requires_delegate_permission(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $messageId = $this->openTaskId($this->startInstance($code, 7313));
        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/delegate", ['targetUserId' => self::USER_FULL2])->assertOk();

        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW); // ۳ فقط VIEW
        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/messages/{$messageId}/revoke-delegation", ['delegateUserId' => self::USER_FULL2])
            ->assertStatus(403);
    }

    public function test_revoke_endpoint_without_active_delegation_is_422(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL);
        $messageId = $this->openTaskId($this->startInstance($code, 7314));

        $this->as(self::USER_FULL)
            ->postJson("/workflow/messages/{$messageId}/revoke-delegation", [])
            ->assertStatus(422);
    }

    public function test_forward_and_delegate_endpoints_require_authentication(): void
    {
        [, , $code] = $this->publishSimpleFlow(reviewAssignee: self::USER_FULL, allowForward: true);
        $messageId = $this->openTaskId($this->startInstance($code, 7315));

        $this->postJson("/workflow/messages/{$messageId}/forward", ['targetUserId' => self::USER_FULL2])->assertStatus(401);
        $this->postJson("/workflow/messages/{$messageId}/delegate", ['targetUserId' => self::USER_FULL2])->assertStatus(401);
        $this->postJson("/workflow/messages/{$messageId}/revoke-delegation", [])->assertStatus(401);
    }

    /* ============================ کمکیِ Draft ============================ */

    private function buildDraft(bool $withEnd = false): array
    {
        $code = 'API_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['code' => $code, 'name' => 'D ' . $code, 'entityType' => 'TEST_ENTITY'], self::USER_FULL);
        $definitionId = (int) $def->DefinitionID;
        $versionId = (int) $this->defs->createDraft($definitionId, self::USER_FULL)->VersionID;

        $steps = [['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0]];
        $transitions = [];
        if ($withEnd) {
            $steps[] = ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 1];
            $transitions[] = ['code' => 'T', 'fromStepCode' => 'START', 'toStepCode' => 'END', 'isDefault' => true];
        }
        $this->defs->saveGraph($versionId, ['steps' => $steps, 'transitions' => $transitions], self::USER_FULL);

        return [$definitionId, $versionId, $code];
    }
}
