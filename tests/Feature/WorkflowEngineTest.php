<?php

namespace Tests\Feature;

use App\Services\Workflow\Dto\EngineResult;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Dto\TaskActionRequest;
use App\Services\Workflow\Exceptions\WorkflowConcurrencyException;
use App\Services\Workflow\Exceptions\WorkflowException;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * تستِ یکپارچهٔ موتورِ فرایند روی SQL Server واقعی.
 * هر تست داخلِ یک تراکنش اجرا و در پایان Rollback می‌شود (DatabaseTransactions).
 *
 * دادهٔ واقعیِ استفاده‌شده (بدونِ ساختِ Fixture جدید):
 *   کاربران فعال: 2 (مهدی، مدیر IT/واحد ۱۰)، 3 (علی، کارشناس واحد ۷)،
 *                 4 (محسن، مدیرِ واحد ۷)، 14 (کاربر دمو)
 *   نقش ۴ «کاربران اتوماسیون» → کاربران 3 و 4
 *   واحد ۷ «منابع انسانی» → کاربران 3 (سمت ۲) و 4 (سمت ۴، IsUnitManager)
 *   واحد ۱۰ «فناوری اطلاعات» → کاربر 2 (سمت ۳، IsUnitManager)
 */
class WorkflowEngineTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER_A = 2;   // مهدی باج مالو
    private const USER_B = 14;  // کاربر دمو
    private const USER_C = 3;   // علی باج مالو  (کارشناسِ واحد ۷)
    private const USER_MGR7 = 4; // محسن باج مالو (مدیرِ واحد ۷)
    private const ROLE_AUTOMATION = 4;
    private const UNIT_HR = 7;
    private const UNIT_IT = 10;
    private const POSITION_HR_EXPERT = 2; // کارشناس استخدام → کاربر ۳

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private WorkflowQueryService $query;
    private WorkflowStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
        $this->query = $this->app->make(WorkflowQueryService::class);
        $this->store = $this->app->make(WorkflowStore::class);
    }

    /* ============================ سازنده‌ها ============================ */

    /** انتشارِ یک نسخهٔ فرایند از روی گرافِ داده‌شده. @return array{0:int,1:int,2:string} */
    private function publishGraph(array $graph, ?string $code = null): array
    {
        $code ??= 'T_' . strtoupper(bin2hex(random_bytes(4)));

        $def = $this->defs->save(['code' => $code, 'name' => 'تست ' . $code, 'entityType' => 'TEST_ENTITY'], self::USER_A);
        $definitionId = (int) $def->DefinitionID;

        $ver = $this->defs->createDraft($definitionId, self::USER_A);
        $versionId = (int) $ver->VersionID;

        $this->defs->saveGraph($versionId, $graph, self::USER_A);
        $this->defs->publish($versionId, self::USER_A);

        return [$definitionId, $versionId, $code];
    }

    /** گرافِ ساده: START → REVIEW(APPROVAL) → END_OK / END_NO */
    private function approvalGraph(
        array $assignments,
        string $policy = 'ANY',
        ?int $required = null,
        string $stepType = 'APPROVAL',
        ?bool $allowForward = null,
        ?int $forwardMax = null,
        ?bool $allowDelegation = null
    ): array {
        $review = [
            'code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => $stepType,
            'assignPolicy' => $policy, 'requiredApprovals' => $required, 'sortOrder' => 1,
        ];
        if ($allowForward !== null) {
            $review['allowForward'] = $allowForward;
        }
        if ($forwardMax !== null) {
            $review['forwardMax'] = $forwardMax;
        }
        if ($allowDelegation !== null) {
            $review['allowDelegation'] = $allowDelegation;
        }

        return [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                $review,
                ['code' => 'END_OK', 'name' => 'پایانِ تأیید', 'stepType' => 'END', 'sortOrder' => 2],
                ['code' => 'END_NO', 'name' => 'پایانِ رد', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'REVIEW', 'code' => 'REJECT', 'kind' => 'REJECT', 'label' => 'رد', 'sortOrder' => 1],
            ],
            'assignments' => array_map(
                fn ($a) => ['stepCode' => 'REVIEW'] + $a,
                $assignments
            ),
            'transitions' => [
                ['code' => 'T_START', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T_OK', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END_OK', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T_NO', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END_NO', 'triggerActionCode' => 'REJECT'],
            ],
        ];
    }

    private function user(int $id): array
    {
        return ['assigneeType' => 'USER', 'refId' => $id];
    }

    private function startWf(string $code, int $entityId, array $opts = []): EngineResult
    {
        return $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY',
            entityId: $entityId,
            startedByUserId: $opts['startedBy'] ?? self::USER_A,
            definitionCode: $code,
            entityOwnerUserId: $opts['entityOwnerUserId'] ?? null,
            entityUnitId: $opts['entityUnitId'] ?? null,
        ));
    }

    /** «تسکِ» بازِ یک Instance = پیامِ کارتابلیِ StepInstanceِ ACTIVE. */
    private function openTask(int $instanceId): object
    {
        $open = array_values(array_filter(
            $this->query->instance($instanceId)['tasks'],
            fn ($t) => $t->Status === 'ACTIVE'
        ));
        $this->assertNotEmpty($open, 'باید یک تسکِ باز وجود داشته باشد.');

        return $this->taskFor((int) $open[0]->MessageID);
    }

    /** جزئیاتِ «تسکِ مرحله» (تازه‌سازی‌شده) بر مبنای MessageID. */
    private function taskFor(int $messageId): object
    {
        return $this->query->stepTaskDetail($messageId)['task'];
    }

    private function rvHex(object $task): string
    {
        return '0x' . bin2hex($task->RowVersion);
    }

    private function act(object $task, int $userId, string $actionCode, ?string $comment = null): EngineResult
    {
        return $this->engine->performAction(new TaskActionRequest(
            messageId: (int) $task->MessageID,
            userId: $userId,
            actionCode: $actionCode,
            comment: $comment,
            expectedRowVersion: $this->rvHex($task),
        ));
    }

    /** @return int[] شناسه‌های کاربرانِ انجام‌دهندهٔ فعالِ یک تسک (بر مبنای MessageID) */
    private function assigneeIds(int $messageId): array
    {
        return collect($this->query->stepTaskDetail($messageId)['assignees'])
            ->where('IsActive', 1)
            ->pluck('UserID')
            ->map(fn ($v) => (int) $v)
            ->sort()->values()->all();
    }

    /** وضعیتِ کارتابلِ *شخصیِ* یک assignee روی این تسک. */
    private function personalStatus(int $messageId, int $userId): ?string
    {
        $row = collect($this->query->stepTaskDetail($messageId)['assignees'])->firstWhere('UserID', $userId);

        return $row->PersonalStatusName ?? null;
    }

    /** آیا این پیام در کارتابلِ دریافتیِ کاربر دیده می‌شود؟ (مسیرِ موجودِ sp_GetMessages) */
    private function inCartable(int $userId, int $messageId): bool
    {
        $rows = \Illuminate\Support\Facades\DB::select(
            'EXEC sp_GetMessages @UserID = ?, @Mode = 1, @IsArchive = 0, @SearchText = NULL, @MessageTypeID = NULL, @MessageStatusID = NULL, @FromDate = NULL, @ToDate = NULL, @msgPriorityID = NULL',
            [$userId]
        );

        return collect($rows)->contains(fn ($r) => (int) $r->MessageID === $messageId);
    }

    private function historyCodes(int $instanceId): array
    {
        return collect($this->query->instance($instanceId)['history'])->pluck('EventCode')->all();
    }

    /* ==================================================================== */
    /*  گروه ۰ — تست‌های پایه (از قبل موجود)                                  */
    /* ==================================================================== */

    public function test_full_approval_lifecycle_completes_the_instance(): void
    {
        [, $vid, $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $res = $this->startWf($code, 5001);
        $instanceId = $res->instanceId;

        // اتصالِ صحیحِ Instance به Definition/Version
        $inst = $this->query->instance($instanceId)['instance'];
        $this->assertSame($vid, (int) $inst->VersionID);

        $task = $this->openTask($instanceId);
        $this->assertSame('ACTIVE', $task->StepStatus);

        $done = $this->act($task, self::USER_A, 'APPROVE');
        $this->assertSame('COMPLETED', $done->instanceStatus);
        $this->assertSame('END_OK', $done->enteredStepCode);
        $this->assertSame('COMPLETED', $this->query->instance($instanceId)['instance']->Status);
    }

    public function test_rejection_routes_to_reject_end(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5002)->instanceId;

        $task = $this->openTask($instanceId);
        $res = $this->act($task, self::USER_A, 'REJECT', 'ناقص است');

        $this->assertSame('COMPLETED', $res->instanceStatus);
        $this->assertSame('END_NO', $res->enteredStepCode);

        $d = $this->query->stepTaskDetail((int) $task->MessageID);
        $this->assertSame('COMPLETED', $d['task']->StepStatus);
        $this->assertSame('REJECTED', collect($d['assignees'])->firstWhere('UserID', self::USER_A)->Decision);
        // نتیجهٔ رد ⇒ کارتابل «انجام نخواهد شد» (نه «انجام شده») و از کارتابل خارج
        $this->assertSame('انجام نخواهد شد', $this->personalStatus((int) $task->MessageID, self::USER_A));
        $this->assertFalse($this->inCartable(self::USER_A, (int) $task->MessageID));
    }

    public function test_return_marks_all_assignees_wont_do(): void
    {
        [, , $code] = $this->publishGraph([
            'steps' => [
                ['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'ب', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ALL', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پ', 'stepType' => 'END', 'sortOrder' => 2],
                ['code' => 'BACK', 'name' => 'بازگشت', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
                ['stepCode' => 'REVIEW', 'code' => 'RETURN', 'kind' => 'RETURN', 'label' => 'عودت'],
            ],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_A],
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_B],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'BACK', 'triggerActionCode' => 'RETURN'],
            ],
        ]);
        $instanceId = $this->startWf($code, 5010)->instanceId;
        $task = $this->openTask($instanceId);

        // A تأیید می‌کند (مرحله زیرِ ALL هنوز باز)، سپس B عودت می‌دهد ⇒ مرحله با RETURN بسته می‌شود
        $this->assertSame('RUNNING', $this->act($task, self::USER_A, 'APPROVE')->instanceStatus);
        $task2 = $this->taskFor((int) $task->MessageID);
        $this->act($task2, self::USER_B, 'RETURN');

        // نتیجهٔ RETURNED ⇒ هیچ‌کس «انجام شده» نمی‌گیرد — حتی A که تأیید کرده بود
        $this->assertSame('انجام نخواهد شد', $this->personalStatus((int) $task->MessageID, self::USER_A));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus((int) $task->MessageID, self::USER_B));
    }

    public function test_duplicate_active_instance_is_blocked(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $this->startWf($code, 5003);

        $this->expectException(WorkflowStateException::class);
        $this->startWf($code, 5003);
    }

    public function test_all_policy_waits_for_every_assignee(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_B)],
            policy: 'ALL'
        ));
        $instanceId = $this->startWf($code, 5004)->instanceId;

        $task = $this->openTask($instanceId);
        $this->assertSame('RUNNING', $this->act($task, self::USER_A, 'APPROVE')->instanceStatus);

        $task2 = $this->taskFor((int) $task->MessageID);
        $this->assertSame('COMPLETED', $this->act($task2, self::USER_B, 'APPROVE')->instanceStatus);

        // ALL با نتیجهٔ APPROVED ⇒ همهٔ تأییدکنندگان «انجام شده» ، هیچ‌کس «انجام نخواهد شد»
        $this->assertSame('انجام شده', $this->personalStatus((int) $task->MessageID, self::USER_A));
        $this->assertSame('انجام شده', $this->personalStatus((int) $task->MessageID, self::USER_B));
    }

    public function test_any_policy_actor_done_others_wont_do(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_B), $this->user(self::USER_C)],
            policy: 'ANY'
        ));
        $instanceId = $this->startWf($code, 5006)->instanceId;
        $task = $this->openTask($instanceId);

        $this->assertSame('COMPLETED', $this->act($task, self::USER_A, 'APPROVE')->instanceStatus);

        // اقدام‌کننده → «انجام شده» ، بقیه که منتظر بودند → «انجام نخواهد شد»
        $this->assertSame('انجام شده', $this->personalStatus((int) $task->MessageID, self::USER_A));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus((int) $task->MessageID, self::USER_B));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus((int) $task->MessageID, self::USER_C));
        // هیچ‌کدام دیگر در کارتابل نیستند
        foreach ([self::USER_A, self::USER_B, self::USER_C] as $u) {
            $this->assertFalse($this->inCartable($u, (int) $task->MessageID));
        }
    }

    public function test_n_of_m_approvers_done_rest_wont_do(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C), $this->user(self::USER_B)],
            policy: 'N_OF_M',
            required: 2
        ));
        $instanceId = $this->startWf($code, 5007)->instanceId;
        $task = $this->openTask($instanceId);

        $this->assertSame('RUNNING', $this->act($task, self::USER_A, 'APPROVE')->instanceStatus);
        $task2 = $this->taskFor((int) $task->MessageID);
        $this->assertSame('COMPLETED', $this->act($task2, self::USER_C, 'APPROVE')->instanceStatus);

        // دو تأییدکننده → «انجام شده» ، نفرِ سومِ باقی‌مانده → «انجام نخواهد شد»
        $this->assertSame('انجام شده', $this->personalStatus((int) $task->MessageID, self::USER_A));
        $this->assertSame('انجام شده', $this->personalStatus((int) $task->MessageID, self::USER_C));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus((int) $task->MessageID, self::USER_B));
    }

    public function test_stale_rowversion_raises_concurrency_error(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5005)->instanceId;
        $task = $this->openTask($instanceId);

        $this->expectException(WorkflowConcurrencyException::class);
        $this->engine->performAction(new TaskActionRequest(
            messageId: (int) $task->MessageID,
            userId: self::USER_A,
            actionCode: 'APPROVE',
            expectedRowVersion: '0x0000000000000001',
        ));
    }

    public function test_publish_is_blocked_when_graph_is_invalid(): void
    {
        $def = $this->defs->save(['code' => 'TEST_BAD_' . uniqid(), 'name' => 'بد', 'entityType' => 'TEST_ENTITY'], self::USER_A);
        $ver = $this->defs->createDraft((int) $def->DefinitionID, self::USER_A);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0]],
        ], self::USER_A);

        $this->expectException(WorkflowValidationException::class);
        $this->defs->publish((int) $ver->VersionID, self::USER_A);
    }

    public function test_non_assignee_cannot_act(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5006)->instanceId;
        $task = $this->openTask($instanceId);

        $this->expectException(WorkflowStateException::class);
        $this->act($task, self::USER_B, 'APPROVE');
    }

    public function test_history_is_recorded_for_lifecycle(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5007)->instanceId;
        $task = $this->openTask($instanceId);
        $this->act($task, self::USER_A, 'APPROVE');

        $events = $this->historyCodes($instanceId);
        $this->assertContains('INSTANCE_STARTED', $events);
        $this->assertContains('TASK_CREATED', $events);
        $this->assertContains('TASK_DECISION', $events);
        $this->assertContains('TASK_COMPLETED', $events);
        $this->assertContains('INSTANCE_COMPLETED', $events);
    }

    /* ==================================================================== */
    /*  گروه ۱ — USER_TASK                                                   */
    /* ==================================================================== */

    private function userTaskToEndGraph(): array
    {
        return [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'WORK', 'name' => 'انجامِ کار', 'stepType' => 'USER_TASK', 'assignPolicy' => 'ANY', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [
                ['stepCode' => 'WORK', 'code' => 'SUBMIT', 'kind' => 'COMPLETE', 'label' => 'ثبت و پایان', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'WORK', 'assigneeType' => 'USER', 'refId' => self::USER_A],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'WORK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'WORK', 'toStepCode' => 'END', 'triggerActionCode' => 'SUBMIT'],
            ],
        ];
    }

    public function test_user_task_step_runs_and_completes_to_end(): void
    {
        [, , $code] = $this->publishGraph($this->userTaskToEndGraph());
        $instanceId = $this->startWf($code, 5101)->instanceId;

        $task = $this->openTask($instanceId);
        // مطمئن شویم واقعاً یک مرحلهٔ USER_TASK اجرا شده (نه شاخهٔ مشترکِ APPROVAL)
        $this->assertSame('USER_TASK', $task->StepType);

        $res = $this->act($task, self::USER_A, 'SUBMIT');
        $this->assertSame('COMPLETED', $res->instanceStatus);
        $this->assertSame('END', $res->enteredStepCode);

        $steps = collect($this->query->instance($instanceId)['steps']);
        $work = $steps->firstWhere('StepCode', 'WORK');
        $this->assertSame('USER_TASK', $work->StepType);
        $this->assertSame('COMPLETED', $work->Status);
    }

    public function test_user_task_then_approval_chain(): void
    {
        [, , $code] = $this->publishGraph([
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'WORK', 'name' => 'انجامِ کار', 'stepType' => 'USER_TASK', 'sortOrder' => 1],
                ['code' => 'REVIEW', 'name' => 'تأییدِ مدیر', 'stepType' => 'APPROVAL', 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'WORK', 'code' => 'SUBMIT', 'kind' => 'COMPLETE', 'label' => 'ثبت', 'sortOrder' => 0],
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'WORK', 'assigneeType' => 'USER', 'refId' => self::USER_A],
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_B],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'WORK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'WORK', 'toStepCode' => 'REVIEW', 'triggerActionCode' => 'SUBMIT'],
                ['code' => 'T3', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ]);
        $instanceId = $this->startWf($code, 5102)->instanceId;

        // مرحلهٔ ۱: USER_TASK
        $work = $this->openTask($instanceId);
        $this->assertSame('USER_TASK', $work->StepType);
        $afterWork = $this->act($work, self::USER_A, 'SUBMIT');
        $this->assertSame('RUNNING', $afterWork->instanceStatus);
        $this->assertSame('REVIEW', $afterWork->enteredStepCode);

        // مرحلهٔ ۲: APPROVAL
        $review = $this->openTask($instanceId);
        $this->assertSame('APPROVAL', $review->StepType);
        $afterReview = $this->act($review, self::USER_B, 'APPROVE');
        $this->assertSame('COMPLETED', $afterReview->instanceStatus);

        $steps = collect($this->query->instance($instanceId)['steps'])->keyBy('StepCode');
        $this->assertSame('USER_TASK', $steps['WORK']->StepType);
        $this->assertSame('COMPLETED', $steps['WORK']->Status);
        $this->assertSame('APPROVAL', $steps['REVIEW']->StepType);
        $this->assertSame('COMPLETED', $steps['REVIEW']->Status);
    }

    /* ==================================================================== */
    /*  گروه ۲ — Version Locking                                             */
    /* ==================================================================== */

    public function test_instance_stays_on_its_original_version_after_new_publish(): void
    {
        // نسخهٔ ۱ — START → REVIEW → END_OK/END_NO  (تأیید ⇒ پایان)
        [$definitionId, $v1, $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));

        $instanceId = $this->startWf($code, 5201)->instanceId;
        $this->assertSame($v1, (int) $this->query->instance($instanceId)['instance']->VersionID);

        // نسخهٔ ۲ — START → REVIEW → REVIEW2 → END  (تأیید ⇒ مرحلهٔ دومِ تأیید، نه پایان)
        $draft2 = $this->defs->createDraft($definitionId, self::USER_A);
        $v2 = (int) $draft2->VersionID;
        $this->defs->saveGraph($v2, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسیِ ۱', 'stepType' => 'APPROVAL', 'sortOrder' => 1],
                ['code' => 'REVIEW2', 'name' => 'بررسیِ ۲', 'stepType' => 'APPROVAL', 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'REVIEW2', 'code' => 'APPROVE2', 'kind' => 'APPROVE', 'label' => 'تأییدِ نهایی', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_A],
                ['stepCode' => 'REVIEW2', 'assigneeType' => 'USER', 'refId' => self::USER_A],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'REVIEW2', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'REVIEW2', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE2'],
            ],
        ], self::USER_A);
        $this->defs->publish($v2, self::USER_A);

        // تعریف اکنون نسخهٔ فعالش v2 است
        $activeNow = collect($this->defs->show($definitionId)['versions'])->firstWhere('Status', 'ACTIVE');
        $this->assertSame($v2, (int) $activeNow->VersionID);

        // اما Instanceِ قدیمی باید همچنان با v1 اجرا شود:
        $task = $this->openTask($instanceId);
        $res = $this->act($task, self::USER_A, 'APPROVE');

        // اگر v2 اعمال می‌شد، به REVIEW2 می‌رفت و RUNNING می‌ماند.
        $this->assertSame('COMPLETED', $res->instanceStatus);
        $this->assertSame('END_OK', $res->enteredStepCode);

        $inst = $this->query->instance($instanceId)['instance'];
        $this->assertSame($v1, (int) $inst->VersionID);
        $this->assertSame('COMPLETED', $inst->Status);

        // هیچ مرحله‌ای با کدِ نسخهٔ ۲ ("REVIEW2") در این Instance وجود ندارد
        $stepCodes = collect($this->query->instance($instanceId)['steps'])->pluck('StepCode')->all();
        $this->assertNotContains('REVIEW2', $stepCodes);
    }

    /* ==================================================================== */
    /*  گروه ۳ — Loop Guard                                                  */
    /* ==================================================================== */

    public function test_loop_guard_fails_the_instance_on_max_transitions(): void
    {
        config(['workflow.max_transitions_per_instance' => 3]);

        // START → REVIEW ؛ REVIEW --RETURN--> REVIEW (حلقه) ؛ REVIEW --APPROVE--> END
        [, , $code] = $this->publishGraph([
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'REVIEW', 'code' => 'RETURN', 'kind' => 'RETURN', 'label' => 'بازگشت', 'sortOrder' => 1],
            ],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_A],
            ],
            'transitions' => [
                ['code' => 'T_START', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T_LOOP', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'REVIEW', 'triggerActionCode' => 'RETURN'],
                ['code' => 'T_END', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ]);

        $instanceId = $this->startWf($code, 5301)->instanceId;
        $this->assertSame(1, (int) $this->query->instance($instanceId)['instance']->TransitionCount);

        // بازگشت‌های پیاپی — TransitionCount باید بالا برود
        $this->act($this->openTask($instanceId), self::USER_A, 'RETURN');
        $this->assertSame(2, (int) $this->query->instance($instanceId)['instance']->TransitionCount);

        $this->act($this->openTask($instanceId), self::USER_A, 'RETURN');
        $this->assertSame(3, (int) $this->query->instance($instanceId)['instance']->TransitionCount);

        // این یکی از سقف (۳) عبور می‌کند ⇒ FAILED
        $res = $this->act($this->openTask($instanceId), self::USER_A, 'RETURN');

        $this->assertSame('FAILED', $res->instanceStatus);

        $inst = $this->query->instance($instanceId)['instance'];
        $this->assertSame('FAILED', $inst->Status);
        $this->assertSame(4, (int) $inst->TransitionCount);
        $this->assertContains('INSTANCE_FAILED', $this->historyCodes($instanceId));

        // هیچ تسکِ بازی نباید باقی مانده باشد و حلقهٔ بی‌نهایتی رخ نداده (تست به پایان رسید)
        $open = array_filter(
            $this->query->instance($instanceId)['tasks'],
            fn ($t) => $t->Status === 'ACTIVE'
        );
        $this->assertEmpty($open);
    }

    /* ==================================================================== */
    /*  گروه ۴ — N_OF_M                                                      */
    /* ==================================================================== */

    public function test_n_of_m_policy_resolves_after_required_approvals(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C), $this->user(self::USER_B)],
            policy: 'N_OF_M',
            required: 2
        ));
        $instanceId = $this->startWf($code, 5401)->instanceId;

        $task = $this->openTask($instanceId);
        $this->assertSame([2, 3, 14], $this->assigneeIds((int) $task->MessageID));

        // نفر اول → هنوز تکمیل نشود
        $this->assertSame('RUNNING', $this->act($task, self::USER_A, 'APPROVE')->instanceStatus);

        // نفر دوم → تکمیل
        $task2 = $this->taskFor((int) $task->MessageID);
        $done = $this->act($task2, self::USER_C, 'APPROVE');
        $this->assertSame('COMPLETED', $done->instanceStatus);
        $this->assertSame('END_OK', $done->enteredStepCode);

        // نفر سوم → نباید Completion تکراری بسازد
        $task3 = $this->taskFor((int) $task->MessageID);
        try {
            $this->act($task3, self::USER_B, 'APPROVE');
            $this->fail('اقدامِ نفرِ سوم روی تسکِ بسته‌شده باید استثنا بدهد.');
        } catch (WorkflowStateException $e) {
            // انتظار می‌رود
        }

        $detail = $this->query->stepTaskDetail((int) $task->MessageID);
        $this->assertSame(2, (int) $detail['task']->ReceivedApprovals);
        $this->assertSame('COMPLETED', $detail['task']->StepStatus);

        $completed = collect($this->query->instance($instanceId)['history'])
            ->where('EventCode', 'TASK_COMPLETED')
            ->where('MessageID', (int) $task->MessageID)
            ->count();
        $this->assertSame(1, $completed);
        $this->assertSame('COMPLETED', $this->query->instance($instanceId)['instance']->Status);
    }

    /* ==================================================================== */
    /*  گروه ۵ — Assignment Resolution                                       */
    /* ==================================================================== */

    private function assigneesForType(array $assignment, array $startOpts = [], int $entityId = 5500): array
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$assignment]));
        $instanceId = $this->startWf($code, $entityId, $startOpts)->instanceId;
        $task = $this->openTask($instanceId);

        return $this->assigneeIds((int) $task->MessageID);
    }

    public function test_assignment_type_user(): void
    {
        $this->assertSame([self::USER_B], $this->assigneesForType($this->user(self::USER_B), entityId: 5501));
    }

    /**
     * Designer Phase 2 — «جانشین» (IsBackup=1) فقط Persist می‌شود؛ Runtime فعلاً هیچ
     * منطقی برایِ فعال‌سازیِ آن ندارد، پس نباید کنارِ انجام‌دهندهٔ اصلی Task بگیرد
     * (Guardِ WorkflowEngine::advance — فیلترِ IsBackup پیش از AssignmentResolver::resolve).
     */
    public function test_backup_assignee_is_excluded_from_resolved_assignees(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([
            ['assigneeType' => 'USER', 'refId' => self::USER_B, 'isBackup' => false],
            ['assigneeType' => 'USER', 'refId' => self::USER_C, 'isBackup' => true],
        ]));
        $instanceId = $this->startWf($code, 5509)->instanceId;
        $task = $this->openTask($instanceId);

        $this->assertSame([self::USER_B], $this->assigneeIds((int) $task->MessageID));
    }

    public function test_assignment_type_role(): void
    {
        // نقشِ ۴ «کاربران اتوماسیون» → کاربران ۳ و ۴
        $this->assertSame([3, 4], $this->assigneesForType(
            ['assigneeType' => 'ROLE', 'refId' => self::ROLE_AUTOMATION], entityId: 5502
        ));
    }

    public function test_assignment_type_position(): void
    {
        // سمتِ ۲ «کارشناس استخدام» → کاربر ۳
        $this->assertSame([3], $this->assigneesForType(
            ['assigneeType' => 'POSITION', 'refId' => self::POSITION_HR_EXPERT], entityId: 5503
        ));
    }

    public function test_assignment_type_unit(): void
    {
        // واحدِ ۷ «منابع انسانی» → کاربران ۳ و ۴
        $this->assertSame([3, 4], $this->assigneesForType(
            ['assigneeType' => 'UNIT', 'refId' => self::UNIT_HR], entityId: 5504
        ));
    }

    public function test_assignment_type_unit_manager(): void
    {
        // مدیرِ واحدِ ۷ → کاربر ۴
        $this->assertSame([self::USER_MGR7], $this->assigneesForType(
            ['assigneeType' => 'UNIT_MANAGER', 'refId' => self::UNIT_HR], entityId: 5505
        ));
    }

    public function test_assignment_type_direct_manager(): void
    {
        // آغازگر = کاربر ۳ (واحدِ ۷) ⇒ مدیرِ مستقیم = کاربر ۴
        $this->assertSame([self::USER_MGR7], $this->assigneesForType(
            ['assigneeType' => 'DIRECT_MANAGER'],
            startOpts: ['startedBy' => self::USER_C],
            entityId: 5506
        ));
    }

    public function test_assignment_type_initiator(): void
    {
        $this->assertSame([self::USER_C], $this->assigneesForType(
            ['assigneeType' => 'INITIATOR'],
            startOpts: ['startedBy' => self::USER_C],
            entityId: 5507
        ));
    }

    public function test_assignment_type_entity_owner_without_resolver_yields_no_assignee(): void
    {
        // NullEntityResolver مالک نمی‌دهد ⇒ مرحله بی‌انجام‌دهنده ⇒ شروع رد می‌شود
        [, , $code] = $this->publishGraph($this->approvalGraph([['assigneeType' => 'ENTITY_OWNER']]));

        $this->expectException(WorkflowValidationException::class);
        $this->startWf($code, 5508);
    }

    public function test_assignment_type_entity_owner_with_explicit_owner(): void
    {
        // قراردادِ EntityResolver حفظ می‌شود؛ مالک صریحاً در درخواستِ شروع می‌آید
        $this->assertSame([self::USER_C], $this->assigneesForType(
            ['assigneeType' => 'ENTITY_OWNER'],
            startOpts: ['entityOwnerUserId' => self::USER_C],
            entityId: 5509
        ));
    }

    /* ==================================================================== */
    /*  گروه ۶ — Idempotency                                                 */
    /* ==================================================================== */

    public function test_same_assignee_cannot_record_decision_twice(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_B)],
            policy: 'ALL'
        ));
        $instanceId = $this->startWf($code, 5601)->instanceId;
        $task = $this->openTask($instanceId);

        $this->assertSame('RUNNING', $this->act($task, self::USER_A, 'APPROVE')->instanceStatus);

        $decisionsAfterFirst = collect($this->query->instance($instanceId)['history'])->where('EventCode', 'TASK_DECISION')->count();
        $this->assertSame(1, $decisionsAfterFirst);

        // تلاشِ دومِ همان کاربر
        $task2 = $this->taskFor((int) $task->MessageID);
        try {
            $this->act($task2, self::USER_A, 'APPROVE');
            $this->fail('اقدامِ دومِ همان کاربر باید رد شود.');
        } catch (WorkflowStateException $e) {
            // انتظار می‌رود
        }

        // نه Decision تکراری، نه History تکراری، نه Task تکراری، نه تغییرِ شمارنده
        $detail = $this->query->stepTaskDetail((int) $task->MessageID);
        $this->assertSame(1, (int) $detail['task']->ReceivedApprovals);
        $this->assertSame(1, collect($this->query->instance($instanceId)['history'])->where('EventCode', 'TASK_DECISION')->count());
        $userADecisions = collect($detail['assignees'])->where('UserID', self::USER_A)->where('Decision', 'APPROVED')->count();
        $this->assertSame(1, $userADecisions);
        $this->assertCount(1, $this->query->instance($instanceId)['tasks']); // هیچ تسکِ جدیدی ساخته نشد
        $this->assertSame('RUNNING', $this->query->instance($instanceId)['instance']->Status);
    }

    public function test_completing_a_task_twice_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5602)->instanceId;
        $task = $this->openTask($instanceId);

        $stepInstanceId = (int) $task->StepInstanceID;
        $this->act($task, self::USER_A, 'APPROVE');

        $closed = $this->taskFor((int) $task->MessageID);
        $this->assertSame('COMPLETED', $closed->StepStatus);
        $completedAt = $closed->CompletedAt;

        // بستنِ مجددِ همان مرحله از طریقِ لایهٔ Store
        try {
            $this->store->completeStepTask(
                $stepInstanceId,
                '0x' . bin2hex($closed->RowVersion),
                'APPROVE',
                null,
                self::USER_A,
                'APPROVED'
            );
            $this->fail('بستنِ دومِ مرحله باید رد شود.');
        } catch (WorkflowConcurrencyException $e) {
            // انتظار می‌رود
        }

        $again = $this->taskFor((int) $task->MessageID);
        $this->assertEquals($completedAt, $again->CompletedAt); // بدونِ بازنویسی
        $this->assertSame(
            1,
            collect($this->query->instance($instanceId)['history'])
                ->where('EventCode', 'TASK_COMPLETED')->where('MessageID', (int) $task->MessageID)->count()
        );
    }

    public function test_completing_a_step_instance_twice_is_inert(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5603)->instanceId;
        $task = $this->openTask($instanceId);
        $this->act($task, self::USER_A, 'APPROVE');

        $reviewSi = collect($this->query->instance($instanceId)['steps'])
            ->firstWhere('StepCode', 'REVIEW');
        $this->assertSame('COMPLETED', $reviewSi->Status);
        $completedAt = $reviewSi->CompletedAt;
        $siCountBefore = count($this->query->instance($instanceId)['steps']);

        // تلاش برای بستنِ دوباره → رد می‌شود، بی‌اثر
        try {
            $this->store->completeStepInstance((int) $reviewSi->StepInstanceID, 'COMPLETED', null, null, self::USER_A);
            $this->fail('بستنِ دومِ StepInstance باید رد شود.');
        } catch (WorkflowException $e) {
            // انتظار می‌رود
        }

        $after = collect($this->query->instance($instanceId)['steps']);
        $reviewAfter = $after->firstWhere('StepCode', 'REVIEW');
        $this->assertEquals($completedAt, $reviewAfter->CompletedAt);       // بدونِ بازنویسی
        $this->assertCount($siCountBefore, $after->all());                  // هیچ StepInstance تکراری
    }

    /* ==================================================================== */
    /*  گروه ۷ — Mark First Opened                                           */
    /* ==================================================================== */

    public function test_mark_first_opened_sets_timestamp_once(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5701)->instanceId;

        $messageId = (int) $this->query->instance($instanceId)['tasks'][0]->MessageID;

        $before = $this->query->stepTaskDetail($messageId)['task']; // بدونِ opening user
        $this->assertSame('ACTIVE', $before->StepStatus);
        $this->assertNull($before->FirstOpenedAt);

        // بازکردنِ تسک توسطِ کاربرِ انجام‌دهنده
        $this->query->stepTaskDetail($messageId, self::USER_A);

        $opened = $this->query->stepTaskDetail($messageId)['task'];
        $this->assertNotNull($opened->FirstOpenedAt);
        $firstStamp = $opened->FirstOpenedAt;

        // بازکردنِ مجدد (همان کاربر و کاربرِ غیرِ انجام‌دهنده) نباید FirstOpenedAt را بازنویسی کند
        $this->query->stepTaskDetail($messageId, self::USER_A);
        $this->query->stepTaskDetail($messageId, self::USER_B);

        $reopened = $this->query->stepTaskDetail($messageId)['task'];
        $this->assertEquals($firstStamp, $reopened->FirstOpenedAt);
    }

    /* ==================================================================== */
    /*  گروه ۸ — History / Transition                                        */
    /* ==================================================================== */

    public function test_transition_taken_events_are_recorded(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5801)->instanceId;
        $task = $this->openTask($instanceId);
        $this->act($task, self::USER_A, 'APPROVE');

        $transitions = collect($this->query->instance($instanceId)['history'])
            ->where('EventCode', 'TRANSITION_TAKEN');

        // دستِ‌کم دو گذار: START→REVIEW و REVIEW→END_OK
        $this->assertGreaterThanOrEqual(2, $transitions->count());

        $first = $transitions->first();
        $detail = json_decode($first->DetailJson ?? '{}', true);
        $this->assertArrayHasKey('fromStepId', $detail);
        $this->assertArrayHasKey('toStepId', $detail);
        $this->assertArrayHasKey('transitionCode', $detail);
    }

    public function test_task_decision_event_is_recorded_explicitly(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_B)],
            policy: 'ALL'
        ));
        $instanceId = $this->startWf($code, 5802)->instanceId;
        $task = $this->openTask($instanceId);

        $this->act($task, self::USER_A, 'APPROVE');

        $decision = collect($this->query->instance($instanceId)['history'])
            ->firstWhere('EventCode', 'TASK_DECISION');
        $this->assertNotNull($decision);
        $this->assertSame((int) $task->MessageID, (int) $decision->MessageID);

        $detail = json_decode($decision->DetailJson ?? '{}', true);
        $this->assertSame('APPROVE', $detail['actionCode'] ?? null);
        $this->assertSame('APPROVED', $detail['decision'] ?? null);
    }

    /* ==================================================================== */
    /*  گروه ۹ — چرخهٔ حیاتِ Instance (Cancel / Suspend / Resume)            */
    /* ==================================================================== */

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

    public function test_cancel_running_instance_sets_status_closes_tasks_skips_steps(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5901)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $res = $this->engine->cancel($instanceId, self::USER_A, 'دیگر لازم نیست');
        $this->assertSame('CANCELLED', $res->instanceStatus);
        $this->assertSame('CANCELLED', $this->instanceStatus($instanceId));

        $inst = $this->query->instance($instanceId);
        // StepInstance مربوط SKIP شده و پیامِ کارتابل با «انجام نخواهد شد» بسته شده (نه «انجام شده»)
        $this->assertSame('SKIPPED', collect($inst['tasks'])->firstWhere('MessageID', $messageId)->Status);
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
        $this->assertFalse($this->inCartable(self::USER_A, $messageId));
        $this->assertSame(0, $this->openTaskCount($instanceId));
        // مرحلهٔ فعال SKIP شده
        $this->assertNotContains('ACTIVE', collect($inst['steps'])->pluck('Status')->all());
        $this->assertContains('SKIPPED', collect($inst['steps'])->pluck('Status')->all());
    }

    public function test_cancel_records_history_with_reason(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5902)->instanceId;
        $this->openTask($instanceId);

        $this->engine->cancel($instanceId, self::USER_A, 'بودجه قطع شد');

        $history = collect($this->query->instance($instanceId)['history']);
        $cancelled = $history->firstWhere('EventCode', 'INSTANCE_CANCELLED');
        $this->assertNotNull($cancelled);
        $this->assertStringContainsString('بودجه قطع شد', $cancelled->Summary);
        $this->assertSame('بودجه قطع شد', json_decode($cancelled->DetailJson, true)['reason']);
        $this->assertSame(self::USER_A, (int) $cancelled->ActorUserID);

        // رویدادِ بسته‌شدنِ تسک هم ثبت شده
        $this->assertContains('TASK_CANCELLED', $history->pluck('EventCode')->all());
    }

    public function test_cancel_already_cancelled_is_state_conflict(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5903)->instanceId;
        $this->engine->cancel($instanceId, self::USER_A, null);

        $this->expectException(WorkflowStateException::class);
        $this->engine->cancel($instanceId, self::USER_A, null);
    }

    public function test_action_after_cancel_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5904)->instanceId;
        $task = $this->openTask($instanceId);
        $this->engine->cancel($instanceId, self::USER_A, null);

        $this->expectException(WorkflowStateException::class);
        $this->act($task, self::USER_A, 'APPROVE');
    }

    public function test_suspend_keeps_tasks_and_blocks_actions(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5905)->instanceId;
        $task = $this->openTask($instanceId);

        $res = $this->engine->suspend($instanceId, self::USER_A, 'منتظرِ مدارک');
        $this->assertSame('SUSPENDED', $res->instanceStatus);
        $this->assertSame('SUSPENDED', $this->instanceStatus($instanceId));

        // تسک همچنان باز است و پیامِ کارتابل دست‌نخورده
        $this->assertSame(1, $this->openTaskCount($instanceId));
        $stillOpen = collect($this->query->instance($instanceId)['tasks'])->firstWhere('MessageID', (int) $task->MessageID);
        $this->assertSame('ACTIVE', $stillOpen->Status);
        $this->assertTrue($this->inCartable(self::USER_A, (int) $task->MessageID));

        // Action رد می‌شود
        try {
            $this->act($task, self::USER_A, 'APPROVE');
            $this->fail('Action هنگام تعلیق باید رد شود.');
        } catch (WorkflowStateException $e) {
            // انتظار می‌رود
        }

        $history = collect($this->query->instance($instanceId)['history']);
        $suspended = $history->firstWhere('EventCode', 'INSTANCE_SUSPENDED');
        $this->assertNotNull($suspended);
        $this->assertSame('منتظرِ مدارک', json_decode($suspended->DetailJson, true)['reason']);
    }

    public function test_suspend_already_suspended_is_state_conflict(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5906)->instanceId;
        $this->openTask($instanceId);
        $this->engine->suspend($instanceId, self::USER_A, null);

        $this->expectException(WorkflowStateException::class);
        $this->engine->suspend($instanceId, self::USER_A, null);
    }

    public function test_resume_restores_running_and_allows_actions(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5907)->instanceId;
        $task = $this->openTask($instanceId);
        $this->engine->suspend($instanceId, self::USER_A, null);

        $res = $this->engine->resume($instanceId, self::USER_A);
        $this->assertSame('RUNNING', $res->instanceStatus);
        $this->assertSame('RUNNING', $this->instanceStatus($instanceId));
        $this->assertContains('INSTANCE_RESUMED', collect($this->query->instance($instanceId)['history'])->pluck('EventCode')->all());

        // همان تسکِ قبلی حالا قابلِ ادامه است
        $fresh = $this->taskFor((int) $task->MessageID);
        $done = $this->act($fresh, self::USER_A, 'APPROVE');
        $this->assertSame('COMPLETED', $done->instanceStatus);
    }

    public function test_resume_running_instance_is_state_conflict(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5908)->instanceId;
        $this->openTask($instanceId);

        $this->expectException(WorkflowStateException::class);
        $this->engine->resume($instanceId, self::USER_A);
    }

    public function test_resume_cancelled_instance_is_state_conflict(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5909)->instanceId;
        $this->engine->cancel($instanceId, self::USER_A, null);

        $this->expectException(WorkflowStateException::class);
        $this->engine->resume($instanceId, self::USER_A);
    }

    public function test_full_suspend_resume_complete_cycle(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5910)->instanceId;
        $task = $this->openTask($instanceId);

        $this->assertSame('SUSPENDED', $this->engine->suspend($instanceId, self::USER_A, null)->instanceStatus);
        $this->assertSame('RUNNING', $this->engine->resume($instanceId, self::USER_A)->instanceStatus);

        $fresh = $this->taskFor((int) $task->MessageID);
        $this->assertSame('COMPLETED', $this->act($fresh, self::USER_A, 'APPROVE')->instanceStatus);
        $this->assertSame('COMPLETED', $this->instanceStatus($instanceId));
    }

    public function test_cancel_is_allowed_from_suspended(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5911)->instanceId;
        $this->openTask($instanceId);

        $this->engine->suspend($instanceId, self::USER_A, null);
        $res = $this->engine->cancel($instanceId, self::USER_A, 'لغو پس از تعلیق');

        $this->assertSame('CANCELLED', $res->instanceStatus);
        $this->assertSame(0, $this->openTaskCount($instanceId));
    }

    public function test_cancel_completed_instance_is_state_conflict(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 5912)->instanceId;
        $task = $this->openTask($instanceId);
        $this->act($task, self::USER_A, 'APPROVE'); // → COMPLETED
        $this->assertSame('COMPLETED', $this->instanceStatus($instanceId));

        $this->expectException(WorkflowStateException::class);
        $this->engine->cancel($instanceId, self::USER_A, null);
    }

    public function test_lifecycle_on_missing_instance_throws(): void
    {
        $this->expectException(WorkflowValidationException::class);
        $this->engine->suspend(999999999, self::USER_A, null);
    }

    /* ==================================================================== */
    /*  گروه ۱۰ — Forward (Step 7.2)                                         */
    /* ==================================================================== */

    /** ردیفِ انجام‌دهندهٔ یک کاربر روی این تسک (فعال یا غیرفعال). */
    private function assigneeRow(int $messageId, int $userId): ?object
    {
        return collect($this->query->stepTaskDetail($messageId)['assignees'])
            ->firstWhere('UserID', $userId);
    }

    /** شمارِ کلِ ردیف‌های dbo.Messages (برای اثباتِ «هیچ پیامِ جدیدی ساخته نشد»). */
    private function totalMessages(): int
    {
        return (int) \Illuminate\Support\Facades\DB::table('Messages')->count();
    }

    public function test_forward_moves_the_active_slot_from_a_to_b(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6001)->instanceId;
        $task = $this->openTask($instanceId);
        $messageId = (int) $task->MessageID;
        $stepInstanceId = (int) $task->StepInstanceID;
        $msgCountBefore = $this->totalMessages();

        $res = $this->engine->forward($messageId, self::USER_A, self::USER_B, 'لطفاً شما بررسی کن');
        $this->assertSame('RUNNING', $res->instanceStatus);

        // همان MessageID و همان StepInstanceID، بدونِ پیامِ جدید
        $after = $this->taskFor($messageId);
        $this->assertSame($messageId, (int) $after->MessageID);
        $this->assertSame($stepInstanceId, (int) $after->StepInstanceID);
        $this->assertSame($msgCountBefore, $this->totalMessages());
        $this->assertCount(1, $this->query->instance($instanceId)['tasks']);

        // A غیرفعال، B فعال با SourceType=FORWARD و SourceRefID=A
        $this->assertSame([self::USER_B], $this->assigneeIds($messageId));
        $this->assertSame(0, (int) $this->assigneeRow($messageId, self::USER_A)->IsActive);
        $bRow = $this->assigneeRow($messageId, self::USER_B);
        $this->assertSame(1, (int) $bRow->IsActive);
        $this->assertSame('FORWARD', $bRow->SourceType);
        $this->assertSame(self::USER_A, (int) $bRow->SourceRefID);

        // کارتابل: A → «انجام نخواهد شد» (۶)، B → «ارسال شده» (۱)
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
        $this->assertSame('ارسال شده', $this->personalStatus($messageId, self::USER_B));
        $this->assertFalse($this->inCartable(self::USER_A, $messageId));
        $this->assertTrue($this->inCartable(self::USER_B, $messageId));

        $this->assertContains('TASK_FORWARDED', $this->historyCodes($instanceId));
    }

    public function test_forward_chain_a_b_c_keeps_one_active_holder(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6002)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $this->engine->forward($messageId, self::USER_B, self::USER_C, null);

        $this->assertSame([self::USER_C], $this->assigneeIds($messageId));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('ارسال شده', $this->personalStatus($messageId, self::USER_C));

        // C می‌تواند تسک را ببندد
        $done = $this->act($this->taskFor($messageId), self::USER_C, 'APPROVE');
        $this->assertSame('COMPLETED', $done->instanceStatus);
        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_C));

        $forwardEvents = collect($this->query->instance($instanceId)['history'])->where('EventCode', 'TASK_FORWARDED')->count();
        $this->assertSame(2, $forwardEvents);
    }

    public function test_forward_by_non_assignee_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6003)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->expectException(WorkflowStateException::class);
        $this->engine->forward($messageId, self::USER_C, self::USER_B, null);
    }

    public function test_forward_after_decision_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_B)], policy: 'ALL', allowForward: true
        ));
        $instanceId = $this->startWf($code, 6004)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        // A تأیید می‌کند؛ زیرِ ALL مرحله باز می‌ماند ولی A دیگر un-decided نیست
        $this->assertSame('RUNNING', $this->act($this->taskFor($messageId), self::USER_A, 'APPROVE')->instanceStatus);

        $this->expectException(WorkflowStateException::class);
        $this->engine->forward($messageId, self::USER_A, self::USER_C, null);
    }

    public function test_forward_to_self_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $messageId = (int) $this->openTask($this->startWf($code, 6005)->instanceId)->MessageID;

        $this->expectException(WorkflowValidationException::class);
        $this->engine->forward($messageId, self::USER_A, self::USER_A, null);
    }

    public function test_forward_to_nonexistent_or_inactive_user_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $messageId = (int) $this->openTask($this->startWf($code, 6006)->instanceId)->MessageID;

        $this->expectException(WorkflowValidationException::class);
        $this->engine->forward($messageId, self::USER_A, 987654, null);
    }

    public function test_forward_to_existing_active_assignee_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_B)], policy: 'ALL', allowForward: true
        ));
        $messageId = (int) $this->openTask($this->startWf($code, 6007)->instanceId)->MessageID;

        $this->expectException(WorkflowValidationException::class);
        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
    }

    public function test_forward_not_allowed_when_step_disables_it(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)])); // allowForward پیش‌فرض = 0
        $messageId = (int) $this->openTask($this->startWf($code, 6008)->instanceId)->MessageID;

        $this->expectException(WorkflowValidationException::class);
        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
    }

    public function test_forward_max_is_enforced(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true, forwardMax: 1));
        $messageId = (int) $this->openTask($this->startWf($code, 6009)->instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null); // اولین ارجاع مجاز
        $this->expectException(WorkflowValidationException::class);
        $this->engine->forward($messageId, self::USER_B, self::USER_C, null); // دومین → عبور از سقف
    }

    public function test_forward_is_blocked_while_suspended(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6010)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;
        $this->engine->suspend($instanceId, self::USER_A, null);

        $this->expectException(WorkflowStateException::class);
        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
    }

    /* ==================================================================== */
    /*  گروه ۱۱ — Delegation                                                 */
    /* ==================================================================== */

    public function test_delegate_moves_active_slot_and_sets_source_type(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)])); // allowDelegation پیش‌فرض = 1
        $instanceId = $this->startWf($code, 6101)->instanceId;
        $task = $this->openTask($instanceId);
        $messageId = (int) $task->MessageID;
        $stepInstanceId = (int) $task->StepInstanceID;
        $msgBefore = $this->totalMessages();

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, 'مرخصی هستم');

        $after = $this->taskFor($messageId);
        $this->assertSame($messageId, (int) $after->MessageID);
        $this->assertSame($stepInstanceId, (int) $after->StepInstanceID);
        $this->assertSame($msgBefore, $this->totalMessages());

        $this->assertSame([self::USER_B], $this->assigneeIds($messageId));
        $this->assertSame(0, (int) $this->assigneeRow($messageId, self::USER_A)->IsActive);
        $bRow = $this->assigneeRow($messageId, self::USER_B);
        $this->assertSame('DELEGATION', $bRow->SourceType);
        $this->assertSame(self::USER_A, (int) $bRow->SourceRefID);

        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
        $this->assertSame('ارسال شده', $this->personalStatus($messageId, self::USER_B));

        $this->assertContains('TASK_DELEGATED', $this->historyCodes($instanceId));
    }

    public function test_delegator_cannot_act_after_delegation_but_delegate_can(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 6102)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);

        try {
            $this->act($this->taskFor($messageId), self::USER_A, 'APPROVE');
            $this->fail('A پس از تفویض نباید بتواند اقدام کند.');
        } catch (WorkflowStateException $e) {
            // انتظار می‌رود
        }

        $done = $this->act($this->taskFor($messageId), self::USER_B, 'APPROVE');
        $this->assertSame('COMPLETED', $done->instanceStatus);
    }

    public function test_delegate_cannot_forward_or_redelegate(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6103)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);

        try {
            $this->engine->forward($messageId, self::USER_B, self::USER_C, null);
            $this->fail('نمایندهٔ تفویض نباید بتواند ارجاع دهد.');
        } catch (WorkflowException $e) {
            // انتظار می‌رود
        }

        try {
            $this->engine->delegate($messageId, self::USER_B, self::USER_C, null);
            $this->fail('نماینده نباید بتواند دوباره تفویض کند.');
        } catch (WorkflowException $e) {
            // انتظار می‌رود
        }

        $this->assertSame([self::USER_B], $this->assigneeIds($messageId));
    }

    public function test_delegation_target_validation_is_enforced(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C)], policy: 'ALL'
        ));
        $messageId = (int) $this->openTask($this->startWf($code, 6104)->instanceId)->MessageID;

        foreach ([
            [self::USER_A, 'تفویض به خود'],
            [987654, 'کاربرِ نامعتبر'],
            [self::USER_C, 'انجام‌دهندهٔ فعالِ موجود'],
        ] as [$target, $why]) {
            try {
                $this->engine->delegate($messageId, self::USER_A, $target, null);
                $this->fail("باید رد شود: {$why}");
            } catch (WorkflowValidationException $e) {
                // انتظار می‌رود
            }
        }
    }

    public function test_delegation_not_allowed_when_step_disables_it(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowDelegation: false));
        $messageId = (int) $this->openTask($this->startWf($code, 6105)->instanceId)->MessageID;

        $this->expectException(WorkflowValidationException::class);
        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);
    }

    public function test_delegation_is_blocked_while_suspended(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 6106)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;
        $this->engine->suspend($instanceId, self::USER_A, null);

        $this->expectException(WorkflowStateException::class);
        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);
    }

    /* ==================================================================== */
    /*  گروه ۱۲ — Revoke Delegation                                          */
    /* ==================================================================== */

    public function test_revoke_restores_the_delegator_as_active_holder(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 6201)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);
        $this->assertSame([self::USER_B], $this->assigneeIds($messageId));

        $res = $this->engine->revokeDelegation($messageId, self::USER_B, self::USER_A);
        $this->assertSame('RUNNING', $res->instanceStatus);

        $this->assertSame([self::USER_A], $this->assigneeIds($messageId));
        $this->assertSame(0, (int) $this->assigneeRow($messageId, self::USER_B)->IsActive);
        $this->assertSame(1, (int) $this->assigneeRow($messageId, self::USER_A)->IsActive);

        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('ارسال شده', $this->personalStatus($messageId, self::USER_A));
        $this->assertTrue($this->inCartable(self::USER_A, $messageId));
        $this->assertFalse($this->inCartable(self::USER_B, $messageId));

        $this->assertContains('TASK_DELEGATION_REVOKED', $this->historyCodes($instanceId));

        $done = $this->act($this->taskFor($messageId), self::USER_A, 'APPROVE');
        $this->assertSame('COMPLETED', $done->instanceStatus);
    }

    public function test_revoke_after_delegate_has_decided_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C)], policy: 'ALL'
        ));
        $instanceId = $this->startWf($code, 6202)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);
        // فعال‌ها: B (نمایندهٔ A) و C. B تأیید می‌کند ولی ALL منتظرِ C می‌ماند.
        $this->assertSame('RUNNING', $this->act($this->taskFor($messageId), self::USER_B, 'APPROVE')->instanceStatus);

        $this->expectException(WorkflowStateException::class);
        $this->engine->revokeDelegation($messageId, self::USER_B, self::USER_A);
    }

    public function test_revoke_when_no_active_delegation_exists_is_rejected(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $messageId = (int) $this->openTask($this->startWf($code, 6203)->instanceId)->MessageID;

        $this->expectException(WorkflowStateException::class);
        $this->engine->revokeDelegation($messageId, self::USER_B, self::USER_A);
    }

    public function test_revoke_is_blocked_while_suspended(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 6204)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;
        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);
        $this->engine->suspend($instanceId, self::USER_A, null);

        $this->expectException(WorkflowStateException::class);
        $this->engine->revokeDelegation($messageId, self::USER_B, self::USER_A);
    }

    /* ==================================================================== */
    /*  گروه ۱۳ — تعامل با Assignment Policy                                 */
    /* ==================================================================== */

    public function test_forward_under_user_task_preserves_completion(): void
    {
        [, , $code] = $this->publishGraph([
            'steps' => [
                ['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'WORK', 'name' => 'کار', 'stepType' => 'USER_TASK', 'assignPolicy' => 'ANY', 'allowForward' => true, 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پ', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [
                ['stepCode' => 'WORK', 'code' => 'SUBMIT', 'kind' => 'COMPLETE', 'label' => 'ثبت'],
            ],
            'assignments' => [
                ['stepCode' => 'WORK', 'assigneeType' => 'USER', 'refId' => self::USER_A],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'WORK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'WORK', 'toStepCode' => 'END', 'triggerActionCode' => 'SUBMIT'],
            ],
        ]);
        $instanceId = $this->startWf($code, 6301)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $done = $this->act($this->taskFor($messageId), self::USER_B, 'SUBMIT');

        $this->assertSame('COMPLETED', $done->instanceStatus);
        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
    }

    public function test_forward_under_any_policy_only_moves_one_slot(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C)], policy: 'ANY', allowForward: true
        ));
        $instanceId = $this->startWf($code, 6302)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $this->assertSame([self::USER_C, self::USER_B], $this->assigneeIds($messageId)); // 3 و 14

        $done = $this->act($this->taskFor($messageId), self::USER_B, 'APPROVE');
        $this->assertSame('COMPLETED', $done->instanceStatus);
        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_C));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
    }

    public function test_delegation_under_all_policy_waits_for_delegate_and_peer(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C)], policy: 'ALL'
        ));
        $instanceId = $this->startWf($code, 6303)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);

        $this->assertSame('RUNNING', $this->act($this->taskFor($messageId), self::USER_B, 'APPROVE')->instanceStatus);
        $this->assertSame('COMPLETED', $this->act($this->taskFor($messageId), self::USER_C, 'APPROVE')->instanceStatus);

        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_C));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
    }

    public function test_delegation_under_n_of_m_counts_delegate_vote(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C), $this->user(self::USER_MGR7)],
            policy: 'N_OF_M', required: 2
        ));
        $instanceId = $this->startWf($code, 6304)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);
        $this->assertSame([self::USER_C, self::USER_MGR7, self::USER_B], $this->assigneeIds($messageId)); // 3،4،14

        $this->assertSame('RUNNING', $this->act($this->taskFor($messageId), self::USER_B, 'APPROVE')->instanceStatus);
        $this->assertSame('COMPLETED', $this->act($this->taskFor($messageId), self::USER_C, 'APPROVE')->instanceStatus);

        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_C));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_MGR7));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
    }

    /* ==================================================================== */
    /*  گروه ۱۴ — تعامل با چرخهٔ حیات (وضعیت‌های Step 7.1 حفظ می‌شوند)        */
    /* ==================================================================== */

    public function test_forward_then_reject_marks_all_wont_do(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6401)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $res = $this->act($this->taskFor($messageId), self::USER_B, 'REJECT', 'رد شد');

        $this->assertSame('COMPLETED', $res->instanceStatus);
        $this->assertSame('END_NO', $res->enteredStepCode);
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
        $this->assertFalse($this->inCartable(self::USER_B, $messageId));
    }

    public function test_delegate_then_approve_marks_delegate_done(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)]));
        $instanceId = $this->startWf($code, 6402)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->delegate($messageId, self::USER_A, self::USER_B, null);
        $res = $this->act($this->taskFor($messageId), self::USER_B, 'APPROVE');

        $this->assertSame('COMPLETED', $res->instanceStatus);
        $this->assertSame('انجام شده', $this->personalStatus($messageId, self::USER_B));       // positive completion = 4
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));  // A کاری نکرد
    }

    public function test_forward_then_return_marks_all_wont_do(): void
    {
        [, , $code] = $this->publishGraph([
            'steps' => [
                ['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'ب', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowForward' => true, 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پ', 'stepType' => 'END', 'sortOrder' => 2],
                ['code' => 'BACK', 'name' => 'بازگشت', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'ت'],
                ['stepCode' => 'REVIEW', 'code' => 'RETURN', 'kind' => 'RETURN', 'label' => 'ع'],
            ],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_A],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'BACK', 'triggerActionCode' => 'RETURN'],
            ],
        ]);
        $instanceId = $this->startWf($code, 6403)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $res = $this->act($this->taskFor($messageId), self::USER_B, 'RETURN');

        $this->assertSame('COMPLETED', $res->instanceStatus);
        $this->assertSame('BACK', $res->enteredStepCode);
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_B));
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_A));
    }

    public function test_cancel_after_forward_deactivates_all_assignees_and_closes_cartable(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6404)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $this->engine->cancel($instanceId, self::USER_A, 'لغو');

        $this->assertSame('CANCELLED', $this->instanceStatus($instanceId));
        $this->assertSame([], $this->assigneeIds($messageId));
        $bRow = $this->assigneeRow($messageId, self::USER_B);
        $this->assertSame(0, (int) $bRow->IsActive);
        $this->assertNull($bRow->Decision); // Decisionِ تاریخی دست‌نخورده
        $this->assertSame('انجام نخواهد شد', $this->personalStatus($messageId, self::USER_B));
        $this->assertFalse($this->inCartable(self::USER_B, $messageId));
    }

    /* ==================================================================== */
    /*  گروه ۱۵ — Cartable Regression                                        */
    /* ==================================================================== */

    public function test_forward_and_delegation_never_create_a_second_message(): void
    {
        $baseline = $this->totalMessages();
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6501)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;
        $this->assertSame($baseline + 1, $this->totalMessages()); // فقط پیامِ تسک

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $this->engine->forward($messageId, self::USER_B, self::USER_C, null);
        $this->engine->delegate($messageId, self::USER_C, self::USER_MGR7, null);
        $this->engine->revokeDelegation($messageId, self::USER_MGR7, self::USER_C);

        $this->assertSame($baseline + 1, $this->totalMessages());
        $this->assertSame($messageId, (int) $this->taskFor($messageId)->MessageID);
        $this->assertCount(1, $this->query->instance($instanceId)['tasks']);
        $this->assertSame([self::USER_C], $this->assigneeIds($messageId));
    }

    public function test_only_the_current_holder_sees_the_task_in_cartable(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph([$this->user(self::USER_A)], allowForward: true));
        $instanceId = $this->startWf($code, 6502)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->assertTrue($this->inCartable(self::USER_A, $messageId));

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $this->assertFalse($this->inCartable(self::USER_A, $messageId));
        $this->assertTrue($this->inCartable(self::USER_B, $messageId));

        $this->engine->delegate($messageId, self::USER_B, self::USER_C, null);
        $this->assertFalse($this->inCartable(self::USER_B, $messageId));
        $this->assertTrue($this->inCartable(self::USER_C, $messageId));

        $this->engine->revokeDelegation($messageId, self::USER_C, self::USER_B);
        $this->assertTrue($this->inCartable(self::USER_B, $messageId));
        $this->assertFalse($this->inCartable(self::USER_C, $messageId));
    }

    public function test_task_leaves_every_cartable_after_completion_following_forward(): void
    {
        [, , $code] = $this->publishGraph($this->approvalGraph(
            [$this->user(self::USER_A), $this->user(self::USER_C)], policy: 'ANY', allowForward: true
        ));
        $instanceId = $this->startWf($code, 6503)->instanceId;
        $messageId = (int) $this->openTask($instanceId)->MessageID;

        $this->engine->forward($messageId, self::USER_A, self::USER_B, null);
        $this->act($this->taskFor($messageId), self::USER_B, 'APPROVE'); // ANY → COMPLETED

        foreach ([self::USER_A, self::USER_B, self::USER_C] as $u) {
            $this->assertFalse($this->inCartable($u, $messageId), "کاربر {$u} نباید تسکِ بسته‌شده را ببیند.");
        }
    }

    /* ==================== Condition Engine / Gateway (Phase 1) ==================== */

    /** گرافِ START → COND → END_HIGH/END_LOW/END_MID با گذارهایِ Ruleدار + یک Default. */
    private function conditionGraph(array $ruledTransitions): array
    {
        $steps = [
            ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
            ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
        ];
        $endCodes = array_unique(array_merge(
            array_column($ruledTransitions, 'toStepCode'),
            ['END_DEFAULT']
        ));
        foreach ($endCodes as $i => $endCode) {
            $steps[] = ['code' => $endCode, 'name' => "پایانِ {$endCode}", 'stepType' => 'END', 'sortOrder' => 2 + $i];
        }

        $transitions = [
            ['code' => 'T_START', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
        ];
        foreach ($ruledTransitions as $i => $rt) {
            $transitions[] = [
                'code' => 'T_RULE_' . $i, 'fromStepCode' => 'COND', 'toStepCode' => $rt['toStepCode'],
                'priority' => $rt['priority'], 'ruleJson' => $rt['rule'],
            ];
        }
        $transitions[] = ['code' => 'T_DEFAULT', 'fromStepCode' => 'COND', 'toStepCode' => 'END_DEFAULT', 'priority' => 999, 'isDefault' => true];

        return ['steps' => $steps, 'actions' => [], 'assignments' => [], 'transitions' => $transitions];
    }

    /** گرافِ CONDITION بدونِ هیچ گذارِ IsDefault (برایِ تستِ Validate/Failure). */
    private function conditionGraphWithoutDefault(array $rule): array
    {
        return [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'END_A', 'name' => 'پایانِ الف', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [],
            'assignments' => [],
            'transitions' => [
                ['code' => 'T_START', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T_RULE', 'fromStepCode' => 'COND', 'toStepCode' => 'END_A', 'priority' => 10, 'ruleJson' => $rule],
            ],
        ];
    }

    /** DECIMAL دیگر float نیست: value.data باید رشتهٔ Canonical باشد، نه عددِ JSON. */
    private function amountRule(string $fieldCode, string $operator, int $value): array
    {
        return [
            'version' => 1, 'type' => 'GROUP', 'logic' => 'AND',
            'children' => [
                ['type' => 'CONDITION', 'field' => $fieldCode, 'operator' => $operator, 'value' => ['kind' => 'CONSTANT', 'data' => (string) $value]],
            ],
        ];
    }

    private function defineAmountField(int $definitionId, string $code = 'AMOUNT'): void
    {
        $this->defs->saveConditionField([
            'definitionId' => $definitionId, 'code' => $code, 'displayName' => 'مبلغ',
            'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'amount',
        ], self::USER_A);
    }

    private function startWfWithContext(string $code, int $entityId, array $context): EngineResult
    {
        return $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: $entityId, startedByUserId: self::USER_A,
            definitionCode: $code, context: $context,
        ));
    }

    public function test_condition_gateway_picks_matching_rule_by_priority(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'CG_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'گیت‌وی', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $this->amountRule('AMOUNT', 'GT', 100)],
            ['toStepCode' => 'END_LOW', 'priority' => 20, 'rule' => $this->amountRule('AMOUNT', 'LTE', 100)],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '150']);

        $this->assertSame('COMPLETED', $result->instanceStatus);
        $this->assertSame('END_HIGH', $result->enteredStepCode);
    }

    public function test_condition_gateway_short_circuits_on_first_true_rule_by_priority(): void
    {
        // هر دو Ruleِ TRUE هستند؛ برنده باید همیشه Priorityِ کمتر باشد.
        $definitionId = (int) $this->defs->save(['code' => 'CG_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'گیت‌وی', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 5, 'rule' => $this->amountRule('AMOUNT', 'GT', 10)],
            ['toStepCode' => 'END_LOW', 'priority' => 50, 'rule' => $this->amountRule('AMOUNT', 'GT', 20)],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '1000']);

        $this->assertSame('END_HIGH', $result->enteredStepCode);
    }

    public function test_condition_gateway_falls_back_to_default_when_no_rule_matches(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'CG_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'گیت‌وی', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $this->amountRule('AMOUNT', 'GT', 1000)],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '5']);

        $this->assertSame('END_DEFAULT', $result->enteredStepCode);
    }

    public function test_condition_gateway_missing_context_value_never_matches_and_uses_default(): void
    {
        // طبقِ قاعدهٔ NULL: غیابِ amount در Context یعنی هر مقایسه FALSE است.
        $definitionId = (int) $this->defs->save(['code' => 'CG_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'گیت‌وی', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $this->amountRule('AMOUNT', 'GT', 1)],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), []);

        $this->assertSame('END_DEFAULT', $result->enteredStepCode);
    }

    public function test_condition_gateway_controlled_failure_when_no_rule_matches_and_no_default(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'CG_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'گیت‌وی', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        // ساختِ گرافِ بدونِ Default و Publishِ آن از طریقِ Store مستقیم (نه Service) تا
        // Guardِ جدیدِ Validate این حالت را در Publishِ عادی رد نکند — این تست دقیقاً
        // رفتارِ Runtime را در برابرِ دادهٔ بن‌بست می‌سنجد، نه رفتارِ Validate را (که
        // جداگانه در تستِ Publish پوشش داده شده است).
        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraphWithoutDefault(
            $this->amountRule('AMOUNT', 'GT', 1000)
        ), self::USER_A);
        $this->store->publishVersion((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '5']);

        $this->assertSame('FAILED', $result->instanceStatus);

        $instance = $this->store->getInstance($result->instanceId)['instance'];
        $this->assertSame('FAILED', $instance->Status);
    }

    /* ==================== Context Snapshot (Start) ==================== */

    public function test_start_context_is_validated_cast_and_snapshotted(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'CTX_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'Context', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 1],
            ],
            'actions' => [], 'assignments' => [],
            'transitions' => [['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'END', 'isDefault' => true]],
        ], self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '150.5']);

        $rawContext = $this->store->getInstanceContext($result->instanceId);
        $this->assertNotNull($rawContext);
        $decoded = json_decode($rawContext, true);
        // DECIMAL دیگر float نیست — رشتهٔ Canonicalِ دقیق در ContextJson ذخیره می‌شود.
        $this->assertSame('150.5', $decoded['AMOUNT']);
    }

    public function test_start_with_invalid_context_creates_no_instance(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'CTXBAD_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'Context بد', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 1],
            ],
            'actions' => [], 'assignments' => [],
            'transitions' => [['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'END', 'isDefault' => true]],
        ], self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);
        $meta = $this->store->getVersionMeta((int) $version->VersionID);

        $countBefore = \Illuminate\Support\Facades\DB::table('WorkflowInstances')->count();

        $this->expectException(WorkflowValidationException::class);

        try {
            $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => 'not-a-number']);
        } finally {
            $countAfter = \Illuminate\Support\Facades\DB::table('WorkflowInstances')->count();
            $this->assertSame($countBefore, $countAfter, 'Contextِ نامعتبر نباید هیچ Instanceای بسازد.');
        }
    }

    public function test_transition_taken_history_includes_rule_evaluation_detail(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'HIST_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'History', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $this->amountRule('AMOUNT', 'GT', 100)],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '500']);

        $history = collect($this->store->getInstanceHistory($result->instanceId));
        $ruleEvents = $history->filter(function ($h) {
            $detail = json_decode($h->DetailJson ?? '{}', true) ?? [];

            return $h->EventCode === 'TRANSITION_TAKEN' && array_key_exists('ruleMatched', $detail);
        });

        $this->assertNotEmpty($ruleEvents, 'باید یک رویدادِ TRANSITION_TAKEN با ruleMatched ثبت شده باشد.');
        $detail = json_decode($ruleEvents->first()->DetailJson, true);
        $this->assertTrue($detail['ruleMatched']);
        $this->assertStringContainsString('AMOUNT', $detail['ruleSummary']);
    }

    /* ==================== Publish Validation (Condition Engine) ==================== */

    public function test_publish_is_blocked_when_condition_step_has_no_default(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'NODEF_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'بدونِ Default', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraphWithoutDefault(
            $this->amountRule('AMOUNT', 'GT', 100)
        ), self::USER_A);

        $result = $this->defs->validate((int) $version->VersionID, self::USER_A, persist: false);
        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($e) => str_contains($e, 'پیش‌فرض')));

        $this->expectException(WorkflowValidationException::class);
        $this->defs->publish((int) $version->VersionID, self::USER_A);
    }

    public function test_publish_is_blocked_when_rule_references_inactive_field(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'INACT_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'فیلدِ غیرفعال', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $field = $this->defs->saveConditionField([
            'definitionId' => $definitionId, 'code' => 'AMOUNT', 'displayName' => 'مبلغ',
            'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'amount',
        ], self::USER_A);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $this->amountRule('AMOUNT', 'GT', 100)],
        ]), self::USER_A);

        // Save همچنان موفق است (Ruleِ اشاره‌کننده به فیلدِ غیرفعال قابلِ Save است، فقط Publish را مسدود می‌کند)
        $this->defs->toggleConditionFieldActive((int) $field->FieldID, self::USER_A);

        $result = $this->defs->validate((int) $version->VersionID, self::USER_A, persist: false);
        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($e) => str_contains($e, 'غیرفعال')));

        $this->expectException(WorkflowValidationException::class);
        $this->defs->publish((int) $version->VersionID, self::USER_A);
    }

    public function test_publish_is_blocked_when_rule_json_is_on_non_condition_transition(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'MISPLACED_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'نادرست', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 1],
            ],
            'actions' => [], 'assignments' => [],
            'transitions' => [[
                'code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'END', 'isDefault' => true,
                'ruleJson' => $this->amountRule('AMOUNT', 'GT', 100),
            ]],
        ], self::USER_A);

        $result = $this->defs->validate((int) $version->VersionID, self::USER_A, persist: false);
        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(fn ($e) => str_contains($e, 'CONDITION نیست')));
    }

    /** یافتهٔ Auditِ Final: گذارِ Default نباید هم‌زمان RuleJson داشته باشد — باید Publish را مسدود کند. */
    public function test_publish_is_blocked_when_a_default_transition_also_has_rule_json(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'DEFRULE_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'Default+Rule', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defineAmountField($definitionId);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'END_A', 'name' => 'پایانِ الف', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [], 'assignments' => [],
            'transitions' => [
                ['code' => 'T_START', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                // این گذار هم Default است هم RuleJson دارد — دقیقاً همان پیکربندیِ مبهمی که باید رد شود.
                ['code' => 'T_AMBIGUOUS', 'fromStepCode' => 'COND', 'toStepCode' => 'END_A', 'isDefault' => true, 'ruleJson' => $this->amountRule('AMOUNT', 'GT', 100)],
            ],
        ], self::USER_A);

        $result = $this->defs->validate((int) $version->VersionID, self::USER_A, persist: false);
        $this->assertFalse($result['ok']);
        $this->assertTrue(collect($result['errors'])->contains(
            fn ($e) => str_contains($e, 'پیش‌فرض') && str_contains($e, 'شرط')
        ), 'باید خطایی دربارهٔ «گذارِ پیش‌فرض نباید هم‌زمان دارایِ شرط باشد» وجود داشته باشد.');

        $this->expectException(WorkflowValidationException::class);
        $this->defs->publish((int) $version->VersionID, self::USER_A);
    }

    /* ==================== DECIMAL بدونِ float (bcmath) ==================== */

    /**
     * موردِ کلاسیکِ خطایِ Precisionِ IEEE-754: 0.1 + 0.2 !== 0.3 در اکثرِ زبان‌ها.
     * این تست مستقیماً همین دو مقدار را (به‌عنوانِ رشته) از مسیرِ واقعیِ Context/Rule
     * عبور می‌دهد تا مطمئن شویم مقایسه هرگز از float عبور نمی‌کند.
     */
    public function test_decimal_comparison_is_exact_and_float_independent(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'DEC_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'Decimal دقیق', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defs->saveConditionField([
            'definitionId' => $definitionId, 'code' => 'AMOUNT', 'displayName' => 'مبلغ',
            'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'amount',
        ], self::USER_A);

        // RuleJson عمداً غیر-Canonical است («0.300» به‌جایِ «0.3») تا اثبات شود مقایسه
        // با bccomp (که فرمت‌هایِ هم‌ارزِ متفاوت را هم‌مقدار می‌داند) انجام می‌شود، نه
        // با ===ِ رشته‌ایِ خام (که «0.3» !== «0.300» می‌گفت) و نه با == رویِ float.
        $rule = [
            'version' => 1, 'type' => 'GROUP', 'logic' => 'AND',
            'children' => [
                ['type' => 'CONDITION', 'field' => 'AMOUNT', 'operator' => 'EQ', 'value' => ['kind' => 'CONSTANT', 'data' => '0.300']],
            ],
        ];

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $rule],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);

        // Contextِ ورودی «0.30» است — در ConditionContextBuilder به فرمِ Canonicalِ
        // «0.3» درمی‌آید، سپس با مقدارِ غیر-Canonicalِ Ruleِ («0.300») مقایسه می‌شود.
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '0.30']);

        $rawContext = $this->store->getInstanceContext($result->instanceId);
        $this->assertSame('0.3', json_decode($rawContext, true)['AMOUNT'], 'Contextِ Canonicalشده باید بدونِ صفرِ اضافیِ انتها ذخیره شود.');

        $this->assertSame('COMPLETED', $result->instanceStatus);
        $this->assertSame('END_HIGH', $result->enteredStepCode, '«0.3» (Context) باید با «0.300» (Rule) برابر شناخته شود.');
    }

    /* ==================== Context Contract — اثباتِ End-to-End ==================== */

    /**
     * زنجیرهٔ کاملِ Mandatory:
     * ConditionField.Code=AMOUNT، SourceKey=amount، DataType=DECIMAL
     *   → StartWorkflowRequest.context={"amount":"1500.25"}
     *   → ConditionContextBuilder (Cast/Canonicalize)
     *   → WorkflowInstances.ContextJson={"AMOUNT":"1500.25"}
     *   → ConditionEvaluator (AMOUNT > 1000)
     *   → نتیجهٔ Runtime باید TRUE باشد (مسیرِ END_HIGH طی شود).
     */
    public function test_context_contract_end_to_end_chain_produces_true_result(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'E2E_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'End to End', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defs->saveConditionField([
            'definitionId' => $definitionId, 'code' => 'AMOUNT', 'displayName' => 'مبلغ',
            'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'amount',
        ], self::USER_A);

        $rule = [
            'version' => 1, 'type' => 'GROUP', 'logic' => 'AND',
            'children' => [
                ['type' => 'CONDITION', 'field' => 'AMOUNT', 'operator' => 'GT', 'value' => ['kind' => 'CONSTANT', 'data' => '1000']],
            ],
        ];

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $rule],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['amount' => '1500.25']);

        // زنجیره را قدم‌به‌قدم اثبات می‌کنیم، نه فقط نتیجهٔ نهایی:
        $rawContext = $this->store->getInstanceContext($result->instanceId);
        $this->assertSame('{"AMOUNT":"1500.25"}', $rawContext, 'ContextJson باید دقیقاً با کلیدِ Code (نه SourceKey) و مقدارِ Canonical ذخیره شده باشد.');

        $this->assertSame('COMPLETED', $result->instanceStatus);
        $this->assertSame('END_HIGH', $result->enteredStepCode, 'Ruleِ AMOUNT>1000 باید TRUE شده باشد.');
    }

    /**
     * کلیدِ ناشناخته در Context (که به هیچ SourceKeyِ فعالی متناظر نیست) نباید
     * Silently Ignore شود — باید Startِ فرایند را با Errorِ صریح متوقف کند.
     */
    public function test_context_contract_rejects_unknown_context_key(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'E2EBAD_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'End to End Bad', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        $this->defs->saveConditionField([
            'definitionId' => $definitionId, 'code' => 'AMOUNT', 'displayName' => 'مبلغ',
            'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'amount',
        ], self::USER_A);

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 1],
            ],
            'actions' => [], 'assignments' => [],
            'transitions' => [['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'END', 'isDefault' => true]],
        ], self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);
        $meta = $this->store->getVersionMeta((int) $version->VersionID);

        $countBefore = \Illuminate\Support\Facades\DB::table('WorkflowInstances')->count();

        $this->expectException(WorkflowValidationException::class);

        try {
            $this->startWfWithContext(
                $meta->DefinitionCode,
                random_int(90000, 99999),
                ['amount' => '1500.25', 'somethingUnknown' => 'abc']
            );
        } finally {
            $countAfter = \Illuminate\Support\Facades\DB::table('WorkflowInstances')->count();
            $this->assertSame($countBefore, $countAfter, 'کلیدِ ناشناخته نباید Instanceای بسازد.');
        }
    }

    /**
     * تستِ Mappingِ Code≠SourceKey: اثبات می‌کند Evaluator از SourceKeyِ صحیح برایِ
     * خواندنِ Contextِ خام استفاده می‌کند (نه به‌طورِ تصادفی از خودِ Code)، درحالی‌که
     * Rule/ContextJson با Code شناخته می‌شوند.
     */
    public function test_context_contract_uses_source_key_not_code_for_raw_context_lookup(): void
    {
        $definitionId = (int) $this->defs->save(['code' => 'MAP_' . strtoupper(bin2hex(random_bytes(4))), 'name' => 'Mapping', 'entityType' => 'TEST_ENTITY'], self::USER_A)->DefinitionID;
        // Code و SourceKey عمداً کاملاً متفاوتند.
        $this->defs->saveConditionField([
            'definitionId' => $definitionId, 'code' => 'TOTAL_AMOUNT', 'displayName' => 'جمعِ مبلغ',
            'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'raw_amt_field_x',
        ], self::USER_A);

        $rule = [
            'version' => 1, 'type' => 'GROUP', 'logic' => 'AND',
            'children' => [
                ['type' => 'CONDITION', 'field' => 'TOTAL_AMOUNT', 'operator' => 'GT', 'value' => ['kind' => 'CONSTANT', 'data' => '1000']],
            ],
        ];

        $version = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $version->VersionID, $this->conditionGraph([
            ['toStepCode' => 'END_HIGH', 'priority' => 10, 'rule' => $rule],
        ]), self::USER_A);
        $this->defs->publish((int) $version->VersionID, self::USER_A);

        $meta = $this->store->getVersionMeta((int) $version->VersionID);

        // اگر Evaluator به‌اشتباه به‌دنبالِ کلیدِ «TOTAL_AMOUNT» در Contextِ خام
        // می‌گشت (به‌جایِ SourceKeyِ صحیح «raw_amt_field_x»)، این مقدار هرگز پیدا
        // نمی‌شد و طبقِ قاعدهٔ NULL، Ruleِ GT همیشه FALSE می‌ماند → END_DEFAULT.
        $result = $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['raw_amt_field_x' => '2000']);

        $rawContext = $this->store->getInstanceContext($result->instanceId);
        $this->assertSame('{"TOTAL_AMOUNT":"2000"}', $rawContext, 'ContextJson باید با کلیدِ Code ذخیره شود، نه SourceKey.');
        $this->assertSame('END_HIGH', $result->enteredStepCode, 'Evaluator باید از SourceKeyِ صحیح مقدار را خوانده باشد.');

        // برعکسِ آزمایش: اگر کلیدِ Contextِ ورودی به‌اشتباه «TOTAL_AMOUNT» (خودِ Code) باشد
        // نه SourceKeyِ واقعی، باید به‌عنوانِ کلیدِ ناشناخته Reject شود (طبقِ همان تستِ بالا) —
        // یعنی سیستم هرگز به‌صورتِ نرم/Fallback بینِ Code و SourceKey سوییچ نمی‌کند.
        $this->expectException(WorkflowValidationException::class);
        $this->startWfWithContext($meta->DefinitionCode, random_int(90000, 99999), ['TOTAL_AMOUNT' => '2000']);
    }
}
