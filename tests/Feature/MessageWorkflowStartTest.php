<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Entity\MessageEntityResolver;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فاز ۳ — P0: شروعِ واقعیِ Workflow روی یک Message واقعی.
 *
 * می‌سنجد: ۴ متدِ MessageEntityResolver (با DB واقعی)، ۸ Validationِ Backendِ
 * الزامیِ Start (طبقِ FINAL PHASE 3 PROPOSAL)، و اینکه شکستِ Start هرگز Message
 * را دست‌نمی‌زند (استقلالِ کاملِ Transaction از sp_InsertMessage).
 *
 * دادهٔ واقعیِ ثابت (بدونِ Fixture جدید — همان کاربرانِ استفاده‌شده در
 * MessageWorkflowIntegrationTest/WorkflowEngineTest):
 *   کاربر ۲ (مهدی)  — مدیرِ واحد ۱۰ (IT)، هر ۹ دسترسیِ WORKFLOW_*
 *   کاربر ۳ (علی)   — کارشناسِ واحد ۷ (HR)، بدونِ هیچ دسترسیِ WORKFLOW_
 *   کاربر ۴ (محسن)  — مدیرِ واحد ۷ (HR)
 *   کاربر ۱۴ (دمو)  — بدونِ نقشی در Messageهایِ این تست (Stranger)
 */
class MessageWorkflowStartTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_C = 3;       // علی — کارشناسِ واحد ۷، بدونِ WORKFLOW_*
    private const USER_MGR7 = 4;    // محسن — مدیرِ واحد ۷
    private const USER_STRANGER = 14;

    private const ROLE_AUTOMATION = 4; // شاملِ کاربرِ ۳
    private const PERM_WORKFLOW_START = 10;

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private WorkflowQueryService $query;
    private MessageEntityResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
        $this->query = $this->app->make(WorkflowQueryService::class);
        $this->resolver = $this->app->make(MessageEntityResolver::class);
    }

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    /** WORKFLOW_START (PermissionID=10) را موقتاً به نقشِ اتوماسیون (شاملِ کاربرِ ۳) می‌دهد. */
    private function grantWorkflowStartToAutomationRole(): void
    {
        DB::table('RolePermissions')->insert([
            'RoleID' => self::ROLE_AUTOMATION, 'PermissionID' => self::PERM_WORKFLOW_START, 'CanAccess' => 1, 'IsActive' => 1,
        ]);
    }

    private function insertMessage(int $senderId, int $recipientId, ?int $ccId = null): int
    {
        $res = DB::selectOne(
            'EXEC sp_InsertMessage @MessageTypeID = 1, @msgPriorityID = 1, @Subject = ?, @MessageText = NULL,
                 @RecipientType = 1, @RecipientUserIDs = ?, @CopyUserIDs = NULL, @CopyDescription = NULL,
                 @SenderUserID = ?, @Year = 1405, @CreateUser = ?, @DueDate = NULL',
            ['پیامِ تستِ Start Workflow', (string) $recipientId, $senderId, $senderId]
        );
        $this->assertSame(1, (int) $res->Success, $res->Message);
        $messageId = (int) $res->NewMessageID;

        if ($ccId !== null) {
            DB::select(
                'EXEC sp_AddMessageCopy @MessageID = ?, @UserID = ?, @CopyUserID = ?, @Description = NULL, @CreateUser = ?',
                [$messageId, $senderId, $ccId, $senderId]
            );
        }

        return $messageId;
    }

    private function messageExists(int $messageId): bool
    {
        return DB::selectOne('SELECT 1 AS X FROM dbo.Messages WHERE MessageID = ?', [$messageId]) !== null;
    }

    /** انتشارِ ساده‌ترین فرایندِ EntityType=MESSAGE با REVIEW سپرده‌شده به DIRECT_MANAGER. */
    private function publishSimpleFlowWithDirectManager(): string
    {
        $def = $this->defs->save(['name' => 'شروع از پیام', 'entityType' => 'MESSAGE'], self::USER_FULL);
        $ver = $this->defs->createDraft((int) $def->DefinitionID, self::USER_FULL);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پ', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید']],
            'assignments' => [['stepCode' => 'REVIEW', 'assigneeType' => 'DIRECT_MANAGER']],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::USER_FULL);
        $this->defs->publish((int) $ver->VersionID, self::USER_FULL);

        return $def->Code;
    }

    /* ==================================================================== */
    /*  MessageEntityResolver — ۴ متدِ Contract                              */
    /* ==================================================================== */

    public function test_resolver_exists_true_for_real_message(): void
    {
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);

        $this->assertTrue($this->resolver->exists('MESSAGE', $messageId));
    }

    public function test_resolver_exists_false_for_nonexistent_message(): void
    {
        $this->assertFalse($this->resolver->exists('MESSAGE', 987654321));
    }

    public function test_resolver_title_returns_subject(): void
    {
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);

        $this->assertSame('پیامِ تستِ Start Workflow', $this->resolver->title('MESSAGE', $messageId));
    }

    public function test_resolver_title_null_for_nonexistent_message(): void
    {
        $this->assertNull($this->resolver->title('MESSAGE', 987654321));
    }

    public function test_resolver_owner_user_id_returns_sender(): void
    {
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);

        $this->assertSame(self::USER_C, $this->resolver->ownerUserId('MESSAGE', $messageId));
    }

    public function test_resolver_unit_id_returns_senders_active_unit(): void
    {
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);

        // کاربرِ ۳ (علی) عضوِ واحدِ ۷ (منابعِ انسانی) است.
        $this->assertSame(7, $this->resolver->unitId('MESSAGE', $messageId));
    }

    public function test_resolver_owner_and_unit_null_for_nonexistent_message(): void
    {
        $this->assertNull($this->resolver->ownerUserId('MESSAGE', 987654321));
        $this->assertNull($this->resolver->unitId('MESSAGE', 987654321));
    }

    /* ==================================================================== */
    /*  Access Check — Sender/Recipient/Cc/Stranger (از طریقِ endpointِ واقعی)  */
    /* ==================================================================== */

    public function test_start_allowed_for_message_sender(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);
        $code = $this->publishSimpleFlowWithDirectManager();

        $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => $messageId,
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_start_allowed_for_message_recipient(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $messageId = $this->insertMessage(self::USER_MGR7, self::USER_C);
        $code = $this->publishSimpleFlowWithDirectManager();

        $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => $messageId,
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_start_allowed_for_message_cc(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $messageId = $this->insertMessage(self::USER_MGR7, self::USER_FULL, ccId: self::USER_C);
        $code = $this->publishSimpleFlowWithDirectManager();

        $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => $messageId,
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_start_denied_for_stranger(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $messageId = $this->insertMessage(self::USER_MGR7, self::USER_FULL);

        $code = $this->publishSimpleFlowWithDirectManager();

        $this->as(self::USER_STRANGER)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => $messageId,
        ])->assertStatus(422)->assertJson(['success' => false]);

        $this->assertTrue($this->messageExists($messageId), 'ردِ دسترسی نباید Message را حذف/تغییر دهد.');
    }

    public function test_start_denied_for_nonexistent_message(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $code = $this->publishSimpleFlowWithDirectManager();

        $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => 987654321,
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  EntityType consistency (Gap 4)                                       */
    /* ==================================================================== */

    public function test_start_denied_for_entity_type_mismatch(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);
        $code = $this->publishSimpleFlowWithDirectManager(); // Definition.EntityType = MESSAGE

        // Definition برایِ MESSAGE است اما درخواست entityType=PROJECT اعلام می‌کند
        $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'PROJECT', 'entityId' => $messageId,
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  مسیرِ موفق — Scenario 1: Message → Start → مدیرِ مستقیمِ آغازگر → End    */
    /* ==================================================================== */

    public function test_successful_start_assigns_review_to_direct_manager_of_starter(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);
        $code = $this->publishSimpleFlowWithDirectManager();

        // آغازگر = کاربرِ ۳ (علی، واحدِ ۷)؛ مدیرِ مستقیمِ او = کاربرِ ۴ (محسن، مدیرِ واحدِ ۷)
        $res = $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => $messageId,
        ])->assertOk()->assertJson(['success' => true, 'instanceStatus' => 'RUNNING'])->json();

        $tasks = $this->query->instance($res['instanceId'])['tasks'];
        $openTask = collect($tasks)->firstWhere('Status', 'ACTIVE');
        $this->assertNotNull($openTask, 'باید یک تسکِ باز (REVIEW) وجود داشته باشد.');

        $detail = $this->query->stepTaskDetail((int) $openTask->MessageID);
        $assigneeIds = collect($detail['assignees'])->pluck('UserID')->map(fn ($id) => (int) $id)->all();

        $this->assertSame([self::USER_MGR7], $assigneeIds, 'انجام‌دهندهٔ REVIEW باید فقط مدیرِ مستقیمِ آغازگر (کاربرِ ۴) باشد.');
    }

    /* ==================================================================== */
    /*  استقلالِ Transaction — شکستِ Start نباید Message را دست بزند            */
    /* ==================================================================== */

    public function test_failed_start_does_not_affect_the_message(): void
    {
        $this->grantWorkflowStartToAutomationRole();
        $messageId = $this->insertMessage(self::USER_C, self::USER_MGR7);
        $code = $this->publishSimpleFlowWithDirectManager();

        $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => $messageId,
        ])->assertOk();

        // تلاشِ دومِ Start رویِ همان Message → ۴۰۹ (Instanceِ فعالِ تکراری)
        $this->as(self::USER_C)->postJson('/workflow/instances', [
            'definitionCode' => $code, 'entityType' => 'MESSAGE', 'entityId' => $messageId,
        ])->assertStatus(409)->assertJson(['success' => false]);

        $this->assertTrue($this->messageExists($messageId), 'شکستِ Startِ دوم نباید Messageِ اصلی را حذف/تغییر دهد.');
        $this->assertSame(
            'پیامِ تستِ Start Workflow',
            DB::selectOne('SELECT Subject FROM dbo.Messages WHERE MessageID = ?', [$messageId])->Subject
        );
    }
}
