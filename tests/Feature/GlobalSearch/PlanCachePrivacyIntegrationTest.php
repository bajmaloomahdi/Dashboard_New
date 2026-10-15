<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\Providers\CrmSearchProvider;
use App\Services\GlobalSearch\Providers\MessageSearchProvider;
use App\Services\GlobalSearch\Providers\ProcessSearchProvider;
use App\Services\GlobalSearch\Providers\ProjectSearchProvider;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * یافتهٔ ۲ ممیزی — متنِ جست‌وجو نباید به‌صورتِ ParameterCompiledValue در plan cache یا Query Store بماند.
 * رویِ LocalDB مستقل اجرا می‌شود و فقط DMVها را می‌خواند (Query Store فقط اگر روشن باشد flush و بررسی می‌شود).
 *
 * نشانه‌ها برعکس (REVERSE) به کوئریِ بررسی داده می‌شوند تا مقدارِ کامپایل‌شدهٔ خودِ همین کوئری
 * هرگز با نشانه یکی نشود.
 */
class PlanCachePrivacyIntegrationTest extends TestCase
{
    private const PROCEDURES = ['sp_Gs_Search', 'sp_Gs_SearchMessages', 'sp_Gs_SearchProjects', 'sp_Gs_SearchProcesses'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (strcasecmp((string) config('database.connections.sqlsrv.host'), '(localdb)\GsTest') !== 0
            || config('database.connections.sqlsrv.database') !== 'Dashboard_GsTest'
            || (int) DB::selectOne("SELECT CAST(SERVERPROPERTY('IsLocalDB') AS INT) AS l")->l !== 1) {
            $this->fail('SAFETY: plan-cache privacy tests must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }

        config([
            'global_search.enabled' => true,
            'global_search.providers.crm' => ['enabled' => true, 'class' => CrmSearchProvider::class],
            'global_search.providers.messages' => ['enabled' => true, 'class' => MessageSearchProvider::class],
            'global_search.providers.projects' => ['enabled' => true, 'class' => ProjectSearchProvider::class],
            'global_search.providers.processes' => ['enabled' => true, 'class' => ProcessSearchProvider::class],
            'global_search.rate_limit.store' => 'array',
            'global_search.rate_limit.max_attempts' => 1000,
        ]);

        $user = User::where('UserName', 'gs_u1_admin')->first(); // CRM_VIEW + WORKFLOW_VIEW ← هر چهار رویه اجرا می‌شوند
        if (! $user) {
            $this->markTestSkipped('Synthetic user gs_u1_admin missing.');
        }
        $this->user = $user;
    }

    private function markers(): array
    {
        return ['gsplan' . bin2hex(random_bytes(6)), (string) random_int(100000000, 999999999)];
    }

    private function searchAll(array $markers): void
    {
        foreach ($markers as $m) {
            $this->actingAs($this->user)->postJson('/global-search', ['q' => $m])->assertOk()->assertJsonPath('complete', true);
        }
    }

    public function test_search_text_is_not_in_any_cached_plan_or_batch_text(): void
    {
        $markers = $this->markers();
        $this->searchAll($markers);
        [$r1, $r2] = [strrev($markers[0]), strrev($markers[1])];

        $hits = DB::select("
            SELECT cp.objtype, ISNULL(OBJECT_NAME(st.objectid, st.dbid), N'(batch)') AS obj
            FROM sys.dm_exec_cached_plans cp
            CROSS APPLY sys.dm_exec_sql_text(cp.plan_handle) st
            CROSS APPLY sys.dm_exec_text_query_plan(cp.plan_handle, DEFAULT, DEFAULT) tqp
            WHERE CHARINDEX(REVERSE(?), ISNULL(tqp.query_plan, N'') + ISNULL(st.text, N'')) > 0
               OR CHARINDEX(REVERSE(?), ISNULL(tqp.query_plan, N'') + ISNULL(st.text, N'')) > 0", [$r1, $r2]);

        $this->assertSame([], $hits, 'Search text found in the plan cache.');
    }

    public function test_search_procedures_have_no_sniffed_parameter_values(): void
    {
        // مستقل از نشانه: اگر رویه‌ها به حالتِ قبل برگردند، هر planی مقدارِ کامپایل‌شده خواهد داشت
        $this->searchAll($this->markers());

        $plans = collect(DB::select("
            SELECT OBJECT_NAME(ps.object_id) AS name, tqp.query_plan AS plan_text
            FROM sys.dm_exec_procedure_stats ps
            CROSS APPLY sys.dm_exec_text_query_plan(ps.plan_handle, DEFAULT, DEFAULT) tqp
            WHERE ps.database_id = DB_ID() AND OBJECT_NAME(ps.object_id) LIKE 'sp[_]Gs[_]%'"))->keyBy('name');

        foreach (self::PROCEDURES as $proc) {
            $this->assertTrue($plans->has($proc), "No cached plan for {$proc}; the check would be vacuous.");
            $this->assertNotSame('', (string) $plans[$proc]->plan_text);
            // خودِ صفتِ XML (متنِ توضیحاتِ رویه هم در StatementText می‌آید، پس فقط نامِ آن کافی نیست)
            $this->assertDoesNotMatchRegularExpression('/\bParameterCompiledValue="/', (string) $plans[$proc]->plan_text, $proc);
            $this->assertStringContainsString('OPTIMIZE FOR UNKNOWN', (string) $plans[$proc]->plan_text, "{$proc}: cached plan must include the search statement");
            $this->assertStringContainsString('OPTIMIZE FOR UNKNOWN', (string) DB::selectOne(
                'SELECT OBJECT_DEFINITION(OBJECT_ID(?)) AS d', ['dbo.' . $proc])->d, $proc);
        }
    }

    public function test_search_text_is_not_in_query_store_when_enabled(): void
    {
        $state = (string) DB::selectOne('SELECT actual_state_desc AS s FROM sys.database_query_store_options')->s;
        if ($state === 'OFF') {
            $this->markTestSkipped('Query Store is OFF in this database; plan-cache tests cover the remaining store.');
        }

        $markers = $this->markers();
        $this->searchAll($markers);
        DB::statement('EXEC sys.sp_query_store_flush_db');
        [$r1, $r2] = [strrev($markers[0]), strrev($markers[1])];

        $planHits = DB::selectOne("SELECT COUNT(*) AS c FROM sys.query_store_plan p
            WHERE CHARINDEX(REVERSE(?), CAST(p.query_plan AS NVARCHAR(MAX))) > 0 OR CHARINDEX(REVERSE(?), CAST(p.query_plan AS NVARCHAR(MAX))) > 0", [$r1, $r2])->c;
        $textHits = DB::selectOne("SELECT COUNT(*) AS c FROM sys.query_store_query_text t
            WHERE CHARINDEX(REVERSE(?), t.query_sql_text) > 0 OR CHARINDEX(REVERSE(?), t.query_sql_text) > 0", [$r1, $r2])->c;

        $this->assertSame(0, (int) $planHits, 'Search text found in Query Store plans.');
        $this->assertSame(0, (int) $textHits, 'Search text found in Query Store query text.');
    }
}
