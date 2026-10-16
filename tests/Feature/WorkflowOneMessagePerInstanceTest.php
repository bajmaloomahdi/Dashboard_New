<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Dto\TaskActionRequest;
use App\Services\Workflow\Support\WorkflowStore;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * قانونِ «یک Workflow Instance = یک Message/Task» — عبور از Step به Step نباید Messageِ
 * جدید بسازد؛ فقط گیرنده/مسئولِ همان Message عوض می‌شود.
 *
 * کاملاً Generic: از AssigneeType=USER با گراف‌هایِ دلخواه استفاده می‌شود (نه هیچ Codeِ خاص
 * مثلِ WF101/مرخصی)؛ Test 4 صراحتاً با دو Definitionِ کاملاً متفاوت اثبات می‌کند.
 */
class WorkflowOneMessagePerInstanceTest extends TestCase
{
    use DatabaseTransactions;

    private const STARTER = 2; // مهدی
    private const U_A = 3;     // علی
    private const U_B = 4;     // محسن
    private const U_C = 14;    // دمو

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private WorkflowQueryService $query;
    private WorkflowStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
        $this->query = $this->app->make(WorkflowQueryService::class);
        $this->store = $this->app->make(WorkflowStore::class);
    }

    private function messagesCount(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) c FROM Messages')->c;
    }

    /** START → S1(USER_TASK) → S2(USER_TASK) → END — عمداً بدونِ preCreatedMessageId (مسیرِ عادی). */
    private function publishTwoSteps(string $prefix = 'OMPI'): int
    {
        $code = $prefix . '_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);

        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'S1', 'name' => 'تأییدِ مدیر', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 1],
                ['code' => 'S2', 'name' => 'ثبتِ نهایی', 'stepType' => 'USER_TASK', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'S1', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'S2', 'code' => 'COMPLETE', 'kind' => 'COMPLETE', 'label' => 'تکمیل', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'S1', 'assigneeType' => 'USER', 'refId' => self::U_B],
                ['stepCode' => 'S2', 'assigneeType' => 'USER', 'refId' => self::U_A],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'S1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'S1', 'toStepCode' => 'S2', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'S2', 'toStepCode' => 'END', 'triggerActionCode' => 'COMPLETE'],
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
            [(string) self::U_B, self::STARTER, self::STARTER]
        );
        $entityMessageId = (int) $letterMsg->NewMessageID;

        $result = $this->engine->start(new StartWorkflowRequest(
            entityType: 'MESSAGE', entityId: $entityMessageId, startedByUserId: self::STARTER, definitionId: $definitionId, context: [],
        ));

        return $result->instanceId;
    }

    private function activeTaskMessageId(int $instanceId): int
    {
        $tasks = $this->query->instance($instanceId)['tasks'];
        $active = collect($tasks)->firstWhere('Status', 'ACTIVE');
        $this->assertNotNull($active, 'باید یک تسکِ باز وجود داشته باشد.');

        return (int) $active->MessageID;
    }

    /* ==================== Test 1 — دو Step پشتِ‌سرِهم ==================== */

    public function test_two_sequential_steps_share_one_message(): void
    {
        $defId = $this->publishTwoSteps();
        $before = $this->messagesCount();

        $instanceId = $this->startInstance($defId);
        $afterStart = $this->messagesCount();
        // ۱ Message برایِ «نامه»یِ Entity (sp_InsertMessage) + ۱ برایِ اولین Task (S1) = ۲
        $this->assertSame(2, $afterStart - $before);

        $m1 = $this->activeTaskMessageId($instanceId); // Task رویِ S1، Assignee=محسن

        $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_B, actionCode: 'APPROVE', comment: null));

        // بعدِ حرکت به S2: هیچ Messageِ تازه‌ای ساخته نشده
        $afterApprove = $this->messagesCount();
        $this->assertSame($afterStart, $afterApprove, 'عبور به Stepِ بعدی نباید Messageِ جدید بسازد.');

        $m2 = $this->activeTaskMessageId($instanceId); // Task رویِ S2، Assignee=علی
        $this->assertSame($m1, $m2, 'MessageIDِ تسکِ S2 باید دقیقاً همانِ S1 باشد.');

        $this->engine->performAction(new TaskActionRequest(messageId: $m2, userId: self::U_A, actionCode: 'COMPLETE', comment: null));

        $afterFinal = $this->messagesCount();
        $this->assertSame($afterStart, $afterFinal, 'رسیدن به END هم نباید Messageِ جدید بسازد.');

        $instData = $this->query->instance($instanceId);
        $this->assertSame('COMPLETED', $instData['instance']->Status);

        // ۲ StepInstanceِ Task (S1, S2)، هر دو با همان MessageID
        $taskSteps = collect($instData['tasks']);
        $this->assertCount(2, $taskSteps);
        $this->assertTrue($taskSteps->every(fn ($t) => (int) $t->MessageID === $m1));

        // Messageِ نهایی برایِ هر دو نفر بسته شده (Done)
        $statuses = DB::select('SELECT md.ToUserID, md.MessageStatusID FROM MessageDetails md WHERE md.MessageID = ? ORDER BY md.MessageDetailID', [$m1]);
        $doneNames = collect($statuses)->pluck('MessageStatusID')->unique();
        $doneId = DB::selectOne("SELECT MessageStatusID FROM MessageStatuses WHERE MessageStatusName=N'انجام شده'")->MessageStatusID;
        $this->assertContains($doneId, $doneNames->all());
    }

    /* ==================== Test 2 — چند Assignee با ANY ==================== */

    public function test_multiple_assignees_any_no_new_message_and_only_actor_acted(): void
    {
        $code = 'OMPI_ANY_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'S1', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'S1', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => [
                ['stepCode' => 'S1', 'assigneeType' => 'USER', 'refId' => self::U_B],
                ['stepCode' => 'S1', 'assigneeType' => 'USER', 'refId' => self::U_C],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'S1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'S1', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $before = $this->messagesCount();
        $instanceId = $this->startInstance($definitionId);
        $afterStart = $this->messagesCount();

        $m1 = $this->activeTaskMessageId($instanceId);
        $recipients = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$m1]));
        $this->assertEqualsCanonicalizing([self::U_B, self::U_C], $recipients, 'هر دو Assignee باید همان Messageِ واحد را در کارتابل ببینند.');

        // فقط محسن (U_B) اقدام می‌کند
        $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_B, actionCode: 'APPROVE', comment: null));

        $this->assertSame($afterStart, $this->messagesCount(), 'اقدامِ ANY نباید Messageِ جدید بسازد.');

        $detail = $this->store->getStepTaskDetail($m1);
        $byUser = collect($detail['assignees'])->keyBy('UserID');
        $this->assertSame('APPROVED', $byUser[self::U_B]->Decision, 'فقط U_B باید Decisionِ واقعی داشته باشد.');
        $this->assertNull($byUser[self::U_C]->Decision, 'U_C فقط Assigneeِ واجدِ شرایط بوده؛ نباید Decision داشته باشد.');

        $instData = $this->query->instance($instanceId);
        $this->assertSame('COMPLETED', $instData['instance']->Status);
        $this->assertCount(1, $instData['tasks']);
        $this->assertSame($m1, (int) $instData['tasks'][0]->MessageID);
    }

    /* ==================== Test 3 — حداقل ۳ Step، MessageID ثابت در همه‌جا ==================== */

    public function test_three_steps_message_id_stable_throughout(): void
    {
        $code = 'OMPI_3STEP_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'S1', 'name' => 'مرحلهٔ ۱', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 1],
                ['code' => 'S2', 'name' => 'مرحلهٔ ۲', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'S3', 'name' => 'مرحلهٔ ۳', 'stepType' => 'USER_TASK', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 3],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 4],
            ],
            'actions' => [
                ['stepCode' => 'S1', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'S2', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'S3', 'code' => 'COMPLETE', 'kind' => 'COMPLETE', 'label' => 'تکمیل', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'S1', 'assigneeType' => 'USER', 'refId' => self::U_B],
                ['stepCode' => 'S2', 'assigneeType' => 'USER', 'refId' => self::U_A],
                ['stepCode' => 'S3', 'assigneeType' => 'USER', 'refId' => self::U_C],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'S1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'S1', 'toStepCode' => 'S2', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'S2', 'toStepCode' => 'S3', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T4', 'fromStepCode' => 'S3', 'toStepCode' => 'END', 'triggerActionCode' => 'COMPLETE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $instanceId = $this->startInstance($definitionId);

        $mBefore = $this->activeTaskMessageId($instanceId); // پیش از S1

        $this->engine->performAction(new TaskActionRequest(messageId: $mBefore, userId: self::U_B, actionCode: 'APPROVE', comment: null));
        $mStep2 = $this->activeTaskMessageId($instanceId);
        $this->assertSame($mBefore, $mStep2);

        $this->engine->performAction(new TaskActionRequest(messageId: $mStep2, userId: self::U_A, actionCode: 'APPROVE', comment: null));
        $mStep3 = $this->activeTaskMessageId($instanceId);
        $this->assertSame($mBefore, $mStep3);

        $this->engine->performAction(new TaskActionRequest(messageId: $mStep3, userId: self::U_C, actionCode: 'COMPLETE', comment: null));

        $instData = $this->query->instance($instanceId);
        $this->assertSame('COMPLETED', $instData['instance']->Status);
        $mCompletion = collect($instData['tasks'])->pluck('MessageID')->unique();
        $this->assertCount(1, $mCompletion, 'همهٔ سه StepInstanceِ Task باید دقیقاً یک MessageID مشترک داشته باشند.');
        $this->assertSame($mBefore, (int) $mCompletion->first());
    }

    /* ==================== Test 4 — Generic بودن روی دو Definitionِ متفاوت ==================== */

    public function test_generic_across_two_different_definitions(): void
    {
        $defA = $this->publishTwoSteps('OMPI_DEFA');
        $defB = $this->publishTwoSteps('OMPI_DEFB');

        foreach ([$defA, $defB] as $defId) {
            $before = $this->messagesCount();
            $instanceId = $this->startInstance($defId);
            $afterStart = $this->messagesCount();

            $m1 = $this->activeTaskMessageId($instanceId);
            $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_B, actionCode: 'APPROVE', comment: null));
            $this->assertSame($afterStart, $this->messagesCount(), "Definition #{$defId}: عبور به Stepِ بعدی نباید Messageِ جدید بسازد.");

            $m2 = $this->activeTaskMessageId($instanceId);
            $this->assertSame($m1, $m2, "Definition #{$defId}: MessageID باید بینِ Stepها ثابت بماند.");

            $this->engine->performAction(new TaskActionRequest(messageId: $m2, userId: self::U_A, actionCode: 'COMPLETE', comment: null));
            $this->assertSame('COMPLETED', $this->query->instance($instanceId)['instance']->Status);
        }
    }

    /* ==================== History حفظ می‌شود ==================== */

    public function test_history_preserved_across_shared_message_steps(): void
    {
        $defId = $this->publishTwoSteps();
        $instanceId = $this->startInstance($defId);
        $m1 = $this->activeTaskMessageId($instanceId);
        $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_B, actionCode: 'APPROVE', comment: null));
        $m2 = $this->activeTaskMessageId($instanceId);
        $this->engine->performAction(new TaskActionRequest(messageId: $m2, userId: self::U_A, actionCode: 'COMPLETE', comment: null));

        $history = $this->store->getInstanceHistory($instanceId);
        $codes = array_map(fn ($h) => $h->EventCode, $history);
        foreach (['INSTANCE_STARTED', 'STEP_ENTERED', 'TASK_CREATED', 'TASK_DECISION', 'TASK_COMPLETED', 'TRANSITION_TAKEN', 'INSTANCE_COMPLETED'] as $expected) {
            $this->assertContains($expected, $codes, "رویدادِ {$expected} باید در تاریخچه باشد.");
        }
        // دو رویدادِ TASK_CREATED (یکی برایِ S1، یکی برایِ ادامهٔ همان Message رویِ S2) — هر دو با همان MessageID
        $taskCreatedEvents = array_values(array_filter($history, fn ($h) => $h->EventCode === 'TASK_CREATED'));
        $this->assertCount(2, $taskCreatedEvents);
        $this->assertSame((int) $taskCreatedEvents[0]->MessageID, (int) $taskCreatedEvents[1]->MessageID);

        // getStepTaskDetail بر رویِ MessageID، پس از رسیدن به Stepِ آخر باید دقیقاً همان Stepِ جاری/آخر را بدهد
        $detail = $this->store->getStepTaskDetail($m1);
        $this->assertSame('S2', $detail['task']->StepCode);
        $this->assertSame('COMPLETED', $detail['task']->StepStatus);
    }

    /** getStepTaskDetail باید دقیقاً Assigneeهایِ Stepِ جاری را بدهد، نه انباشتهٔ همهٔ Stepهایِ قبلی. */
    public function test_step_task_detail_shows_only_current_step_assignees_and_actions(): void
    {
        $defId = $this->publishTwoSteps();
        $instanceId = $this->startInstance($defId);
        $m1 = $this->activeTaskMessageId($instanceId);

        $before = $this->store->getStepTaskDetail($m1);
        $this->assertSame('S1', $before['task']->StepCode);
        $this->assertEqualsCanonicalizing([self::U_B], array_map(fn ($a) => (int) $a->UserID, $before['assignees']));
        $this->assertEqualsCanonicalizing(['APPROVE'], array_map(fn ($a) => $a->Code, $before['actions']));

        $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_B, actionCode: 'APPROVE', comment: null));

        $after = $this->store->getStepTaskDetail($m1); // همان MessageID، اما حالا Stepِ جاری S2 است
        $this->assertSame('S2', $after['task']->StepCode);
        $this->assertEqualsCanonicalizing([self::U_A], array_map(fn ($a) => (int) $a->UserID, $after['assignees']), 'نباید Assigneeِ Stepِ قبلی (محسن) هم اینجا بیاید.');
        $this->assertEqualsCanonicalizing(['COMPLETE'], array_map(fn ($a) => $a->Code, $after['actions']), 'نباید Actionِ Stepِ قبلی (APPROVE) هم اینجا بیاید.');
    }

    /**
     * رگرسیونِ باگِ واقعی: وقتی آخرین Step چند Assignee دارد (ANY) و فقط یکی اقدام می‌کند، بستنِ
     * هم‌زمانِ ردیفِ اقدام‌کننده (Done) و ردیفِ همراهِ بدونِ‌اقدام (WontDo) در یک UPDATE باعثِ
     * CreateDateِ یکسان می‌شود؛ لیستِ «ارسالی» (sp_GetMessages Mode=2) نباید با تساویِ CreateDate
     * به‌طورِ اتفاقی ردیفِ WontDo را به‌عنوانِ وضعیتِ نهاییِ Message نشان دهد.
     */
    public function test_sent_list_shows_done_not_wont_do_when_final_step_has_non_acting_co_assignee(): void
    {
        $code = 'OMPI_FINAL2_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'S1', 'name' => 'مرحلهٔ ۱', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 1],
                ['code' => 'S2', 'name' => 'مرحلهٔ نهایی', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [
                ['stepCode' => 'S1', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'S2', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
            ],
            // S2 دو Assignee دارد (ANY) — دقیقاً هم‌الگو با WF101/TASK_2 (POSITION با دو دارنده)
            'assignments' => [
                ['stepCode' => 'S1', 'assigneeType' => 'USER', 'refId' => self::U_B],
                ['stepCode' => 'S2', 'assigneeType' => 'USER', 'refId' => self::U_A],
                ['stepCode' => 'S2', 'assigneeType' => 'USER', 'refId' => self::U_C],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'S1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'S1', 'toStepCode' => 'S2', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'S2', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $instanceId = $this->startInstance($definitionId);
        $m1 = $this->activeTaskMessageId($instanceId);
        $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_B, actionCode: 'APPROVE', comment: null));

        $m2 = $this->activeTaskMessageId($instanceId);
        // فقط U_A اقدام می‌کند؛ U_C (Assigneeِ همراه) هرگز اقدام نمی‌کند
        $result = $this->engine->performAction(new TaskActionRequest(messageId: $m2, userId: self::U_A, actionCode: 'APPROVE', comment: null));

        $this->assertSame('COMPLETED', $result->instanceStatus);

        // ردیف‌هایِ MessageDetails: U_A=Done، U_C=WontDo — با CreateDateِ یکسان (هم‌زمان بسته شده)
        $rows = DB::select('SELECT ToUserID, MessageStatusID, CreateDate FROM MessageDetails WHERE MessageID = ? ORDER BY MessageDetailID', [$m2]);
        $byUser = collect($rows)->keyBy('ToUserID');
        $doneId = (int) DB::selectOne("SELECT MessageStatusID FROM MessageStatuses WHERE MessageStatusName=N'انجام شده'")->MessageStatusID;
        $wontDoId = (int) DB::selectOne("SELECT MessageStatusID FROM MessageStatuses WHERE MessageStatusName=N'انجام نخواهد شد'")->MessageStatusID;
        $this->assertSame($doneId, (int) $byUser[self::U_A]->MessageStatusID, 'خودِ ردیفِ اقدام‌کننده باید Done باشد (این قبلاً هم درست بود).');
        $this->assertSame($wontDoId, (int) $byUser[self::U_C]->MessageStatusID, 'ردیفِ Assigneeِ بدونِ‌اقدام باید WontDo باشد.');

        // نکتهٔ اصلیِ باگ: لیستِ «ارسالی» (Mode=2) — که فرستنده (مهدی) وضعیتِ کلیِ Message را می‌بیند —
        // نباید با تساویِ CreateDate به‌طورِ اتفاقی WontDo را انتخاب کند.
        $sent = collect(DB::select('EXEC sp_GetMessages @UserID = ?, @Mode = 2', [self::STARTER]))->firstWhere('MessageID', $m2);
        $this->assertNotNull($sent);
        $this->assertSame('انجام شده', $sent->MessageStatusName, 'وضعیتِ نهاییِ Message در لیستِ ارسالی باید «انجام شده» باشد، نه «انجام نخواهد شد».');
        $this->assertSame($doneId, (int) $sent->MessageStatusID);
    }

    /**
     * رگرسیونِ باگِ واقعیِ دیگر: در «گردشِ پیام» (MessageDetails.FromUserID)، Senderِ ردیف‌هایِ
     * Stepهایِ بعدی باید همان کسی باشد که واقعاً اقدام کرد و Transition را طی کرد (Actor)، نه همیشه
     * آغازگرِ کلِ Instance. Messages.SenderUserID (ایجادکنندهٔ اصلی) دست‌نخورده می‌ماند.
     */
    public function test_message_flow_from_user_reflects_actual_transition_actor_not_always_initiator(): void
    {
        $defId = $this->publishTwoSteps();
        $instanceId = $this->startInstance($defId);
        $m1 = $this->activeTaskMessageId($instanceId);

        // ردیفِ اولیه: از مهدی (آغازگر) به محسن — این باید همیشه همین‌طور بماند
        $initialRows = DB::select('EXEC sp_GetMessageDetailsList @MessageID = ?', [$m1]);
        $this->assertCount(1, $initialRows);
        $this->assertSame(self::STARTER, (int) $initialRows[0]->FromUserID, 'ردیفِ اولیه باید از آغازگرِ Instance باشد.');
        $this->assertSame(self::U_B, (int) $initialRows[0]->ToUserID);

        // Messages.SenderUserID (ستونِ اصلیِ Message) هرگز نباید تغییر کند
        $senderBefore = (int) DB::selectOne('SELECT SenderUserID FROM Messages WHERE MessageID = ?', [$m1])->SenderUserID;
        $this->assertSame(self::STARTER, $senderBefore);

        // محسن تأیید می‌کند → S2 (علی) — Senderِ ردیفِ جدید باید «محسن» باشد، نه «مهدی»
        $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_B, actionCode: 'APPROVE', comment: null));

        $rowsAfterS1 = collect(DB::select('EXEC sp_GetMessageDetailsList @MessageID = ?', [$m1]));
        $s2Row = $rowsAfterS1->firstWhere('ToUserID', self::U_A);
        $this->assertNotNull($s2Row);
        $this->assertSame(self::U_B, (int) $s2Row->FromUserID, 'گردشِ Stepِ دوم باید از محسن (اقدام‌کنندهٔ Stepِ اول) باشد، نه از آغازگر.');

        // Messages.SenderUserID همچنان باید مهدی باشد (ایجادکنندهٔ اصلی، بدونِ تغییرِ دائمی)
        $senderAfter = (int) DB::selectOne('SELECT SenderUserID FROM Messages WHERE MessageID = ?', [$m1])->SenderUserID;
        $this->assertSame(self::STARTER, $senderAfter, 'Messages.SenderUserID نباید هرگز تغییر کند.');

        // علی تکمیل می‌کند → END
        $this->engine->performAction(new TaskActionRequest(messageId: $m1, userId: self::U_A, actionCode: 'COMPLETE', comment: null));

        // گردشِ نهایی: مهدی→محسن، محسن→علی
        $finalRows = collect(DB::select('EXEC sp_GetMessageDetailsList @MessageID = ?', [$m1]))->sortBy('MessageDetailID')->values();
        $this->assertCount(2, $finalRows);
        $this->assertSame(self::STARTER, (int) $finalRows[0]->FromUserID);
        $this->assertSame(self::U_B, (int) $finalRows[0]->ToUserID);
        $this->assertSame(self::U_B, (int) $finalRows[1]->FromUserID);
        $this->assertSame(self::U_A, (int) $finalRows[1]->ToUserID);
    }
}
