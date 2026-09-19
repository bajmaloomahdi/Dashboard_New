<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RegistersTestEntityType;
use Tests\TestCase;

/**
 * Phase 3 — Phase A: Registryِ نوعِ موجودیت‌ها (WorkflowEntityTypes).
 *
 * می‌سنجد: CRUD، Permissionِ WORKFLOW_MANAGE_ENTITY_TYPES، اینکه MESSAGE/PROJECT
 * (Seedِ این فاز) واقعاً Usable هستند، اینکه یک ردیفِ غیرفعال یا بدونِ Resolverِ
 * واقعی هرگز Usable نمی‌شود، و اینکه Definitionِ جدید با EntityTypeِ نامعتبر رد
 * می‌شود — بدونِ Regression در Definitionِ MESSAGE/PROJECTِ موجود.
 *
 * کاربران: 2 → هر ۱۱ دسترسیِ WORKFLOW_* (شاملِ WORKFLOW_MANAGE_ENTITY_TYPES تازه)
 *          3 → بدونِ هیچ دسترسیِ WORKFLOW_
 */
class WorkflowEntityTypeTest extends TestCase
{
    use DatabaseTransactions;
    use RegistersTestEntityType;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private WorkflowDefinitionService $defs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestEntityType();
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

    /* ==================================================================== */
    /*  CRUD                                                                 */
    /* ==================================================================== */

