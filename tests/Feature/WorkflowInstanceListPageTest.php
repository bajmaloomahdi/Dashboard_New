<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * تستِ صفحهٔ Inertiaِ جدیدِ Instance List (GET /process/instances →
 * Pages/Process/Instances/Index.tsx) — Gap 3 از فازِ Runtime UX.
 */
class WorkflowInstanceListPageTest extends TestCase
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

    /** @return array{0:int DefinitionID, 1:string Code} */
    private function publishSimpleFlow(): array
    {
        $code = 'LIST_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['code' => $code, 'name' => 'صفحهٔ تستِ لیست', 'entityType' => 'TEST_ENTITY'], self::USER_FULL);
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

        return [(int) $def->DefinitionID, $code];
    }

    private function startInstance(string $code, int $entityId): int
    {
        return $this->engine->start(new StartWorkflowRequest(
            entityType: 'TEST_ENTITY', entityId: $entityId, startedByUserId: self::USER_FULL, definitionCode: $code,
        ))->instanceId;
    }

    public function test_instance_list_page_renders_with_expected_props(): void
    {
        [$definitionId, $code] = $this->publishSimpleFlow();
        $instanceId = $this->startInstance($code, 9301);

        $response = $this->as(self::USER_FULL)->get('/process/instances');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Process/Instances/Index')
            ->has('instances')
            ->has('totalCount')
            ->has('filters')
            ->has('page')
            ->has('pageSize')
            ->has('permissions')
            ->has('definitionOptions')
            ->has('users')
        );

        $props = $response->viewData('page')['props'];
        $ids = collect($props['instances'])->pluck('InstanceID')->map(fn ($v) => (int) $v);
        $this->assertContains($instanceId, $ids);

        $row = collect($props['instances'])->first(fn ($r) => (int) $r->InstanceID === $instanceId);
        $this->assertSame('RUNNING', $row->Status);
        $this->assertSame('REVIEW', $row->CurrentStepCode);
        $this->assertSame('بررسی', $row->CurrentStepName);
    }

    public function test_instance_list_current_step_is_null_when_completed(): void
    {
        [, $code] = $this->publishSimpleFlow();
        $instanceId = $this->startInstance($code, 9302);

        // طیِ کاملِ گردش: REVIEW را تأیید می‌کنیم تا Instance به END/COMPLETED برسد
        $task = collect($this->app->make(\App\Services\Workflow\WorkflowQueryService::class)->instance($instanceId)['tasks'])->first();
        $this->engine->performAction(new \App\Services\Workflow\Dto\TaskActionRequest(
            messageId: (int) $task->MessageID, userId: self::USER_FULL, actionCode: 'APPROVE',
        ));

        $props = $this->as(self::USER_FULL)->get('/process/instances?status=COMPLETED')->viewData('page')['props'];
        $row = collect($props['instances'])->first(fn ($r) => (int) $r->InstanceID === $instanceId);

        $this->assertNotNull($row);
        $this->assertSame('COMPLETED', $row->Status);
        $this->assertNull($row->CurrentStepID);
        $this->assertNull($row->CurrentStepCode);
        $this->assertNull($row->CurrentStepName);
    }

    public function test_instance_list_filters_by_definition_and_entity(): void
    {
        [$definitionId, $code] = $this->publishSimpleFlow();
        $instanceId = $this->startInstance($code, 9303);
        [, $otherCode] = $this->publishSimpleFlow();
        $this->startInstance($otherCode, 9304);

        $props = $this->as(self::USER_FULL)
            ->get("/process/instances?definitionId={$definitionId}&entityType=TEST_ENTITY&entityId=9303")
            ->viewData('page')['props'];

        $ids = collect($props['instances'])->pluck('InstanceID')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([$instanceId], $ids);
        $this->assertSame(1, $props['totalCount']);
    }

    public function test_instance_list_pagination_total_count_independent_of_page_size(): void
    {
        [, $code] = $this->publishSimpleFlow();
        $this->startInstance($code, 9305);
        $this->startInstance($code, 9306);
        $this->startInstance($code, 9307);

        $props = $this->as(self::USER_FULL)
            ->get('/process/instances?pageSize=1&page=1')
            ->viewData('page')['props'];

        $this->assertCount(1, $props['instances']);
        $this->assertGreaterThanOrEqual(3, $props['totalCount']);
    }

    public function test_instance_list_requires_workflow_view_permission(): void
    {
        $this->as(self::USER_NOPERM)->get('/process/instances')->assertStatus(403);
    }

    public function test_instance_list_requires_authentication(): void
    {
        $this->get('/process/instances')->assertRedirect('/login');
    }

    public function test_instance_list_route_does_not_collide_with_instance_show_route(): void
    {
        // اطمینان از ترتیبِ درستِ Routeها: 'instances' نباید به‌عنوانِ {instanceId} تفسیر شود
        $response = $this->as(self::USER_FULL)->get('/process/instances');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Process/Instances/Index'));
    }
}
