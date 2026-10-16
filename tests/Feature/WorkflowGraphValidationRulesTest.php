<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * قواعدِ جدیدِ اعتبارسنجیِ گراف (Action↔Transition) که مانعِ Publishِ یک Workflowِ
 * ناقص می‌شوند — کاملاً Generic، بدونِ هیچ ارجاعی به یک Definition/Step خاص.
 *
 * ریشه: بررسیِ یک نمونهٔ واقعی نشان داد یک Stepِ USER_TASK می‌توانست بدونِ هیچ Action
 * و با یک Transitionِ نه Trigger‌شده نه Default، Publish شود — نتیجه یک Instanceِ
 * ACTIVEِ بن‌بست بود که هرگز از آن Step عبور نمی‌کرد.
 */
class WorkflowGraphValidationRulesTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER = 2;

    private WorkflowDefinitionService $defs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
    }

    /** پیش‌نویسِ خام: START → TASK(USER_TASK) → END، بدونِ Action/Transitionِ Step میانی. */
    private function draftWithUserTask(): array
    {
        $def = $this->defs->save(['name' => 'گرافِ تستِ اعتبارسنجی', 'entityType' => 'TEST_ENTITY'], self::USER);
        $versionId = (int) $this->defs->createDraft((int) $def->DefinitionID, self::USER)->VersionID;

        return [(int) $def->DefinitionID, $versionId, $def->Code];
    }

    private function saveGraph(int $versionId, array $graph): void
    {
        $this->defs->saveGraph($versionId, array_merge([
            'steps' => [], 'actions' => [], 'assignments' => [], 'transitions' => [],
        ], $graph), self::USER);
    }

    private function baseSteps(): array
    {
        return [
            ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
            ['code' => 'TASK', 'name' => 'مرحلهٔ اقدام', 'stepType' => 'USER_TASK', 'sortOrder' => 1],
            ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
        ];
    }

    private function baseAssignments(): array
    {
        return [['stepCode' => 'TASK', 'assigneeType' => 'USER', 'refId' => self::USER]];
    }

    public function test_publish_rejects_user_task_step_with_no_action_at_all(): void
    {
        [, $versionId] = $this->draftWithUserTask();

        $this->saveGraph($versionId, [
            'steps' => $this->baseSteps(),
            'assignments' => $this->baseAssignments(),
            // بدونِ actions
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'isDefault' => true],
            ],
        ]);

        try {
            $this->defs->publish($versionId, self::USER);
            $this->fail('انتظار می‌رفت Publish با WorkflowValidationException رد شود.');
        } catch (WorkflowValidationException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn ($m) => str_contains($m, 'هیچ Actionی ندارد')),
                'پیامِ خطا شاملِ «هیچ Actionی ندارد» نیست: '.implode(' | ', $e->errors)
            );
        }
    }

    public function test_publish_rejects_action_with_no_matching_transition(): void
    {
        [, $versionId] = $this->draftWithUserTask();

        $this->saveGraph($versionId, [
            'steps' => $this->baseSteps(),
            'assignments' => $this->baseAssignments(),
            'actions' => [
                ['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                // TASK→END فقط Default است؛ هیچ Transitionی با triggerActionCode=APPROVE نیست.
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'isDefault' => true],
            ],
        ]);

        try {
            $this->defs->publish($versionId, self::USER);
            $this->fail('انتظار می‌رفت Publish با WorkflowValidationException رد شود.');
        } catch (WorkflowValidationException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn ($m) => str_contains($m, 'هیچ گذارِ متناظری ندارد')),
                'پیامِ خطا شاملِ «هیچ گذارِ متناظری ندارد» نیست: '.implode(' | ', $e->errors)
            );
        }
    }

    /**
     * حالتِ واقعیِ رگرسیون: Stepِ خودکار (START) با دقیقاً یک گذارِ خروجیِ بدونِ
     * Trigger و بدونِ Default باید معتبر باشد — چون TransitionResolver::resolve()
     * دقیقاً همین حالت را با «تکِ گذارِ بدونِ Trigger» حل می‌کند (بدونِ نیاز به Default).
     * این الگو دقیقاً همانِ چیزی است که در Workflowِ واقعیِ «مرخصی» وجود دارد.
     */
    public function test_publish_accepts_single_untriggered_transition_on_an_automatic_step(): void
    {
        [, $versionId] = $this->draftWithUserTask();

        $this->saveGraph($versionId, [
            'steps' => $this->baseSteps(),
            'assignments' => $this->baseAssignments(),
            'actions' => [
                ['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
            ],
            'transitions' => [
                // بدونِ triggerActionCode و بدونِ isDefault — تکِ گذارِ خروجیِ START.
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK'],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ]);

        $result = $this->defs->publish($versionId, self::USER);
        $this->assertSame(1, (int) $result->Success);
    }

    /**
     * حالتِ واقعاً ناسالم: Stepِ خودکار (START) با ≥۲ گذارِ خروجیِ بدونِ Trigger و
     * بدونِ هیچ Default — اینجا Runtime واقعاً نمی‌تواند تصمیم بگیرد کدام را طی کند.
     */
    public function test_publish_rejects_automatic_step_with_ambiguous_untriggered_transitions(): void
    {
        [, $versionId] = $this->draftWithUserTask();

        $this->saveGraph($versionId, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'TASK', 'name' => 'مرحلهٔ اقدام', 'stepType' => 'USER_TASK', 'sortOrder' => 1],
                ['code' => 'TASK2', 'name' => 'مرحلهٔ دوم', 'stepType' => 'USER_TASK', 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'assignments' => [
                ['stepCode' => 'TASK', 'assigneeType' => 'USER', 'refId' => self::USER],
                ['stepCode' => 'TASK2', 'assigneeType' => 'USER', 'refId' => self::USER],
            ],
            'actions' => [
                ['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
                ['stepCode' => 'TASK2', 'code' => 'DONE', 'kind' => 'COMPLETE', 'label' => 'انجام شد'],
            ],
            'transitions' => [
                // ابهام: START دو گذارِ خروجیِ بدونِ Trigger دارد و هیچ‌کدام Default نیست.
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK'],
                ['code' => 'T2', 'fromStepCode' => 'START', 'toStepCode' => 'TASK2'],
                ['code' => 'T3', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T4', 'fromStepCode' => 'TASK2', 'toStepCode' => 'END', 'triggerActionCode' => 'DONE'],
            ],
        ]);

        try {
            $this->defs->publish($versionId, self::USER);
            $this->fail('انتظار می‌رفت Publish با WorkflowValidationException رد شود.');
        } catch (WorkflowValidationException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn ($m) => str_contains($m, 'Runtime نمی‌تواند مسیرِ بعدی را تعیین کند')),
                'پیامِ خطا شاملِ عبارتِ مورد انتظار نیست: '.implode(' | ', $e->errors)
            );
        }
    }

    public function test_publish_rejects_more_than_one_default_transition_on_same_step(): void
    {
        [, $versionId] = $this->draftWithUserTask();

        $this->saveGraph($versionId, [
            'steps' => $this->baseSteps(),
            'assignments' => $this->baseAssignments(),
            'actions' => [
                ['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'isDefault' => true],
                ['code' => 'T4', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'isDefault' => true],
            ],
        ]);

        try {
            $this->defs->publish($versionId, self::USER);
            $this->fail('انتظار می‌رفت Publish با WorkflowValidationException رد شود.');
        } catch (WorkflowValidationException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn ($m) => str_contains($m, 'بیش از یک گذارِ پیش‌فرض')),
                'پیامِ خطا شاملِ «بیش از یک گذارِ پیش‌فرض» نیست: '.implode(' | ', $e->errors)
            );
        }
    }

    /**
     * حالتی که خودِ Designer اصلاً نمی‌تواند تولید کند (Dropdownِ Trigger Action فقط
     * Actionهایِ همان fromStep را نشان می‌دهد) — اینجا صرفاً برایِ تستِ خطِ دفاعیِ
     * Backend، مستقیماً در DB شبیه‌سازی می‌شود.
     */
    public function test_publish_rejects_trigger_action_belonging_to_a_different_step(): void
    {
        [, $versionId] = $this->draftWithUserTask();

        $this->saveGraph($versionId, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'TASK', 'name' => 'مرحلهٔ اقدام', 'stepType' => 'USER_TASK', 'sortOrder' => 1],
                ['code' => 'TASK2', 'name' => 'مرحلهٔ دوم', 'stepType' => 'USER_TASK', 'sortOrder' => 2],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 3],
            ],
            'assignments' => [
                ['stepCode' => 'TASK', 'assigneeType' => 'USER', 'refId' => self::USER],
                ['stepCode' => 'TASK2', 'assigneeType' => 'USER', 'refId' => self::USER],
            ],
            'actions' => [
                ['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
                ['stepCode' => 'TASK2', 'code' => 'DONE', 'kind' => 'COMPLETE', 'label' => 'انجام شد'],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'TASK2', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'TASK2', 'toStepCode' => 'END', 'triggerActionCode' => 'DONE'],
            ],
        ]);

        // شبیه‌سازیِ دستی: TriggerActionIDِ گذارِ T2 (مبدأ=TASK) را به Actionِ DONE (که
        // متعلقِ TASK2 است) تغییر می‌دهیم — چیزی که خودِ Save/Designer هرگز تولید نمی‌کند.
        $wrongActionId = DB::selectOne(
            "SELECT ActionID FROM dbo.WorkflowStepActions WHERE VersionID = ? AND Code = 'DONE'",
            [$versionId]
        )->ActionID;
        DB::update(
            "UPDATE dbo.WorkflowTransitions SET TriggerActionID = ? WHERE VersionID = ? AND Code = 'T2'",
            [$wrongActionId, $versionId]
        );

        try {
            $this->defs->publish($versionId, self::USER);
            $this->fail('انتظار می‌رفت Publish با WorkflowValidationException رد شود.');
        } catch (WorkflowValidationException $e) {
            $this->assertTrue(
                collect($e->errors)->contains(fn ($m) => str_contains($m, 'خارج از مرحلهٔ مبدأ')),
                'پیامِ خطا شاملِ عبارتِ مورد انتظار نیست: '.implode(' | ', $e->errors)
            );
        }
    }

    public function test_publish_succeeds_with_complete_actions_and_transitions(): void
    {
        [, $versionId] = $this->draftWithUserTask();

        $this->saveGraph($versionId, [
            'steps' => $this->baseSteps(),
            'assignments' => $this->baseAssignments(),
            'actions' => [
                ['stepCode' => 'TASK', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
                ['stepCode' => 'TASK', 'code' => 'REJECT', 'kind' => 'REJECT', 'label' => 'رد'],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
                ['code' => 'T3', 'fromStepCode' => 'TASK', 'toStepCode' => 'END', 'triggerActionCode' => 'REJECT'],
            ],
        ]);

        $result = $this->defs->publish($versionId, self::USER);

        $this->assertSame(1, (int) $result->Success);

        $status = DB::selectOne('SELECT Status FROM dbo.WorkflowVersions WHERE VersionID = ?', [$versionId])->Status;
        $this->assertSame('ACTIVE', $status);
    }

    /**
     * Workflowهایِ ازقبل‌ACTIVE (که با قواعدِ قدیمی‌تر Publish شده‌اند و طبقِ قواعدِ
     * جدید ناقص‌اند) نباید توسطِ این تغییر لمس یا مختل شوند — Instanceهایِ درحال‌اجرا
     * و Runtime باید دقیقاً مثلِ قبل کار کنند، چون validate() هیچ‌جایِ Runtime
     * (start/performAction) صدا زده نمی‌شود، فقط در لحظهٔ Publish.
     */
    public function test_pre_existing_active_version_that_violates_new_rules_keeps_working_at_runtime(): void
    {
        [, $versionId, $code] = $this->draftWithUserTask();

        // همان الگویِ ناقصِ واقعی: TASK بدونِ Action، Transitionِ TASK→END نه Trigger نه Default.
        $this->saveGraph($versionId, [
            'steps' => $this->baseSteps(),
            'assignments' => $this->baseAssignments(),
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK', 'toStepCode' => 'END'],
            ],
        ]);

        // شبیه‌سازیِ «این نسخه پیش‌ازاین، پیشِ از وجودِ این قواعد، Publish شده بود» —
        // مستقیماً وضعیت را ACTIVE می‌کنیم (بدونِ عبور از publish() که حالا سخت‌گیرتر است).
        DB::update("UPDATE dbo.WorkflowVersions SET Status = 'ACTIVE' WHERE VersionID = ?", [$versionId]);

        // Runtime باید بدونِ هیچ خطایی کار کند — چون Engine هیچ‌وقت validate() را صدا نمی‌زند.
        /** @var WorkflowEngine $engine */
        $engine = $this->app->make(WorkflowEngine::class);
        $result = $engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: 990001, startedByUserId: self::USER, definitionCode: $code,
        ));

        $this->assertNotNull($result->instanceId);

        $instanceStatus = DB::selectOne(
            'SELECT Status FROM dbo.WorkflowInstances WHERE InstanceID = ?', [$result->instanceId]
        )->Status;
        $this->assertSame('RUNNING', $instanceStatus);

        // و validate() رویِ همین Versionِ ACTIVE، صرفاً خواندنی است — هیچ داده‌ای را
        // تغییر نمی‌دهد و خودِ ACTIVE-بودنش را مختل نمی‌کند.
        $validation = $this->defs->validate($versionId, self::USER, persist: false);
        $this->assertFalse($validation['ok']);

        $statusAfter = DB::selectOne('SELECT Status FROM dbo.WorkflowVersions WHERE VersionID = ?', [$versionId])->Status;
        $this->assertSame('ACTIVE', $statusAfter);
    }
}
