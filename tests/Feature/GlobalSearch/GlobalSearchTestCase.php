<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\Contracts\AccessContextResolver;
use Illuminate\Support\Facades\DB;
use Tests\Feature\GlobalSearch\Fakes\FakeAccessContextResolver;
use Tests\TestCase;

require_once __DIR__ . '/Fakes/FakeProviders.php';

/**
 * پایهٔ آزمون‌هایِ جست‌وجویِ سراسری.
 * - محافظ: فقط رویِ LocalDB مستقل (Dashboard_GsTest) اجرا می‌شود؛ در غیرِ این صورت آزمون متوقف می‌شود.
 * - همهٔ کوئری‌هایِ دیتابیس ثبت می‌شوند تا آزمون‌ها بتوانند «بدونِ دیتابیس» بودن را تأیید کنند.
 * - مجوزها با FakeAccessContextResolver (بدونِ دیتابیس) جایگزین می‌شوند.
 */
abstract class GlobalSearchTestCase extends TestCase
{
    protected FakeAccessContextResolver $access;

    /** @var string[] */
    protected array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        $host = (string) config('database.connections.sqlsrv.host');
        $db = (string) config('database.connections.sqlsrv.database');
        if (config('database.default') !== 'sqlsrv' || strcasecmp($host, '(localdb)\GsTest') !== 0 || $db !== 'Dashboard_GsTest') {
            $this->fail('SAFETY: tests must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }

        $this->access = new FakeAccessContextResolver();
        $this->app->instance(AccessContextResolver::class, $this->access);

        config([
            'global_search.enabled' => true,
            'global_search.rate_limit.store' => 'array',
            'global_search.rate_limit.max_attempts' => 100,
        ]);

        $this->queries = [];
        DB::listen(function ($q) {
            $this->queries[] = $q->sql;
        });
    }

    /** کاربرِ ساختگی بدونِ ذخیره در دیتابیس */
    protected function fakeUser(int $id = 501, bool $active = true): User
    {
        $user = new User();
        $user->forceFill(['UserID' => $id, 'UserName' => 'gs_fake_' . $id, 'IsActive' => $active, 'IsLocked' => false]);
        $user->exists = true;

        return $user;
    }

    /** متنِ جست‌وجو فقط در بدنهٔ POST (یافتهٔ ۱ ممیزی) */
    protected function search(string $query = '', array $extra = [])
    {
        return $this->postJson('/global-search', array_merge(['q' => $query], $extra));
    }

    protected function assertNoDatabaseQueries(): void
    {
        $this->assertSame([], $this->queries, 'Expected no database queries.');
    }
}