    public function test_manage_entity_types_permission_is_granted_to_admin_role(): void
    {
        // اطمینان از اینکه Permissionِ تازه به RoleID=1 هم اعطا شده (نه فقط ایجاد شده)
        $res = $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'code' => $this->uniqueCode('CRUDCHK'),
            'displayName' => 'بررسیِ دسترسی',
        ]);
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_create_and_list_entity_type(): void
    {
        $code = $this->uniqueCode('CRUD');

        $created = $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'code' => $code,
            'displayName' => 'نوعِ تستی',
            'resolverClass' => 'App\\Services\\Workflow\\Entity\\NullEntityResolver',
            'sortOrder' => 99,
        ])->assertOk()->json();

        $this->assertTrue($created['success']);
        $entityTypeId = $created['entityTypeId'];

        $list = $this->as(self::USER_FULL)->getJson('/workflow/entity-types?search=' . $code)
            ->assertOk()->json('items');

        $row = collect($list)->firstWhere('EntityTypeID', $entityTypeId);
        $this->assertNotNull($row);
        $this->assertSame($code, $row['Code']);
        $this->assertSame('نوعِ تستی', $row['DisplayName']);
    }

    public function test_edit_existing_entity_type(): void
    {
        $code = $this->uniqueCode('EDIT');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'code' => $code, 'displayName' => 'نامِ اولیه',
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'entityTypeId' => $created['entityTypeId'], 'code' => $code, 'displayName' => 'نامِ ویرایش‌شده',
        ])->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/entity-types?search=' . $code)->json('items');
        $this->assertSame('نامِ ویرایش‌شده', collect($list)->first()['DisplayName']);
    }

    public function test_toggle_entity_type_active(): void
    {
        $code = $this->uniqueCode('TGL');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'code' => $code, 'displayName' => 'تستِ Toggle',
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/workflow/entity-types/{$created['entityTypeId']}/toggle")
            ->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/entity-types?search=' . $code)->json('items');
        $this->assertFalse((bool) collect($list)->first()['IsActive']);
    }

    /* ==================================================================== */
    /*  Permission                                                           */
    /* ==================================================================== */

    public function test_store_without_manage_permission_is_403(): void
    {
        $this->as(self::USER_NOPERM)->postJson('/workflow/entity-types', [
            'code' => $this->uniqueCode('NOPERM'), 'displayName' => 'ن',
        ])->assertStatus(403);
    }

    public function test_toggle_without_manage_permission_is_403(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'code' => $this->uniqueCode('NOPERM2'), 'displayName' => 'ن',
        ])->assertOk()->json();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/entity-types/{$created['entityTypeId']}/toggle")
            ->assertStatus(403);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/workflow/entity-types')->assertStatus(401);
    }

    /* ==================================================================== */
    /*  MESSAGE / PROJECT — Seedِ این فاز                                    */
    /* ==================================================================== */

    public function test_message_entity_type_is_usable(): void
    {
        $this->assertTrue($this->defs->isEntityTypeUsable('MESSAGE'));

        $items = $this->as(self::USER_FULL)
            ->getJson('/workflow/entity-types?usableOnly=1')
            ->assertOk()->json('items');

        $this->assertContains('MESSAGE', collect($items)->pluck('Code')->all());
    }

    public function test_project_entity_type_is_usable(): void
    {
        $this->assertTrue($this->defs->isEntityTypeUsable('PROJECT'));

        $items = $this->as(self::USER_FULL)
            ->getJson('/workflow/entity-types?usableOnly=1')
            ->assertOk()->json('items');

        $this->assertContains('PROJECT', collect($items)->pluck('Code')->all());
    }

    /** رگرسیون: Definitionِ MESSAGE موجود همچنان بدونِ خطا ساخته می‌شود. */
    public function test_creating_message_definition_has_no_regression(): void
    {
        $code = $this->uniqueCode('MSGREG');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'latinName' => $code, 'name' => 'رگرسیونِ پیام', 'entityType' => 'MESSAGE',
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
    }

    /** رگرسیون: Definitionِ PROJECT موجود همچنان بدونِ خطا ساخته می‌شود. */
    public function test_creating_project_definition_has_no_regression(): void
    {
        $code = $this->uniqueCode('PRJREG');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'latinName' => $code, 'name' => 'رگرسیونِ پروژه', 'entityType' => 'PROJECT',
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
    }

    /** رگرسیون: Resolverِ واقعیِ MESSAGE (MessageEntityResolver) هنوز درست وصل است. */
    public function test_message_resolver_mapping_still_resolves_via_container(): void
    {
        $registry = $this->app->make(\App\Services\Workflow\Entity\EntityResolverRegistry::class);
        $resolver = $registry->resolverFor('MESSAGE');
        $this->assertInstanceOf(\App\Services\Workflow\Entity\MessageEntityResolver::class, $resolver);
    }

    /* ==================================================================== */
    /*  Entityِ غیرفعال / بدونِ Resolver — نباید Usable باشد                  */
    /* ==================================================================== */

    public function test_inactive_entity_type_is_not_usable_even_if_resolver_known(): void
    {
        // TEST_ENTITY در setUp() فقط در Config ثبت شده (بدونِ ردیفِ Registry) —
        // طبقِ طراحی هنوز Usable است (نبودِ ردیف = رفتارِ قبل‌از‌Registry). اما اگر
        // یک ردیفِ Registryِ صریحاً غیرفعال برایش ثبت کنیم، باید Unusable شود.
        $created = $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'code' => 'TEST_ENTITY', 'displayName' => 'موجودیتِ تستی',
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/workflow/entity-types/{$created['entityTypeId']}/toggle")->assertOk();

        $this->assertFalse($this->defs->isEntityTypeUsable('TEST_ENTITY'));

        // و در سطحِ Backendِ ساختِ Definition هم رد شود
        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'latinName' => $this->uniqueCode('INACTIVE_ENTITY'), 'name' => 'ن', 'entityType' => 'TEST_ENTITY',
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    /** اثباتِ اینکه UI/Backend از Registryِ واقعیِ DB می‌خواند، نه صرفاً Config را Mirror می‌کند. */
    public function test_entity_type_with_no_config_resolver_is_listed_but_not_usable(): void
    {
        $code = $this->uniqueCode('NORESOLVER');

        // ردیفی در Registry با IsActive=1 اما بدونِ هیچ Resolverِ واقعی در config/workflow.php
        $created = $this->as(self::USER_FULL)->postJson('/workflow/entity-types', [
            'code' => $code, 'displayName' => 'بدونِ Resolver',
        ])->assertOk()->json();
        $this->assertTrue($created['success']);

        // در لیستِ خامِ Registry دیده می‌شود (ثابت می‌کند UI از DB می‌خواند)
        $rawList = $this->as(self::USER_FULL)->getJson('/workflow/entity-types?search=' . $code)->json('items');
        $this->assertNotEmpty($rawList, 'باید در لیستِ خامِ Registry دیده شود.');

        // اما در لیستِ usableOnly نیست (چون Resolverِ واقعی در Config ندارد)
        $usableList = $this->as(self::USER_FULL)->getJson('/workflow/entity-types?usableOnly=1')->json('items');
        $this->assertNotContains($code, collect($usableList)->pluck('Code')->all());

        $this->assertFalse($this->defs->isEntityTypeUsable($code));

        // و ساختِ Definition با این Code هم رد می‌شود
        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'latinName' => $this->uniqueCode('NORESOLVER_DEF'), 'name' => 'ن', 'entityType' => $code,
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  EntityType نامعتبر در ساختِ Definition                                */
    /* ==================================================================== */

    public function test_definition_with_completely_unknown_entity_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'latinName' => $this->uniqueCode('UNKNOWN'), 'name' => 'ن', 'entityType' => 'TOTALLY_UNKNOWN_ENTITY',
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  غیرفعال‌سازیِ MESSAGE/PROJECT وقتی Definitionِ فعال دارند، مسدود می‌شود      */
    /* ==================================================================== */

    public function test_toggle_inactive_is_blocked_when_active_definition_exists(): void
    {
        // یک Definitionِ فعالِ MESSAGE از قبل در DB واقعی وجود دارد (از Seedِ P0/Phase قبلی
        // یا همین تستِ رگرسیون بالا) — پس تلاش برایِ غیرفعال‌کردنِ MESSAGE باید مسدود شود.
        $messageRow = collect($this->as(self::USER_FULL)->getJson('/workflow/entity-types?search=MESSAGE')->json('items'))
            ->firstWhere('Code', 'MESSAGE');
        $this->assertNotNull($messageRow);

        // یک Definitionِ فعال برایِ اطمینان می‌سازیم
        $this->as(self::USER_FULL)->postJson('/workflow/definitions', [
            'latinName' => $this->uniqueCode('BLOCKTGL'), 'name' => 'ن', 'entityType' => 'MESSAGE',
        ])->assertOk();

        $this->as(self::USER_FULL)->postJson("/workflow/entity-types/{$messageRow['EntityTypeID']}/toggle")
            ->assertStatus(422)->assertJson(['success' => false]);
    }
}
