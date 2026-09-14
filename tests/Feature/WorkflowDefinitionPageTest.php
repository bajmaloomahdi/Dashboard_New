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

    /** یک گرافِ حداقلیِ معتبر (START→END) برایِ Publishِ موفق. */
    private function publishMinimalVersion(int $definitionId): int
    {
        $version = $this->defs->createDraft($definitionId, self::USER_FULL);
        $versionId = (int) $version->VersionID;

        $this->defs->saveGraph($versionId, [
            'steps' => [
                ['code' => 'START', 'name' => 'شروع', 'stepType' => 'START', 'sortOrder' => 0],
                ['code' => 'END', 'name' => 'پایان', 'stepType' => 'END', 'sortOrder' => 1],
            ],
            'actions' => [],
            'assignments' => [],
            'transitions' => [
                ['code' => 'T1', 'fromStepCode' => 'START', 'toStepCode' => 'END', 'isDefault' => true],
            ],
        ], self::USER_FULL);

        $this->defs->publish($versionId, self::USER_FULL);

        return $versionId;
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

    /* ==================================================================== */
    /*  GET /process/definitions/{id}/open — Redirectِ خودکار به Canvas      */
    /* ==================================================================== */

    public function test_open_redirects_to_draft_version_when_one_exists(): void
    {
        $definitionId = $this->createDefinition();
        $version = $this->defs->createDraft($definitionId, self::USER_FULL);

        $this->as(self::USER_FULL)
            ->get("/process/definitions/{$definitionId}/open")
            ->assertRedirect("/process/versions/{$version->VersionID}");
    }

    /**
     * حالتِ غیرمنتظره: اگر (بدونِ اینکه هیچ مسیرِ فعلیِ UI آن را عمداً بسازد — چون
     * sp_Wf_CreateDraftVersion هیچ Guardی در برابرِ چند DRAFTِ هم‌زمان ندارد) چند نسخهٔ
     * DRAFT برایِ یک Definition وجود داشته باشد، رفتار باید قطعی و امن بماند: همیشه
     * جدیدترین (بیشترین VersionNo) انتخاب می‌شود — نه تصادفی، نه قدیمی‌ترین.
     */
    public function test_open_picks_newest_draft_when_multiple_unexpected_drafts_exist(): void
    {
        $definitionId = $this->createDefinition();
        $draftV1 = $this->defs->createDraft($definitionId, self::USER_FULL);
        $draftV2 = $this->defs->createDraft($definitionId, self::USER_FULL);
        $draftV3 = $this->defs->createDraft($definitionId, self::USER_FULL);

        $this->assertNotSame((int) $draftV1->VersionID, (int) $draftV3->VersionID);

        $this->as(self::USER_FULL)
            ->get("/process/definitions/{$definitionId}/open")
            ->assertRedirect("/process/versions/{$draftV3->VersionID}");
    }

    public function test_open_redirects_to_active_version_when_no_draft_exists(): void
    {
        $definitionId = $this->createDefinition();
        $activeVersionId = $this->publishMinimalVersion($definitionId);

        $this->as(self::USER_FULL)
            ->get("/process/definitions/{$definitionId}/open")
            ->assertRedirect("/process/versions/{$activeVersionId}");
    }

    public function test_open_prefers_draft_over_active_when_both_exist(): void
    {
        $definitionId = $this->createDefinition();
        $this->publishMinimalVersion($definitionId); // v1 → ACTIVE
        $draftV2 = $this->defs->createDraft($definitionId, self::USER_FULL); // v2 → DRAFT

        $this->as(self::USER_FULL)
            ->get("/process/definitions/{$definitionId}/open")
            ->assertRedirect("/process/versions/{$draftV2->VersionID}");
    }

    /**
     * بررسیِ امنیتیِ اصلاح‌شده: GET /open یک Route فقط‌خواندنی است — حتی برایِ کاربرِ
     * دارایِ WORKFLOW_DESIGN، وقتی هیچ نسخه‌ای وجود ندارد، **هیچ Draftی نمی‌سازد** و
     * فقط به صفحهٔ تاریخچه Redirect می‌کند. هیچ INSERTای در WorkflowVersions رخ نمی‌دهد.
     */
    public function test_open_never_creates_draft_even_with_design_permission(): void
    {
        $definitionId = $this->createDefinition();

        $countBefore = \Illuminate\Support\Facades\DB::table('WorkflowVersions')->count();

        $response = $this->as(self::USER_FULL)->get("/process/definitions/{$definitionId}/open");
        $response->assertRedirect("/process/definitions/{$definitionId}");

        $countAfter = \Illuminate\Support\Facades\DB::table('WorkflowVersions')->count();
        $this->assertSame($countBefore, $countAfter, 'GET /open نباید هیچ ردیفی در WorkflowVersions درج کند.');

        $versions = $this->defs->show($definitionId)['versions'];
        $this->assertCount(0, $versions);
    }

    public function test_open_falls_back_to_hub_when_no_versions_and_no_design_permission(): void
    {
        $definitionId = $this->createDefinition();

        // موقتاً فقط WORKFLOW_VIEW (PermissionID=7) به نقشِ کاربرِ ۳ می‌دهیم؛ با Rollbackِ
        // تست (DatabaseTransactions) پاک می‌شود — همان الگویِ WorkflowApiTest.php.
        \Illuminate\Support\Facades\DB::table('RolePermissions')->insert([
            'RoleID' => 4, 'PermissionID' => 7, 'CanAccess' => 1, 'IsActive' => 1,
        ]);

        $response = $this->as(self::USER_NOPERM)->get("/process/definitions/{$definitionId}/open");
        $response->assertRedirect("/process/definitions/{$definitionId}");

        $versions = $this->defs->show($definitionId)['versions'];
        $this->assertCount(0, $versions); // هیچ Draftِ خودکاری ساخته نشده
    }

    /* ==================================================================== */
    /*  POST /workflow/definitions/{id}/versions — مسیرِ صریحِ Write برایِ    */
    /*  ساختِ Draft (Endpointِ از‌قبل‌موجود، فقط اینجا رفتارش تثبیت می‌شود)   */
    /* ==================================================================== */

    public function test_create_draft_via_explicit_post_requires_workflow_design_permission(): void
    {
        $definitionId = $this->createDefinition();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/definitions/{$definitionId}/versions")
            ->assertStatus(403);

        $versions = $this->defs->show($definitionId)['versions'];
        $this->assertCount(0, $versions);
    }

    public function test_create_draft_via_explicit_post_succeeds_with_workflow_design_permission(): void
    {
        $definitionId = $this->createDefinition();

        $res = $this->as(self::USER_FULL)->postJson("/workflow/definitions/{$definitionId}/versions");
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertNotNull($res->json('versionId'));

        $versions = $this->defs->show($definitionId)['versions'];
        $this->assertCount(1, $versions);
        $this->assertSame('DRAFT', $versions[0]->Status);
    }

    public function test_open_requires_workflow_view_permission(): void
    {
        $definitionId = $this->createDefinition();

        $this->as(self::USER_NOPERM)->get("/process/definitions/{$definitionId}/open")->assertStatus(403);
    }

    public function test_open_missing_definition_is_404(): void
    {
        $this->as(self::USER_FULL)->get('/process/definitions/999999/open')->assertStatus(404);
    }

    /**
     * WORKFLOW_DESIGN به‌تنهایی (بدونِ WORKFLOW_VIEW) نباید بتواند از Gateِ
     * authorizeView() عبور کند — DESIGN هرگز نباید جایگزینِ VIEW شود، و هیچ Draftی
     * برایِ کاربرِ بدونِ VIEW نباید ساخته شود (بررسیِ صریحِ امنیت).
     */
    public function test_open_design_permission_alone_does_not_bypass_view_gate(): void
    {
        $definitionId = $this->createDefinition();

        \Illuminate\Support\Facades\DB::table('RolePermissions')->insert([
            'RoleID' => 4, 'PermissionID' => 8, 'CanAccess' => 1, 'IsActive' => 1, // WORKFLOW_DESIGN فقط
        ]);

        $this->as(self::USER_NOPERM)->get("/process/definitions/{$definitionId}/open")->assertStatus(403);

        $versions = $this->defs->show($definitionId)['versions'];
        $this->assertCount(0, $versions); // هیچ Draftی ساخته نشده
    }
}
