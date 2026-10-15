<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\PersianNormalizer;
use App\Services\GlobalSearch\Providers\CrmSearchProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GlobalSearch\Fakes\BrokenCrmSearchProvider;
use Tests\TestCase;

/**
 * آزمونِ یکپارچهٔ گروهِ CRM رویِ LocalDB مستقل با دادهٔ مصنوعیِ seederها (فقط خواندنی).
 * از resolverِ واقعی (sp_GetUserPermissions) و رویهٔ واقعیِ dbo.sp_Gs_Search استفاده می‌کند.
 */
class CrmSearchIntegrationTest extends TestCase
{
    /** @var array<string,User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (strcasecmp((string) config('database.connections.sqlsrv.host'), '(localdb)\GsTest') !== 0
            || config('database.connections.sqlsrv.database') !== 'Dashboard_GsTest') {
            $this->fail('SAFETY: CRM integration tests must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }

        config([
            'global_search.enabled' => true,
            'global_search.providers.crm.enabled' => true,
            'global_search.providers.crm.class' => CrmSearchProvider::class,
            'global_search.rate_limit.store' => 'array',
            'global_search.rate_limit.max_attempts' => 1000,
        ]);

        foreach (['U1' => 'gs_u1_admin', 'U2' => 'gs_u2_crm', 'U3' => 'gs_u3_staff', 'U4' => 'gs_u4_process',
                  'U5' => 'gs_u5_forward', 'U6' => 'gs_u6_member', 'U7' => 'gs_u7_disabled'] as $k => $name) {
            $user = User::where('UserName', $name)->first();
            if (! $user) {
                $this->markTestSkipped("Synthetic user {$name} missing - run GlobalSearchTestSeeder first.");
            }
            $this->users[$k] = $user;
        }
        if (! DB::table('CrmParties')->where('Description', 'GS test data (synthetic)')->exists()) {
            $this->markTestSkipped('Synthetic CRM data missing - run GlobalSearchBusinessTestSeeder first.');
        }
    }

    private function search(string $user, string $q): TestResponse
    {
        return $this->actingAs($this->users[$user])->postJson('/global-search', ['q' => $q]);
    }

    /** @return array<int,array<string,mixed>> */
    private function crmItems(TestResponse $r): array
    {
        foreach ((array) $r->json('groups') as $g) {
            if (($g['key'] ?? null) === 'crm') {
                return $g['items'];
            }
        }

        return [];
    }

    private function findItem(TestResponse $r, string $type, string $titleContains): ?array
    {
        foreach ($this->crmItems($r) as $item) {
            if ($item['type'] === $type && str_contains($item['title'], $titleContains)) {
                return $item;
            }
        }

        return null;
    }

    private function partyId(string $officialName): int
    {
        return (int) DB::table('CrmParties')->where('OfficialName', $officialName)->value('PartyID');
    }

    // ================= نرمال‌سازیِ SQL = PHP =================

    public function test_sql_normalizer_matches_php_and_shared_vectors(): void
    {
        $data = json_decode(file_get_contents(base_path('tests/Fixtures/global_search_normalization_vectors.json')), true);
        $php = new PersianNormalizer();

        foreach ($data['vectors'] as $v) {
            $sql = DB::selectOne('SELECT dbo.fn_Gs_Normalize(?) AS N', [$v['input']])->N;
            $this->assertSame($v['expected'], (string) $sql, 'SQL: ' . $v['name']);
            $this->assertSame($php->normalize($v['input']), (string) $sql, 'PHP vs SQL: ' . $v['name']);
        }
    }

    // ================= ماتریسِ دسترسیِ U1..U7 =================

    public function test_users_with_crm_view_get_results(): void
    {
        foreach (['U1', 'U2'] as $u) {
            $r = $this->search($u, 'کیان‌نگار')->assertOk();
            $this->assertNotNull($this->findItem($r, 'party', 'کیان‌نگار'), "{$u} must find the party.");
        }
    }

    public function test_users_without_crm_view_get_no_crm_group(): void
    {
        foreach (['U3', 'U4', 'U5', 'U6'] as $u) {
            $r = $this->search($u, 'کیان‌نگار')->assertOk();
            $this->assertSame([], $this->crmItems($r), "{$u} must not see CRM.");
            $this->assertSame([], $r->json('groups'), "{$u}: CRM group must be absent, not empty.");
            $this->assertStringNotContainsString('/crm/', (string) $r->getContent());
        }
    }

    public function test_deactivated_user_gets_403(): void
    {
        $this->search('U7', 'کیان‌نگار')->assertStatus(403)->assertJsonMissingPath('groups');
    }

    public function test_stored_procedure_enforces_access_independently_of_php(): void
    {
        $exec = fn (string $u) => DB::select(
            'EXEC dbo.sp_Gs_Search @UserID = ?, @Group = ?, @Query = ?, @QueryLike = ?, @Top = ?',
            [$this->users[$u]->UserID, 'crm', 'کیان نگار', 'کیان نگار', 5]
        );

        $this->assertNotEmpty($exec('U2'));
        $this->assertSame([], $exec('U3'), 'No CRM_VIEW: procedure must return nothing.');
        $this->assertSame([], $exec('U7'), 'Deactivated user: procedure must return nothing even with CRM_VIEW.');
        $this->assertSame([], DB::select(
            'EXEC dbo.sp_Gs_Search @UserID = ?, @Group = ?, @Query = ?, @QueryLike = ?, @Top = ?',
            [$this->users['U2']->UserID, 'unknown', 'کیان نگار', 'کیان نگار', 5]
        ), 'Unknown group must return nothing.');
    }

