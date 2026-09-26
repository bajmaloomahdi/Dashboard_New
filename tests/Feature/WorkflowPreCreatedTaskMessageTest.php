<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\ConditionFieldService;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Dto\TaskActionRequest;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pre-create + Adopt — WorkflowEngine::startWithNewTaskMessage() / TaskService::adoptStepTask().
 *
 * فقط برایِ EntityType=MESSAGE و فقط وقتی START مستقیماً (بدونِ CONDITIONِ میانی) به
 * اولین USER_TASK/APPROVAL می‌رسد. هدف: دقیقاً یک Message ساخته شود (نه دو)، از نوعِ
 * «وظیفه»، با گیرندهٔ واقعیِ حل‌شده توسطِ همان AssignmentResolver.
 */
class WorkflowPreCreatedTaskMessageTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_A = 2;    // مهدی باج مالو — آغازگر
    private const USER_MGR7 = 4; // محسن باج مالو — DIRECT_MANAGER واحدِ نتیجه‌شده برایِ کاربرِ ۲

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private WorkflowQueryService $query;
    private WorkflowStore $store;
    private ConditionFieldService $conditionFields;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
        $this->query = $this->app->make(WorkflowQueryService::class);
        $this->store = $this->app->make(WorkflowStore::class);
        $this->conditionFields = $this->app->make(ConditionFieldService::class);
    }

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    /** START → TASK_1(USER_TASK, DIRECT_MANAGER) → END — مستقیم، بدونِ CONDITION. */
    private function publishDirectTaskDefinition(): int
    {
        $code = 'PCA_DIRECT_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::USER_A);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'TASK_1', 'name' => 'بررسی', 'stepType' => 'USER_TASK', 'assignPolicy' => 'ANY', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'TASK_1', 'code' => 'COMPLETE', 'kind' => 'COMPLETE', 'label' => 'تکمیل', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK_1', 'assigneeType' => 'DIRECT_MANAGER', 'sortOrder' => 0]],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK_1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK_1', 'toStepCode' => 'END', 'triggerActionCode' => 'COMPLETE'],
            ],
        ], self::USER_A);
        $this->defs->publish((int) $ver->VersionID, self::USER_A);

        return $definitionId;
    }

    /** START → TASK_1(USER_TASK) → COND → END_HIGH/END_LOW — Conditionِ بعدِ Task (مسیرِ COND_TASK_DEMO). */
    private function publishConditionAfterTaskDefinition(): int
    {
        $code = 'PCA_CONDAFTER_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::USER_A);
        $definitionId = (int) $def->DefinitionID;
        // فیلدها اکنون سراسری‌اند — اگر AMOUNT از قبل وجود دارد (دادهٔ واقعیِ COND_TASK_DEMO
        // یا تستِ دیگری) دوباره ساخته نمی‌شود، همان استفاده می‌شود.
        if (collect($this->conditionFields->list(includeInactive: true))->firstWhere('Code', 'AMOUNT') === null) {
            $this->conditionFields->save([
                'code' => 'AMOUNT', 'displayName' => 'مبلغ',
                'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'amount',
            ], self::USER_A);
        }
        $ver = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'TASK_1', 'name' => 'بررسی', 'stepType' => 'USER_TASK', 'assignPolicy' => 'ANY', 'sortOrder' => 1],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 2],
                ['code' => 'END_HIGH', 'name' => 'بالا', 'stepType' => 'END', 'sortOrder' => 3],
                ['code' => 'END_LOW', 'name' => 'پایین', 'stepType' => 'END', 'sortOrder' => 4],
            ],
            'actions' => [['stepCode' => 'TASK_1', 'code' => 'COMPLETE', 'kind' => 'COMPLETE', 'label' => 'تکمیل', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK_1', 'assigneeType' => 'DIRECT_MANAGER', 'sortOrder' => 0]],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK_1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK_1', 'toStepCode' => 'COND', 'triggerActionCode' => 'COMPLETE'],
                ['code' => 'T_HIGH', 'fromStepCode' => 'COND', 'toStepCode' => 'END_HIGH', 'priority' => 10, 'ruleJson' => [
                    'version' => 1, 'type' => 'GROUP', 'logic' => 'AND',
                    'children' => [
                        ['type' => 'CONDITION', 'field' => 'AMOUNT', 'operator' => 'GT', 'value' => ['kind' => 'CONSTANT', 'data' => '1000']],
                    ],
                ]],
                ['code' => 'T_LOW', 'fromStepCode' => 'COND', 'toStepCode' => 'END_LOW', 'priority' => 999, 'isDefault' => true],
            ],
        ], self::USER_A);
        $this->defs->publish((int) $ver->VersionID, self::USER_A);

        return $definitionId;
    }

    /** START → COND → TASK_1 → END — Conditionِ قبلِ Task (باید مسیرِ Pre-create را رد کند). */
    private function publishConditionBeforeTaskDefinition(): int
    {
        $code = 'PCA_CONDBEFORE_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::USER_A);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::USER_A);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'TASK_1', 'name' => 'بررسی', 'stepType' => 'USER_TASK', 'assignPolicy' => 'ANY', 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [['stepCode' => 'TASK_1', 'code' => 'COMPLETE', 'kind' => 'COMPLETE', 'label' => 'تکمیل', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK_1', 'assigneeType' => 'DIRECT_MANAGER', 'sortOrder' => 0]],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK_1', 'isDefault' => true],
                ['code' => 'T3', 'fromStepCode' => 'TASK_1', 'toStepCode' => 'END', 'triggerActionCode' => 'COMPLETE'],
            ],
        ], self::USER_A);
        $this->defs->publish((int) $ver->VersionID, self::USER_A);

        return $definitionId;
    }

    /* ==================================================================== */

    public function test_direct_path_creates_exactly_one_message_with_correct_recipient(): void
    {
        $definitionId = $this->publishDirectTaskDefinition();
        $countBefore = DB::selectOne('SELECT COUNT(*) AS C FROM Messages')->C;

        $result = $this->engine->startWithNewTaskMessage(
            definitionId: $definitionId,
            startedByUserId: self::USER_A,
            subject: 'درخواستِ مرخصیِ ساعتیِ QA',
            messageText: 'اینجانب درخواستِ مرخصیِ ساعتی از ۰۸:۰۰ تا ۱۶:۳۰ را دارم.',
        );

        $countAfter = DB::selectOne('SELECT COUNT(*) AS C FROM Messages')->C;
        $this->assertSame(1, $countAfter - $countBefore, 'باید دقیقاً یک Message ساخته شود.');

        $this->assertSame('RUNNING', $result->instanceStatus);
        $this->assertCount(1, $result->createdMessageIds);
        $messageId = $result->createdMessageIds[0];

        $msg = DB::selectOne('SELECT m.MessageID, m.MessageTypeID, mt.MessageTypeName, m.SenderUserID, m.Subject, m.MessageText
            FROM Messages m JOIN MessageTypes mt ON mt.MessageTypeID = m.MessageTypeID WHERE m.MessageID = ?', [$messageId]);
        $this->assertSame('وظیفه', $msg->MessageTypeName, 'Messageِ ساخته‌شده باید از نوعِ «وظیفه» باشد.');
        $this->assertSame(self::USER_A, (int) $msg->SenderUserID, 'SenderUserID باید initiatorUserId باشد.');
        $this->assertStringContainsString('مرخصی', $msg->Subject);

        $recipients = DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]);
        $recipientIds = array_map(fn ($r) => (int) $r->ToUserID, $recipients);
        $this->assertSame([self::USER_MGR7], $recipientIds, 'گیرندهٔ Message باید دقیقاً همان Assigneeِ واقعیِ Workflow باشد.');

        // نمایشِ Inbox — sp_GetMessages برایِ Assignee
        $inbox = DB::select('EXEC sp_GetMessages @UserID = ?, @Mode = 1', [self::USER_MGR7]);
        $inboxRow = collect($inbox)->firstWhere('MessageID', $messageId);
        $this->assertNotNull($inboxRow, 'Task باید در Inboxِ Assignee دیده شود.');
        $this->assertSame(1, (int) $inboxRow->InInbox);

        // sp_GetMessageHeader.IsWfTask
        $header = DB::selectOne('EXEC sp_GetMessageHeader @MessageID = ?', [$messageId]);
        $this->assertSame(1, (int) $header->IsWfTask);

        // تکمیلِ تسک → END
        $this->engine->performAction(new TaskActionRequest(
            messageId: $messageId, userId: self::USER_MGR7, actionCode: 'COMPLETE', comment: 'تأیید',
        ));

        $instData = $this->query->instance($result->instanceId);
        $this->assertSame('COMPLETED', $instData['instance']->Status);

        // History کامل — از همان SPِ اصلاح‌شدهٔ Fix 2 (InstanceID-scoped)
        $taskDetail = $this->store->getStepTaskDetail($messageId);
        $events = array_map(fn ($h) => $h->EventCode, $taskDetail['history']);
        foreach (['INSTANCE_STARTED', 'STEP_ENTERED', 'TASK_CREATED', 'TASK_COMPLETED', 'INSTANCE_COMPLETED'] as $expected) {
            $this->assertContains($expected, $events, "رویدادِ {$expected} باید در Historyِ سمتِ Task-Card دیده شود.");
        }

        // هیچ Notification/Message دومی برایِ همین عملیات ساخته نشده
        $countFinal = DB::selectOne('SELECT COUNT(*) AS C FROM Messages')->C;
        $this->assertSame(1, $countFinal - $countBefore, 'در کلِ عملیات (تا تکمیلِ تسک) نباید بیش از یک Message ساخته شود.');
    }

    public function test_condition_between_start_and_task_rejects_precreate_and_creates_no_message(): void
    {
        $definitionId = $this->publishConditionBeforeTaskDefinition();
        $countBefore = DB::selectOne('SELECT COUNT(*) AS C FROM Messages')->C;

        $this->expectException(WorkflowValidationException::class);

        try {
            $this->engine->startWithNewTaskMessage(
                definitionId: $definitionId,
                startedByUserId: self::USER_A,
                subject: 'نباید ساخته شود',
            );
        } finally {
            $countAfter = DB::selectOne('SELECT COUNT(*) AS C FROM Messages')->C;
            $this->assertSame(0, $countAfter - $countBefore, 'وقتی مسیر مستقیم نیست، هیچ Messageای نباید ساخته شود.');
        }
    }

    public function test_already_attached_message_cannot_be_adopted_twice(): void
    {
        $definitionId = $this->publishDirectTaskDefinition();

        $first = $this->engine->startWithNewTaskMessage(
            definitionId: $definitionId,
            startedByUserId: self::USER_A,
            subject: 'اولین درخواست',
        );
        $alreadyAttachedMessageId = $first->createdMessageIds[0];

        // تلاش برای Adoptِ دوبارهٔ همان MessageID روی یک Instanceِ دیگر
        $this->expectException(WorkflowStateException::class);
        $this->engine->start(new StartWorkflowRequest(
            entityType: 'MESSAGE',
            entityId: $alreadyAttachedMessageId,
            startedByUserId: self::USER_A,
            definitionId: $definitionId,
            preCreatedMessageId: $alreadyAttachedMessageId,
        ));
    }

    public function test_precreated_message_with_wrong_sender_is_rejected(): void
    {
        $definitionId = $this->publishDirectTaskDefinition();

        // Messageای که Senderاش با startedByUserId مطابقت ندارد (شبیه‌سازیِ ورودیِ دستکاری‌شدهٔ فرانت‌اند)
        $foreignMsg = $this->store->createTaskMessage([
            'subject' => 'Message با فرستندهٔ متفاوت',
            'senderUserId' => self::USER_MGR7,
            'assigneeUserIds' => [self::USER_MGR7],
            'createUser' => self::USER_MGR7,
        ]);
        $foreignMessageId = (int) $foreignMsg->MessageID;

        $this->expectException(WorkflowValidationException::class);
        $this->engine->start(new StartWorkflowRequest(
            entityType: 'MESSAGE',
            entityId: $foreignMessageId,
            startedByUserId: self::USER_A, // آغازگرِ متفاوت از SenderUserIDِ Message
            definitionId: $definitionId,
            preCreatedMessageId: $foreignMessageId,
        ));
    }

    /** رگرسیون — مسیرِ قدیمی (بدونِ preCreatedMessageId) رویِ الگویِ COND_TASK_DEMO باید دست‌نخورده بماند. */
    public function test_old_path_unaffected_for_condition_after_task_definition(): void
    {
        $definitionId = $this->publishConditionAfterTaskDefinition();

        // Messageِ «نامه»یِ عادی — دقیقاً مسیرِ قدیمیِ پیش از این تغییر
        $letterMsg = DB::selectOne(
            "EXEC sp_InsertMessage @MessageTypeID = 1, @msgPriorityID = 1, @Subject = N'نامهٔ عادی', @MessageText = NULL,
                 @RecipientType = 1, @RecipientUserIDs = ?, @CopyUserIDs = NULL, @CopyDescription = NULL,
                 @SenderUserID = ?, @Year = 1405, @CreateUser = ?, @DueDate = NULL",
            [(string) self::USER_MGR7, self::USER_A, self::USER_A]
        );
        $this->assertSame(1, (int) $letterMsg->Success);
        $letterMessageId = (int) $letterMsg->NewMessageID;

        $countBefore = DB::selectOne('SELECT COUNT(*) AS C FROM Messages')->C;

        $result = $this->engine->start(new StartWorkflowRequest(
            entityType: 'MESSAGE',
            entityId: $letterMessageId,
            startedByUserId: self::USER_A,
            definitionId: $definitionId,
            context: [],
            // preCreatedMessageId عمداً داده نمی‌شود — مسیرِ قدیمی
        ));

        $countAfter = DB::selectOne('SELECT COUNT(*) AS C FROM Messages')->C;
        $this->assertSame(1, $countAfter - $countBefore, 'مسیرِ قدیمی باید دقیقاً یک Messageِ تسکِ جدید بسازد (جدا از نامه).');
        $this->assertSame('RUNNING', $result->instanceStatus);
        $this->assertNotSame($letterMessageId, $result->createdMessageIds[0], 'تسک باید Messageِ جداگانه‌ای از نامه باشد (رفتارِ قدیمی).');
    }
}
