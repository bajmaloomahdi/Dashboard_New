<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\Support\SqlAccessContextResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * آزمونِ یکپارچهٔ فقط‌خواندنی رویِ LocalDB مستقل: sp_GetUserPermissions فقط SELECT است
 * (بررسی‌شده) و این آزمون هیچ ردیفی نمی‌نویسد. پایگاهِ تست خالی است، پس مجوزها خالی برمی‌گردند.
 */
class SqlAccessContextResolverTest extends TestCase
{
    /** @var string[] */
    private array $queries = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (strcasecmp((string) config('database.connections.sqlsrv.host'), '(localdb)\GsTest') !== 0
            || config('database.connections.sqlsrv.database') !== 'Dashboard_GsTest') {
            $this->fail('SAFETY: integration test must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }

        DB::listen(function ($q) {
            $this->queries[] = $q->sql;
        });
    }

    private function user(int $id, bool $active): User
    {
        $u = (new User())->forceFill(['UserID' => $id, 'UserName' => 'gs_it_' . $id, 'IsActive' => $active]);
        $u->exists = true;

        return $u;
    }

    public function test_connected_server_is_the_isolated_localdb(): void
    {
        $row = DB::selectOne("SELECT DB_NAME() AS Db, CAST(SERVERPROPERTY('IsLocalDB') AS INT) AS IsLocalDB");

        $this->assertSame('Dashboard_GsTest', $row->Db);
        $this->assertSame(1, (int) $row->IsLocalDB);
    }

    public function test_active_user_permissions_come_from_stored_procedure_read_only(): void
    {
        $ctx = (new SqlAccessContextResolver())->resolve($this->user(999001, true));

        $this->assertSame(999001, $ctx->userId);
        $this->assertTrue($ctx->isActive);
        $this->assertFalse($ctx->can('CRM_VIEW'));
        $this->assertSame(['EXEC sp_GetUserPermissions @UserID = ?'], $this->queries);
    }

    public function test_inactive_user_is_not_queried_and_has_no_permissions(): void
    {
        $ctx = (new SqlAccessContextResolver())->resolve($this->user(999002, false));

        $this->assertFalse($ctx->isActive);
        $this->assertFalse($ctx->can('CRM_VIEW'));
        $this->assertSame([], $this->queries);
    }
}