    // ================= نرمال‌سازیِ فارسی/عربی =================

    public function test_arabic_letters_and_space_instead_of_zwnj(): void
    {
        $r = $this->search('U2', "\u{0643}\u{064A}\u{0627}\u{0646} \u{0646}\u{06AF}\u{0627}\u{0631}")->assertOk(); // «كيان نگار»
        $this->assertNotNull($this->findItem($r, 'party', 'کیان‌نگار'));
    }

    public function test_persian_query_finds_arabic_stored_text(): void
    {
        $this->assertNotNull($this->findItem($this->search('U2', "زنجیره\u{200C}ای")->assertOk(), 'party', 'آلفا'));
        $this->assertNotNull($this->findItem($this->search('U2', 'علی تستی')->assertOk(), 'person', 'تست'));
        $this->assertNotNull($this->findItem($this->search('U2', 'کاوه')->assertOk(), 'brand', 'اوه'));
    }

    public function test_harakat_and_persian_digits(): void
    {
        $this->assertNotNull($this->findItem($this->search('U2', 'محمد')->assertOk(), 'party', 'نمونه'));
        $this->assertNotNull($this->findItem($this->search('U2', '۱۴۰۵')->assertOk(), 'party', 'کارخانه'));
        $this->assertNotNull($this->findItem($this->search('U2', '1405')->assertOk(), 'party', 'کارخانه'));
    }

    // ================= انواعِ دادهٔ CRM و مقصد =================

    public function test_person_brand_and_party_urls(): void
    {
        $person = $this->findItem($this->search('U2', 'سارا آزمونی')->assertOk(), 'person', 'سارا');
        $this->assertNotNull($person);
        $this->assertMatchesRegularExpression('#^/crm/persons/\d+$#', $person['url']);

        $brand = $this->findItem($this->search('U2', 'Sigma')->assertOk(), 'brand', 'Sigma');
        $this->assertNotNull($brand);
        $this->assertMatchesRegularExpression('#^/crm/brands/\d+$#', $brand['url']);
    }

    public function test_address_points_to_owner_party(): void
    {
        $item = $this->findItem($this->search('U2', 'خیابان نمونه')->assertOk(), 'address', 'کیان‌نگار');

        $this->assertNotNull($item);
        $this->assertSame('/crm/parties/' . $this->partyId('شرکت آزمایشی کیان‌نگار'), $item['url']);
        $this->assertStringContainsString('خیابان نمونه', $item['subtitle']);
    }

    public function test_address_location_names_province_county_city_are_searchable(): void
    {
        // «استان/شهرستان آزمایشی» فقط در جدول‌هایِ جغرافیا هستند، نه در متنِ آدرس (v1.2 رویه)
        foreach (['استان آزمایشی', 'شهرستان آزمایشی', 'استان   آزمايشي', "استان\u{200C}آزمایشی"] as $q) {
            $addresses = array_values(array_filter($this->crmItems($this->search('U2', $q)->assertOk()), fn ($i) => $i['type'] === 'address'));
            $this->assertCount(4, $addresses, "query: {$q}");
        }

        $item = $this->findItem($this->search('U2', 'استان آزمایشی')->assertOk(), 'address', 'کیان‌نگار');
        $this->assertNotNull($item);
        $this->assertSame('/crm/parties/' . $this->partyId('شرکت آزمایشی کیان‌نگار'), $item['url']);
        $this->assertStringContainsString('استان آزمایشی', $item['subtitle']);
        $this->assertStringContainsString('شهر آزمایشی', $item['subtitle']);
    }

    public function test_address_location_search_keeps_the_crm_permission_gate(): void
    {
        foreach (['U3', 'U4', 'U5', 'U6'] as $u) { // بدونِ CRM_VIEW
            $r = $this->search($u, 'استان آزمایشی')->assertOk();
            $this->assertSame([], $this->crmItems($r), "{$u} must not see CRM addresses");
            $this->assertStringNotContainsString('/crm/', (string) $r->getContent());
        }
        $this->search('U7', 'استان آزمایشی')->assertStatus(403);
        $this->assertSame([], DB::select(
            'EXEC dbo.sp_Gs_Search @UserID = ?, @Group = ?, @Query = ?, @QueryLike = ?, @T1 = ?, @T2 = ?, @Top = ?',
            [$this->users['U3']->UserID, 'crm', 'استان ازمایشی', 'استان ازمایشی', 'استان', 'ازمایشی', 5]
        ), 'Direct procedure call without CRM_VIEW must return nothing.');
    }

