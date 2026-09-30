<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Dto\TaskActionRequest;
use App\Services\Workflow\Support\WorkflowStore;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «انجام‌دهندهٔ واقعیِ مرحله» در برابرِ «Assigneeِ اولیه/واجدِ شرایط» — sp_Wf_GetInstance.ActedAssigneeNames
 * در مقابلِ AssigneeNames (بدون‌تغییر).
 *
 * عمداً از AssigneeType=USER با کاربرانِ واقعیِ موجود استفاده می‌شود (نه POSITION) تا هیچ Positionِ
 * واقعی/UserPositions لمس نشود؛ سناریو یکسان است چون ActedAssigneeNames فقط رویِ
 * WorkflowTaskAssignees.Decision فیلتر می‌کند، مستقل از AssigneeType.
 */
class WorkflowInstanceActedAssigneesTest extends TestCase
{
    use DatabaseTransactions;

    private const STARTER = 2;
    private const U_A = 3;
    private const U_B = 4;
    private const U_C = 14;

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private WorkflowStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
        $this->store = $this->app->make(WorkflowStore::class);
    }

    /** START → REVIEW(APPROVAL, Assignments=USERها) → END_OK/END_NO. */
    private function publish(array $userIds, string $policy, ?int $required = null): int
    {
        $code = 'ACTED_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);

        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'assignPolicy' => $policy, 'requiredApprovals' => $required, 'allowDelegation' => false, 'sortOrder' => 1],
                ['code' => 'END_OK', 'name' => 'تأیید', 'stepType' => 'END', 'sortOrder' => 2],
                ['code' => 'END_NO', 'name' => 'رد', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'REVIEW', 'code' => 'REJECT', 'kind' => 'REJECT', 'label' => 'رد', 'sortOrder' => 1],
            ],
            'assignments' => array_map(fn ($u) => ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => $u], $userIds),
            'transitions' => [
                ['code' => 'T_START', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T_OK', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END_OK', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T_NO', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END_NO', 'triggerActionCode' => 'REJECT'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        return $definitionId;
    }

    private function startInstance(int $definitionId): int
    {
        $letterMsg = DB::selectOne(
            "EXEC sp_InsertMessage @MessageTypeID = 1, @msgPriorityID = 1, @Subject = N'تست', @MessageText = NULL,
                @RecipientType = 1, @RecipientUserIDs = ?, @CopyUserIDs = NULL, @CopyDescription = NULL,
                @SenderUserID = ?, @Year = 1405, @CreateUser = ?, @DueDate = NULL",
            [(string) self::U_A, self::STARTER, self::STARTER]
        );
        $messageId = (int) $letterMsg->NewMessageID;

        $result = $this->engine->start(new StartWorkflowRequest(
            entityType: 'MESSAGE', entityId: $messageId, startedByUserId: self::STARTER, definitionId: $definitionId, context: [],
        ));

        return $result->instanceId;
    }

    private function reviewTask(int $instanceId): object
    {
        $data = $this->store->getInstance($instanceId);
        $task = collect($data['tasks'])->firstWhere('StepName', 'بررسی');
        $this->assertNotNull($task, 'تسکِ «بررسی» باید در Instance موجود باشد.');

        return $task;
    }

    /* ============================ سناریوها ============================ */

    /** ۱) ANY با دو Assignee که فقط یکی اقدام کرده → Acted فقط همان یک نفر. */
    public function test_any_with_two_assignees_only_one_acted(): void
    {
        $defId = $this->publish([self::U_A, self::U_B], 'ANY');
        $instanceId = $this->startInstance($defId);
        $task = $this->reviewTask($instanceId);

        $this->assertEqualsCanonicalizing([self::U_A, self::U_B], $this->assigneeIds($instanceId));

        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_A, actionCode: 'APPROVE', comment: null));

        $after = $this->reviewTask($instanceId);
        $this->assertSame('علی باج مالو', $after->ActedAssigneeNames);
        $this->assertStringContainsString('علی باج مالو', $after->AssigneeNames);
        $this->assertStringContainsString('محسن باج مالو', $after->AssigneeNames, 'AssigneeNamesِ اولیه باید بدونِ تغییر هر دو را نشان دهد.');
    }

    /** ۲) ANY قبلِ هر اقدام → Acted خالی/NULL، بدونِ نامِ ساختگی. */
    public function test_any_before_any_action_acted_is_empty(): void
    {
        $defId = $this->publish([self::U_A, self::U_B], 'ANY');
        $instanceId = $this->startInstance($defId);

        $task = $this->reviewTask($instanceId);
        $this->assertNull($task->ActedAssigneeNames);
        $this->assertNotNull($task->AssigneeNames, 'AssigneeNamesِ اولیه باید همچنان پر باشد.');
    }

    /** ۳) ALL با چند انجام‌دهنده → همهٔ کسانی که واقعاً Decision دارند در Acted باشند. */
    public function test_all_policy_acted_grows_as_each_assignee_decides(): void
    {
        $defId = $this->publish([self::U_A, self::U_B, self::U_C], 'ALL');
        $instanceId = $this->startInstance($defId);
        $task = $this->reviewTask($instanceId);

        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_A, actionCode: 'APPROVE', comment: null));
        $mid = $this->reviewTask($instanceId);
        $this->assertSame('علی باج مالو', $mid->ActedAssigneeNames, 'فقط کسی که تا الان اقدام کرده باید در Acted باشد.');

        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_B, actionCode: 'APPROVE', comment: null));
        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_C, actionCode: 'APPROVE', comment: null));

        // با explode رویِ جداکنندهٔ فارسی کار نمی‌کنیم (Driverِ sqlsrv جداکنندهٔ چندبایتی را در رفت‌وبرگشت
        // به‌گونه‌ای بازمی‌گرداند که تطبیقِ بایت‌به‌بایت در PHP شکننده است)؛ به‌جایش هر سه نام باید حاضر باشند.
        $data = $this->store->getInstance($instanceId);
        $completedTask = collect($data['tasks'])->firstWhere('StepName', 'بررسی');
        foreach (['علی باج مالو', 'محسن باج مالو', 'کاربر دمو'] as $name) {
            $this->assertStringContainsString($name, $completedTask->ActedAssigneeNames);
        }
    }

    /** ۴) N_OF_M: فقط کسانی که واقعاً Decision داده‌اند در Acted باشند (نه هرکسی که Assignee بوده). */
    public function test_n_of_m_only_actual_deciders_in_acted(): void
    {
        $defId = $this->publish([self::U_A, self::U_B, self::U_C], 'N_OF_M', required: 2);
        $instanceId = $this->startInstance($defId);
        $task = $this->reviewTask($instanceId);

        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_A, actionCode: 'APPROVE', comment: null));
        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_B, actionCode: 'APPROVE', comment: null));

        // با ۲ تأیید از ۳، N_OF_M(2) بسته می‌شود؛ کاربرِ سوم (دمو) هرگز اقدام نکرد.
        $data = $this->store->getInstance($instanceId);
        $completedTask = collect($data['tasks'])->firstWhere('StepName', 'بررسی');
        $this->assertStringContainsString('علی باج مالو', $completedTask->ActedAssigneeNames);
        $this->assertStringContainsString('محسن باج مالو', $completedTask->ActedAssigneeNames);
        $this->assertStringNotContainsString('کاربر دمو', $completedTask->ActedAssigneeNames);
    }

    /**
     * ۵) Assigneeای که به‌علتِ ANY/N_OF_M دیگر «فعال» نیست ولی Decision ندارد (مثلِ «کاربر دمو» در
     * سناریوی واقعیِ WF101/TASK_2) نباید در Acted بیاید — مستقل از IsActive، فقط بر اساسِ Decision.
     */
    public function test_assignee_without_decision_excluded_from_acted_regardless_of_active_flag(): void
    {
        $defId = $this->publish([self::U_A, self::U_B, self::U_C], 'ANY');
        $instanceId = $this->startInstance($defId);
        $task = $this->reviewTask($instanceId);

        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_A, actionCode: 'APPROVE', comment: null));

        // دقیقاً هم‌الگو با WF101/TASK_2 واقعی: ردیف‌هایِ U_B و U_C بدونِ Decision باقی می‌مانند.
        $rows = DB::select('SELECT UserID, Decision, IsActive FROM WorkflowTaskAssignees WHERE StepInstanceID = ?', [$task->StepInstanceID]);
        $byUser = collect($rows)->keyBy('UserID');
        $this->assertSame('APPROVED', $byUser[self::U_A]->Decision);
        $this->assertNull($byUser[self::U_B]->Decision);
        $this->assertNull($byUser[self::U_C]->Decision);

        $after = $this->reviewTask($instanceId);
        $this->assertSame('علی باج مالو', $after->ActedAssigneeNames);
    }

    /** ۶) تاریخچهٔ فرایند (WorkflowHistory) دست‌نخورده و درست — این تغییر فقط رویِ Result-Setِ Taskها است. */
    public function test_workflow_history_unaffected(): void
    {
        $defId = $this->publish([self::U_A, self::U_B], 'ANY');
        $instanceId = $this->startInstance($defId);
        $task = $this->reviewTask($instanceId);
        $this->engine->performAction(new TaskActionRequest(messageId: (int) $task->MessageID, userId: self::U_A, actionCode: 'APPROVE', comment: null));

        $data = $this->store->getInstance($instanceId);
        $history = $this->store->getInstanceHistory($instanceId);
        $codes = array_map(fn ($h) => $h->EventCode, $history);
        foreach (['INSTANCE_STARTED', 'STEP_ENTERED', 'TASK_CREATED', 'TASK_DECISION', 'TASK_COMPLETED', 'INSTANCE_COMPLETED'] as $expected) {
            $this->assertContains($expected, $codes);
        }
        $decision = collect($history)->firstWhere('EventCode', 'TASK_DECISION');
        $this->assertSame(self::U_A, (int) $decision->ActorUserID);
        $this->assertSame('COMPLETED', $data['instance']->Status);
    }

    /** ۷) Instanceِ واقعیِ قبلاً‌ساخته‌شده (با Decision موجود) درست نمایش داده می‌شود — رگرسیونِ زندهٔ WF101. */
    public function test_existing_wf101_instance_still_reflects_correct_actor(): void
    {
        $instance = DB::selectOne(
            "SELECT TOP 1 wi.InstanceID FROM WorkflowInstances wi
             JOIN WorkflowDefinitions wd ON wd.DefinitionID = wi.DefinitionID
             WHERE wd.Code = 'WF101' AND wi.StartedByUserID = 2 ORDER BY wi.StartedAt DESC"
        );
        if ($instance === null) {
            $this->markTestSkipped('نمونهٔ زندهٔ WF101 در این محیط موجود نیست.');
        }

        $data = $this->store->getInstance((int) $instance->InstanceID);
        $step2 = collect($data['tasks'])->firstWhere('StepName', 'ثبت در سیستم توسط کارشناس اداری');
        if ($step2 === null) {
            $this->markTestSkipped('مرحلهٔ TASK_2 در این Instance یافت نشد.');
        }

        $this->assertSame('علی باج مالو', $step2->ActedAssigneeNames);
        $this->assertStringContainsString('کاربر دمو', $step2->AssigneeNames, 'AssigneeNamesِ اولیه باید همچنان هر دو دارندهٔ Position را نشان دهد.');
        $this->assertStringNotContainsString('کاربر دمو', $step2->ActedAssigneeNames ?? '', 'ActedAssigneeNames نباید شاملِ کسی باشد که اقدام نکرده.');
    }

    /** ۸) Instanceِ در‌حالِ‌انجام بدونِ هیچ Decision — بدونِ Regression (ActedAssigneeNames=NULL، AssigneeNames پر). */
    public function test_running_instance_without_any_decision_no_regression(): void
    {
        $defId = $this->publish([self::U_A, self::U_B], 'ALL');
        $instanceId = $this->startInstance($defId);

        $task = $this->reviewTask($instanceId);
        $this->assertNull($task->ActedAssigneeNames);
        $this->assertStringContainsString('علی باج مالو', $task->AssigneeNames);
        $this->assertStringContainsString('محسن باج مالو', $task->AssigneeNames);

        $data = $this->store->getInstance($instanceId);
        $this->assertSame('RUNNING', $data['instance']->Status);
    }

    private function assigneeIds(int $instanceId): array
    {
        $data = $this->store->getInstance($instanceId);
        $task = collect($data['tasks'])->firstWhere('StepName', 'بررسی');
        $rows = DB::select('SELECT UserID FROM WorkflowTaskAssignees WHERE StepInstanceID = ?', [$task->StepInstanceID]);

        return array_map(fn ($r) => (int) $r->UserID, $rows);
    }
}
