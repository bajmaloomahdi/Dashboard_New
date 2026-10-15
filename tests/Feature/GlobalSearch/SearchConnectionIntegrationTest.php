<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\PersianNormalizer;
use App\Services\GlobalSearch\Providers\MessageSearchProvider;
use App\Services\GlobalSearch\SearchQuery;
use App\Services\GlobalSearch\Support\SearchConnection;
use App\Services\GlobalSearch\Support\SearchConnectionBusy;
use Illuminate\Database\Connectors\SqlServerConnector;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\GlobalSearch\Fakes\SlowSqlSearchProvider;
use Tests\TestCase;

/**
 * اتصالِ اختصاصیِ جست‌وجو (sqlsrv_search، بدونِ Pooling) — رویِ LocalDB.
 * Timeout در اولین دستور، پس از قطع و اتصالِ مجدد؛ وضعیتِ تراکنش؛ دست‌نخورده‌ماندنِ Poolingِ اتصالِ اصلی؛
 * و توقفِ جست‌وجو رویِ اتصالی که تراکنشِ باز دارد (بدونِ افشایِ جزئیات).
 * ارائه‌دهندهٔ کُند فقط WAITFOR اجرا می‌کند؛ BEGIN TRAN/ROLLBACKها بدونِ هیچ نوشتنی هستند.
 */
class SearchConnectionIntegrationTest extends TestCase
{
    private const MAIN = 'sqlsrv';
    private const SEARCH = 'sqlsrv_search';

