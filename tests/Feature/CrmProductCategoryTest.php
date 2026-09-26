<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * دسته‌بندیِ محصولات (CrmProductCategories — درختِ نامحدود) + صفحه‌ها و Permission.
 * قواعدِ تعریفِ برند برایِ طرف‌حساب (CrmPartyBrandCategories): tests/Feature/CrmPartyBrandDefinitionTest.php
 *
 * کاربران: 2 → RoleID=1 (مدیرِ سیستم)، شاملِ CRM_VIEW / CRM_MANAGE_PARTIES / CRM_MANAGE_MASTER_DATA
 *          3 → بدونِ این Permissionها
 */
class CrmProductCategoryTest extends TestCase
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

    private function category(string $name, ?int $parentId = null): int
    {
        return $this->as(self::USER_FULL)->postJson('/crm/product-categories', [
            'displayName' => $name, 'parentCategoryId' => $parentId,
        ])->assertOk()->assertJson(['success' => true])->json('productCategoryId');
    }

    private function saveCategory(array $body)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/product-categories', $body);
    }

    private function toggleCategory(int $id)
    {
        return $this->as(self::USER_FULL)->postJson("/crm/product-categories/{$id}/toggle");
    }

    /** @return array<int, array<string, mixed>> */
    private function categoryRows(): array
    {
        return $this->as(self::USER_FULL)->getJson('/crm/product-categories-list')->assertOk()->json('items');
    }

    private function brand(): int
    {
        return $this->as(self::USER_FULL)->postJson('/crm/brands', ['name' => $this->uniq('برندِ سهم')])->assertOk()->json('brandId');
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= (string) random_int(0, 9);
        }

        return $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'فروشگاهِ تستیِ سهم', 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function share(int $partyId, int $brandId, int $categoryId, string $entry, ?string $exit, $percent)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/party-brand-categories', [
            'partyId' => $partyId, 'brandId' => $brandId, 'productCategoryId' => $categoryId,
            'entryDate' => $entry, 'exitDate' => $exit, 'sharePercent' => $percent,
        ]);
    }

    private function assertRejected($response, ?string $contains = null): void
    {
        $response->assertStatus(422)->assertJson(['success' => false]);
        if ($contains !== null) {
            $this->assertStringContainsString($contains, $response->json('message'));
        }
    }

    /* ==================================================================== */
    /*  درختِ دسته‌بندی                                                     */
    /* ==================================================================== */

    public function test_multi_level_tree_with_paths_and_levels(): void
    {
        $root = $this->uniq('محصولاتِ زیبایی');
        $a = $this->category($root);
        $b = $this->category('مو', $a);
        $c = $this->category('شامپو', $b);
        $d = $this->category('ضدشوره', $c);
        $e = $this->category('گیاهی', $d); // سطحِ پنجم — محدود به ۳ سطح نیست
        $this->category('رنگِ مو', $b);
        $this->category('پوست', $a);

        $rows = collect($this->categoryRows())->keyBy(fn ($r) => (int) $r['ProductCategoryID']);
        $this->assertSame(0, (int) $rows[$a]['Level']);
        $this->assertSame(4, (int) $rows[$e]['Level']);
        $this->assertSame("{$root} / مو / شامپو / ضدشوره / گیاهی", $rows[$e]['Path']);
        $this->assertNull($rows[$a]['ParentCategoryID']);
        $this->assertSame($d, (int) $rows[$e]['ParentCategoryID']);
        $this->assertSame(2, (int) $rows[$b]['ChildCount']);
        $this->assertMatchesRegularExpression('/^\d+$/', $rows[$a]['Code']);
    }

    public function test_loop_is_prevented_when_changing_parent(): void
    {
        $a = $this->category($this->uniq('ریشه'));
        $b = $this->category('ب', $a);
        $c = $this->category('ج', $b);

        $this->assertRejected($this->saveCategory(['productCategoryId' => $a, 'parentCategoryId' => $c, 'displayName' => 'x']), 'زیرمجموعه');
        $this->assertRejected($this->saveCategory(['productCategoryId' => $b, 'parentCategoryId' => $b, 'displayName' => 'ب']), 'زیرمجموعه');
        $this->assertRejected($this->saveCategory(['productCategoryId' => $b, 'parentCategoryId' => $c, 'displayName' => 'ب']), 'زیرمجموعه');
    }

    public function test_category_can_move_to_another_parent_or_become_root(): void
    {
        $a = $this->category($this->uniq('ریشهٔ یک'));
        $x = $this->category($this->uniq('ریشهٔ دو'));
        $b = $this->category('ب', $a);
        $c = $this->category('ج', $b);

        $this->saveCategory(['productCategoryId' => $b, 'parentCategoryId' => $x, 'displayName' => 'ب'])->assertOk()->assertJson(['success' => true]);
        $rows = collect($this->categoryRows())->keyBy(fn ($r) => (int) $r['ProductCategoryID']);
        $this->assertSame($x, (int) $rows[$b]['ParentCategoryID']);
        $this->assertSame(2, (int) $rows[$c]['Level'], 'زیرمجموعه‌ها همراهِ دسته جابه‌جا می‌شوند.');

        $rootName = $this->uniq('حالا ریشه');
        $this->saveCategory(['productCategoryId' => $b, 'parentCategoryId' => null, 'displayName' => $rootName])->assertOk();
        $rows = collect($this->categoryRows())->keyBy(fn ($r) => (int) $r['ProductCategoryID']);
        $this->assertNull($rows[$b]['ParentCategoryID']);
        $this->assertSame(0, (int) $rows[$b]['Level']);
    }

    public function test_duplicate_name_under_same_parent_is_rejected_but_allowed_under_different_parents(): void
    {
        $root = $this->uniq('ریشه');
        $a = $this->category($root);
        $skin = $this->category('پوست', $a);
        $hair = $this->category('مو', $a);
        $this->category('کرم', $skin);

        $this->assertRejected($this->saveCategory(['parentCategoryId' => $skin, 'displayName' => 'کرم']), 'همین والد');
        $this->assertRejected($this->saveCategory(['parentCategoryId' => $skin, 'displayName' => '  کرم  ']), 'همین والد');
        $this->assertRejected($this->saveCategory(['parentCategoryId' => null, 'displayName' => $root]), 'همین والد');
        $this->category('کرم', $hair);

        // جابه‌جایی به والدی که فرزندی هم‌نام دارد هم رد می‌شود
        $other = $this->category('کرم', $this->category('دیگر', $a));
        $this->assertRejected($this->saveCategory(['productCategoryId' => $other, 'parentCategoryId' => $skin, 'displayName' => 'کرم']), 'همین والد');
    }

    public function test_category_name_is_required(): void
    {
        $this->assertRejected($this->saveCategory(['displayName' => '   ']));
    }

    public function test_activation_rules_follow_the_tree(): void
    {
        $a = $this->category($this->uniq('ریشه'));
        $b = $this->category('ب', $a);
        $c = $this->category('ج', $b);

        $this->assertRejected($this->toggleCategory($a), 'زیرمجموعهٔ فعال');
        $this->assertRejected($this->toggleCategory($b), 'زیرمجموعهٔ فعال');

        $this->toggleCategory($c)->assertOk()->assertJson(['success' => true]);
        $this->toggleCategory($b)->assertOk()->assertJson(['success' => true]);

        // زیرِ دستهٔ غیرفعال: نه ایجاد، نه انتقال، نه فعال‌سازیِ فرزند
        $this->assertRejected($this->saveCategory(['parentCategoryId' => $b, 'displayName' => 'جدید']), 'غیرفعال');
        $loose = $this->category('آزاد', $a);
        $this->assertRejected($this->saveCategory(['productCategoryId' => $loose, 'parentCategoryId' => $b, 'displayName' => 'آزاد']), 'غیرفعال');
        $this->assertRejected($this->toggleCategory($c), 'والد');

        // از بالا به پایین دوباره فعال می‌شود؛ هیچ ردیفی حذف نشده
        $this->toggleCategory($b)->assertOk();
        $this->toggleCategory($c)->assertOk();
        $rows = collect($this->categoryRows())->keyBy(fn ($r) => (int) $r['ProductCategoryID']);
        $this->assertSame(1, (int) $rows[$c]['IsActive']);

        $inactive = collect($this->as(self::USER_FULL)->getJson('/crm/product-categories-list?isActive=0')->json('items'))->pluck('ProductCategoryID')->map(fn ($v) => (int) $v);
        $this->assertNotContains($c, $inactive->all());
    }

    /* ==================================================================== */
    /*  صفحه‌ها و Permission                                                */
    /* ==================================================================== */

    public function test_pages_receive_the_data(): void
    {
        $party = $this->party();
        $brand = $this->brand();
        $cat = $this->category($this->uniq('ریشه'));
        $this->share($party, $brand, $cat, '2030-01-01', null, 25)->assertOk();

        $this->as(self::USER_FULL)->get('/crm/product-categories')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/ProductCategories/Index')->has('categories')->where('canManage', true));
        $this->as(self::USER_FULL)->get("/crm/parties/{$party}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Parties/Show')->has('brandCategories', 1));
        // سهم بخشی از ارتباطِ طرف‌حساب است؛ صفحهٔ برند فقط ارتباط‌ها را دارد، نه سهم/دسته/تاریخ
        $this->as(self::USER_FULL)->get("/crm/brands/{$brand}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Brands/Show')->has('parties', 1)->missing('partyCategories'));
    }

    public function test_endpoints_require_permissions(): void
    {
        $party = $this->party();
        $brand = $this->brand();
        $cat = $this->category($this->uniq('ریشه'));
        $row = $this->share($party, $brand, $cat, '2030-01-01', null, 10)->json('partyBrandCategoryId');

        $this->as(self::USER_NOPERM)->get('/crm/product-categories')->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson('/crm/product-categories-list')->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson('/crm/product-categories', ['displayName' => 'ن'])->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson("/crm/product-categories/{$cat}/toggle")->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson("/crm/party-brand-categories?partyId={$party}")->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson('/crm/party-brand-categories', [
            'partyId' => $party, 'brandId' => $brand, 'productCategoryId' => $cat, 'entryDate' => '2031-01-01', 'sharePercent' => 1,
        ])->assertStatus(403);
        $this->as(self::USER_NOPERM)->postJson("/crm/party-brand-categories/{$row}/toggle")->assertStatus(403);
    }
}
