<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow\TemplateParameterService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 3 — Phase B: Registryِ پارامترهایِ Template (TemplateParameters).
 *
 * کاملاً مستقل از Condition Engine — این فایل هیچ‌جا WorkflowConditionFields،
 * ConditionContextBuilder یا ConditionDataTypeCaster را import/فراخوانی نمی‌کند؛
 * فقط از EntityTypeِ واقعیِ Phase A (`MESSAGE`) برایِ اثباتِ سازگاری با
 * WorkflowEntityTypes استفاده می‌شود.
 *
 * ساده‌سازیِ UX (دورِ Code): Code دیگر ورودیِ مستقیمِ کاربر/کلاینت نیست — Service آن
 * را از caption (یا latinName، وقتی caption حرفِ لاتینِ کافی ندارد) می‌سازد و برایِ
 * ویرایش همیشه همان Codeِ موجود را حفظ می‌کند (نگاه کن به TemplateParameterService::save()).
 *
 * کاربران: 2 → هر ۱۲ دسترسیِ WORKFLOW_* (شاملِ WORKFLOW_MANAGE_TEMPLATE_PARAMETERS تازه)
 *          3 → بدونِ هیچ دسترسیِ WORKFLOW_
 */
class WorkflowTemplateParameterTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private TemplateParameterService $params;

    protected function setUp(): void
    {
        parent::setUp();
        $this->params = $this->app->make(TemplateParameterService::class);
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

    /**
     * caption عمداً فارسی است (رفتارِ واقعیِ اکثرِ کاربرانِ این سیستم) — پس هر تستی که
     * به یک Codeِ مشخص نیاز دارد باید 'latinName' بدهد؛ چون uniqueCode() از قبل
     * با الگویِ Code سازگار است، Codeِ نهایی همیشه دقیقاً برابرِ همان latinName می‌شود.
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'latinName' => $this->uniqueCode('TP'),
            'caption' => 'پارامترِ تست',
            'dataType' => 'STRING',
            'sourceType' => 'FORM',
            'sourceKey' => 'testField',
        ], $overrides);
    }

    /* ==================================================================== */
    /*  CRUD / List / Search / Filter                                        */
    /* ==================================================================== */

    public function test_manage_template_parameters_permission_is_granted_to_admin_role(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload());
        $res->assertOk();
        $this->assertTrue($res->json('success'));
    }

    public function test_create_and_list_template_parameter(): void
    {
        $latinName = $this->uniqueCode('CRUD');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName]))
            ->assertOk()->json();

        $this->assertTrue($created['success']);
        $this->assertSame($latinName, $created['code']);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?search=' . $latinName)->assertOk()->json('items');
        $row = collect($list)->firstWhere('TemplateParameterID', $created['templateParameterId']);
        $this->assertNotNull($row);
        $this->assertSame($latinName, $row['Code']);
    }

    public function test_filter_by_group_code(): void
    {
        $latinName = $this->uniqueCode('GROUPFILTER');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName, 'sourceType' => 'SYSTEM', 'dataType' => 'DATE']))
            ->assertOk();

        $items = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?groupCode=SYSTEM')->json('items');
        $this->assertContains($latinName, collect($items)->pluck('Code')->all());

        $itemsOtherGroup = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?groupCode=USER')->json('items');
        $this->assertNotContains($latinName, collect($itemsOtherGroup)->pluck('Code')->all());
    }

    public function test_filter_by_is_active(): void
    {
        $latinName = $this->uniqueCode('ACTIVEFILTER');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName]))->json();
        $this->as(self::USER_FULL)->postJson("/workflow/template-parameters/{$created['templateParameterId']}/toggle")->assertOk();

        $activeOnly = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?isActive=1&search=' . $latinName)->json('items');
        $this->assertEmpty($activeOnly);

        $inactiveOnly = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?isActive=0&search=' . $latinName)->json('items');
        $this->assertNotEmpty($inactiveOnly);
    }

    public function test_update_existing_template_parameter(): void
    {
        $latinName = $this->uniqueCode('EDIT');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName, 'caption' => 'اولیه']))->json();

        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'templateParameterId' => $created['templateParameterId'], 'caption' => 'ویرایش‌شده',
        ]))->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?search=' . $latinName)->json('items');
        $this->assertSame('ویرایش‌شده', collect($list)->first()['Caption']);
    }

    public function test_toggle_active(): void
    {
        $latinName = $this->uniqueCode('TGL');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName]))->json();

        $this->as(self::USER_FULL)->postJson("/workflow/template-parameters/{$created['templateParameterId']}/toggle")
            ->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson('/workflow/template-parameters?search=' . $latinName)->json('items');
        $this->assertFalse((bool) collect($list)->first()['IsActive']);
    }

    public function test_get_by_code_exact_lookup(): void
    {
        $row = $this->params->getByCode('USER_FULL_NAME');
        $this->assertNotNull($row);
        $this->assertSame('USER_FULL_NAME', $row->Code);
        $this->assertSame('FullName', $row->SourceKey);

        $this->assertNull($this->params->getByCode('TOTALLY_UNKNOWN_CODE_XYZ'));
    }

    /* ==================================================================== */
    /*  تولیدِ خودکارِ Code — کاربر هرگز Code را مستقیماً وارد نمی‌کند           */
    /* ==================================================================== */

    /** وقتی خودِ عنوان حرفِ لاتینِ کافی دارد، Code مستقیماً از رویِ همان ساخته می‌شود — نیازی به latinName نیست. */
    public function test_code_is_generated_directly_from_latin_caption(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'caption' => 'Test Amount Field',
            // اگر caption خودش قابلِ‌استفاده باشد، حتی اگر latinName هم فرستاده شود، نادیده گرفته می‌شود.
            'latinName' => 'SHOULD_BE_IGNORED',
        ]))->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertSame('TEST_AMOUNT_FIELD', $res['code']);
    }

    /** عنوانِ کاملاً فارسی بدونِ latinName باید با پیامِ روشن رد شود، نه با یک Codeِ خالی/نامعتبر. */
    public function test_fully_persian_caption_without_latin_name_is_rejected(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'caption' => 'مبلغ درخواست',
            'dataType' => 'DECIMAL',
            'sourceType' => 'FORM',
            'sourceKey' => 'amountField',
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
        $this->assertStringContainsString('نامِ لاتین', $res->json('message'));
    }

    /** عنوانِ فارسی + latinName معتبر → Codeِ نهایی دقیقاً از رویِ همان latinName ساخته می‌شود. */
    public function test_persian_caption_with_latin_name_generates_code_from_latin_name(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'caption' => 'تاریخِ شروعِ مرخصی', 'latinName' => 'leave_start_date',
        ]))->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertSame('LEAVE_START_DATE', $res['code']);
    }

    /** latinNameِ نامعتبر (فقط عدد/علامت — بعدِ پاک‌سازی خالی می‌ماند) باید با پیامِ روشن رد شود. */
    public function test_invalid_latin_name_is_rejected(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', [
            'caption' => 'یک عنوانِ کاملاً فارسیِ دیگر', 'latinName' => '12345',
            'dataType' => 'STRING', 'sourceType' => 'FORM', 'sourceKey' => 'x',
        ]);
        $res->assertStatus(422);
        $this->assertFalse($res->json('success'));
    }

    /**
     * برخوردِ Codeِ تکراری: به‌جایِ خطا یا Codeِ تصادفی/بی‌معنا، یک پسوندِ عددیِ
     * قابلِ‌پیش‌بینی امتحان می‌شود (AMOUNT، سپس AMOUNT_2، ...).
     */
    public function test_duplicate_latin_name_gets_a_predictable_numeric_suffix(): void
    {
        $latinName = $this->uniqueCode('DUP');

        $first = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName]))
            ->assertOk()->json();
        $this->assertSame($latinName, $first['code']);

        $second = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName]))
            ->assertOk()->json();
        $this->assertSame($latinName . '_2', $second['code']);

        $third = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName]))
            ->assertOk()->json();
        $this->assertSame($latinName . '_3', $third['code']);
    }

    /** حروفِ کوچکِ latinName هم باید در Codeِ نهایی بزرگ شوند. */
    public function test_lowercase_latin_name_is_uppercased_in_generated_code(): void
    {
        $latinName = $this->uniqueCode('lower');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => strtolower($latinName)]))
            ->assertOk()->json();

        $this->assertSame(strtoupper($latinName), $res['code']);
        $this->assertNotNull($this->params->getByCode(strtoupper($latinName)));
    }

    /**
     * قلبِ ساده‌سازیِ UX: Code پس از ایجاد هرگز تغییر نمی‌کند — حتی اگر عنوان/latinName
     * در ویرایش عوض شود، یا کلاینت عمداً مقدارِ دیگری برایِ آن‌ها بفرستد.
     */
    public function test_code_never_changes_via_edit_even_if_caption_or_latin_name_change(): void
    {
        $originalLatinName = $this->uniqueCode('LOCKEDTP');
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'latinName' => $originalLatinName, 'caption' => 'عنوانِ اولیه',
        ]))->assertOk()->json();

        $edited = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'templateParameterId' => $created['templateParameterId'],
            'caption' => 'یک عنوانِ کاملاً متفاوتِ فارسی', 'latinName' => 'TOTALLY_DIFFERENT_NAME',
        ]))->assertOk()->json();

        $this->assertSame($originalLatinName, $edited['code']);
        $stored = $this->params->getByCode($originalLatinName);
        $this->assertNotNull($stored);
        $this->assertSame('یک عنوانِ کاملاً متفاوتِ فارسی', $stored->Caption);
    }

    /* ==================================================================== */
    /*  ساده‌سازیِ UX: GroupCode/SourceType/DataType                          */
    /* ==================================================================== */

    /**
     * ساده‌سازیِ UX: GroupCode دیگر ورودیِ مستقلِ کاربر نیست — Service همیشه آن را
     * برابرِ SourceType می‌سازد (نگاه کن به TemplateParameterService::save())، حتی
     * اگر کلاینت مقدارِ دیگری برایِ groupCode بفرستد (نادیده گرفته می‌شود).
     */
    public function test_group_code_is_always_derived_from_source_type(): void
    {
        $latinName = $this->uniqueCode('DERIVEDGROUP');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'latinName' => $latinName, 'groupCode' => 'NOT_A_REAL_GROUP', 'sourceType' => 'FORM',
        ]))->assertOk()->json();
        $this->assertTrue($res['success']);

        $stored = $this->params->getByCode($latinName);
        $this->assertSame('FORM', $stored->GroupCode);
        $this->assertSame('FORM', $stored->SourceType);
    }

    public function test_invalid_source_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['sourceType' => 'NOT_A_SOURCE']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_invalid_data_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['dataType' => 'BOOLEAN']))
            ->assertStatus(422)->assertJson(['success' => false]);

        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['dataType' => 'SELECT']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    /* ==================================================================== */
    /*  ساده‌سازیِ UX — Source=USER/SYSTEM (SourceKeyِ خودکار/محدود)           */
    /* ==================================================================== */

    public function test_user_source_key_must_be_a_known_field(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'sourceType' => 'USER', 'sourceKey' => 'SomeRandomColumn', 'dataType' => 'STRING',
        ]))->assertStatus(422)->assertJson(['success' => false]);

        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'latinName' => $this->uniqueCode('USERFIELD'), 'sourceType' => 'USER', 'sourceKey' => 'PositionName', 'dataType' => 'STRING',
        ]))->assertOk()->json();
        $this->assertTrue($res['success']);
    }

    public function test_system_source_key_is_always_auto_assigned(): void
    {
        $latinName = $this->uniqueCode('SYSKEY');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'latinName' => $latinName, 'sourceType' => 'SYSTEM', 'dataType' => 'DATE', 'sourceKey' => 'AnythingSentByClient',
        ]))->assertOk()->json();
        $this->assertTrue($res['success']);

        $stored = $this->params->getByCode($latinName);
        $this->assertSame('Today', $stored->SourceKey);
    }

    /* ==================================================================== */
    /*  Description (توضیحِ اختیاری)                                        */
    /* ==================================================================== */

    public function test_description_is_optional_and_round_trips(): void
    {
        $withDesc = $this->uniqueCode('DESC');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload([
            'latinName' => $withDesc, 'description' => 'یک توضیحِ کوتاه برایِ تست.',
        ]))->assertOk();
        $this->assertSame('یک توضیحِ کوتاه برایِ تست.', $this->params->getByCode($withDesc)->Description);

        $withoutDesc = $this->uniqueCode('NODESC');
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $withoutDesc]))
            ->assertOk();
        $this->assertNull($this->params->getByCode($withoutDesc)->Description);
    }

    /* ==================================================================== */
    /*  EntityType — سازگاری با WorkflowEntityTypesِ فازِ A                     */
    /* ==================================================================== */

    public function test_valid_entity_type_from_registry_is_accepted(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['entityType' => 'MESSAGE']))
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_unknown_entity_type_is_rejected(): void
    {
        $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['entityType' => 'TOTALLY_UNKNOWN_ENTITY_XYZ']))
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_null_entity_type_means_global_and_is_accepted(): void
    {
        $latinName = $this->uniqueCode('GLOBAL');
        $res = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload(['latinName' => $latinName]))
            ->assertOk()->json();
        $this->assertTrue($res['success']);

        $stored = $this->params->getByCode($latinName);
        $this->assertNull($stored->EntityType);
    }

    /* ==================================================================== */
    /*  Permission                                                           */
    /* ==================================================================== */

    public function test_store_without_manage_permission_is_403(): void
    {
        $this->as(self::USER_NOPERM)->postJson('/workflow/template-parameters', $this->validPayload())
            ->assertStatus(403);
    }

    public function test_toggle_without_manage_permission_is_403(): void
    {
        $created = $this->as(self::USER_FULL)->postJson('/workflow/template-parameters', $this->validPayload())->json();

        $this->as(self::USER_NOPERM)
            ->postJson("/workflow/template-parameters/{$created['templateParameterId']}/toggle")
            ->assertStatus(403);
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/workflow/template-parameters')->assertStatus(401);
    }

    /* ==================================================================== */
    /*  Seedِ Phase B                                                        */
    /* ==================================================================== */

    public function test_seed_data_exists_with_expected_values(): void
    {
        $expected = [
            'USER_FULL_NAME' => ['USER', 'STRING', 'USER', 'FullName'],
            'USER_PERSONNEL_CODE' => ['USER', 'STRING', 'USER', 'UserCode'],
            'USER_POSITION_TITLE' => ['USER', 'STRING', 'USER', 'PositionName'],
            'USER_UNIT_NAME' => ['USER', 'STRING', 'USER', 'UnitName'],
            'TODAY' => ['SYSTEM', 'DATE', 'SYSTEM', 'Today'],
            'GENERIC_DESCRIPTION' => ['FORM', 'STRING', 'FORM', 'description'],
        ];

        foreach ($expected as $code => [$group, $dataType, $sourceType, $sourceKey]) {
            $row = $this->params->getByCode($code);
            $this->assertNotNull($row, "Seedِ {$code} یافت نشد.");
            $this->assertSame($group, $row->GroupCode, "{$code}: GroupCode");
            $this->assertSame($dataType, $row->DataType, "{$code}: DataType");
            $this->assertSame($sourceType, $row->SourceType, "{$code}: SourceType");
            $this->assertSame($sourceKey, $row->SourceKey, "{$code}: SourceKey");
            $this->assertNull($row->EntityType, "{$code}: باید سراسری (EntityType=NULL) باشد.");
            $this->assertTrue((bool) $row->IsActive, "{$code}: باید فعال باشد.");
        }
    }
}
