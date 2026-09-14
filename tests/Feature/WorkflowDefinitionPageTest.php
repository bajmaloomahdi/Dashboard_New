<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * تستِ صفحاتِ Inertiaِ جدیدِ مدیریتِ Definitions (GET /process/definitions،
 * GET /process/definitions/{id}). این کنترلرها Contractِ JSONِ workflow/* را
 * تغییر نمی‌دهند؛ فقط لودِ اولیه (props) را از همان WorkflowDefinitionService
 * می‌گیرند.
 */
class WorkflowDefinitionPageTest extends TestCase
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

    private function createDefinition(): int
    {
        $code = 'PAGE_' . strtoupper(bin2hex(random_bytes(4)));
        $def = $this->defs->save(['code' => $code, 'name' => 'صفحهٔ تستِ Definition', 'entityType' => 'MESSAGE'], self::USER_FULL);

        return (int) $def->DefinitionID;
    }

    public function test_definitions_index_page_renders_with_expected_props(): void
    {
        $this->createDefinition();

        $response = $this->as(self::USER_FULL)->get('/process/definitions');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Process/Definitions/Index')
            ->has('definitions')
            ->has('filters')
            ->has('permissions')
        );

        $perms = $response->viewData('page')['props']['permissions'] ?? [];
        $this->assertContains('WORKFLOW_DESIGN', $perms);
        $this->assertContains('WORKFLOW_VIEW', $perms);
    }

    public function test_definitions_index_requires_workflow_view_permission(): void
    {
        $this->as(self::USER_NOPERM)->get('/process/definitions')->assertStatus(403);
    }

    public function test_definitions_index_requires_authentication(): void
    {
        $this->get('/process/definitions')->assertRedirect('/login');
    }

    public function test_definitions_show_page_renders_with_expected_props(): void
    {
        $definitionId = $this->createDefinition();

        $response = $this->as(self::USER_FULL)->get("/process/definitions/{$definitionId}");
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Process/Definitions/Show')
            ->has('definition')
            ->has('versions')
            ->has('permissions')
        );

        $props = $response->viewData('page')['props'];
        $this->assertSame($definitionId, (int) $props['definition']->DefinitionID);
        $this->assertIsArray($props['versions']);
        $this->assertCount(0, $props['versions']); // هنوز هیچ نسخه‌ای ساخته نشده
    }

    public function test_definitions_show_missing_definition_is_404(): void
    {
        $this->as(self::USER_FULL)->get('/process/definitions/999999')->assertStatus(404);
    }

    public function test_definitions_show_requires_workflow_view_permission(): void
    {
        $definitionId = $this->createDefinition();

        $this->as(self::USER_NOPERM)->get("/process/definitions/{$definitionId}")->assertStatus(403);
    }
}
