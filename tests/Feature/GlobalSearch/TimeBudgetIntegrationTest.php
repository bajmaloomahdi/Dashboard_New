<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\GlobalSearchService;
use App\Services\GlobalSearch\Providers\MessageSearchProvider;
use App\Services\GlobalSearch\Support\SqlAccessContextResolver;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GlobalSearch\Fakes\SlowSqlSearchProvider;
use Tests\TestCase;

/**
 * یافتهٔ ۴ ممیزی — بودجهٔ زمانی برایِ کلِ درخواست مشترک است و Query Timeout واقعاً کوئری را لغو می‌کند.
 * رویِ LocalDB؛ ارائه‌دهندهٔ کُند فقط WAITFOR اجرا می‌کند (بدونِ داده).
 *
 * محدودیتِ درایور (اندازه‌گیری‌شده): با Connection Poolingِ ODBC در ویندوز (پیش‌فرضِ pdo_sqlsrv)،
 * Query Timeout رویِ «اولین» دستورِ یک اتصالِ برداشته‌شده از Pool اعمال نمی‌شود؛ از دستورِ دوم به بعد درست است.
 * در درخواستِ واقعی ارائه‌دهنده هرگز اولین دستور نیست (بازیابیِ کاربر و sp_GetUserPermissions پیش از آن اجرا
 * می‌شوند)، پس آزمون از مسیرِ واقعیِ HTTP با کاربرِ مصنوعی عبور می‌کند.
 */
class TimeBudgetIntegrationTest extends TestCase
{
    private function syntheticUser(): User
    {
        $user = User::where('UserName', 'gs_u1_admin')->first();
        if (! $user) {
            $this->markTestSkipped('Synthetic user gs_u1_admin missing.');
        }

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (strcasecmp((string) config('database.connections.sqlsrv.host'), '(localdb)\GsTest') !== 0
            || config('database.connections.sqlsrv.database') !== 'Dashboard_GsTest') {
            $this->fail('SAFETY: time-budget integration tests must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }
        SlowSqlSearchProvider::reset();
    }

    private function service(): GlobalSearchService
    {
        return $this->app->make(GlobalSearchService::class);
    }

    public function test_slow_providers_cannot_each_take_a_full_budget(): void
    {
        // پیش از اصلاح: هر گروه ۲ ثانیهٔ کامل ← دو گروهِ کُند ≈ ۴ ثانیه
        config([
            'global_search.limits.time_budget_ms' => 1500,
            'global_search.providers' => [
                'crm' => ['enabled' => true, 'class' => SlowSqlSearchProvider::class],
                'messages' => ['enabled' => false, 'class' => null],
                'projects' => ['enabled' => true, 'class' => SlowSqlSearchProvider::class],
                'processes' => ['enabled' => true, 'class' => SlowSqlSearchProvider::class],
            ],
        ]);
        $user = $this->syntheticUser();
        config(['global_search.enabled' => true, 'global_search.rate_limit.store' => 'array']);

        $started = hrtime(true);
        $response = $this->actingAs($user)->postJson('/global-search', ['q' => 'بودجه'])->assertOk();
        $elapsed = (hrtime(true) - $started) / 1e9;
        $outcome = 'Outcome: ' . implode('; ', SlowSqlSearchProvider::$outcomes);

        $this->assertSame(1, SlowSqlSearchProvider::$calls, 'Only the first slow provider may run; the budget is then spent.');
        $this->assertSame([2], SlowSqlSearchProvider::$timeouts);
        $this->assertStringStartsWith('PDOException(HYT00)', SlowSqlSearchProvider::$outcomes[0] ?? '', $outcome);
        $response->assertJsonPath('complete', false)->assertJsonPath('groups', []);
        $this->assertLessThan(2.8, $elapsed, 'Whole request must stay under budget + 1s (driver granularity) + overhead. ' . $outcome);
        $this->assertGreaterThan(1.9, $elapsed, 'The WAITFOR must actually have been cancelled by the query timeout, not skipped.');
    }

    public function test_connection_is_usable_after_a_cancelled_query_and_real_provider_still_works(): void
    {
        $user = $this->syntheticUser();

        config([
            'global_search.limits.time_budget_ms' => 1000,
            'global_search.providers' => [
                'crm' => ['enabled' => true, 'class' => SlowSqlSearchProvider::class],
                'messages' => ['enabled' => false, 'class' => null],
                'projects' => ['enabled' => false, 'class' => null],
                'processes' => ['enabled' => false, 'class' => null],
            ],
        ]);
        $svc = $this->service();
        $access = (new SqlAccessContextResolver())->resolve($user); // دستورِ اولِ اتصال، مثلِ درخواستِ واقعی
        $this->assertFalse($svc->search($access, $svc->buildQuery('بودجه'))['complete']);
        $this->assertStringStartsWith('PDOException(HYT00)', SlowSqlSearchProvider::$outcomes[0] ?? '');

        // همان اتصال: بدونِ تراکنشِ باز و قابلِ استفاده
        $this->assertSame(0, (int) DB::selectOne('SELECT @@TRANCOUNT AS t')->t);

        config([
            'global_search.limits.time_budget_ms' => 1500,
            'global_search.providers.crm' => ['enabled' => false, 'class' => null],
            'global_search.providers.messages' => ['enabled' => true, 'class' => MessageSearchProvider::class],
        ]);
        $result = $svc->search($access, $svc->buildQuery('بودجه')); // M1 «نامه محرمانه بودجه آزمایشی» — U1 فرستنده است

        $this->assertTrue($result['complete']);
        $this->assertSame(['messages'], array_column($result['groups'], 'key'));
    }
}
