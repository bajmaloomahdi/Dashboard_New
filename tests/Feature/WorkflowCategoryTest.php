<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * دسته‌بندیِ فرایندها (WorkflowCategories): CRUD، گاردِ غیرفعال‌سازی، اثرِ
 * grandfathering، و فیلترِ لیستِ Definitions بر اساسِ Category.
 *
 * کاربران: 2 → هر ۱۰ دسترسیِ WORKFLOW_* (شاملِ WORKFLOW_MANAGE_CATEGORIES تازه)
 *          3 → بدونِ هیچ دسترسیِ WORKFLOW_
 */
class WorkflowCategoryTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

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

    private function uniqueCode(string $prefix): string
    {
        return $prefix . '_' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function createCategory(?string $code = null): int
    {
        $res = $this->defs->saveCategory([
            'code' => $code ?? $this->uniqueCode('CAT'),
            'name' => 'دستهٔ تست',
        ], self::USER_FULL);

        return (int) $res->CategoryID;
    }

    public function test_manage_categories_permission_is_granted_to_admin_role(): void
    {
        // اطمینان از اینکه Permissionِ تازه به RoleID=1 هم اعطا شده (نه فقط ایجاد شده)
        $res = $this->as(self::USER_FULL)->postJson('/workflow/categories', [
            'code' => $this->uniqueCode('PERMCHK'),
            'name' => 'بررسیِ دسترسی',
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_list_categories_requires_only_workflow_view(): void
    {
        $this->createCategory();

        $res = $this->as(self::USER_FULL)->getJson('/workflow/categories');
        $res->assertOk();
        $this->assertTrue($res->json('success'));
        $this->assertNotEmpty($res->json('items'));
    }

    public function test_list_categories_requires_authentication(): void
    {
        $this->getJson('/workflow/categories')->assertStatus(401);
    }

    public function test_store_category_requires_manage_categories_permission(): void
    {
        $this->as(self::USER_NOPERM)->postJson('/workflow/categories', [
            'code' => $this->uniqueCode('NOPERM'),
            'name' => 'بدونِ دسترسی',
        ])->assertStatus(403);
    }

    public function test_store_category_rejects_duplicate_code(): void
    {
        $code = $this->uniqueCode('DUP');
        $this->createCategory($code);

        $res = $this->as(self::USER_FULL)->postJson('/workflow/categories', [
            'code' => $code,
            'name' => 'نسخهٔ دوم',
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
        $this->assertStringContainsString('تکراری', $res->json('message'));
    }

    public function test_update_category_via_http(): void
    {
        $categoryId = $this->createCategory();

        $res = $this->as(self::USER_FULL)->postJson('/workflow/categories', [
            'categoryId' => $categoryId,
            'code'       => $this->uniqueCode('UPD'),
            'name'       => 'نامِ جدید',
            'sortOrder'  => 5,
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('success'));

        $items = collect($this->defs->listCategories());
        $this->assertSame('نامِ جدید', $items->firstWhere('CategoryID', $categoryId)->Name);
    }

    public function test_toggle_active_requires_manage_categories_permission(): void
    {
        $categoryId = $this->createCategory();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/categories/{$categoryId}/toggle")
            ->assertStatus(403);
    }

    public function test_toggle_active_is_blocked_when_active_definition_depends_on_it(): void
    {
        $categoryId = $this->createCategory();
        $this->defs->save([
            'code' => $this->uniqueCode('DEP'), 'name' => 'وابسته', 'entityType' => 'MESSAGE',
            'categoryId' => $categoryId, 'isActive' => 1,
        ], self::USER_FULL);

        $res = $this->as(self::USER_FULL)->postJson("/workflow/categories/{$categoryId}/toggle");
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
        $this->assertStringContainsString('وابسته', $res->json('message'));
    }

    public function test_toggle_active_succeeds_once_dependent_definition_is_inactive(): void
    {
        $categoryId = $this->createCategory();
        $def = $this->defs->save([
            'code' => $this->uniqueCode('DEP2'), 'name' => 'وابستهٔ غیرفعال', 'entityType' => 'MESSAGE',
            'categoryId' => $categoryId, 'isActive' => 0,
        ], self::USER_FULL);

        $res = $this->as(self::USER_FULL)->postJson("/workflow/categories/{$categoryId}/toggle");
        $res->assertOk();
        $this->assertTrue($res->json('success'));

        // Grandfathering: خودِ Definition همچنان CategoryID را نگه می‌دارد
        $shown = $this->defs->show((int) $def->DefinitionID)['definition'];
        $this->assertSame($categoryId, (int) $shown->CategoryID);
    }

    public function test_cannot_assign_inactive_category_to_a_new_definition(): void
    {
        $categoryId = $this->createCategory();
        $this->as(self::USER_FULL)->postJson("/workflow/categories/{$categoryId}/toggle")->assertOk();

        $res = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'code' => $this->uniqueCode('NEWDEF'), 'name' => 'فرایندِ جدید', 'entityType' => 'MESSAGE',
            'categoryId' => $categoryId,
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
    }

    public function test_re_saving_definition_keeps_its_already_assigned_inactive_category(): void
    {
        $categoryId = $this->createCategory();
        $code = $this->uniqueCode('KEEP');
        $def = $this->defs->save([
            'code' => $code, 'name' => 'قبلِ غیرفعالی', 'entityType' => 'MESSAGE',
            'categoryId' => $categoryId, 'isActive' => 0,
        ], self::USER_FULL);
        $this->as(self::USER_FULL)->postJson("/workflow/categories/{$categoryId}/toggle")->assertOk();

        // ویرایشِ نام، بدونِ تغییرِ CategoryID — نباید رد شود
        $res = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'definitionId' => (int) $def->DefinitionID,
            'code'         => $code,
            'name'         => 'نامِ تغییریافته',
            'entityType'   => 'MESSAGE',
            'categoryId'   => $categoryId,
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_toggle_definition_active_does_not_wipe_its_category(): void
    {
        $categoryId = $this->createCategory();
        $def = $this->defs->save([
            'code' => $this->uniqueCode('TGLDEF'), 'name' => 'تستِ Toggle', 'entityType' => 'MESSAGE',
            'categoryId' => $categoryId, 'isActive' => 1,
        ], self::USER_FULL);

        $this->as(self::USER_FULL)
            ->postJson("/workflow/definitions/{$def->DefinitionID}/toggle")
            ->assertOk();

        $shown = $this->defs->show((int) $def->DefinitionID)['definition'];
        $this->assertSame($categoryId, (int) $shown->CategoryID);
        $this->assertSame(0, (int) $shown->IsActive);
    }

    public function test_definitions_list_can_be_filtered_by_category(): void
    {
        $catA = $this->createCategory();
        $catB = $this->createCategory();

        $defA = $this->defs->save(['code' => $this->uniqueCode('FILT_A'), 'name' => 'الف', 'entityType' => 'MESSAGE', 'categoryId' => $catA], self::USER_FULL);
        $this->defs->save(['code' => $this->uniqueCode('FILT_B'), 'name' => 'ب', 'entityType' => 'MESSAGE', 'categoryId' => $catB], self::USER_FULL);

        $res = $this->as(self::USER_FULL)->getJson('/workflow/definitions?categoryId=' . $catA);
        $res->assertOk();

        $codes = collect($res->json('items'))->pluck('DefinitionID');
        $this->assertContains((int) $defA->DefinitionID, $codes->map(fn ($v) => (int) $v));
        $this->assertCount(1, $codes);
    }

    public function test_definition_show_includes_category_name(): void
    {
        $categoryId = $this->createCategory();
        $def = $this->defs->save(['code' => $this->uniqueCode('SHOWCAT'), 'name' => 'تستِ نمایش', 'entityType' => 'MESSAGE', 'categoryId' => $categoryId], self::USER_FULL);

        $shown = $this->defs->show((int) $def->DefinitionID)['definition'];
        $this->assertSame('دستهٔ تست', $shown->CategoryName);
    }

    public function test_version_meta_includes_category_name(): void
    {
        $categoryId = $this->createCategory();
        $def = $this->defs->save(['code' => $this->uniqueCode('VERMETA'), 'name' => 'تستِ Meta', 'entityType' => 'MESSAGE', 'categoryId' => $categoryId], self::USER_FULL);
        $version = $this->defs->createDraft((int) $def->DefinitionID, self::USER_FULL);

        $graph = $this->defs->getGraph((int) $version->VersionID);
        $this->assertSame('دستهٔ تست', $graph['meta']->CategoryName);
    }

    public function test_definition_without_category_is_still_valid(): void
    {
        $def = $this->defs->save(['code' => $this->uniqueCode('NOCAT'), 'name' => 'بدونِ دسته', 'entityType' => 'MESSAGE'], self::USER_FULL);

        $shown = $this->defs->show((int) $def->DefinitionID)['definition'];
        $this->assertNull($shown->CategoryID);
        $this->assertNull($shown->CategoryName);
    }
}
