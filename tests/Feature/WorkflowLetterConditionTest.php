<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\ConditionFieldService;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * نامهٔ فرایندی وقتی مسیرِ START تا اولین Task از CONDITION عبور می‌کند — «مساعده»محور،
 * ولی کاملاً Generic (هیچ Code/DefinitionID خاصی Hard-code نشده؛ فقط از AssigneeType=USER
 * و یک ConditionFieldِ عمومیِ AMOUNT استفاده می‌شود که هر Definitionی می‌تواند داشته باشد).
 *
 * تضمین‌هایِ اصلی: وجودِ CONDITION دیگر Blockِ Submit نیست؛ دقیقاً یک Message برایِ کلِ
 * Instance؛ گیرنده فقط در سرور و فقط بعدِ ارزیابیِ واقعیِ CONDITION تعیین می‌شود؛ شکستِ هر
 * بخش (Context/Condition/Assignment/Adopt) کلِ Start را Rollback می‌کند؛ مسیرهایِ بدونِ
 * CONDITION دقیقاً همان رفتارِ قبلی را دارند.
 */
class WorkflowLetterConditionTest extends TestCase
{
    use DatabaseTransactions;

    private const STARTER = 2; // مهدی
    private const U_MANAGER = 4; // محسن — DIRECT_MANAGERِ کاربرِ ۲
    private const U_A = 3; // علی
    private const U_C = 14; // دمو

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

    private function ensureAmountField(): void
    {
        if (collect($this->conditionFields->list(includeInactive: true))->firstWhere('Code', 'AMOUNT') === null) {
            $this->conditionFields->save([
                'code' => 'AMOUNT', 'displayName' => 'مبلغ',
                'dataType' => 'DECIMAL', 'sourceType' => 'START_CONTEXT', 'sourceKey' => 'amount',
            ], self::STARTER);
        }
    }

    private function amountRule(string $operator, string $value): array
    {
        return [
            'version' => 1, 'type' => 'GROUP', 'logic' => 'AND',
            'children' => [
                ['type' => 'CONDITION', 'field' => 'AMOUNT', 'operator' => $operator, 'value' => ['kind' => 'CONSTANT', 'data' => $value]],
            ],
        ];
    }

