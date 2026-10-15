<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\Providers\ProjectSearchProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GlobalSearch\Fakes\BrokenProjectSearchProvider;
use Tests\TestCase;

/**
 * آزمونِ یکپارچهٔ گروهِ پروژه‌ها رویِ LocalDB مستقل (فقط خواندنی).
 *   PR1 PRJ-1405-9001  سازنده U1، عضوِ فعال U2، عضوِ غیرفعال U6   ← U1, U2   (U6 نه)
 *   PR2 PRJ-1405-9002  سازنده U3، عضو U5                          ← U3, U5
 *   PR3 PRJ-1405-9003  غیرفعال، سازنده U2                         ← U2 (با برچسبِ «غیرفعال»)
 */
class ProjectSearchIntegrationTest extends TestCase
{
    /** @var array<string,User> */
    private array $users = [];

    /** @var array<string,int> */
    private array $pr = [];

    private const EXPECTED = [
        'PR1' => ['U1', 'U2'],
        'PR2' => ['U3', 'U5'],
        'PR3' => ['U2'],
    ];

    private const CODES = ['PR1' => 'PRJ-1405-9001', 'PR2' => 'PRJ-1405-9002', 'PR3' => 'PRJ-1405-9003'];

    protected function setUp(): void
    {
        parent::setUp();

        if (strcasecmp((string) config('database.connections.sqlsrv.host'), '(localdb)\GsTest') !== 0
            || config('database.connections.sqlsrv.database') !== 'Dashboard_GsTest') {
            $this->fail('SAFETY: project integration tests must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }

        config([
            'global_search.enabled' => true,
            'global_search.providers.crm.enabled' => false,
            'global_search.providers.messages.enabled' => false,
            'global_search.providers.processes.enabled' => false,
            'global_search.providers.projects.enabled' => true,
            'global_search.providers.projects.class' => ProjectSearchProvider::class,
            'global_search.rate_limit.store' => 'array',
            'global_search.rate_limit.max_attempts' => 1000,
        ]);

        foreach (['U1' => 'gs_u1_admin', 'U2' => 'gs_u2_crm', 'U3' => 'gs_u3_staff', 'U4' => 'gs_u4_process',
                  'U5' => 'gs_u5_forward', 'U6' => 'gs_u6_member', 'U7' => 'gs_u7_disabled'] as $k => $name) {
            $user = User::where('UserName', $name)->first();
            if (! $user) {
                $this->markTestSkipped("Synthetic user {$name} missing.");
            }
            $this->users[$k] = $user;
        }
        foreach (self::CODES as $k => $code) {
            $this->pr[$k] = (int) DB::table('Projects')->where('ProjectCode', $code)->value('ProjectID');
        }
        if (in_array(0, $this->pr, true)) {
            $this->markTestSkipped('Synthetic projects missing.');
        }
    }

    private function search(string $user, string $q): TestResponse
    {
        return $this->actingAs($this->users[$user])->postJson('/global-search', ['q' => $q]);
    }

    private function group(TestResponse $r, string $key = 'projects'): ?array
    {
        foreach ((array) $r->json('groups') as $g) {
            if (($g['key'] ?? null) === $key) {
                return $g;
            }
        }

        return null;
    }

    /** @return int[] */
    private function ids(TestResponse $r): array
    {
        return array_map(fn ($i) => (int) $i['id'], $this->group($r)['items'] ?? []);
    }

    /** @return int[] شناسه‌هایِ قابلِ مشاهده طبقِ همان رویهٔ صفحهٔ پروژه */
    private function pageVisibleIds(string $user): array
    {
        $rows = DB::select('EXEC sp_GetProjects @SearchText = NULL, @IsActive = NULL, @ProjectStatusID = NULL, @UserID = ?', [$this->users[$user]->UserID]);

        return array_map(fn ($r) => (int) $r->ProjectID, $rows);
    }

    public function test_visibility_matrix_matches_project_page_rule(): void
    {
        foreach (self::CODES as $p => $code) {
            foreach (['U1', 'U2', 'U3', 'U4', 'U5', 'U6'] as $u) {
                $r = $this->search($u, $code)->assertOk();
                $expected = in_array($u, self::EXPECTED[$p], true);

                $this->assertSame($expected, in_array($this->pr[$p], $this->ids($r), true), "{$u} / {$p}");
                $this->assertSame($expected, in_array($this->pr[$p], $this->pageVisibleIds($u), true), "page rule {$u} / {$p}");
                if (! $expected) {
                    $this->assertNull($this->group($r), "{$u} must get no projects group for {$p}");
                    $this->assertStringNotContainsString('/projects/' . $this->pr[$p], (string) $r->getContent());
                }
            }
        }
    }

    public function test_inactive_member_u6_has_no_access(): void
    {
        $this->assertNull($this->group($this->search('U6', 'پرتال مشتریان')->assertOk()));
        $this->assertSame([], DB::select(
            'EXEC dbo.sp_Gs_SearchProjects @UserID = ?, @Query = ?, @QueryLike = ?, @Top = ?',
            [$this->users['U6']->UserID, 'prj-1405-9001', 'prj-1405-9001', 5]
        ));
    }

    public function test_deactivated_user_gets_403_and_procedure_returns_nothing(): void
    {
        $this->search('U7', 'PRJ-1405-9001')->assertStatus(403);
        $this->assertSame([], DB::select(
            'EXEC dbo.sp_Gs_SearchProjects @UserID = ?, @Query = ?, @QueryLike = ?, @Top = ?',
            [$this->users['U7']->UserID, 'prj-1405-9001', 'prj-1405-9001', 5]
        ));
    }

    public function test_creator_member_inactive_project_badge_and_item_shape(): void
    {
        $item = ($this->group($this->search('U2', 'PRJ-1405-9003')->assertOk())['items'] ?? [])[0] ?? null;

        $this->assertNotNull($item);
        $this->assertSame('project', $item['type']);
        $this->assertSame(['غیرفعال'], $item['badges']);
        $this->assertSame('code', $item['match']);
        $this->assertSame('/projects/' . $this->pr['PR3'], $item['url']);
        $this->assertStringContainsString('PRJ-1405-9003', $item['subtitle']);
        $this->assertStringContainsString('متوقف', $item['subtitle']);

        $active = ($this->group($this->search('U2', 'PRJ-1405-9001')->assertOk())['items'] ?? [])[0] ?? null;
        $this->assertSame([], $active['badges'] ?? null);
    }

    public function test_title_search_and_persian_digits(): void
    {
        $this->assertContains($this->pr['PR1'], $this->ids($this->search('U1', 'پرتال مشتریان')->assertOk()));
        $this->assertContains($this->pr['PR2'], $this->ids($this->search('U3', '۹۰۰۲')->assertOk()));
        $this->assertContains($this->pr['PR2'], $this->ids($this->search('U5', 'انبار')->assertOk()));
    }

    public function test_no_crm_owner_interaction_or_member_data_is_exposed(): void
    {
        $r = $this->search('U1', 'پرتال')->assertOk(); // PR1، مالکِ آن طرف‌حسابِ «کیان‌نگار» است
        $body = (string) $r->getContent();
        $this->assertStringNotContainsString('کیان', $body);
        $this->assertStringNotContainsString('/crm/', $body);
        $this->assertStringNotContainsString('gs_u2', $body);

        // مالک یا تعاملِ مرتبط از مسیرِ پروژه قابلِ جست‌وجو نیست
        $this->assertNull($this->group($this->search('U1', 'کیان‌نگار')->assertOk()));
        $this->assertNull($this->group($this->search('U3', 'هماهنگی')->assertOk()));
    }

    public function test_counts_and_has_more_come_only_from_visible_projects(): void
    {
        config(['global_search.limits.per_group' => 1]);

        $g2 = $this->group($this->search('U2', 'آزمایشی')->assertOk()); // PR1 + PR3
        $this->assertCount(1, $g2['items']);
        $this->assertTrue($g2['hasMore']);

        $g5 = $this->group($this->search('U5', 'آزمایشی')->assertOk()); // فقط PR2
        $this->assertSame([$this->pr['PR2']], array_map(fn ($i) => (int) $i['id'], $g5['items']));
        $this->assertFalse($g5['hasMore']);

        $this->assertNull($this->group($this->search('U6', 'آزمایشی')->assertOk()));
        $this->assertNull($this->group($this->search('U4', 'آزمایشی')->assertOk()));
    }

    public function test_invalid_and_wildcard_queries(): void
    {
        foreach (['%%', '__', '[a]%', "%' OR 1=1 --"] as $q) {
            $this->assertNull($this->group($this->search('U1', $q)->assertOk()), "Query {$q} must match nothing.");
        }
        $this->search('U1', 'a')->assertStatus(422);
    }

    public function test_provider_failure_is_isolated_and_hides_sql(): void
    {
        config(['global_search.providers.projects.class' => BrokenProjectSearchProvider::class]);

        $r = $this->search('U1', 'پرتال')->assertOk();
        $r->assertJsonPath('complete', false);
        $this->assertNull($this->group($r));
        $this->assertStringNotContainsString('SQLSTATE', (string) $r->getContent());
        $this->assertStringNotContainsString('sp_Gs', (string) $r->getContent());
    }

    public function test_searches_do_not_write_any_data(): void
    {
        $tables = ['Projects', 'ProjectMembers', 'CrmParties', 'CrmInteractions', 'Users', 'DevChangeLog'];
        $before = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));
        foreach (['PRJ', 'آزمایشی', '%%'] as $q) {
            foreach (['U1', 'U3', 'U6'] as $u) {
                $this->search($u, $q)->assertOk();
            }
        }
        $after = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));
        $this->assertSame($before, $after);
    }
}
