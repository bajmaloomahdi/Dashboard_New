<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * تستِ صفحهٔ Inertiaِ جدیدِ Instance Detail (GET /process/instances/{id} →
 * Pages/Process/Instances/Show.tsx). این کنترلر Contractِ JSONِ workflow/* را
 * تغییر نمی‌دهد؛ فقط لودِ اولیه (props) را از همان WorkflowQueryService می‌گیرد.
 */
class WorkflowInstancePageTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER_FULL = 2;   // مهدی — هر ۹ دسترسیِ WORKFLOW_*
    private const USER_NOPERM = 3; // علی — بدونِ هیچ دسترسیِ WORKFLOW_

    private WorkflowDefinitionService $defs;
    private WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
        $this->engine = $this->app->make(WorkflowEngine::class);
    }

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    /** انتشارِ یک فرایندِ ساده + Start + بازگرداندنِ instanceId. */
    private function startSimpleInstance(int $entityId): int
    {
        $code = 'PAGE_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['code' => $code, 'name' => 'صفحهٔ تست', 'entityType' => 'TEST_ENTITY'], self::USER_FULL);
        $ver = $this->defs->createDraft((int) $def->DefinitionID, self::USER_FULL);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پ', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید']],
            'assignments' => [['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_FULL]],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::USER_FULL);
        $this->defs->publish((int) $ver->VersionID, self::USER_FULL);

        return $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: $entityId, startedByUserId: self::USER_FULL, definitionCode: $code,
        ))->instanceId;
    }

    public function test_instance_page_renders_with_expected_props(): void
    {
        $instanceId = $this->startSimpleInstance(9201);

        $response = $this->as(self::USER_FULL)->get("/process/instances/{$instanceId}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Process/Instances/Show')
            ->has('instance')
            ->has('steps')
            ->has('tasks')
            ->has('history')
            ->has('permissions')
            ->where('instance.Status', 'RUNNING')
        );

        $props = $response->viewData('page')['props'];
        $this->assertSame($instanceId, (int) $props['instance']->InstanceID);
        $perms = $props['permissions'] ?? [];
        $this->assertContains('WORKFLOW_CANCEL', $perms);
        $this->assertContains('WORKFLOW_SUSPEND', $perms);
        $this->assertContains('WORKFLOW_VIEW', $perms);

        // Gap 1: RequiredApprovals باید در Result-Setِ Steps موجود باشد (حتی اگر NULL باشد)
        $reviewStep = collect($props['steps'])->first(fn ($s) => $s->StepCode === 'REVIEW');
        $this->assertNotNull($reviewStep);
        $this->assertTrue(property_exists($reviewStep, 'RequiredApprovals'));
    }

    public function test_instance_page_exposes_required_approvals_value_for_n_of_m_step(): void
    {
        $code = 'PAGE_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['code' => $code, 'name' => 'صفحهٔ تستِ N_OF_M', 'entityType' => 'TEST_ENTITY'], self::USER_FULL);
        $ver = $this->defs->createDraft((int) $def->DefinitionID, self::USER_FULL);
        $this->defs->saveGraph((int) $ver->VersionID, [
            'steps' => [
                ['code' => 'START', 'name' => 'ش', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'REVIEW', 'name' => 'بررسی', 'stepType' => 'APPROVAL', 'assignPolicy' => 'N_OF_M', 'requiredApprovals' => 2, 'sortOrder' => 1],
                ['code' => 'END', 'name' => 'پ', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [['stepCode' => 'REVIEW', 'code' => 'APPROVE', 'kind' => 'APPROVE', 'label' => 'تأیید']],
            'assignments' => [
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => self::USER_FULL],
                ['stepCode' => 'REVIEW', 'assigneeType' => 'USER', 'refId' => 4],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'REVIEW', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'REVIEW', 'toStepCode' => 'END', 'triggerActionCode' => 'APPROVE'],
            ],
        ], self::USER_FULL);
        $this->defs->publish((int) $ver->VersionID, self::USER_FULL);

        $instanceId = $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: 9299, startedByUserId: self::USER_FULL, definitionCode: $code,
        ))->instanceId;

        $props = $this->as(self::USER_FULL)->get("/process/instances/{$instanceId}")->viewData('page')['props'];
        $reviewStep = collect($props['steps'])->first(fn ($s) => $s->StepCode === 'REVIEW');

        $this->assertSame(2, (int) $reviewStep->RequiredApprovals);
    }

    public function test_instance_page_requires_workflow_view_permission(): void
    {
        // کاربرِ ۳ هیچ دسترسیِ WORKFLOW_ ندارد (نقشِ اتوماسیون بدونِ RolePermission)
        $instanceId = $this->startSimpleInstance(9202);

        $this->as(self::USER_NOPERM)->get("/process/instances/{$instanceId}")->assertStatus(403);
    }

    public function test_instance_page_missing_instance_is_404(): void
    {
        $this->as(self::USER_FULL)->get('/process/instances/999999')->assertStatus(404);
    }

    public function test_instance_page_requires_authentication(): void
    {
        $instanceId = $this->startSimpleInstance(9203);

        $this->get("/process/instances/{$instanceId}")->assertRedirect('/login');
    }
}