    public function test_phone_digits_only_matching_and_email(): void
    {
        $r = $this->search('U2', '09000000001')->assertOk();         // ذخیره‌شده: «0900 000 0001»
        $contact = $this->findItem($r, 'contact', 'سارا');
        $this->assertNotNull($contact);
        $this->assertSame('code', $contact['match']);
        $this->assertMatchesRegularExpression('#^/crm/persons/\d+$#', $contact['url']);

        $this->assertNotNull($this->findItem($this->search('U2', '۰۹۰۰۰۰۰۰۰۰۱')->assertOk(), 'contact', 'سارا')); // ارقامِ فارسی
        $this->assertNotNull($this->findItem($this->search('U2', 'alfa.example')->assertOk(), 'contact', 'آلفا'));
    }

    public function test_interaction_points_to_party(): void
    {
        $item = $this->findItem($this->search('U2', 'قرارداد نمونه')->assertOk(), 'interaction', 'قرارداد');

        $this->assertNotNull($item);
        $this->assertSame('/crm/parties/' . $this->partyId('شرکت آزمایشی کیان‌نگار'), $item['url']);
    }

    public function test_exact_identifier_ranks_first_as_code(): void
    {
        $items = $this->crmItems($this->search('U2', '99900000001')->assertOk());

        $this->assertNotEmpty($items);
        $this->assertSame('party', $items[0]['type']);
        $this->assertSame('code', $items[0]['match']);
    }

    // ================= رکوردهایِ غیرفعال =================

    public function test_inactive_records_are_shown_with_badge_to_crm_users(): void
    {
        $r = $this->search('U2', 'گاما')->assertOk();
        $this->assertSame(['غیرفعال'], $this->findItem($r, 'party', 'گاما')['badges'] ?? null);

        $this->assertSame(['غیرفعال'], $this->findItem($this->search('U2', 'امگا')->assertOk(), 'brand', 'امگا')['badges'] ?? null);
        $this->assertSame(['غیرفعال'], $this->findItem($this->search('U2', 'رضا ساختگی')->assertOk(), 'person', 'رضا')['badges'] ?? null);
        $this->assertSame(['غیرفعال'], $this->findItem($this->search('U2', 'شهرک غیرفعال')->assertOk(), 'address', 'گاما')['badges'] ?? null);
        $this->assertSame(['غیرفعال'], $this->findItem($this->search('U2', 'مشتری غیرفعال')->assertOk(), 'interaction', 'مشتری')['badges'] ?? null);

        // رکوردِ فعال برچسب ندارد
        $this->assertSame([], $this->findItem($this->search('U2', 'کیان‌نگار')->assertOk(), 'party', 'کیان‌نگار')['badges'] ?? null);
    }

    // ================= عدمِ افشایِ دادهٔ پروژه =================

    public function test_interaction_never_exposes_project_title_or_code(): void
    {
        $r = $this->search('U2', 'هماهنگی')->assertOk();

        $this->assertNotNull($this->findItem($r, 'interaction', 'هماهنگی'));
        $body = (string) $r->getContent();
        $this->assertStringNotContainsString('پروژه داخلی انبار آزمایشی', $body);
        $this->assertStringNotContainsString('PRJ-1405-9002', $body);
        $this->assertStringNotContainsString('ProjectID', $body);
    }

    // ================= ورودی‌هایِ ناامن، سقف، خطا =================

    public function test_like_wildcards_are_literal_not_match_all(): void
    {
        foreach (['%%', '__', '[a]%', "%' OR 1=1 --"] as $q) {
            $r = $this->search('U2', $q)->assertOk();
            $this->assertSame([], $this->crmItems($r), "Query {$q} must not match everything.");
        }
    }

    public function test_per_group_limit_and_has_more(): void
    {
        config(['global_search.limits.per_group' => 2]);

        $r = $this->search('U2', 'آزمایشی')->assertOk();

        $this->assertCount(2, $this->crmItems($r));
        $this->assertTrue($r->json('groups.0.hasMore'));
        $this->assertSame('/crm/parties', $r->json('groups.0.moreUrl')); // بدونِ متنِ جست‌وجو در URL
    }

    public function test_provider_failure_is_isolated_and_hides_sql(): void
    {
        config(['global_search.providers.crm.class' => BrokenCrmSearchProvider::class]);

        $r = $this->search('U2', 'کیان‌نگار')->assertOk();

        $r->assertJsonPath('complete', false);
        $this->assertSame([], $this->crmItems($r));
        $this->assertStringNotContainsString('SQLSTATE', (string) $r->getContent());
        $this->assertStringNotContainsString('sp_Gs', (string) $r->getContent());
    }

    public function test_searches_do_not_write_any_data(): void
    {
        $tables = ['CrmParties', 'CrmPersons', 'CrmBrands', 'CrmAddresses', 'CrmContacts', 'CrmInteractions', 'Users', 'Messages', 'Projects', 'DevChangeLog'];
        $before = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));

        foreach (['کیان‌نگار', 'آزمایشی', '09000000001', 'گاما', '%%'] as $q) {
            $this->search('U1', $q)->assertOk();
            $this->search('U3', $q)->assertOk();
        }

        $after = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));
        $this->assertSame($before, $after);
    }
}