    private function newCode(string $prefix): string
    {
        return $prefix . '_' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function messagesCount(): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) c FROM Messages')->c;
    }

    /* ==================== Test A — بدونِ CONDITION، رفتارِ فعلی حفظ می‌شود ==================== */

    public function test_a_no_condition_behaves_exactly_as_before(): void
    {
        $code = $this->newCode('COND_A');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'TASK', 'name' => 'تأیید', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK', 'assigneeType' => 'DIRECT_MANAGER']],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $preview = $this->engine->previewAssignment($definitionId, self::STARTER);
        $this->assertTrue($preview['resolved']);
        $this->assertNull($preview['reason']);
        $this->assertSame([self::U_MANAGER], array_column($preview['users'], 'userId'), 'گیرنده باید همان لحظهٔ Preview (بدونِ CONDITION) مشخص باشد.');

        $before = $this->messagesCount();
        $result = $this->engine->startWithNewTaskMessage($definitionId, self::STARTER, 'درخواست تست A');
        $this->assertSame(1, $this->messagesCount() - $before);
        $this->assertSame('RUNNING', $result->instanceStatus);

        $messageId = $result->createdMessageIds[0];
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::U_MANAGER], $to);
    }

    /* ==================== Test B — START → CONDITION → USER_TASK ==================== */

    public function test_b_condition_then_task_creates_one_message_and_adopts_real_task(): void
    {
        $this->ensureAmountField();
        $code = $this->newCode('COND_B');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'TASK', 'name' => 'تأیید مدیر', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK', 'assigneeType' => 'DIRECT_MANAGER']],
            'transitions' => [
                ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T1', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK', 'priority' => 10, 'ruleJson' => $this->amountRule('GT', '1000')],
                // «else» — هر CONDITION الزاماً یک گذارِ پیش‌فرض می‌خواهد (طبقِ Publish Validation)؛
                // اینجا صرفاً برایِ برآوردنِ همین الزام، به همان مقصد اشاره می‌کند.
                ['code' => 'T1_ELSE', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK', 'priority' => 999, 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        // Preview نباید مسدود کند؛ فقط می‌گوید بعداً مشخص می‌شود
        $preview = $this->engine->previewAssignment($definitionId, self::STARTER);
        $this->assertFalse($preview['resolved']);
        $this->assertSame('CONDITION', $preview['reason']);

        $before = $this->messagesCount();
        $result = $this->engine->startWithNewTaskMessage(
            $definitionId, self::STARTER, 'درخواست مساعده', formValues: ['amount' => '5000']
        );

        $this->assertSame(1, $this->messagesCount() - $before, 'دقیقاً یک Message — نه برایِ Adoptِ اولیه، نه برایِ Taskِ واقعی.');
        $this->assertSame('RUNNING', $result->instanceStatus);
        $this->assertCount(1, $result->createdMessageIds);

        $messageId = $result->createdMessageIds[0];
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::U_MANAGER], $to, 'گیرندهٔ واقعیِ Task (بعدِ CONDITION) باید مدیرِ مستقیم باشد.');

        $msg = DB::selectOne('SELECT SenderUserID FROM Messages WHERE MessageID = ?', [$messageId]);
        $this->assertSame(self::STARTER, (int) $msg->SenderUserID);

        $si = DB::selectOne('SELECT StepInstanceID, StepCode FROM WorkflowStepInstances WHERE MessageID = ?', [$messageId]);
        $this->assertSame('TASK', $si->StepCode);
    }

    /* ==================== Test C — چند CONDITION پشتِ‌سرِهم ==================== */

    public function test_c_multiple_conditions_in_a_row_are_traversed_generically(): void
    {
        $this->ensureAmountField();
        $code = $this->newCode('COND_C');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND1', 'name' => 'شرطِ ۱', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'COND2', 'name' => 'شرطِ ۲', 'stepType' => 'CONDITION', 'sortOrder' => 2],
                ['code' => 'TASK', 'name' => 'تأیید', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 3],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 4],
            ],
            'actions' => [['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK', 'assigneeType' => 'DIRECT_MANAGER']],
            'transitions' => [
                ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND1', 'isDefault' => true],
                ['code' => 'T1', 'fromStepCode' => 'COND1', 'toStepCode' => 'COND2', 'priority' => 10, 'ruleJson' => $this->amountRule('GT', '100')],
                ['code' => 'T1_ELSE', 'fromStepCode' => 'COND1', 'toStepCode' => 'COND2', 'priority' => 999, 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'COND2', 'toStepCode' => 'TASK', 'priority' => 10, 'ruleJson' => $this->amountRule('LT', '999999')],
                ['code' => 'T2_ELSE', 'fromStepCode' => 'COND2', 'toStepCode' => 'TASK', 'priority' => 999, 'isDefault' => true],
                ['code' => 'T3', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $before = $this->messagesCount();
        $result = $this->engine->startWithNewTaskMessage(
            $definitionId, self::STARTER, 'درخواست تست C', formValues: ['amount' => '5000']
        );
        $this->assertSame(1, $this->messagesCount() - $before);
        $this->assertSame('RUNNING', $result->instanceStatus);

        $steps = collect($this->query->instance($result->instanceId)['steps'])->pluck('StepCode')->all();
        $this->assertContains('COND1', $steps);
        $this->assertContains('COND2', $steps);
        $this->assertContains('TASK', $steps);
    }

    /* ==================== Test D/E — دو مسیرِ متفاوتِ CONDITION، هرکدام Assignmentِ خودش ==================== */

    private function publishTwoBranchDefinition(): int
    {
        $this->ensureAmountField();
        $code = $this->newCode('COND_DE');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'TASK_HIGH', 'name' => 'تأییدِ ارشد', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'TASK_LOW', 'name' => 'تأییدِ ساده', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 3],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 4],
            ],
            'actions' => [
                ['stepCode' => 'TASK_HIGH', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'TASK_LOW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'TASK_HIGH', 'assigneeType' => 'USER', 'refId' => self::U_A],
                ['stepCode' => 'TASK_LOW', 'assigneeType' => 'USER', 'refId' => self::U_C],
            ],
            'transitions' => [
                ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T_HIGH', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK_HIGH', 'priority' => 10, 'ruleJson' => $this->amountRule('GT', '1000')],
                ['code' => 'T_LOW', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK_LOW', 'priority' => 999, 'isDefault' => true],
                ['code' => 'T_END_HIGH', 'fromStepCode' => 'TASK_HIGH', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T_END_LOW', 'fromStepCode' => 'TASK_LOW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        return $definitionId;
    }

    public function test_d_condition_routes_to_branch_a_with_its_own_assignment(): void
    {
        $definitionId = $this->publishTwoBranchDefinition();
        $result = $this->engine->startWithNewTaskMessage(
            $definitionId, self::STARTER, 'مبلغِ بالا', formValues: ['amount' => '5000']
        );
        $messageId = $result->createdMessageIds[0];
        $si = DB::selectOne('SELECT StepCode FROM WorkflowStepInstances WHERE MessageID = ?', [$messageId]);
        $this->assertSame('TASK_HIGH', $si->StepCode);
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::U_A], $to);
    }

    public function test_e_condition_routes_to_branch_b_with_its_own_assignment(): void
    {
        $definitionId = $this->publishTwoBranchDefinition();
        $result = $this->engine->startWithNewTaskMessage(
            $definitionId, self::STARTER, 'مبلغِ پایین', formValues: ['amount' => '10']
        );
        $messageId = $result->createdMessageIds[0];
        $si = DB::selectOne('SELECT StepCode FROM WorkflowStepInstances WHERE MessageID = ?', [$messageId]);
        $this->assertSame('TASK_LOW', $si->StepCode);
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::U_C], $to);
    }

    /* ==================== Test F — Task مقصد Assignee ندارد → Fail کامل، Rollback ==================== */

    public function test_f_condition_then_unresolvable_assignment_fails_and_rolls_back_completely(): void
    {
        $this->ensureAmountField();
        $code = $this->newCode('COND_F');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                // Assigneeِ غیرِقابلِ‌حل: کاربرِ ۴ (محسن) هیچ DIRECT_MANAGERی ندارد (طبقِ دادهٔ
                // سازمانیِ واقعیِ همین محیط، بارها در این نشست بررسی شده).
                ['code' => 'TASK', 'name' => 'تأیید', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK', 'assigneeType' => 'DIRECT_MANAGER']],
            'transitions' => [
                ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T1', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $beforeMessages = $this->messagesCount();
        $beforeInstances = (int) DB::selectOne('SELECT COUNT(*) c FROM WorkflowInstances')->c;

        // مهدی (STARTER) آغازگر است ولی Taskِ واقعی به‌جایِ DIRECT_MANAGERِ او با
        // DIRECT_MANAGERِ کاربرِ بدونِ مدیر (محسن) تلاش می‌کند — برایِ همین به‌عنوانِ آغازگر
        // از محسن استفاده می‌کنیم تا AssignmentResolver واقعاً چیزی پیدا نکند.
        $this->expectException(WorkflowValidationException::class);
        try {
            $this->engine->startWithNewTaskMessage(
                $definitionId, self::U_MANAGER, 'درخواستِ بدونِ گیرنده', formValues: []
            );
        } finally {
            $this->assertSame($beforeMessages, $this->messagesCount(), 'هیچ Messageای — حتی ناقص — نباید باقی بماند.');
            $this->assertSame($beforeInstances, (int) DB::selectOne('SELECT COUNT(*) c FROM WorkflowInstances')->c, 'هیچ Instanceای نباید باقی بماند.');
        }
    }

    /* ==================== Test G — چند Assignee برایِ Stepِ مقصد، همان یک MessageID ==================== */

    public function test_g_multiple_assignees_on_destination_step_share_one_message(): void
    {
        $this->ensureAmountField();
        $code = $this->newCode('COND_G');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'TASK', 'name' => 'کارشناسیِ مالی', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'actions' => [['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => [
                ['stepCode' => 'TASK', 'assigneeType' => 'USER', 'refId' => self::U_A],
                ['stepCode' => 'TASK', 'assigneeType' => 'USER', 'refId' => self::U_C],
            ],
            'transitions' => [
                ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T1', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $before = $this->messagesCount();
        $result = $this->engine->startWithNewTaskMessage($definitionId, self::STARTER, 'درخواستِ چندگیرنده');
        $this->assertSame(1, $this->messagesCount() - $before, 'چند Assignee = همچنان فقط یک Message.');

        $messageId = $result->createdMessageIds[0];
        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ? ORDER BY ToUserID', [$messageId]));
        $this->assertSame([self::U_A, self::U_C], $to);
    }

    /* ==================== Test H — بدونِ CONDITION و بدونِ Assignee: Regressionِ رفتارِ قبلی ==================== */

    public function test_h_no_condition_and_no_assignee_still_fails_as_before(): void
    {
        $code = $this->newCode('COND_H');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'TASK', 'name' => 'تأیید', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0]],
            'assignments' => [['stepCode' => 'TASK', 'assigneeType' => 'DIRECT_MANAGER']],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $before = $this->messagesCount();
        $this->expectException(WorkflowValidationException::class);
        try {
            // محسن به‌عنوانِ آغازگر، بدونِ DIRECT_MANAGER
            $this->engine->startWithNewTaskMessage($definitionId, self::U_MANAGER, 'بدونِ گیرنده');
        } finally {
            $this->assertSame($before, $this->messagesCount());
        }
    }

    /* ==================== Test I — Client نمی‌تواند گیرنده را دستکاری کند (سطحِ HTTP) ==================== */

    public function test_i_client_supplied_recipient_is_ignored_server_resolves_real_one(): void
    {
        $definitionId = $this->publishTwoBranchDefinition();

        // فرمِ نمونه نیازمندِ یک TemplateParameterِ FORM با SourceKey برابرِ amount است تا با
        // ConditionFieldِ AMOUNT (که همان SourceKey را دارد) هماهنگ باشد.
        $paramLatinName = $this->newCode('AMOUNT_FORM');
        $this->app->make(\App\Services\Workflow\TemplateParameterService::class)->save([
            'latinName' => $paramLatinName, 'caption' => 'مبلغ', 'sourceType' => 'FORM', 'sourceKey' => 'amount',
            'dataType' => 'DECIMAL', 'entityType' => 'MESSAGE',
        ], self::STARTER);

        $templateRes = $this->app->make(\App\Services\Workflow\LetterTemplateService::class)->save([
            'code' => $this->newCode('LT'), 'name' => 'قالبِ تست', 'entityType' => 'MESSAGE', 'definitionId' => $definitionId,
            'subjectTemplate' => 'درخواستِ مبلغ {{USER_FULL_NAME}}', 'bodyTemplate' => "مبلغِ درخواستی: {{{$paramLatinName}}}",
        ], self::STARTER);

        $before = $this->messagesCount();

        $res = $this->as(self::STARTER)->postJson('/workflow/letters', [
            'definitionId' => $definitionId,
            'letterTemplateId' => (int) $templateRes->LetterTemplateID,
            'formValues' => ['amount' => '5000'],
            'msgPriorityID' => 1,
            // تلاشِ Clientِ فرضی برایِ دستکاریِ گیرنده — باید کاملاً نادیده گرفته شود
            'RecipientUserIDs' => [self::U_C],
            'RecipientType' => 2,
            'SenderUserID' => self::U_C,
        ]);

        $res->assertOk();
        $this->assertSame(1, $this->messagesCount() - $before);
        $messageId = (int) $res->json('messageId');

        $to = array_map(fn ($r) => (int) $r->ToUserID, DB::select('SELECT ToUserID FROM MessageDetails WHERE MessageID = ?', [$messageId]));
        $this->assertSame([self::U_A], $to, 'گیرندهٔ واقعی باید نتیجهٔ CONDITION+AssignmentResolver باشد (علی)، نه چیزی که Client فرستاد (دمو).');
        $sender = (int) DB::selectOne('SELECT SenderUserID FROM Messages WHERE MessageID = ?', [$messageId])->SenderUserID;
        $this->assertSame(self::STARTER, $sender, 'فرستنده باید همان آغازگرِ واقعی (مهدی) باشد، نه SenderUserIDِ جعلیِ ارسالی.');
    }

    /* ==================== Test J — یک Message برایِ کلِ Instance، حتی با دو Taskِ پشتِ‌سرِهم ==================== */

    public function test_j_one_message_per_instance_holds_with_condition_and_two_tasks(): void
    {
        $this->ensureAmountField();
        $code = $this->newCode('COND_J');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'TASK1', 'name' => 'تأییدِ مدیر', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'TASK2', 'name' => 'ثبتِ مالی', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 3],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 4],
            ],
            'actions' => [
                ['stepCode' => 'TASK1', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'TASK2', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'TASK1', 'assigneeType' => 'DIRECT_MANAGER'],
                ['stepCode' => 'TASK2', 'assigneeType' => 'USER', 'refId' => self::U_A],
            ],
            'transitions' => [
                ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T1', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK1', 'toStepCode' => 'TASK2', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'TASK2', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $before = $this->messagesCount();
        $result = $this->engine->startWithNewTaskMessage($definitionId, self::STARTER, 'درخواستِ مساعدهٔ دومرحله‌ای');
        $messageId = $result->createdMessageIds[0];

        // محسن (TASK1) تأیید می‌کند
        $this->engine->performAction(new \App\Services\Workflow\Dto\TaskActionRequest(
            messageId: $messageId, userId: self::U_MANAGER, actionCode: 'APPROVE', comment: null,
        ));
        // علی (TASK2) تکمیل می‌کند
        $finalResult = $this->engine->performAction(new \App\Services\Workflow\Dto\TaskActionRequest(
            messageId: $messageId, userId: self::U_A, actionCode: 'APPROVE', comment: null,
        ));

        $this->assertSame('COMPLETED', $finalResult->instanceStatus);
        $this->assertSame(1, $this->messagesCount() - $before, 'در کلِ عمرِ Instance (CONDITION + دو Task) فقط یک Message ساخته شده باشد.');

        $taskSteps = collect($this->query->instance($result->instanceId)['tasks']);
        $this->assertCount(2, $taskSteps);
        $this->assertTrue($taskSteps->every(fn ($t) => (int) $t->MessageID === $messageId));
    }

    /* ==================== Test K — گردشِ Sender: آغازگر → Actorِ Step۱ → Actorِ Step۲ ==================== */

    public function test_k_sender_chain_reflects_actual_actors_through_condition_path(): void
    {
        $this->ensureAmountField();
        $code = $this->newCode('COND_K');
        $def = $this->defs->save(['latinName' => $code, 'name' => 'ت', 'entityType' => 'MESSAGE'], self::STARTER);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::STARTER);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'COND', 'name' => 'شرط', 'stepType' => 'CONDITION', 'sortOrder' => 1],
                ['code' => 'TASK1', 'name' => 'تأییدِ مدیر', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 2],
                ['code' => 'TASK2', 'name' => 'ثبتِ مالی', 'stepType' => 'APPROVAL', 'assignPolicy' => 'ANY', 'allowDelegation' => false, 'sortOrder' => 3],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 4],
            ],
            'actions' => [
                ['stepCode' => 'TASK1', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
                ['stepCode' => 'TASK2', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید', 'sortOrder' => 0],
            ],
            'assignments' => [
                ['stepCode' => 'TASK1', 'assigneeType' => 'DIRECT_MANAGER'],
                ['stepCode' => 'TASK2', 'assigneeType' => 'USER', 'refId' => self::U_A],
            ],
            'transitions' => [
                ['code' => 'T0', 'fromStepCode' => 'START', 'toStepCode' => 'COND', 'isDefault' => true],
                ['code' => 'T1', 'fromStepCode' => 'COND', 'toStepCode' => 'TASK1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK1', 'toStepCode' => 'TASK2', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'TASK2', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::STARTER);
        $this->defs->publish((int) $ver->VersionID, self::STARTER);

        $result = $this->engine->startWithNewTaskMessage($definitionId, self::STARTER, 'درخواستِ گردش');
        $messageId = $result->createdMessageIds[0];

        $this->engine->performAction(new \App\Services\Workflow\Dto\TaskActionRequest(
            messageId: $messageId, userId: self::U_MANAGER, actionCode: 'APPROVE', comment: null,
        ));
        $this->engine->performAction(new \App\Services\Workflow\Dto\TaskActionRequest(
            messageId: $messageId, userId: self::U_A, actionCode: 'APPROVE', comment: null,
        ));

        $rows = collect(DB::select('EXEC sp_GetMessageDetailsList @MessageID = ?', [$messageId]))->sortBy('MessageDetailID')->values();
        $this->assertCount(2, $rows);
        $this->assertSame(self::STARTER, (int) $rows[0]->FromUserID, 'ردیفِ اول: از آغازگر به Actorِ Step۱.');
        $this->assertSame(self::U_MANAGER, (int) $rows[0]->ToUserID);
        $this->assertSame(self::U_MANAGER, (int) $rows[1]->FromUserID, 'ردیفِ دوم: از Actorِ Step۱ به Actorِ Step۲.');
        $this->assertSame(self::U_A, (int) $rows[1]->ToUserID);
    }
}