    /** @var MessageLogged[] */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::MAIN, self::SEARCH] as $c) {
            if (strcasecmp((string) config("database.connections.$c.host"), '(localdb)\GsTest') !== 0
                || config("database.connections.$c.database") !== 'Dashboard_GsTest'
                || (int) DB::connection($c)->selectOne("SELECT CAST(SERVERPROPERTY('IsLocalDB') AS INT) AS l")->l !== 1) {
                $this->fail("SAFETY: connection {$c} must point to (localdb)\\GsTest / Dashboard_GsTest. Aborted.");
            }
        }

        SlowSqlSearchProvider::reset();
        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e;
        });
    }

    protected function tearDown(): void
    {
        // هیچ تراکنشی باز نمی‌ماند (بدونِ نوشتن؛ فقط ROLLBACKِ تراکنش‌هایِ خالیِ همین آزمون)
        foreach ([self::MAIN, self::SEARCH] as $c) {
            $conn = DB::connection($c);
            while ($conn->transactionLevel() > 0) {
                $conn->rollBack();
            }
            if ($conn->getRawPdo() instanceof \PDO && $conn->getRawPdo()->inTransaction()) {
                $conn->getRawPdo()->rollBack();
            }
            $conn->unprepared('IF @@TRANCOUNT > 0 ROLLBACK');
        }
        parent::tearDown();
    }

    private function physicalConnectionId(string $c): string
    {
        return DB::connection($c)->selectOne(
            'SELECT CONVERT(varchar(36), connection_id) AS id FROM sys.dm_exec_connections WHERE session_id = @@SPID AND parent_connection_id IS NULL'
        )->id;
    }

    private function reopen(string $c): void
    {
        DB::purge($c);
        gc_collect_cycles(); // PDO آزاد شود تا (در صورتِ Pooling) به Pool برگردد
    }

    /** یک اجرایِ WAITFORِ ۶ ثانیه‌ای از مسیرِ واقعیِ ارائه‌دهنده؛ نتیجه: «کلاس(کد) after Ns» */
    private function slowSearch(int $timeoutSeconds = 1): string
    {
        try {
            (new SlowSqlSearchProvider(new PersianNormalizer()))
                ->search(new SearchQuery('xx', 'xx', ['xx']), new AccessContext(1, true, []), 5, $timeoutSeconds);
        } catch (\Throwable) {
            // نتیجه در SlowSqlSearchProvider::$outcomes ثبت شده است
        }

        return (string) end(SlowSqlSearchProvider::$outcomes);
    }

    private function assertCancelledWithin(string $outcome, float $maxSeconds): void
    {
        $this->assertStringStartsWith('PDOException(HYT00)', $outcome, 'Query must be cancelled by the timeout.');
        preg_match('/after ([0-9.]+)s/', $outcome, $m);
        $this->assertLessThan($maxSeconds, (float) ($m[1] ?? 99), $outcome);
    }

    private function tranCount(string $c): object
    {
        return DB::connection($c)->selectOne('SELECT @@TRANCOUNT AS t, XACT_STATE() AS x');
    }

    // ---------- پیکربندی و Pooling ----------

    public function test_main_connection_config_is_unchanged_and_only_search_connection_disables_pooling(): void
    {
        $main = config('database.connections.' . self::MAIN);
        $this->assertSame(
            ['driver', 'url', 'host', 'port', 'database', 'username', 'password', 'charset', 'prefix', 'prefix_indexes', 'encrypt', 'trust_server_certificate'],
            array_keys($main),
            'The sqlsrv connection definition must stay exactly as before.'
        );
        $this->assertArrayNotHasKey('pooling', $main);
        $this->assertFalse(config('database.connections.' . self::SEARCH . '.pooling'));
        $this->assertSame(self::SEARCH, config('global_search.connection'));
        foreach (['host', 'port', 'database', 'encrypt', 'trust_server_certificate'] as $k) {
            $this->assertSame($main[$k], config('database.connections.' . self::SEARCH . ".$k"), $k);
        }

        $connector = new class extends SqlServerConnector {
            public function dsn(array $config): string
            {
                return $this->getDsn($config);
            }
        };
        $this->assertStringNotContainsString('ConnectionPooling', $connector->dsn($main));
        $this->assertStringContainsString('ConnectionPooling=0', $connector->dsn(config('database.connections.' . self::SEARCH)));
    }

    /** آیا اتصالِ فیزیکیِ داده‌شده هنوز رویِ SQL Server باز است؟ (از دیدِ یک اتصالِ ناظرِ دیگر) */
    private function isOpenOnServer(string $connectionId, string $observer): bool
    {
        return (int) DB::connection($observer)->selectOne(
            'SELECT COUNT(*) AS c FROM sys.dm_exec_connections WHERE connection_id = CONVERT(uniqueidentifier, ?)',
            [$connectionId]
        )->c > 0;
    }

    /** وضعیتِ سمتِ سرور تا حداکثر $seconds بررسی می‌شود (بستنِ فیزیکیِ اتصال در سرور ممکن است لحظه‌ای طول بکشد) */
    private function waitUntilClosedOnServer(string $connectionId, string $observer, float $seconds = 3.0): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            if (! $this->isOpenOnServer($connectionId, $observer)) {
                return true;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    public function test_main_connection_is_still_pooled_and_search_connection_is_closed_on_the_server(): void
    {
        // معیار: وضعیتِ واقعیِ سمتِ SQL Server پس از بستنِ اتصال در PHP — نه مقایسهٔ شناسه‌ها.
        // Pooling: اتصالِ فیزیکی پس از بستن در Pool می‌ماند و در sys.dm_exec_connections باز است.
        // بدونِ Pooling: اتصالِ فیزیکی واقعاً بسته می‌شود و از sys.dm_exec_connections حذف می‌شود.
        $main = $this->physicalConnectionId(self::MAIN);
        $search = $this->physicalConnectionId(self::SEARCH);
        $this->assertNotSame($main, $search);
        $this->assertSame('RagaGlobalSearch', DB::connection(self::SEARCH)->selectOne(
            'SELECT program_name AS p FROM sys.dm_exec_sessions WHERE session_id = @@SPID')->p);

        $this->reopen(self::MAIN);   // ناظر: اتصالِ جست‌وجو
        usleep(500_000);
        $this->assertTrue($this->isOpenOnServer($main, self::SEARCH),
            'sqlsrv must still be pooled: its physical connection stays open on the server after close.');

        $this->reopen(self::SEARCH); // ناظر: اتصالِ اصلی
        $this->assertTrue($this->waitUntilClosedOnServer($search, self::MAIN),
            'sqlsrv_search must not be pooled: its physical connection must be closed on the server after close.');

        // دورِ دوم با اتصال‌هایِ تازه، برایِ اطمینان از تکرارپذیری
        $search2 = $this->physicalConnectionId(self::SEARCH);
        $this->assertNotSame($search, $search2);
        $this->reopen(self::SEARCH);
        $this->assertTrue($this->waitUntilClosedOnServer($search2, self::MAIN));
    }

    // ---------- Timeout ----------

    public function test_timeout_applies_to_the_first_statement_of_a_fresh_search_connection(): void
    {
        // Poolِ اتصالِ اصلی گرم است؛ اتصالِ جست‌وجو تازه باز می‌شود و WAITFOR اولین دستورِ آن است
        $this->reopen(self::MAIN);
        $this->physicalConnectionId(self::MAIN);
        $this->reopen(self::SEARCH);
        $this->assertNotInstanceOf(\PDO::class, DB::connection(self::SEARCH)->getRawPdo(), 'Precondition: not connected yet (lazy).');

        $this->assertCancelledWithin($this->slowSearch(1), 1.6);
    }

    public function test_timeout_applies_after_disconnect(): void
    {
        DB::connection(self::SEARCH)->getPdo();
        DB::disconnect(self::SEARCH);
        $this->assertNull(DB::connection(self::SEARCH)->getRawPdo(), 'Precondition: connection is disconnected.');

        $this->assertCancelledWithin($this->slowSearch(1), 1.6); // reconnectIfMissingConnection پیش از getPdo
        $this->assertInstanceOf(\PDO::class, DB::connection(self::SEARCH)->getRawPdo());
    }

    public function test_timeout_applies_after_reconnect_and_on_following_statements(): void
    {
        DB::reconnect(self::SEARCH);

        $this->assertCancelledWithin($this->slowSearch(1), 1.6);
        $this->assertCancelledWithin($this->slowSearch(2), 2.6);
        $this->assertSame(1, (int) DB::connection(self::SEARCH)->selectOne('SELECT 1 AS one')->one);
    }

    // ---------- تراکنش ----------

    public function test_no_transaction_is_left_open_and_other_connection_transaction_is_untouched(): void
    {
        $this->assertCancelledWithin($this->slowSearch(1), 1.6);
        $state = $this->tranCount(self::SEARCH);
        $this->assertSame([0, 0], [(int) $state->t, (int) $state->x]);
        $this->assertSame(0, DB::connection(self::SEARCH)->transactionLevel());

        // تراکنشِ (خالیِ) اتصالِ اصلی با Timeoutِ اتصالِ جست‌وجو دست نمی‌خورد
        DB::connection(self::MAIN)->beginTransaction();
        $this->assertCancelledWithin($this->slowSearch(1), 1.6);
        $this->assertSame(1, DB::connection(self::MAIN)->transactionLevel());
        $this->assertSame(1, (int) $this->tranCount(self::MAIN)->t);
        DB::connection(self::MAIN)->rollBack();
    }

    private function searchAsU1(string $q)
    {
        $user = User::where('UserName', 'gs_u1_admin')->first();
        if (! $user) {
            $this->markTestSkipped('Synthetic user gs_u1_admin missing.');
        }
        config([
            'global_search.enabled' => true,
            'global_search.rate_limit.store' => 'array',
            'global_search.providers' => [
                'crm' => ['enabled' => false, 'class' => null],
                'messages' => ['enabled' => true, 'class' => MessageSearchProvider::class],
                'projects' => ['enabled' => false, 'class' => null],
                'processes' => ['enabled' => false, 'class' => null],
            ],
        ]);

        return $this->actingAs($user)->postJson('/global-search', ['q' => $q]);
    }

    private function assertNoInternalDetails($response): void
    {
        $body = (string) $response->getContent();
        foreach (['transaction', 'GS_SEARCH', 'SQLSTATE', 'TRANCOUNT', 'sqlsrv_search', 'SearchConnection'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, $body);
        }
    }

    private function loggedExceptionClasses(): array
    {
        return array_values(array_filter(array_map(fn (MessageLogged $e) => $e->context['exception'] ?? null, $this->logged)));
    }

    public function test_search_is_refused_on_an_open_laravel_transaction_without_leaking_details(): void
    {
        DB::connection(self::SEARCH)->beginTransaction();

        $r = $this->searchAsU1('بودجه')->assertOk();
        $r->assertJsonPath('complete', false)->assertJsonPath('groups', []);
        $this->assertNoInternalDetails($r);
        $this->assertSame([SearchConnectionBusy::class], $this->loggedExceptionClasses());
        $this->assertSame(1, DB::connection(self::SEARCH)->transactionLevel(), 'The caller\'s transaction is not touched.');

        DB::connection(self::SEARCH)->rollBack();
        $this->searchAsU1('بودجه')->assertOk()->assertJsonPath('complete', true)->assertJsonPath('groups.0.key', 'messages');
    }

    public function test_search_is_refused_on_an_open_pdo_level_transaction(): void
    {
        // تراکنشی که با خودِ PDO (نه لاراول) باز شده: لاراول از آن خبر ندارد ولی PDO و سرور می‌بینند
        $pdo = DB::connection(self::SEARCH)->getPdo();
        $pdo->beginTransaction();
        $this->assertSame(0, DB::connection(self::SEARCH)->transactionLevel(), 'Precondition: invisible to Laravel.');
        $this->assertSame(1, (int) $this->tranCount(self::SEARCH)->t, 'Precondition: visible on the server.');

        $r = $this->searchAsU1('بودجه')->assertOk();
        $r->assertJsonPath('complete', false)->assertJsonPath('groups', []);
        $this->assertNoInternalDetails($r);
        $this->assertSame([SearchConnectionBusy::class], $this->loggedExceptionClasses());

        // تراکنشِ فراخواننده دست‌نخورده
        $this->assertTrue($pdo->inTransaction());
        $this->assertSame([1, 1], array_map('intval', array_values((array) $this->tranCount(self::SEARCH))));

        $pdo->rollBack();
        $this->searchAsU1('بودجه')->assertOk()->assertJsonPath('complete', true);
    }

    public function test_server_side_guard_refuses_to_run_inside_an_open_transaction_and_leaves_it_intact(): void
    {
        // نگهبانِ سمتِ سرور مستقل از بررسی‌هایِ لاراول/PDO: Batchِ محافظت‌شده داخلِ یک تراکنشِ باز اجرا می‌شود
        $pdo = DB::connection(self::SEARCH)->getPdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(SearchConnection::guard('SELECT 1 AS reached'));
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            $this->fail('The guarded batch must not run inside an open transaction; got ' . json_encode($rows));
        } catch (\PDOException $e) {
            $this->assertStringContainsString('GS_SEARCH_CONNECTION_BUSY', $e->getMessage());
            $this->assertStringContainsString((string) SearchConnection::SERVER_GUARD_ERROR, json_encode($e->errorInfo));
        }

        $this->assertTrue($pdo->inTransaction(), 'The caller\'s transaction must stay open.');
        $this->assertSame([1, 1], array_map('intval', array_values((array) $this->tranCount(self::SEARCH))), '@@TRANCOUNT=1 and XACT_STATE=1 (committable).');
        $pdo->rollBack();

        // بدونِ تراکنش، همان Batch عادی اجرا می‌شود
        $stmt = $pdo->prepare(SearchConnection::guard('SELECT 1 AS reached'));
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int) $rows[0]['reached']); // pdo_sqlsrv اعدادِ صحیح را رشته برمی‌گرداند
    }

    public function test_mars_rolls_back_a_raw_transaction_at_the_end_of_its_batch(): void
    {
        // مستندسازیِ رفتارِ MARS (پیش‌فرضِ pdo_sqlsrv؛ این اتصال آن را خاموش نمی‌کند): BEGIN TRANِ خام در یک
        // Batchِ جدا باقی نمی‌ماند — SQL Server در پایانِ همان Batch آن را Rollback می‌کند و خطا می‌دهد.
        // پس روی این اتصال «تراکنشِ خامِ ماندگارِ نامرئی برایِ PDO» ممکن نیست؛ نگهبانِ سمتِ سرور لایهٔ دفاعیِ
        // اضافه برایِ حالتی است که MARS خاموش شود.
        $this->assertArrayNotHasKey('multiple_active_result_sets', config('database.connections.' . self::SEARCH));

        try {
            DB::connection(self::SEARCH)->unprepared('BEGIN TRAN');
            $this->fail('With MARS, a raw BEGIN TRAN must not survive its batch.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('MARS batch', $e->getMessage());
        }

        $this->assertSame([0, 0], array_map('intval', array_values((array) $this->tranCount(self::SEARCH))));
        $this->assertFalse(DB::connection(self::SEARCH)->getPdo()->inTransaction());
        $this->searchAsU1('بودجه')->assertOk()->assertJsonPath('complete', true)->assertJsonPath('groups.0.key', 'messages');
    }
}
