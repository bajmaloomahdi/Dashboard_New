<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\Providers\CrmSearchProvider;
use App\Services\GlobalSearch\Providers\MessageSearchProvider;
use App\Services\GlobalSearch\Providers\ProcessSearchProvider;
use App\Services\GlobalSearch\Providers\ProjectSearchProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GlobalSearch\Fakes\BrokenProcessSearchProvider;
use Tests\TestCase;

/**
 * آزمونِ یکپارچهٔ گروهِ فرایندها رویِ LocalDB مستقل (فقط خواندنی).
 * WORKFLOW_VIEW: U1، U4. بدونِ آن: U2، U3، U5، U6 (حتی U3/U5 که نمونه را شروع کرده‌اند). U7 غیرفعال.
 * تعریف: GS_TEST_LEAVE «فرایند مرخصی آزمایشی»؛ نمونه‌ها: WFI-900001 RUNNING، 900002 COMPLETED، 900003 CANCELLED.
 */
class ProcessSearchIntegrationTest extends TestCase
{
    /** @var array<string,User> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (strcasecmp((string) config('database.connections.sqlsrv.host'), '(localdb)\GsTest') !== 0
            || config('database.connections.sqlsrv.database') !== 'Dashboard_GsTest') {
            $this->fail('SAFETY: process integration tests must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }

        config([
            'global_search.enabled' => true,
            'global_search.providers.crm.enabled' => false,
            'global_search.providers.messages.enabled' => false,
            'global_search.providers.projects.enabled' => false,
            'global_search.providers.processes.enabled' => true,
            'global_search.providers.processes.class' => ProcessSearchProvider::class,
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
        if (! DB::table('WorkflowInstances')->where('InstanceNumber', 'WFI-900001')->exists()) {
            $this->markTestSkipped('Synthetic process data missing.');
        }
    }

    private function search(string $user, string $q): TestResponse
    {
        return $this->actingAs($this->users[$user])->postJson('/global-search', ['q' => $q]);
    }

    private function group(TestResponse $r, string $key = 'processes'): ?array
    {
        foreach ((array) $r->json('groups') as $g) {
            if (($g['key'] ?? null) === $key) {
                return $g;
            }
        }

        return null;
    }

    public function test_users_with_workflow_view_see_definitions_and_all_instances(): void
    {
        foreach (['U1', 'U4'] as $u) {
            $items = $this->group($this->search($u, 'مرخصی')->assertOk())['items'] ?? [];
            $types = array_count_values(array_column($items, 'type'));

            $this->assertSame(1, $types['process_definition'] ?? 0, "{$u} definition");
            $this->assertSame(3, $types['process_instance'] ?? 0, "{$u} instances of every status");
        }
    }

    public function test_users_without_workflow_view_get_no_process_group(): void
    {
        foreach (['U2', 'U3', 'U5', 'U6'] as $u) {
            $r = $this->search($u, 'مرخصی')->assertOk();
            $this->assertNull($this->group($r), "{$u} must not see processes");
            $this->assertStringNotContainsString('/process/', (string) $r->getContent());
        }
        // U3 و U5 نمونه‌ای را شروع کرده‌اند ولی بدونِ WORKFLOW_VIEW صفحهٔ نمونه هم برایشان 403 است
        $this->assertNull($this->group($this->search('U3', 'WFI-900002')->assertOk()));
    }

    public function test_deactivated_user_gets_403(): void
    {
        $this->search('U7', 'مرخصی')->assertStatus(403);
    }

    public function test_stored_procedure_enforces_permission_independently_of_php(): void
    {
        $exec = fn (string $u) => DB::select(
            'EXEC dbo.sp_Gs_SearchProcesses @UserID = ?, @Query = ?, @QueryLike = ?, @Top = ?',
            [$this->users[$u]->UserID, 'مرخصی', 'مرخصی', 10]
        );

        $this->assertNotEmpty($exec('U4'));
        $this->assertSame([], $exec('U2'), 'no WORKFLOW_VIEW');
        $this->assertSame([], $exec('U3'), 'starter without WORKFLOW_VIEW');
        $this->assertSame([], $exec('U7'), 'deactivated');
    }

    public function test_definition_and_instances_are_separated_with_status_and_urls(): void
    {
        $byNumber = [];
        foreach ($this->group($this->search('U4', 'مرخصی')->assertOk())['items'] as $item) {
            if ($item['type'] === 'process_definition') {
                $this->assertMatchesRegularExpression('#^/process/definitions/\d+$#', $item['url']);
                $this->assertStringContainsString('تعریف فرایند', $item['subtitle']);
                $this->assertStringContainsString('GS_TEST_LEAVE', $item['subtitle']);
            } else {
                $this->assertSame('process_instance', $item['type']);
                $this->assertMatchesRegularExpression('#^/process/instances/\d+$#', $item['url']);
                preg_match('/WFI-\d+/', $item['subtitle'], $m);
                $byNumber[$m[0] ?? '?'] = $item['subtitle'];
            }
        }

        $this->assertStringContainsString('در جریان', $byNumber['WFI-900001'] ?? '');
        $this->assertStringContainsString('تکمیل‌شده', $byNumber['WFI-900002'] ?? '');
        $this->assertStringContainsString('لغوشده', $byNumber['WFI-900003'] ?? '');
    }

    public function test_exact_instance_number_ranks_first(): void
    {
        $items = $this->group($this->search('U1', 'WFI-900002')->assertOk())['items'] ?? [];

        $this->assertNotEmpty($items);
        $this->assertSame('process_instance', $items[0]['type']);
        $this->assertSame('code', $items[0]['match']);
        $this->assertSame((int) DB::table('WorkflowInstances')->where('InstanceNumber', 'WFI-900002')->value('InstanceID'), (int) $items[0]['id']);
    }

    public function test_instance_entity_data_is_never_returned(): void
    {
        $r = $this->search('U4', 'مرخصی')->assertOk();
        foreach ($this->group($r)['items'] as $item) {
            $this->assertSame(['type', 'id', 'title', 'subtitle', 'url', 'badges', 'match'], array_keys($item));
        }
        $this->assertStringNotContainsString('EntityType', (string) $r->getContent());
        $this->assertStringNotContainsString('EntityID', (string) $r->getContent());
    }

    public function test_workflow_view_does_not_grant_messages_or_crm(): void
    {
        config([
            'global_search.providers.crm.enabled' => true, 'global_search.providers.crm.class' => CrmSearchProvider::class,
            'global_search.providers.messages.enabled' => true, 'global_search.providers.messages.class' => MessageSearchProvider::class,
            'global_search.providers.projects.enabled' => true, 'global_search.providers.projects.class' => ProjectSearchProvider::class,
        ]);

        // U4: WORKFLOW_VIEW ولی بدونِ CRM_VIEW و نه شرکت‌کنندهٔ M1/M2
        $this->assertSame([], $this->search('U4', 'کیان‌نگار')->assertOk()->json('groups'));
        $this->assertSame([], $this->search('U4', 'بودجه')->assertOk()->json('groups'));
        $this->assertSame(['processes'], array_column($this->search('U4', 'مرخصی')->assertOk()->json('groups'), 'key'));

        // U1 (همه‌چیز را از راهِ قواعدِ خودش می‌بیند) ولی نامهٔ خصوصیِ M3 همچنان نه
        $this->assertNull($this->group($this->search('U1', 'پیام خصوصی')->assertOk(), 'messages'));
    }

    public function test_invalid_and_wildcard_queries(): void
    {
        foreach (['%%', '__', '[a]%', "%' OR 1=1 --"] as $q) {
            $this->assertNull($this->group($this->search('U4', $q)->assertOk()), "Query {$q} must match nothing.");
        }
        $this->search('U4', "abc\u{202E}def")->assertStatus(422);
    }

    public function test_has_more_and_limit(): void
    {
        config(['global_search.limits.per_group' => 2]);
        $g = $this->group($this->search('U4', 'مرخصی')->assertOk());

        $this->assertCount(2, $g['items']);
        $this->assertTrue($g['hasMore']);
    }

    public function test_provider_failure_is_isolated_and_hides_sql(): void
    {
        config(['global_search.providers.processes.class' => BrokenProcessSearchProvider::class]);

        $r = $this->search('U4', 'مرخصی')->assertOk();
        $r->assertJsonPath('complete', false);
        $this->assertNull($this->group($r));
        $this->assertStringNotContainsString('SQLSTATE', (string) $r->getContent());
        $this->assertStringNotContainsString('sp_Gs', (string) $r->getContent());
    }

    public function test_searches_do_not_write_any_data(): void
    {
        $tables = ['WorkflowDefinitions', 'WorkflowVersions', 'WorkflowInstances', 'Messages', 'Users', 'DevChangeLog'];
        $before = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));
        foreach (['مرخصی', 'WFI-900001', '%%'] as $q) {
            foreach (['U1', 'U3', 'U4'] as $u) {
                $this->search($u, $q)->assertOk();
            }
        }
        $after = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));
        $this->assertSame($before, $after);
    }
}
