<?php

namespace Tests\Feature;

use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\Support\WorkflowStore;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * تستِ سخت‌سازیِ هم‌زمانیِ Start (پچ 020 | sql/020_workflow_start_concurrency.sql).
 *
 * اثبات می‌کند ایندکسِ یکتای فیلترشدهٔ dbo.UX_WorkflowInstances_Running_Entity
 * (روی WorkflowInstances(DefinitionID, EntityType, EntityID) WHERE Status='RUNNING')
 * واقعاً آخرین گاردِ «حداکثر یک RUNNING به‌ازای هر موجودیت» است — جدا از
 * pre-checkِ WorkflowEngine::start() (که در WorkflowEngineTest پوشش داده شده).
 *
 * چون WorkflowEngine::start() به‌طورِ عادی پیش از رسیدن به INSERT رد می‌کند
 * (pre-check)، برای این‌که واقعاً مسیرِ ایندکس اجرا شود این فایل از دو تکنیک استفاده
 * می‌کند:
 *   ۱) درجِ مستقیمِ یک ردیفِ RUNNING (bypass کاملِ Engine) و سپس فراخوانیِ
 *      WorkflowStore::startInstance() به‌طورِ مستقیم — دقیقاً همان مسیری که
 *      Engine در انتهای start() صدا می‌زند.
 *   ۲) partialMock کردنِ WorkflowStore::hasActiveInstance() برای برگرداندنِ null
 *      (شبیه‌سازیِ دقیقِ لحظه‌ای که pre-checkِ Requestِ بازنده هنوز RUNNINGِ تازه‌درج‌شدهٔ
 *      Requestِ برنده را ندیده) درحالی‌که یک RUNNINGِ واقعی از قبل در دیتابیس هست.
 *      در این حالت تنها ایندکسِ یکتا می‌تواند جلوی INSERTِ دوم را بگیرد.
 */
class WorkflowConcurrencyTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER_A = 2;

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;
    private WorkflowStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
        $this->store = $this->app->make(WorkflowStore::class);
    }

    /* ============================ کمکی‌ها ============================ */

    /** انتشارِ کوچک‌ترین گراف: START → REVIEW(APPROVAL) → END. @return array{0:int,1:int,2:string} */
    private function publishMinimalFlow(?string $code = null): array
    {
        $code ??= 'CONC_' . strtoupper(bin2hex(random_bytes(4)));

        $def = $this->defs->save(['latinName' => $code, 'name' => 'همزمانی ' . $code, 'entityType' => 'TEST_ENTITY'], self::USER_A);
        $definitionId = (int) $def->DefinitionID;
        $ver = $this->defs->createDraft($definitionId, self::USER_A);
        $versionId = (int) $ver->VersionID;

        $this->defs->saveGraph($versionId, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [
                ['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید'],
            ],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_A],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::USER_A);

        $this->defs->publish($versionId, self::USER_A);

        return [$definitionId, $versionId, $code];
    }

    /** درجِ مستقیمِ یک ردیفِ WorkflowInstances — بدونِ عبورِ از Engine (شبیه‌سازیِ «بردِ رقیب»). */
    private function insertRawInstance(int $definitionId, int $versionId, string $entityType, int $entityId, string $status, ?string $number = null): int
    {
        $number ??= 'WFI-' . random_int(100000, 999999);
        $completedAt = $status === 'RUNNING' ? null : now();

        return (int) DB::selectOne(
            'INSERT INTO dbo.WorkflowInstances
                (InstanceNumber, DefinitionID, VersionID, EntityType, EntityID, Status, StartedAt, CompletedAt, Date_InsertFirst)
             OUTPUT INSERTED.InstanceID AS id
             VALUES (?, ?, ?, ?, ?, ?, SYSDATETIME(), ?, SYSDATETIME())',
            [$number, $definitionId, $versionId, $entityType, $entityId, $status, $completedAt]
        )->id;
    }

    private function runningCount(int $definitionId, string $entityType, int $entityId): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM dbo.WorkflowInstances WHERE DefinitionID = ? AND EntityType = ? AND EntityID = ? AND Status = N\'RUNNING\'',
            [$definitionId, $entityType, $entityId]
        )->n;
    }

    /* ==================================================================== */
    /*  ۱) Backstop regression — Engine::start() روی RUNNINGِ درج‌شدهٔ مستقیم  */
    /* ==================================================================== */

    public function test_engine_start_is_rejected_when_a_running_instance_already_exists_directly_in_db(): void
    {
        [$definitionId, $versionId, $code] = $this->publishMinimalFlow();
        $entityId = 9001;

        // بردِ رقیب شبیه‌سازی می‌شود: یک RUNNING مستقیماً درج شده، بدونِ عبور از Engine
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING');

        try {
            $this->engine->start(new StartWorkflowRequest(
                entityType: 'TEST_ENTITY', entityId: $entityId, startedByUserId: self::USER_A, definitionCode: $code,
            ));
            $this->fail('Startِ دوم باید رد شود چون یک RUNNING از قبل وجود دارد.');
        } catch (WorkflowStateException $e) {
            $this->assertStringContainsString('از قبل یک فرایندِ فعال وجود دارد', $e->getMessage());
        }

        $this->assertSame(1, $this->runningCount($definitionId, 'TEST_ENTITY', $entityId));
    }

    /* ==================================================================== */
    /*  ۲) Duplicate RUNNING در سطحِ DB — ایندکسِ یکتا                        */
    /* ==================================================================== */

    public function test_second_running_insert_is_blocked_by_unique_index_and_mapped_to_state_exception(): void
    {
        [$definitionId, $versionId] = $this->publishMinimalFlow();
        $entityId = 9002;

        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING', 'WFI-900001');

        // INSERTِ دوم از همان مسیرِ Store::startInstance که Engine در انتها صدا می‌زند
        // (شبیه‌سازیِ Requestِ بازنده‌ای که pre-check را رد کرده و به INSERT رسیده)
        try {
            $this->store->startInstance([
                'definitionId' => $definitionId, 'versionId' => $versionId,
                'entityType' => 'TEST_ENTITY', 'entityId' => $entityId,
                'startedByUserId' => self::USER_A, 'instanceNumber' => 'WFI-900002',
            ]);
            $this->fail('INSERTِ دومِ RUNNING باید توسطِ ایندکسِ یکتا رد شود.');
        } catch (WorkflowStateException $e) {
            $this->assertStringContainsString('از قبل یک فرایندِ فعال وجود دارد', $e->getMessage());
        }

        $this->assertSame(1, $this->runningCount($definitionId, 'TEST_ENTITY', $entityId));
    }

    public function test_the_underlying_error_is_the_running_entity_unique_index(): void
    {
        [$definitionId, $versionId] = $this->publishMinimalFlow();
        $entityId = 9003;
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING', 'WFI-900101');

        // مستقیماً روی SP، بدونِ نگاشتِ WorkflowStore — برای اثباتِ اینکه خطا واقعاً از
        // خودِ ایندکسِ UX_WorkflowInstances_Running_Entity می‌آید (نه یک فرضِ دیگر).
        try {
            DB::selectOne(
                'EXEC dbo.sp_Wf_StartInstance @DefinitionID = ?, @VersionID = ?, @EntityType = ?, @EntityID = ?, @StartedByUserID = ?, @InstanceNumber = ?',
                [$definitionId, $versionId, 'TEST_ENTITY', $entityId, null, 'WFI-900102']
            );
            $this->fail('SP باید نقضِ ایندکسِ یکتا بدهد.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame('23000', $e->getCode());
            $this->assertSame(2601, $e->errorInfo[1] ?? null); // SQL Server: duplicate key row ... unique index
            $this->assertStringContainsString('UX_WorkflowInstances_Running_Entity', $e->getMessage());
        }
    }

    /* ==================================================================== */
    /*  ۳) Regression — ترکیب‌های مجاز/غیرمجاز                                */
    /* ==================================================================== */

    public function test_running_and_completed_may_coexist_for_the_same_entity(): void
    {
        [$definitionId, $versionId] = $this->publishMinimalFlow();
        $entityId = 9010;

        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'COMPLETED');
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING'); // نباید استثنا بدهد

        $this->assertSame(1, $this->runningCount($definitionId, 'TEST_ENTITY', $entityId));
    }

    public function test_running_and_suspended_may_coexist_for_the_same_entity(): void
    {
        // طبقِ رفتارِ فعلیِ hasActiveInstance: predicateِ ایندکس فقط RUNNING است،
        // پس SUSPENDED مانعِ یک RUNNINGِ جدید نیست (رفتار‌حفظ، نه رگرسیون).
        [$definitionId, $versionId] = $this->publishMinimalFlow();
        $entityId = 9011;

        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'SUSPENDED');
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING'); // نباید استثنا بدهد

        $this->assertSame(1, $this->runningCount($definitionId, 'TEST_ENTITY', $entityId));
    }

    public function test_two_running_for_the_same_entity_is_impossible(): void
    {
        [$definitionId, $versionId] = $this->publishMinimalFlow();
        $entityId = 9012;
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING');
    }

    public function test_two_running_for_different_entities_is_allowed(): void
    {
        [$definitionId, $versionId] = $this->publishMinimalFlow();

        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', 9013, 'RUNNING');
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', 9014, 'RUNNING'); // نباید استثنا بدهد

        $this->assertSame(1, $this->runningCount($definitionId, 'TEST_ENTITY', 9013));
        $this->assertSame(1, $this->runningCount($definitionId, 'TEST_ENTITY', 9014));
    }

    public function test_two_running_for_different_definitions_is_allowed(): void
    {
        [$definitionId1, $versionId1] = $this->publishMinimalFlow();
        [$definitionId2, $versionId2] = $this->publishMinimalFlow();
        $entityId = 9015;

        $this->insertRawInstance($definitionId1, $versionId1, 'TEST_ENTITY', $entityId, 'RUNNING');
        $this->insertRawInstance($definitionId2, $versionId2, 'TEST_ENTITY', $entityId, 'RUNNING'); // نباید استثنا بدهد

        $this->assertSame(1, $this->runningCount($definitionId1, 'TEST_ENTITY', $entityId));
        $this->assertSame(1, $this->runningCount($definitionId2, 'TEST_ENTITY', $entityId));
    }

    /* ==================================================================== */
    /*  ۴) نگاشتِ خطا — نقضِ UQ_WorkflowInstances_Number نباید duplicate-      */
    /*     active-instance بشود                                              */
    /* ==================================================================== */

    public function test_instance_number_collision_is_not_mapped_to_duplicate_active_instance(): void
    {
        [$definitionId, $versionId] = $this->publishMinimalFlow();
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', 9020, 'RUNNING', 'WFI-DUPNUM');

        try {
            // Entityِ متفاوت ⇒ ایندکسِ RUNNING دخیل نیست؛ اما همان InstanceNumber ⇒
            // نقضِ UQ_WorkflowInstances_Number.
            $this->store->startInstance([
                'definitionId' => $definitionId, 'versionId' => $versionId,
                'entityType' => 'TEST_ENTITY', 'entityId' => 9021,
                'startedByUserId' => self::USER_A, 'instanceNumber' => 'WFI-DUPNUM',
            ]);
            $this->fail('باید نقضِ UQ_WorkflowInstances_Number رخ دهد.');
        } catch (WorkflowStateException $e) {
            $this->fail(
                'نقضِ UQ_WorkflowInstances_Number نباید به WorkflowStateException (duplicate-active-instance) '
                . 'swallow شود؛ پیامِ گرفته‌شده: ' . $e->getMessage()
            );
        } catch (QueryException $e) {
            // انتظار می‌رود: استثنایِ اصلیِ کوئری، دست‌نخورده
            $this->assertStringContainsString('UQ_WorkflowInstances_Number', $e->getMessage());
        }
    }

    /* ==================================================================== */
    /*  ۵) Backstop واقعی — pre-check کور، فقط ایندکس جلوی INSERT را می‌گیرد   */
    /* ==================================================================== */

    public function test_index_is_the_true_last_line_of_defense_when_pre_check_misses_the_race(): void
    {
        [$definitionId, $versionId, $code] = $this->publishMinimalFlow();
        $entityId = 9030;

        // بردِ رقیب از قبل در دیتابیس هست...
        $this->insertRawInstance($definitionId, $versionId, 'TEST_ENTITY', $entityId, 'RUNNING');

        // ...ولی pre-checkِ Engine کور می‌شود (دقیقاً شبیه‌سازیِ پنجرهٔ TOCTOU: لحظه‌ای که
        // Requestِ بازنده هنوز commitِ Requestِ برنده را نمی‌بیند). partialMock فقط همین
        // یک متد را override می‌کند؛ startInstance/nextNumber/... واقعی و روی DBِ واقعی می‌مانند.
        $this->partialMock(WorkflowStore::class, function ($mock) {
            $mock->shouldReceive('hasActiveInstance')->andReturn(null);
        });

        $engine = $this->app->make(WorkflowEngine::class);

        try {
            $engine->start(new StartWorkflowRequest(
                entityType: 'TEST_ENTITY', entityId: $entityId, startedByUserId: self::USER_A, definitionCode: $code,
            ));
            $this->fail('با pre-checkِ کور، فقط ایندکسِ یکتا باید جلوی دومین RUNNING را بگیرد.');
        } catch (WorkflowStateException $e) {
            $this->assertStringContainsString('از قبل یک فرایندِ فعال وجود دارد', $e->getMessage());
        }

        $this->assertSame(1, $this->runningCount($definitionId, 'TEST_ENTITY', $entityId));
    }
}
