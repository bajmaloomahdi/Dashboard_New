<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * قاعدهٔ ترتیب در SPها (پچ 081): هر رکورد یک کلیدِ ترتیب دارد —
 *   SortOrder > 0  ⇒  همان SortOrder
 *   SortOrder = 0  ⇒  کد (Code، به‌صورتِ عددی)
 * و هم‌ارزها با کد و سپس شناسه شکسته می‌شوند. صفر یعنی «کد مبناست»، نه «اول/آخرِ فهرست».
 *
 * داده‌ها با کدهایِ بزرگ (900000+) مستقیم درج می‌شوند (داخلِ تراکنشِ تست، Rollback می‌شود)
 * و فقط ترتیبِ نسبیِ همین رکوردها در خروجیِ SP بررسی می‌شود.
 */
class SortOrderCodeRuleTest extends TestCase
{
    use DatabaseTransactions;

    private int $seq = 0;

    private function uniq(string $p): string
    {
        return $p . ' ' . bin2hex(random_bytes(3)) . ' ' . (++$this->seq);
    }

    private function insert(string $table, string $idCol, array $row): int
    {
        DB::table($table)->insert($row);

        return (int) DB::table($table)->where(array_key_first($row), reset($row))->value($idCol);
    }

    private function position(string $code, int $sort): int
    {
        return $this->insert('CrmPositions', 'PositionID', ['Code' => $code, 'DisplayName' => $this->uniq('سمت'), 'SortOrder' => $sort, 'IsActive' => 1]);
    }

    /** ترتیبِ نسبیِ شناسه‌هایِ مدّنظر در خروجیِ یک SP */
    private function relativeOrder(array $rows, string $idCol, array $ids): array
    {
        return array_values(array_filter(array_map(fn ($r) => (int) $r->{$idCol}, $rows), fn ($id) => in_array($id, $ids, true)));
    }

    private function positionsOrder(array $ids): array
    {
        return $this->relativeOrder(DB::select('EXEC dbo.sp_Crm_GetPositions'), 'PositionID', $ids);
    }

    /* ---------------- CRM Master Data (Code از نوعِ NVARCHAR با مقدارِ عددی) ---------------- */

    public function test_zero_sort_orders_by_numeric_code_not_text(): void
    {
        // متنی: '9000050' < '900060' — عددی: 900060 < 9000050
        $big = $this->position('9000050', 0);
        $small = $this->position('900060', 0);
        $mid = $this->position('900070', 0);

        $this->assertSame([$small, $mid, $big], $this->positionsOrder([$big, $small, $mid]));
    }

    public function test_positive_sort_orders_by_sort_value_regardless_of_code(): void
    {
        $c = $this->position('900101', 3);
        $a = $this->position('900103', 1);
        $b = $this->position('900102', 2);

        $this->assertSame([$a, $b, $c], $this->positionsOrder([$a, $b, $c]));
    }

    public function test_mixed_zero_and_positive_use_one_key_and_zero_is_not_forced_to_an_edge(): void
    {
        // کلیدها: A=900201 (کد)، B=900203 (ترتیب)، C=900202 (کد)، D=900204 (کد)
        $A = $this->position('900201', 0);
        $B = $this->position('900290', 900203);
        $C = $this->position('900202', 0);
        $D = $this->position('900204', 0);

        // رکوردِ دارایِ ترتیبِ مثبت (B) میانِ رکوردهایِ صفر قرار می‌گیرد — نه اول، نه آخر
        $this->assertSame([$A, $C, $B, $D], $this->positionsOrder([$A, $B, $C, $D]));
    }

    public function test_equal_positive_sort_breaks_ties_by_code_then_id(): void
    {
        $x = $this->position('900303', 5);
        $y = $this->position('900301', 5);
        $z = $this->position('900302', 5);

        $this->assertSame([$y, $z, $x], $this->positionsOrder([$x, $y, $z]));
    }

