<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تعریفِ برند برایِ طرف‌حساب (CrmPartyBrandCategories؛ ارتباطِ داخلیِ CrmPartyBrands خودکار):
 * - برند اجباری؛ دسته و تاریخ‌ها اختیاری؛ درصد اجباری با پیش‌فرضِ ۰ و بین ۰..۱۰۰.
 * - Party+Brand+Category (Category=NULL هم یک مقدار) فقط یک ردیفِ فعال؛ ردیف‌هایِ غیرفعال تاریخچه‌اند.
 * - سقفِ ۱۰۰٪ فقط برایِ ردیف‌هایِ فعالِ دارایِ دسته، در هر روزِ هم‌پوشان؛ EntryDate خالی = از ابتدا، ExitDate خالی = بی‌پایان.
 * - در ویرایش هر پنج مقدار قابلِ تغییر و همهٔ قواعد دوباره اجرا می‌شوند؛ تغییرِ برند ارتباطِ داخلی را می‌سازد/فعال می‌کند.
 *
 * کاربران: 2 → RoleID=1 (مدیرِ سیستم)؛ 3 → بدونِ Permissionهایِ CRM
 */
class CrmPartyBrandDefinitionTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function uniq(string $prefix): string
    {
        return $prefix . ' ' . strtoupper(bin2hex(random_bytes(3)));
    }

    private function brand(): int
    {
        return $this->as(self::USER_FULL)->postJson('/crm/brands', ['name' => $this->uniq('برندِ تعریف')])->assertOk()->json('brandId');
    }

    private function category(?int $parentId = null): int
    {
        return $this->as(self::USER_FULL)->postJson('/crm/product-categories', [
            'displayName' => $this->uniq('دستهٔ تعریف'), 'parentCategoryId' => $parentId,
        ])->assertOk()->json('productCategoryId');
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'طرف‌حسابِ تستِ برند', 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    /** ایجاد (بدونِ id) یا ویرایشِ کامل (با id) — کلیدهایِ نیامده ارسال نمی‌شوند */
    private function save(array $body)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/party-brand-categories', $body);
    }

    private function create(int $party, int $brand, ?int $cat = null, $percent = 0, ?string $entry = null, ?string $exit = null)
    {
        return $this->save(['partyId' => $party, 'brandId' => $brand, 'productCategoryId' => $cat, 'sharePercent' => $percent, 'entryDate' => $entry, 'exitDate' => $exit]);
    }

    private function edit(int $id, int $brand, ?int $cat = null, $percent = 0, ?string $entry = null, ?string $exit = null)
    {
        return $this->save(['partyBrandCategoryId' => $id, 'brandId' => $brand, 'productCategoryId' => $cat, 'sharePercent' => $percent, 'entryDate' => $entry, 'exitDate' => $exit]);
    }

    private function toggle(int $id)
    {
        return $this->as(self::USER_FULL)->postJson("/crm/party-brand-categories/{$id}/toggle");
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function rows(int $party)
    {
        return collect($this->as(self::USER_FULL)->getJson("/crm/party-brand-categories?partyId={$party}")->assertOk()->json('items'))
            ->keyBy(fn ($r) => (int) $r['PartyBrandCategoryID']);
    }

    private function rejected($response, ?string $contains = null): void
    {
        $response->assertStatus(422)->assertJson(['success' => false]);
        if ($contains !== null) {
            $this->assertStringContainsString($contains, $response->json('message'));
        }
    }

    private function ok($response): int
    {
        return $response->assertOk()->assertJson(['success' => true])->json('partyBrandCategoryId');
    }

    /* ==================================================================== */
    /*  فیلدها                                                              */
    /* ==================================================================== */

    public function test_brand_is_required_on_create_and_edit(): void
    {
        $party = $this->party();
        $this->rejected($this->save(['partyId' => $party, 'sharePercent' => 10]), 'برند');
        $this->rejected($this->save(['partyId' => $party, 'brandId' => null]), 'برند');

        $id = $this->ok($this->create($party, $this->brand()));
        $this->rejected($this->save(['partyBrandCategoryId' => $id, 'sharePercent' => 5]), 'برند');
    }

    public function test_percent_defaults_to_zero_and_is_bounded(): void
    {
        $party = $this->party();
        $a = $this->ok($this->save(['partyId' => $party, 'brandId' => $this->brand()]));
        $b = $this->ok($this->save(['partyId' => $party, 'brandId' => $this->brand(), 'sharePercent' => null]));
        $c = $this->ok($this->save(['partyId' => $party, 'brandId' => $this->brand(), 'sharePercent' => '']));
        $rows = $this->rows($party);
        foreach ([$a, $b, $c] as $id) {
            $this->assertSame(0.0, (float) $rows[$id]['SharePercent']);
        }

        $this->rejected($this->create($party, $this->brand(), null, -0.01), '۰ تا ۱۰۰');
        $this->rejected($this->create($party, $this->brand(), null, 100.01), '۰ تا ۱۰۰');
        $this->rejected($this->create($party, $this->brand(), null, 'x'));
        $this->ok($this->create($party, $this->brand(), null, 100));
    }

    public function test_dates_are_optional_and_ordered(): void
    {
        $party = $this->party();
        $cat = $this->category();
        $none = $this->ok($this->create($party, $this->brand(), $cat, 1));
        $entryOnly = $this->ok($this->create($party, $this->brand(), $cat, 1, '2030-01-01'));
        $exitOnly = $this->ok($this->create($party, $this->brand(), $cat, 1, null, '2030-01-01'));
        $same = $this->ok($this->create($party, $this->brand(), $cat, 1, '2030-02-01', '2030-02-01'));
        $this->rejected($this->create($party, $this->brand(), $cat, 1, '2030-02-02', '2030-02-01'), 'تاریخِ خروج');

        $rows = $this->rows($party);
        $this->assertNull($rows[$none]['EntryDate']);
        $this->assertNull($rows[$none]['ExitDate']);
        $this->assertNull($rows[$entryOnly]['ExitDate']);
        $this->assertNull($rows[$exitOnly]['EntryDate']);
        $this->assertNotNull($rows[$same]['EntryDate']);
    }

    /* ==================================================================== */
    /*  یکتاییِ فعال و تاریخچه                                              */
    /* ==================================================================== */

    public function test_brand_without_category_has_only_one_active_row(): void
    {
        $party = $this->party();
        $brand = $this->brand();
        $this->ok($this->create($party, $brand));
        $this->rejected($this->create($party, $brand, null, 50), 'بدونِ دسته‌بندی');

        // برندِ دیگر یا طرف‌حسابِ دیگر مستقل‌اند
        $this->ok($this->create($party, $this->brand()));
        $this->ok($this->create($this->party(), $brand));
    }

    public function test_same_brand_in_different_categories_is_allowed_but_not_twice_in_one(): void
    {
        $party = $this->party();
        $brand = $this->brand();
        [$a, $b, $c] = [$this->category(), $this->category(), $this->category()];

        foreach ([$a, $b, $c] as $cat) {
            $this->ok($this->create($party, $brand, $cat, 10));
        }
        $this->ok($this->create($party, $brand)); // و یک ردیفِ بدونِ دسته
        $this->rejected($this->create($party, $brand, $a, 5), 'این دسته‌بندی');
        $this->assertCount(4, $this->rows($party));
    }

    public function test_inactive_rows_are_history_and_reactivation_respects_uniqueness(): void
    {
        $party = $this->party();
        $brand = $this->brand();
        $cat = $this->category();

        $old = $this->ok($this->create($party, $brand, $cat, 30, '2020-01-01', '2020-12-31'));
        $this->toggle($old)->assertOk();
        $new = $this->ok($this->create($party, $brand, $cat, 40, '2025-01-01'));

        $this->rejected($this->toggle($old), 'این دسته‌بندی');

        $this->toggle($new)->assertOk();
        $this->toggle($old)->assertOk()->assertJson(['success' => true]);

        $rows = $this->rows($party);
        $this->assertCount(2, $rows, 'هیچ ردیفی حذف نمی‌شود.');
        $this->assertSame(1, (int) $rows[$old]['IsActive']);
        $this->assertSame(0, (int) $rows[$new]['IsActive']);

        // چند ردیفِ تاریخیِ غیرفعال برایِ همان ترکیب مجاز است
        $this->toggle($old)->assertOk();
        $third = $this->ok($this->create($party, $brand, $cat, 10));
        $this->toggle($third)->assertOk();
        $this->assertSame(0, $this->rows($party)->where('IsActive', '1')->count());
        $this->assertCount(3, $this->rows($party));
    }

    public function test_active_uniqueness_is_enforced_by_the_database_itself(): void
    {
        $party = $this->party();
        $id = $this->ok($this->create($party, $this->brand()));
        $partyBrandId = (int) DB::selectOne('SELECT PartyBrandID p FROM CrmPartyBrandCategories WHERE PartyBrandCategoryID = ?', [$id])->p;

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::insert('INSERT INTO CrmPartyBrandCategories (PartyBrandID, ProductCategoryID, SharePercent, IsActive) VALUES (?, NULL, 0, 1)', [$partyBrandId]);
    }

    /* ==================================================================== */
    /*  سقفِ ۱۰۰٪                                                           */
    /* ==================================================================== */

    public function test_rows_without_category_are_outside_the_cap(): void
    {
        $party = $this->party();
        $this->ok($this->create($party, $this->brand(), null, 100));
        $this->ok($this->create($party, $this->brand(), null, 100));
        $this->ok($this->create($party, $this->brand(), null, 100));

        // و در سقفِ یک دسته هم شمرده نمی‌شوند
        $cat = $this->category();
        $this->ok($this->create($party, $this->brand(), $cat, 100));
    }

    public function test_cap_exactly_100_and_above(): void
    {
        $party = $this->party();
        $cat = $this->category();
        $this->ok($this->create($party, $this->brand(), $cat, 20));
        $this->ok($this->create($party, $this->brand(), $cat, 30));
        $this->ok($this->create($party, $this->brand(), $cat, 50)); // = 100
        $this->rejected($this->create($party, $this->brand(), $cat, 0.01), '۱۰۰٪');
        $this->ok($this->create($party, $this->brand(), $cat, 0)); // ۰٪ مجموع را بالا نمی‌برد

        // سقف برایِ هر طرف‌حساب و هر دسته جداست؛ والد و فرزند هم جدا
        $this->ok($this->create($party, $this->brand(), $this->category(), 100));
        $this->ok($this->create($party, $this->brand(), $this->category($cat), 100));
        $this->ok($this->create($this->party(), $this->brand(), $cat, 100));
    }

    public function test_empty_entry_date_means_from_the_beginning(): void
    {
        $party = $this->party();
        $cat = $this->category();
        $this->ok($this->create($party, $this->brand(), $cat, 60)); // ∅..∅

        $this->rejected($this->create($party, $this->brand(), $cat, 50, '2030-01-01'), '2030-01-01');
        $this->rejected($this->create($party, $this->brand(), $cat, 50, null, '1990-01-01'), 'از ابتدا');
        $this->ok($this->create($party, $this->brand(), $cat, 40, null, '1990-01-01')); // = 100 از ابتدا تا 1990
        $this->ok($this->create($party, $this->brand(), $cat, 40, '2040-01-01'));       // = 100 از 2040

        $this->rejected($this->create($party, $this->brand(), $cat, 1, '1990-01-01', '1990-01-01'), '1990-01-01'); // روزِ خروج جزوِ بازه
        $this->ok($this->create($party, $this->brand(), $cat, 0.5, '1990-01-02', '2039-12-31'));                   // 60 + 0.5
    }

    public function test_overlapping_periods_are_summed_and_non_overlapping_are_not(): void
    {
        $party = $this->party();
        $cat = $this->category();
        $this->ok($this->create($party, $this->brand(), $cat, 70, '2030-01-01', '2030-06-30'));
        $this->rejected($this->create($party, $this->brand(), $cat, 40, '2030-06-30'), '2030-06-30');
        $this->ok($this->create($party, $this->brand(), $cat, 40, '2030-07-01'));
        $this->ok($this->create($party, $this->brand(), $cat, 30, '2030-06-30', '2030-07-15'));   // 100 در 06-30، 70 در 07-01
        $this->rejected($this->create($party, $this->brand(), $cat, 1, '2030-06-01', '2030-06-30'), '2030-06-30');
    }

    /* ==================================================================== */
    /*  ویرایشِ کاملِ ردیف                                                  */
    /* ==================================================================== */

    public function test_every_field_can_be_edited_and_brand_change_creates_the_internal_link(): void
    {
        $party = $this->party();
        [$b1, $b2] = [$this->brand(), $this->brand()];
        [$c1, $c2] = [$this->category(), $this->category()];
        $id = $this->ok($this->create($party, $b1, $c1, 10));

        $this->ok($this->edit($id, $b2, $c2, 25, '2031-01-01', '2031-12-31'));
        $row = $this->rows($party)[$id];
        $this->assertSame($b2, (int) $row['BrandID']);
        $this->assertSame($c2, (int) $row['ProductCategoryID']);
        $this->assertSame(25.0, (float) $row['SharePercent']);
        $this->assertSame('2031-01-01', substr($row['EntryDate'], 0, 10));
        $this->assertSame('2031-12-31', substr($row['ExitDate'], 0, 10));

        // ارتباطِ داخلیِ برندِ جدید ساخته شده و ارتباطِ برندِ قبلی حذف نشده است
        $links = DB::select('SELECT BrandID, IsActive FROM CrmPartyBrands WHERE PartyID = ?', [$party]);
        $this->assertEqualsCanonicalizing([$b1, $b2], array_map(fn ($l) => (int) $l->BrandID, $links));

        // دسته → بدونِ دسته، تاریخ‌ها → خالی
        $this->ok($this->edit($id, $b2, null, 0));
        $row = $this->rows($party)[$id];
        $this->assertNull($row['ProductCategoryID']);
        $this->assertNull($row['EntryDate']);
        $this->assertNull($row['ExitDate']);
    }

    public function test_edit_reruns_uniqueness_and_cap(): void
    {
        $party = $this->party();
        [$b1, $b2] = [$this->brand(), $this->brand()];
        $cat = $this->category();
        $x = $this->ok($this->create($party, $b1, $cat, 60));
        $y = $this->ok($this->create($party, $b2, $cat, 40));
        $z = $this->ok($this->create($party, $b1)); // b1 بدونِ دسته

        $this->rejected($this->edit($z, $b1, $cat, 0), 'این دسته‌بندی');   // b1+cat فعال هست
        $this->rejected($this->edit($y, $b1, $cat, 40), 'این دسته‌بندی');  // تغییرِ برند به b1 در همان دسته
        $this->rejected($this->edit($y, $b2, $cat, 41), '۱۰۰٪');
        $this->ok($this->edit($x, $b1, $cat, 60, null, '2020-01-01')); // تا 2020 هنوز 60+40 = 100 → مجاز
        $this->ok($this->edit($y, $b2, $cat, 100, '2020-01-02'));        // بعد از خروجِ x، y می‌تواند 100 باشد
    }

    public function test_edit_date_or_percent_change_rechecked_and_inactive_rows_skip_cap_until_reactivated(): void
    {
        $party = $this->party();
        $cat = $this->category();
        $b = $this->brand();
        $a = $this->ok($this->create($party, $this->brand(), $cat, 60));
        $r = $this->ok($this->create($party, $b, $cat, 40, null, '2029-12-31'));
        $this->ok($this->create($party, $this->brand(), $cat, 40, '2030-01-01'));

        $this->rejected($this->edit($r, $b, $cat, 40, null, '2030-01-01'), '2030-01-01'); // گسترشِ بازه → 140
        $this->toggle($r)->assertOk();
        $this->ok($this->edit($r, $b, $cat, 90)); // غیرفعال: بدونِ سقف ذخیره می‌شود
        $this->rejected($this->toggle($r), '۱۰۰٪');
        $this->ok($this->edit($r, $b, $cat, 0));
        $this->toggle($r)->assertOk()->assertJson(['success' => true]);
        $this->assertSame(60.0, (float) $this->rows($party)[$a]['SharePercent']);
    }

    public function test_inactive_brand_or_category_cannot_be_newly_chosen_but_existing_value_is_kept(): void
    {
        $party = $this->party();
        $brand = $this->brand();
        $cat = $this->category();
        $id = $this->ok($this->create($party, $brand, $cat, 10));

        $this->as(self::USER_FULL)->postJson("/crm/brands/{$brand}/toggle")->assertOk();
        $this->as(self::USER_FULL)->postJson("/crm/product-categories/{$cat}/toggle")->assertOk();

        $this->rejected($this->create($party, $brand), 'برند');
        $this->rejected($this->create($party, $this->brand(), $cat), 'دسته‌بندی');
        $this->ok($this->edit($id, $brand, $cat, 20)); // مقدارِ فعلیِ بدونِ تغییر مجاز است

        $this->toggle($id)->assertOk();
        $this->rejected($this->toggle($id), 'برند');
    }

    /* ==================================================================== */
    /*  ارتباطِ داخلی، شمارش‌ها، حذفِ مسیرهایِ قدیمی                        */
    /* ==================================================================== */

    public function test_inactive_internal_link_is_reactivated_by_a_new_active_row(): void
    {
        $party = $this->party();
        $brand = $this->brand();
        DB::insert('INSERT INTO CrmPartyBrands (PartyID, BrandID, IsActive, UserID_InsertFirst) VALUES (?, ?, 0, 2)', [$party, $brand]);

        $this->ok($this->create($party, $brand));
        $this->assertSame(1, (int) DB::selectOne('SELECT IsActive i FROM CrmPartyBrands WHERE PartyID = ? AND BrandID = ?', [$party, $brand])->i);
        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM CrmPartyBrands WHERE PartyID = ? AND BrandID = ?', [$party, $brand])->c);
    }

    public function test_counts_come_from_distinct_brands_with_an_active_row(): void
    {
        $party = $this->party();
        [$b1, $b2] = [$this->brand(), $this->brand()];
        $this->ok($this->create($party, $b1));
        $this->ok($this->create($party, $b1, $this->category(), 10));
        $r = $this->ok($this->create($party, $b2));

        $count = fn () => (int) collect($this->as(self::USER_FULL)->getJson('/crm/parties-list')->json('items'))->firstWhere('PartyID', (string) $party)['ActiveBrandCount'];
        $this->assertSame(2, $count());
        $this->toggle($r)->assertOk();
        $this->assertSame(1, $count());

        $this->as(self::USER_FULL)->get("/crm/brands/{$b1}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('parties', 1)->where('parties.0.ActiveRowCount', '2'));
        $this->as(self::USER_FULL)->get("/crm/brands/{$b2}")->assertOk()->assertInertia(fn ($page) => $page->has('parties', 0));
        $brandRow = collect($this->as(self::USER_FULL)->getJson('/crm/brands?isActive=1')->json('items'))->firstWhere('BrandID', (string) $b1);
        $this->assertSame(1, (int) $brandRow['ActivePartyCount']);
    }

    public function test_party_form_no_longer_defines_brands_and_old_link_endpoints_are_gone(): void
    {
        $brand = $this->brand();
        $digits = '1' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        $party = $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'بدونِ برند', 'identifierNumber' => $digits, 'brandIds' => [$brand],
        ])->assertOk()->json('partyId');
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM CrmPartyBrands WHERE PartyID = ?', [$party])->c, 'brandIds دیگر پردازش نمی‌شود.');

        $this->as(self::USER_FULL)->getJson("/crm/party-brands?partyId={$party}")->assertNotFound();
        $this->as(self::USER_FULL)->postJson('/crm/party-brands', ['partyId' => $party, 'brandId' => $brand])->assertNotFound();
        $this->as(self::USER_FULL)->postJson('/crm/party-brands/1/toggle')->assertNotFound();

        $this->as(self::USER_FULL)->get("/crm/parties/{$party}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('brandCategories', 0)->missing('brands'));
    }

    public function test_every_existing_internal_link_is_visible_in_the_grid(): void
    {
        // بعد از migration هر ارتباطِ داخلی حداقل یک ردیف (فعال یا تاریخی) در گرید دارد
        $invisible = DB::select('SELECT pb.PartyBrandID FROM CrmPartyBrands pb WHERE NOT EXISTS (SELECT 1 FROM CrmPartyBrandCategories x WHERE x.PartyBrandID = pb.PartyBrandID)');
        $this->assertSame([], $invisible);

        $activeWithoutRow = DB::select('SELECT pb.PartyBrandID FROM CrmPartyBrands pb WHERE pb.IsActive = 1 AND NOT EXISTS (SELECT 1 FROM CrmPartyBrandCategories x WHERE x.PartyBrandID = pb.PartyBrandID AND x.IsActive = 1)');
        $this->assertSame([], $activeWithoutRow);
    }

    public function test_endpoints_require_permissions(): void
    {
        $party = $this->party();
        $id = $this->ok($this->create($party, $this->brand()));

        $this->as(self::USER_NOPERM)->getJson("/crm/party-brand-categories?partyId={$party}")->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson('/crm/party-brand-categories', ['partyId' => $party, 'brandId' => 1])->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson("/crm/party-brand-categories/{$id}/toggle")->assertStatus(403);
    }
}
