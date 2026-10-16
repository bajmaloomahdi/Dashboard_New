<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * هستهٔ Actionها از مسیرِ واقعیِ Designer (PUT graph → validate → publish → اجرا):
 * Validationِ ورودی، هم‌خوانیِ Action↔Transition، اتمیک‌بودنِ ذخیره، پیش‌فرضِ تفویض،
 * و اجرایِ کاملاً Generic (بدونِ هیچ کدِ مخصوصِ یک Workflow).
 */
class WorkflowActionsDesignerApiTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER = 2; // دارایِ همهٔ WORKFLOW_*

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private int $entitySeq = 880000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
    }

    private function as(): self
    {
        $this->actingAs(User::find(self::USER));

        return $this;
    }

    /** @return array{0:int,1:int,2:string} [definitionId, draftVersionId, definitionCode] */
    private function newDraft(string $name): array
    {
        $def = $this->as()->postJson('/workflow/definitions', ['name' => $name, 'entityType' => 'TEST_ENTITY'])->assertOk()->json();
        $ver = $this->as()->postJson("/workflow/definitions/{$def['definitionId']}/versions")->assertOk()->json();

        return [(int) $def['definitionId'], (int) $ver['versionId'], $def['code']];
    }

    private function putGraph(int $versionId, array $graph)
    {
        return $this->as()->putJson("/workflow/versions/{$versionId}/graph", array_merge(
            ['steps' => [], 'actions' => [], 'assignments' => [], 'transitions' => []],
            $graph
        ));
    }

    private function graphOf(int $versionId): array
    {
        return $this->as()->getJson("/workflow/versions/{$versionId}/graph")->assertOk()->json();
    }

    private function counts(int $versionId): array
    {
        $g = $this->graphOf($versionId);

        return [count($g['steps']), count($g['actions']), count($g['assignments']), count($g['transitions'])];
    }

    /** START → REVIEW(USER_TASK, یک Action) → END */
    private function simpleGraph(?array $overrideActions = null, ?array $overrideTransitions = null): array
    {
        return [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START'],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'USER_TASK'],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END'],
            ],
            'assignments' => [['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER]],
            'actions' => $overrideActions ?? [['stepCode' => 'REVIEW', 'code' => 'OK', 'kind' => 'APPROVE', 'label' => 'تأیید']],
            'transitions' => $overrideTransitions ?? [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'OK'],
            ],
        ];
    }

    /* ==================== Validationِ ورودیِ actions.* ==================== */

    public function test_save_graph_rejects_invalid_action_payloads_with_422_and_keeps_previous_graph(): void
    {
        [, $versionId] = $this->newDraft('اعتبارسنجی ورودی');
        $this->putGraph($versionId, $this->simpleGraph())->assertOk();
        $before = $this->counts($versionId);

        $bad = [
            'بدونِ برچسب'      => ['stepCode' => 'REVIEW', 'code' => 'X', 'kind' => 'APPROVE'],
            'Kindِ نامعتبر'    => ['stepCode' => 'REVIEW', 'code' => 'X', 'kind' => 'BOGUS', 'label' => 'x'],
            'Codeِ خیلی بلند'  => ['stepCode' => 'REVIEW', 'code' => str_repeat('A', 65), 'kind' => 'APPROVE', 'label' => 'x'],
            'برچسبِ خیلی بلند' => ['stepCode' => 'REVIEW', 'code' => 'X', 'kind' => 'APPROVE', 'label' => str_repeat('ب', 101)],
            'بدونِ Code'        => ['stepCode' => 'REVIEW', 'kind' => 'APPROVE', 'label' => 'x'],
        ];
        foreach ($bad as $why => $action) {
            $this->putGraph($versionId, $this->simpleGraph([$action], [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END'],
            ]))->assertStatus(422);
            $this->assertSame($before, $this->counts($versionId), "گرافِ قبلی باید دست‌نخورده بماند ({$why})");
        }
    }

    public function test_all_db_allowed_kinds_are_accepted_so_forward_and_cancel_are_untouched(): void
    {
        [, $versionId] = $this->newDraft('همهٔ Kindها');
        $actions = [];
        foreach (['APPROVE', 'REJECT', 'RETURN', 'COMPLETE', 'CUSTOM', 'FORWARD', 'CANCEL'] as $i => $kind) {
            $actions[] = ['stepCode' => 'REVIEW', 'code' => "A_{$kind}", 'kind' => $kind, 'label' => $kind, 'sortOrder' => $i];
        }
        $this->putGraph($versionId, $this->simpleGraph($actions, [
            ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
            ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END'],
        ]))->assertOk();

        $this->assertCount(7, $this->graphOf($versionId)['actions']);
    }

    /* ==================== هم‌خوانیِ Action ↔ Transition ==================== */

    public function test_duplicate_action_code_in_a_step_is_rejected_case_insensitively(): void
    {
        [, $versionId] = $this->newDraft('Code تکراری');
        $res = $this->putGraph($versionId, $this->simpleGraph([
            ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'a'],
            ['stepCode' => 'REVIEW', 'code' => 'approve', 'kind' => 'APPROVE', 'label' => 'b'],
        ], []))->assertStatus(422);

        $this->assertTrue(collect($res->json('errors'))->contains(fn ($m) => str_contains($m, 'تکراری')));
    }

    public function test_same_action_code_is_allowed_in_different_steps(): void
    {
        [, $versionId] = $this->newDraft('Code یکسان در دو Step');
        $graph = $this->simpleGraph();
        $graph['steps'][] = ['code' => 'WORK', 'name' => 'کار', 'stepType' => 'USER_TASK'];
        $graph['assignments'][] = ['stepCode' => 'WORK', 'assigneeType' => 'USER', 'refId' => self::USER];
        $graph['actions'][] = ['stepCode' => 'WORK', 'code' => 'OK', 'kind' => 'COMPLETE', 'label' => 'انجام'];
        $this->putGraph($versionId, $graph)->assertOk();
    }

    public function test_trigger_referencing_missing_or_foreign_step_action_is_rejected_not_silently_dropped(): void
    {
        [, $versionId] = $this->newDraft('Trigger نامعتبر');

        // ۱) Trigger به Actionی که اصلاً وجود ندارد
        $this->putGraph($versionId, $this->simpleGraph(null, [
            ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
            ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'GHOST'],
        ]))->assertStatus(422);

        // ۲) Trigger به Actionِ یک Stepِ دیگر (مبدأ START است، ولی OK متعلق به REVIEW است)
        $this->putGraph($versionId, $this->simpleGraph(null, [
            ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'triggerActionCode' => 'OK'],
            ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'OK'],
        ]))->assertStatus(422);

        // ۳) Action برایِ Stepی که در گراف نیست
        $this->putGraph($versionId, $this->simpleGraph([
            ['stepCode' => 'NOPE', 'code' => 'OK', 'kind' => 'APPROVE', 'label' => 'x'],
        ], []))->assertStatus(422);
    }

    public function test_rename_action_code_with_updated_trigger_saves_and_relinks_but_stale_trigger_is_rejected(): void
    {
        [, $versionId] = $this->newDraft('تغییر Code');
        $this->putGraph($versionId, $this->simpleGraph())->assertOk();

        // همان چیزی که Designer پس از Rename می‌فرستد: Action و Trigger هر دو Code جدید
        $renamed = $this->simpleGraph(
            [['stepCode' => 'REVIEW', 'code' => 'CONFIRMED', 'kind' => 'APPROVE', 'label' => 'تأیید']],
            [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'CONFIRMED'],
            ]
        );
        $this->putGraph($versionId, $renamed)->assertOk();

        $g = $this->graphOf($versionId);
        $actionId = collect($g['actions'])->firstWhere('Code', 'CONFIRMED')['ActionID'];
        $t2 = collect($g['transitions'])->firstWhere('Code', 'T2');
        $this->assertSame((int) $actionId, (int) $t2['TriggerActionID'], 'Trigger باید به Actionِ Rename‌شده وصل باشد');

        // Trigger کهنه (Code قدیمی) نباید بی‌صدا پذیرفته شود
        $stale = $renamed;
        $stale['transitions'][1]['triggerActionCode'] = 'OK';
        $this->putGraph($versionId, $stale)->assertStatus(422);
    }

    public function test_deleting_an_action_requires_clearing_its_trigger_and_leaves_no_dangling_reference(): void
    {
        [, $versionId] = $this->newDraft('حذف Action');
        $this->putGraph($versionId, $this->simpleGraph())->assertOk();

        // حذفِ Action ولی Triggerِ باقی‌مانده ⇒ رد می‌شود
        $this->putGraph($versionId, $this->simpleGraph([], [
            ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
            ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'OK'],
        ]))->assertStatus(422);

        // همان چیزی که Designer پس از حذف می‌فرستد: Trigger خالی
        $this->putGraph($versionId, $this->simpleGraph([], [
            ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
            ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => null],
        ]))->assertOk();

        $this->assertCount(0, $this->graphOf($versionId)['actions']);
        $dangling = DB::selectOne(
            'SELECT COUNT(*) AS c FROM dbo.WorkflowTransitions t WHERE t.VersionID = ? AND t.TriggerActionID IS NOT NULL
             AND NOT EXISTS (SELECT 1 FROM dbo.WorkflowStepActions a WHERE a.ActionID = t.TriggerActionID)',
            [$versionId]
        )->c;
        $this->assertSame(0, (int) $dangling);
    }

    /* ==================== اتمیک‌بودنِ ذخیره ==================== */

    public function test_a_failing_save_does_not_wipe_the_previously_saved_graph(): void
    {
        [, $versionId] = $this->newDraft('اتمیک');
        $this->putGraph($versionId, $this->simpleGraph())->assertOk();
        $before = $this->counts($versionId);

        // Service را مستقیم صدا می‌زنیم (Validationِ Controller را دور می‌زنیم) تا خودِ DB خطا بدهد:
        // Kindِ نامعتبر ⇒ CK_WfStepActions_Kind. بدونِ Transaction، DELETEهایِ SP ماندگار می‌شد.
        try {
            $this->defs->saveGraph($versionId, $this->simpleGraph([
                ['stepCode' => 'REVIEW', 'code' => 'X', 'kind' => 'BOGUS', 'label' => 'x'],
            ], []), self::USER);
            $this->fail('انتظار می‌رفت DB خطا بدهد.');
        } catch (QueryException) {
            // انتظار می‌رود
        }

        $this->assertSame($before, $this->counts($versionId));
    }

    /* ==================== پیش‌فرضِ تفویض ==================== */

    public function test_step_saved_without_allow_delegation_has_no_delegation_and_explicit_true_is_kept(): void
    {
        [, $versionId] = $this->newDraft('پیش‌فرض تفویض');
        $graph = $this->simpleGraph();
        $graph['steps'][1]['allowDelegation'] = false; // REVIEW صریح
        $graph['steps'][] = ['code' => 'WORK', 'name' => 'کار', 'stepType' => 'USER_TASK']; // بدونِ کلیدِ allowDelegation
        $graph['steps'][] = ['code' => 'WORK2', 'name' => 'کار۲', 'stepType' => 'USER_TASK', 'allowDelegation' => true];
        $this->putGraph($versionId, $graph)->assertOk();

        $byCode = collect($this->graphOf($versionId)['steps'])->keyBy('Code');
        $this->assertFalse((bool) $byCode['REVIEW']['AllowDelegation']);
        $this->assertFalse((bool) $byCode['WORK']['AllowDelegation'], 'Stepِ جدید بدونِ کلید ⇒ بدونِ تفویض');
        $this->assertTrue((bool) $byCode['WORK2']['AllowDelegation'], 'روشن‌کردنِ صریح باید حفظ شود');
    }

    /* ==================== Publish با Actionهایِ کامل ==================== */

    public function test_publish_rejects_step_without_action_and_accepts_one_action_with_matching_transition(): void
    {
        [, $versionId] = $this->newDraft('Publish');

        $this->putGraph($versionId, $this->simpleGraph([], [
            ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
            ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END'],
        ]))->assertOk();
        $res = $this->as()->postJson("/workflow/versions/{$versionId}/publish")->assertStatus(422);
        $this->assertTrue(collect($res->json('errors'))->contains(fn ($m) => str_contains($m, 'هیچ Actionی ندارد')));

        $this->putGraph($versionId, $this->simpleGraph())->assertOk();
        $this->as()->postJson("/workflow/versions/{$versionId}/publish")->assertOk()->assertJson(['success' => true]);
    }

    /* ==================== اجرایِ Generic: Definitionهایِ مختلف، بدونِ Hard-code ==================== */

    /**
     * START → REVIEW[approve→WORK, reject→END_NO] ؛ WORK[done→END_OK]
     * نام/Code/Label/Kindِ Actionها کاملاً پارامتری‌اند — همان کد برایِ هر Workflow.
     */
    private function publishReviewFlow(string $name, array $spec): string
    {
        [, $versionId, $code] = $this->newDraft($name);
        $this->putGraph($versionId, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START'],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'USER_TASK'],
                ['code' => 'WORK', 'name' => 'اجرا', 'stepType' => 'USER_TASK'],
                ['code' => 'END_OK', 'name' => 'پایانِ موفق', 'stepType' => 'END'],
                ['code' => 'END_NO', 'name' => 'پایانِ رد‌شده', 'stepType' => 'END'],
            ],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER],
                ['stepCode' => 'WORK', 'assigneeType' => 'USER', 'refId' => self::USER],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => $spec['approve'][0], 'kind' => 'APPROVE', 'label' => $spec['approve'][1], 'sortOrder' => 0],
                ['stepCode' => 'REVIEW', 'code' => $spec['reject'][0], 'kind' => 'REJECT', 'label' => $spec['reject'][1], 'sortOrder' => 1],
                ['stepCode' => 'WORK', 'code' => $spec['done'][0], 'kind' => 'COMPLETE', 'label' => $spec['done'][1], 'sortOrder' => 0],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW'],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'WORK', 'triggerActionCode' => $spec['approve'][0]],
                ['code' => 'T3', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END_NO', 'triggerActionCode' => $spec['reject'][0]],
                ['code' => 'T4', 'fromStepCode' => 'WORK', 'toStepCode' => 'END_OK', 'triggerActionCode' => $spec['done'][0]],
            ],
        ])->assertOk();
        $this->as()->postJson("/workflow/versions/{$versionId}/publish")->assertOk();

        return $code;
    }

    private function startTask(string $definitionCode): array
    {
        $res = $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: ++$this->entitySeq, startedByUserId: self::USER, definitionCode: $definitionCode,
        ));

        return [(int) $res->instanceId, (int) $res->createdMessageIds[0]];
    }

    private function endStepOf(int $instanceId): string
    {
        return DB::selectOne("SELECT StepCode FROM dbo.WorkflowStepInstances WHERE InstanceID = ? AND StepCode LIKE 'END%'", [$instanceId])->StepCode;
    }

    #[DataProvider('workflowSpecs')]
    public function test_generic_execution_approve_path_and_reject_path_follow_their_own_transitions(string $name, array $spec): void
    {
        $code = $this->publishReviewFlow($name, $spec);

        // مسیرِ تأیید
        [$instanceA, $taskA] = $this->startTask($code);
        $detail = $this->as()->getJson("/workflow/messages/{$taskA}")->assertOk()->json();
        $this->assertSame(
            [[$spec['approve'][0], 'APPROVE', $spec['approve'][1]], [$spec['reject'][0], 'REJECT', $spec['reject'][1]]],
            collect($detail['actions'])->map(fn ($a) => [$a['Code'], $a['Kind'], $a['Label']])->all(),
            'دکمه‌ها باید عیناً از Definition (به ترتیبِ SortOrder) بیایند'
        );
        $this->assertTrue($detail['canAct']);
        $this->assertFalse((bool) $detail['task']['AllowDelegation'], 'Stepِ جدید نباید تفویض داشته باشد');

        $act = $this->as()->postJson("/workflow/messages/{$taskA}/actions", ['actionCode' => $spec['approve'][0]])->assertOk()->json();
        $this->assertSame('RUNNING', $act['instanceStatus']);
        $this->assertSame('WORK', $act['enteredStepCode']);

        $taskA2 = (int) $act['createdMessageIds'][0];
        $detail2 = $this->as()->getJson("/workflow/messages/{$taskA2}")->assertOk()->json();
        $this->assertSame([[$spec['done'][0], 'COMPLETE', $spec['done'][1]]], collect($detail2['actions'])->map(fn ($a) => [$a['Code'], $a['Kind'], $a['Label']])->all());

        $this->as()->postJson("/workflow/messages/{$taskA2}/actions", ['actionCode' => $spec['done'][0]])->assertOk()->assertJson(['instanceStatus' => 'COMPLETED']);
        $this->assertSame('END_OK', $this->endStepOf($instanceA));

        // مسیرِ رد (نمونهٔ مستقل)
        [$instanceB, $taskB] = $this->startTask($code);
        $this->as()->postJson("/workflow/messages/{$taskB}/actions", ['actionCode' => $spec['reject'][0]])->assertOk()->assertJson(['instanceStatus' => 'COMPLETED']);
        $this->assertSame('END_NO', $this->endStepOf($instanceB));
    }

    public static function workflowSpecs(): array
    {
        return [
            // نام‌ها/Codeها عمداً هیچ ربطی به «مرخصی» ندارند و با یکدیگر متفاوت‌اند
            'مساعده'  => ['مساعده', ['approve' => ['GRANT', 'موافقت'], 'reject' => ['DENY', 'مخالفت'], 'done' => ['PAID', 'پرداخت شد']]],
            'خرید'    => ['خرید',   ['approve' => ['ACCEPT_X', 'تأییدِ خرید'], 'reject' => ['DECLINE_X', 'ردِ خرید'], 'done' => ['ORDERED', 'سفارش ثبت شد']]],
        ];
    }
}
