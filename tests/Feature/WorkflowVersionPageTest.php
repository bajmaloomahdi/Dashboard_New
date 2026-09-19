<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * تستِ صفحهٔ Inertiaِ Version Editor (GET /process/versions/{versionId}).
 * این کنترلر Contractِ JSONِ workflow/* را تغییر نمی‌دهد؛ فقط لودِ اولیهٔ
 * صفحه (props) را از همان WorkflowDefinitionService::getGraph() می‌گیرد.
 */
class WorkflowVersionPageTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;   // مهدی — هر ۹ دسترسیِ WORKFLOW_*
    private const USER_NOPERM = 3; // علی — بدونِ هیچ دسترسیِ WORKFLOW_

    private WorkflowDefinitionService $defs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defs = $this->app->make(WorkflowDefinitionService::class);
    }

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function createDraftVersion(): int
    {
        $code = 'PAGE_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['latinName' => $code, 'name' => 'صفحهٔ تستِ نسخه', 'entityType' => 'MESSAGE'], self::USER_FULL);
        $version = $this->defs->createDraft((int) $def->DefinitionID, self::USER_FULL);

        return (int) $version->VersionID;
    }

    public function test_version_show_page_renders_with_expected_props(): void
    {
        $versionId = $this->createDraftVersion();

        $response = $this->as(self::USER_FULL)->get("/process/versions/{$versionId}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Process/Versions/Show')
            ->has('meta')
            ->has('steps')
            ->has('actions')
            ->has('assignments')
            ->has('transitions')
            ->has('permissions')
            ->has('users')
            ->has('roles')
            ->has('positions')
            ->has('units')
        );

        $props = $response->viewData('page')['props'];
        $this->assertSame($versionId, (int) $props['meta']->VersionID);
        $this->assertSame('DRAFT', $props['meta']->Status);
        $this->assertIsArray($props['steps']);
        $this->assertCount(0, $props['steps']); // هنوز هیچ Stepی ساخته نشده

        $perms = $props['permissions'];
        $this->assertContains('WORKFLOW_DESIGN', $perms);
        $this->assertContains('WORKFLOW_PUBLISH', $perms);
        $this->assertContains('WORKFLOW_VIEW', $perms);
    }

    /**
     * Visual Process Designer: PositionX/PositionY/Description باید از طریقِ همان Endpointِ
     * واقعیِ HTTP (PUT workflow/versions/{id}/graph) در چرخهٔ Save→Reload حفظ شوند — یعنی
     * قوانینِ Validationِ Laravel در Controller هم این سه فیلد را allow-list کرده باشند
     * (دقیقاً همان دسته‌باگی که در Gap 1 برایِ RequiredApprovals رفع شد).
     */
    public function test_version_save_via_http_persists_position_and_description(): void
    {
        $versionId = $this->createDraftVersion();

        $payload = [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0, 'positionX' => 120, 'positionY' => 80],
                ['code' => 'COND_1', 'name' => 'آیا مبلغ بیشتر از ۱۰ میلیون است؟', 'stepType' => 'CONDITION', 'sortOrder' => 1, 'positionX' => 340, 'positionY' => 80, 'description' => 'بررسیِ سقفِ مبلغ'],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2, 'positionX' => 560, 'positionY' => 80],
            ],
            'actions' => [],
            'assignments' => [],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'COND_1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'COND_1', 'toStepCode' => 'END', 'label' => 'بله'],
            ],
        ];

        $res = $this->as(self::USER_FULL)->putJson("/workflow/versions/{$versionId}/graph", $payload);
        $res->assertOk();
        $this->assertTrue($res->json('success'));

        $graph = $this->defs->getGraph($versionId);
        $steps = collect($graph['graph']['steps'])->keyBy('Code');

        $this->assertSame(120, (int) $steps['START']->PositionX);
        $this->assertSame(80, (int) $steps['START']->PositionY);
        $this->assertSame(340, (int) $steps['COND_1']->PositionX);
        $this->assertSame('بررسیِ سقفِ مبلغ', $steps['COND_1']->Description);
        $this->assertSame('CONDITION', $steps['COND_1']->StepType);

        $transitions = collect($graph['graph']['transitions']);
        $this->assertSame('بله', $transitions->firstWhere('Code', 'T2')->Label);
    }

    /**
     * Designer Phase 2 (پیکربندیِ Step/Transition درونِ همان صفحه): DueDurationHours،
     * IsBackup و ConditionExpression باید از طریقِ همان Endpointِ واقعیِ HTTP در چرخهٔ
     * Save→Reload حفظ شوند — همان دسته‌باگِ Allow-listِ Laravel که قبلاً دو بار رفع شد.
     */
    public function test_version_save_via_http_persists_duration_backup_and_condition(): void
    {
        $versionId = $this->createDraftVersion();

        $payload = [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'TASK_1', 'name' => 'بررسیِ مدیر', 'stepType' => 'USER_TASK', 'sortOrder' => 1, 'dueDurationHours' => 48],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 2],
            ],
            'actions' => [],
            'assignments' => [
                ['stepCode' => 'TASK_1', 'assigneeType' => 'USER', 'refId' => self::USER_FULL, 'sortOrder' => 0, 'isBackup' => false],
                ['stepCode' => 'TASK_1', 'assigneeType' => 'USER', 'refId' => self::USER_NOPERM, 'sortOrder' => 1, 'isBackup' => true],
            ],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'TASK_1', 'isDefault' => true],
                ['code' => 'T2', 'fromStepCode' => 'TASK_1', 'toStepCode' => 'END', 'label' => 'بله', 'conditionExpression' => 'amount > 10000000'],
            ],
        ];

        $res = $this->as(self::USER_FULL)->putJson("/workflow/versions/{$versionId}/graph", $payload);
        $res->assertOk();
        $this->assertTrue($res->json('success'));

        $graph = $this->defs->getGraph($versionId);
        $steps = collect($graph['graph']['steps'])->keyBy('Code');
        $this->assertSame(48, (int) $steps['TASK_1']->DueDurationHours);

        $assignments = collect($graph['graph']['assignments'])->keyBy('RefID');
        $this->assertSame(0, (int) $assignments[self::USER_FULL]->IsBackup);
        $this->assertSame(1, (int) $assignments[self::USER_NOPERM]->IsBackup);

        $transitions = collect($graph['graph']['transitions']);
        $this->assertSame('amount > 10000000', $transitions->firstWhere('Code', 'T2')->ConditionExpression);
    }

    public function test_version_show_missing_version_is_404(): void
    {
        $this->as(self::USER_FULL)->get('/process/versions/999999')->assertStatus(404);
    }

    public function test_version_show_requires_workflow_view_permission(): void
    {
        $versionId = $this->createDraftVersion();

        $this->as(self::USER_NOPERM)->get("/process/versions/{$versionId}")->assertStatus(403);
    }

    public function test_version_show_requires_authentication(): void
    {
        $versionId = $this->createDraftVersion();

        $this->get("/process/versions/{$versionId}")->assertRedirect('/login');
    }
}
