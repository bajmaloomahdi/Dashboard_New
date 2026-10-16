<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * Gap 1 + Gap 2 (تشخیصِ دقیقِ Workflow Task + Permissionِ Frontend) — پچ 021
 * (sp_GetMessageHeader.IsWfTask) و MessageController::show() (workflowPermissions).
 *
 * چون پروژه فریم‌ورکِ تستِ Frontend ندارد، این فایل معادلِ Backendِ سناریوهای
 * خواسته‌شده را می‌سنجد: خودِ contractای که Messages/Show.tsx بر اساسِ آن
 * تصمیم می‌گیرد آیا WorkflowTaskCard را mount/fetch کند یا نه، و آیا دکمه‌هایِ
 * Forward/Delegate را نشان دهد یا نه.
 */
class MessageWorkflowIntegrationTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER_FULL = 2;   // مهدی — هر ۹ دسترسیِ WORKFLOW_*
    private const USER_NOPERM = 3; // علی — بدونِ هیچ دسترسیِ WORKFLOW_
    private const USER_MGR7 = 4;   // محسن — مدیرِ واحدِ ۷ (گیرندهٔ مجازِ sp_InsertMessage نوعِ «وظیفه»)
    private const ROLE_AUTOMATION = 4;
    private const PERM_WORKFLOW_VIEW = 7;

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

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function grantAutomationPermission(int $permissionId): void
    {
        DB::table('RolePermissions')->insert([
            'RoleID' => self::ROLE_AUTOMATION, 'PermissionID' => $permissionId, 'CanAccess' => 1, 'IsActive' => 1,
        ]);
    }

    /** پیامِ عادیِ غیرِ«وظیفه» (MessageTypeID=1) — نه Task، نه Workflow. */
    private function insertNormalMessage(): int
    {
        $res = DB::selectOne(
            'EXEC sp_InsertMessage @MessageTypeID = 1, @msgPriorityID = 1, @Subject = ?, @MessageText = NULL,
                 @RecipientType = 1, @RecipientUserIDs = ?, @CopyUserIDs = NULL, @CopyDescription = NULL,
                 @SenderUserID = ?, @Year = 1405, @CreateUser = ?, @DueDate = NULL',
            ['پیامِ عادیِ تست', (string) self::USER_NOPERM, self::USER_FULL, self::USER_FULL]
        );
        $this->assertSame(1, (int) $res->Success, $res->Message);

        return (int) $res->NewMessageID;
    }

    /** وظیفهٔ عادی (MessageTypeID=2) از مسیرِ موجودِ sp_InsertMessage — Task است ولی Workflow نیست. */
    private function insertNormalTaskMessage(): int
    {
        $res = DB::selectOne(
            'EXEC sp_InsertMessage @MessageTypeID = 2, @msgPriorityID = 1, @Subject = ?, @MessageText = NULL,
                 @RecipientType = 1, @RecipientUserIDs = ?, @CopyUserIDs = NULL, @CopyDescription = NULL,
                 @SenderUserID = ?, @Year = 1405, @CreateUser = ?, @DueDate = NULL',
            ['وظیفهٔ عادیِ تست', (string) self::USER_MGR7, self::USER_FULL, self::USER_FULL]
        );
        $this->assertSame(1, (int) $res->Success, $res->Message);

        return (int) $res->NewMessageID;
    }

    /** انتشارِ یک فرایندِ ساده + Start + بازگرداندنِ MessageIDِ تسکِ فعال (تسکِ Workflow واقعی). */
    private function startWorkflowTaskMessage(int $assignee, int $entityId): int
    {
        $code = 'MSGWF_' . strtoupper(bin2hex(random_bytes(4)));
        // entityType=TEST_ENTITY: این Instance صرفاً برایِ ساختِ یک تسکِ Workflowِ واقعی
        // است (برایِ سنجشِ IsWfTask/workflowPermissions رویِ همان تسک)؛ entityId یک
        // شناسهٔ دلخواه است و به هیچ ردیفِ واقعیِ Messages متصل نیست.
        $def = $this->defs->save(['name' => 'ت', 'entityType' => 'TEST_ENTITY'], self::USER_FULL);
        $code = $def->Code; // Codeِ واقعی، ساخته‌شده در Backend (WF101/...) — نه فقط پیشنهادِ محلی
        $ver = $this->defs->createDraft((int) $def->DefinitionID, self::USER_FULL);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پ', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید']],
            'assignments' => [['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => $assignee]],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::USER_FULL);
        $this->defs->publish((int) $ver->VersionID, self::USER_FULL);

        $result = $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: $entityId, startedByUserId: self::USER_FULL, definitionCode: $code,
        ));

        $tasks = $this->query->instance($result->instanceId)['tasks'];
        $open = collect($tasks)->firstWhere('Status', 'ACTIVE');
        $this->assertNotNull($open, 'باید یک تسکِ باز وجود داشته باشد.');

        return (int) $open->MessageID;
    }

    /* ==================================================================== */
    /*  Gap 1 — IsWfTask                                                     */
    /* ==================================================================== */

    public function test_normal_message_reports_is_wf_task_false(): void
    {
        $messageId = $this->insertNormalMessage();

        $response = $this->as(self::USER_FULL)->get("/messages/{$messageId}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Messages/Show')
            ->where('isTask', false)
            ->where('isWfTask', false)
            ->where('workflowPermissions', [])
        );
    }

    public function test_normal_task_reports_is_task_true_but_is_wf_task_false(): void
    {
        $messageId = $this->insertNormalTaskMessage();

        $response = $this->as(self::USER_FULL)->get("/messages/{$messageId}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Messages/Show')
            ->where('isTask', true)
            ->where('isWfTask', false)
            // برایِ تسکِ غیرِ Workflow، workflowPermissions محاسبه نمی‌شود (خالی می‌ماند)
            ->where('workflowPermissions', [])
        );
    }

    public function test_workflow_task_reports_is_task_and_is_wf_task_true(): void
    {
        $messageId = $this->startWorkflowTaskMessage(self::USER_FULL, 9101);

        $response = $this->as(self::USER_FULL)->get("/messages/{$messageId}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Messages/Show')
            ->where('isTask', true)
            ->where('isWfTask', true)
        );
    }

    /* ==================================================================== */
    /*  Gap 2 — workflowPermissions (contract برایِ UX، نه مرجعِ Authorization) */
    /* ==================================================================== */

    public function test_workflow_permissions_prop_contains_forward_and_delegate_for_full_user(): void
    {
        $messageId = $this->startWorkflowTaskMessage(self::USER_FULL, 9102);

        $response = $this->as(self::USER_FULL)->get("/messages/{$messageId}");
        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Messages/Show')->where('isWfTask', true);
            $perms = $page->toArray()['props']['workflowPermissions'] ?? [];
            $this->assertContains('WORKFLOW_FORWARD', $perms);
            $this->assertContains('WORKFLOW_DELEGATE', $perms);
        });
    }

    public function test_workflow_permissions_prop_excludes_forward_and_delegate_for_user_without_grant(): void
    {
        // کاربرِ ۳ فقط WORKFLOW_VIEW دارد؛ خودش انجام‌دهندهٔ همین تسک است (چون
        // MessageController::show() اکنون Participantِ‌بودن را الزامی می‌کند —
        // مطابقِ downloadAttachment/Start Workflow — پس یک شخصِ کاملاً بی‌ربط
        // دیگر نمی‌تواند صفحهٔ پیامِ او را ببیند).
        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW);
        $messageId = $this->startWorkflowTaskMessage(self::USER_NOPERM, 9103);

        $response = $this->as(self::USER_NOPERM)->get("/messages/{$messageId}");
        $response->assertOk();
        $response->assertInertia(function ($page) {
            $page->component('Messages/Show')->where('isWfTask', true);
            $perms = $page->toArray()['props']['workflowPermissions'] ?? [];
            $this->assertNotContains('WORKFLOW_FORWARD', $perms);
            $this->assertNotContains('WORKFLOW_DELEGATE', $perms);
            $this->assertContains('WORKFLOW_VIEW', $perms);
        });
    }

    /* ==================================================================== */
    /*  رگرسیون — Authorizationِ سمتِ Backend همچنان مرجعِ نهایی است            */
    /* ==================================================================== */

    public function test_backend_still_enforces_403_for_forward_regardless_of_frontend_hint(): void
    {
        $this->grantAutomationPermission(self::PERM_WORKFLOW_VIEW);
        $messageId = $this->startWorkflowTaskMessage(self::USER_NOPERM, 9104);

        // حتی اگر Frontend دکمه را (فرضاً) نشان می‌داد، تلاشِ مستقیم روی endpoint باید ۴۰۳ بگیرد
        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/messages/{$messageId}/forward", ['targetUserId' => self::USER_FULL])
            ->assertStatus(403);
    }
}