    public function test_sort_value_equal_to_another_rows_code_breaks_tie_by_code(): void
    {
        // P: ترتیب 900402 (کلید 900402، کد 900499)؛ Q: صفر با کد 900402 (کلید 900402) ⇒ هم‌ارز ⇒ کدِ کوچک‌تر (Q) اول
        $P = $this->position('900499', 900402);
        $Q = $this->position('900402', 0);

        $this->assertSame([$Q, $P], $this->positionsOrder([$P, $Q]));
    }

    public function test_rule_applies_to_other_master_data_with_different_alias_and_pk(): void
    {
        $o1 = $this->insert('CrmOwnershipTypes', 'OwnershipTypeID', ['Code' => '900502', 'DisplayName' => $this->uniq('مالکیت'), 'SortOrder' => 0]);
        $o2 = $this->insert('CrmOwnershipTypes', 'OwnershipTypeID', ['Code' => '900599', 'DisplayName' => $this->uniq('مالکیت'), 'SortOrder' => 900501]);
        $this->assertSame([$o2, $o1], $this->relativeOrder(DB::select('EXEC dbo.sp_Crm_GetOwnershipTypes'), 'OwnershipTypeID', [$o1, $o2]));

        $t1 = $this->insert('CrmTitles', 'TitleID', ['Code' => '900612', 'DisplayName' => $this->uniq('عنوان'), 'SortOrder' => 0]);
        $t2 = $this->insert('CrmTitles', 'TitleID', ['Code' => '900611', 'DisplayName' => $this->uniq('عنوان'), 'SortOrder' => 0]);
        $t3 = $this->insert('CrmTitles', 'TitleID', ['Code' => '900699', 'DisplayName' => $this->uniq('عنوان'), 'SortOrder' => 900610]);
        $this->assertSame([$t3, $t2, $t1], $this->relativeOrder(DB::select('EXEC dbo.sp_Crm_GetTitles'), 'TitleID', [$t1, $t2, $t3]));
    }

    /* ---------------- جدول‌هایی با Code از نوعِ INT (نامِ فیلد/الیاسِ متفاوت) ---------------- */

    public function test_message_priorities_with_int_code(): void
    {
        $m1 = $this->insert('msgPriorities', 'msgPriorityID', ['Code' => 900702, 'Name' => $this->uniq('اولویت'), 'SortOrder' => 0, 'IsActive' => 1]);
        $m2 = $this->insert('msgPriorities', 'msgPriorityID', ['Code' => 900799, 'Name' => $this->uniq('اولویت'), 'SortOrder' => 900701, 'IsActive' => 1]);
        $m3 = $this->insert('msgPriorities', 'msgPriorityID', ['Code' => 900703, 'Name' => $this->uniq('اولویت'), 'SortOrder' => 0, 'IsActive' => 1]);

        $rows = DB::select('EXEC dbo.sp_GetMsgPriorities');
        $this->assertSame([$m2, $m1, $m3], $this->relativeOrder($rows, 'msgPriorityID', [$m1, $m2, $m3]));
    }

    public function test_project_priorities_with_int_code_and_no_alias(): void
    {
        $p1 = $this->insert('ProjectPriorities', 'ProjectPriorityID', ['Code' => 900803, 'Name' => $this->uniq('اولویت پروژه'), 'SortOrder' => 0, 'IsActive' => 1]);
        $p2 = $this->insert('ProjectPriorities', 'ProjectPriorityID', ['Code' => 900899, 'Name' => $this->uniq('اولویت پروژه'), 'SortOrder' => 900802, 'IsActive' => 1]);
        $p3 = $this->insert('ProjectPriorities', 'ProjectPriorityID', ['Code' => 900801, 'Name' => $this->uniq('اولویت پروژه'), 'SortOrder' => 0, 'IsActive' => 1]);

        $rows = DB::select('EXEC dbo.sp_GetProjectPriorities @IsActive = 1');
        $this->assertSame([$p3, $p2, $p1], $this->relativeOrder($rows, 'ProjectPriorityID', [$p1, $p2, $p3]));
    }

    /* ---------------- چندسطحی: درختِ دسته‌بندیِ محصول ---------------- */

    public function test_product_category_tree_orders_each_level_by_rule_and_keeps_parent_before_children(): void
    {
        $A = $this->insert('CrmProductCategories', 'ProductCategoryID', ['Code' => '900901', 'DisplayName' => $this->uniq('ریشه الف'), 'SortOrder' => 0]);
        $B = $this->insert('CrmProductCategories', 'ProductCategoryID', ['Code' => '900999', 'DisplayName' => $this->uniq('ریشه ب'), 'SortOrder' => 900900]);
        $a1 = $this->insert('CrmProductCategories', 'ProductCategoryID', ['Code' => '900904', 'ParentCategoryID' => $A, 'DisplayName' => $this->uniq('فرزند ۱'), 'SortOrder' => 0]);
        $a2 = $this->insert('CrmProductCategories', 'ProductCategoryID', ['Code' => '900908', 'ParentCategoryID' => $A, 'DisplayName' => $this->uniq('فرزند ۲'), 'SortOrder' => 2]);
        $b1 = $this->insert('CrmProductCategories', 'ProductCategoryID', ['Code' => '900905', 'ParentCategoryID' => $B, 'DisplayName' => $this->uniq('فرزندِ ب'), 'SortOrder' => 0]);

        $rows = DB::select('EXEC dbo.sp_Crm_GetProductCategories');
        // B (کلید 900900) قبل از A (کلید 900901)؛ هر پدر قبل از فرزندانش؛ a2 (ترتیب 2) قبل از a1 (کد 900904)
        $this->assertSame([$B, $b1, $A, $a2, $a1], $this->relativeOrder($rows, 'ProductCategoryID', [$A, $B, $a1, $a2, $b1]));

        // Pathِ نمایشی تغییر نکرده است (همان «پدر / فرزند»)
        $a2Row = collect($rows)->firstWhere('ProductCategoryID', $a2);
        $this->assertStringContainsString(' / ', $a2Row->Path);
    }

    /* ---------------- منطقِ خاص: نامِ نقش‌ها در STRING_AGG + SPهایِ جدیدِ نقش/سمتِ طرف‌حساب ---------------- */

    public function test_aggregated_relation_role_names_and_party_roles_follow_the_rule(): void
    {
        $this->actingAs(User::find(2));
        $r1 = $this->insert('CrmContactRoles', 'ContactRoleID', ['Code' => '901003', 'DisplayName' => 'نقش‌ج' . (++$this->seq), 'SortOrder' => 0]);
        $r2 = $this->insert('CrmContactRoles', 'ContactRoleID', ['Code' => '901099', 'DisplayName' => 'نقش‌الف' . (++$this->seq), 'SortOrder' => 901001]);
        $r3 = $this->insert('CrmContactRoles', 'ContactRoleID', ['Code' => '901002', 'DisplayName' => 'نقش‌ب' . (++$this->seq), 'SortOrder' => 0]);
        $names = DB::table('CrmContactRoles')->whereIn('ContactRoleID', [$r1, $r2, $r3])->pluck('DisplayName', 'ContactRoleID');

        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }
        $partyId = (int) $this->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => $this->uniq('شرکتِ ترتیب'), 'identifierNumber' => $digits,
            'roleIds' => [$r1, $r2, $r3],
        ])->assertOk()->json('partyId');
        $personId = (int) $this->postJson('/crm/persons', ['firstName' => 'ترتیب', 'lastName' => 'تست'])->assertOk()->json('personId');
        $this->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId, 'roleIds' => [$r1, $r2, $r3]])->assertOk();

        // کلیدها: r2=901001، r3=901002، r1=901003
        $expected = implode('، ', [$names[$r2], $names[$r3], $names[$r1]]);
        $this->assertSame($expected, DB::select('EXEC dbo.sp_Crm_GetPartyRelations @PartyID = ?', [$partyId])[0]->RoleNames);
        $this->assertSame($expected, DB::select('EXEC dbo.sp_Crm_GetPersonRelations @PersonID = ?', [$personId])[0]->RoleNames);
        $this->assertSame([$r2, $r3, $r1], array_map(fn ($r) => (int) $r->RoleID, DB::select('EXEC dbo.sp_Crm_GetPartyRoles @PartyID = ?', [$partyId])));
    }
}
