<?php

namespace Tests\Unit;

use App\Console\Commands\PatchBatches;
use App\Console\Commands\PatchDatabaseCommand;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PatchRunnerTest extends TestCase
{
    private function patch(int $number, string $sql = "SELECT 1\nGO\nSELECT 2"): array
    {
        return ['number' => $number, 'name' => "{$number}_test.sql", 'sql' => $sql, 'hash' => hash('sha256', $sql)];
    }

    public function test_runner_commits_success_and_skips_it_on_rerun(): void
    {
        $patch = $this->patch(200);
        $pdo = new PatchFakeSession();
        $runner = new TestPatchRunner();
        self::assertSame(0, $runner->runForTest($pdo, [$patch]));
        self::assertSame(['SELECT 1', 'SELECT 2'], $pdo->committed);
        self::assertSame('SUCCESS', $runner->ledger[0]['Status']);
        self::assertSame(0, $runner->runForTest($pdo, [$patch]));
        self::assertCount(1, $runner->ledger);
        self::assertCount(2, $pdo->committed);
        $this->expectExceptionMessage('Checksum mismatch');
        $runner->runForTest($pdo, [$this->patch(200, 'SELECT 9')]);
    }

    public function test_batch_failure_rolls_back_and_stops_later_patches(): void
    {
        $pdo = new PatchFakeSession();
        $runner = new TestPatchRunner();
        try {
            $runner->runForTest($pdo, [$this->patch(200, "SELECT 1\nGO\nFAIL"), $this->patch(201)]);
            self::fail('Expected SQL error');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('batch 2 (starts at file line 3)', $error->getMessage());
        }
        self::assertSame([], $pdo->committed);
        self::assertSame(['SELECT 1', 'FAIL'], $pdo->attempted);
        self::assertSame(['FAILED'], array_column($runner->ledger, 'Status'));
    }

    public function test_success_insert_failure_rolls_back_sql(): void
    {
        $pdo = new PatchFakeSession();
        $runner = new TestPatchRunner();
        $runner->failSuccessInsert = true;
        try {
            $runner->runForTest($pdo, [$this->patch(200)]);
            self::fail('Expected journal failure');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('journal failure', $error->getMessage());
        }
        self::assertSame([], $pdo->committed);
        self::assertSame(['FAILED'], array_column($runner->ledger, 'Status'));
    }

    public function test_lock_contention_stops_before_any_batch_or_ledger_write(): void
    {
        $pdo = new PatchFakeSession();
        $pdo->lock = -1;
        $runner = new TestPatchRunner();
        try {
            $runner->runForTest($pdo, [$this->patch(200)]);
            self::fail('Expected lock failure');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('Could not acquire the patch lock', $error->getMessage());
        }
        self::assertSame([], $pdo->attempted);
        self::assertSame([], $runner->ledger);
        self::assertFalse($pdo->inTransaction());
    }

    public function test_legacy_success_is_skipped_and_status_does_not_open_a_transaction(): void
    {
        $pdo = new PatchFakeSession();
        $runner = new TestPatchRunner();
        $runner->ledger[] = ['PatchName' => '200_test.sql', 'PatchNumber' => null, 'Sha256' => null, 'Evidence' => null, 'Status' => 'SUCCESS'];
        self::assertSame(0, $runner->runForTest($pdo, [$this->patch(200)], true));
        self::assertSame(0, $pdo->begins);
        self::assertSame(0, $runner->runForTest($pdo, [$this->patch(200)]));
        self::assertSame([], $pdo->attempted);
        self::assertNull($runner->ledger[0]['Sha256']);
    }

    public function test_go_inside_sql_strings_comments_and_identifiers_does_not_split_batches(): void
    {
        $sql = "SELECT N'a\nGO\nb', [x\nGO\ny]\n/*\nGO\n/* nested */\n*/\nGO -- split here\nSELECT 2";
        self::assertSame([1, 11], array_column(PatchBatches::split($sql), 'line'));
        $this->expectExceptionMessage('Unsupported SQLCMD directive');
        PatchBatches::split("SELECT 1\nGO 2");
    }
}

final class TestPatchRunner extends PatchDatabaseCommand
{
    public array $ledger = [];
    public bool $failSuccessInsert = false;

    public function runForTest(PDO $pdo, array $patches, bool $statusOnly = false): int
    {
        return $this->runPatches($pdo, $patches, $statusOnly);
    }

    public function line($string, $style = null, $verbosity = null) {}
    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = []) {}

    protected function history(PDO $pdo, int $schema): array
    {
        return $this->ledger;
    }

    protected function record(PDO $pdo, array $patch, string $status, ?int $duration, ?string $error = null): void
    {
        if ($status === 'SUCCESS') {
            if (!$pdo->inTransaction() || $this->failSuccessInsert) {
                throw new RuntimeException('journal failure');
            }
        }
        $this->ledger[] = ['PatchName' => $patch['name'], 'PatchNumber' => $patch['number'],
            'Sha256' => $patch['hash'], 'Status' => $status, 'Evidence' => null];
    }
}

final class PatchFakeSession extends PDO
{
    public int $begins = 0;
    public int $lock = 0;
    public array $attempted = [];
    public array $committed = [];
    private bool $active = false;
    private array $pending = [];

    public function __construct() {}
    public function beginTransaction(): bool { $this->begins++; return $this->active = true; }
    public function inTransaction(): bool { return $this->active; }
    public function commit(): bool
    {
        $this->committed = [...$this->committed, ...$this->pending];

        return $this->rollBack();
    }
    public function rollBack(): bool { $this->active = false; $this->pending = []; return true; }
    public function exec(string $statement): int|false { return 0; }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'COL_LENGTH')) { return new PatchFakeStatement([2]); }
        if (str_contains($query, 'sp_getapplock')) { return new PatchFakeStatement([$this->lock]); }
        if ($query === 'SELECT DB_NAME()') { return new PatchFakeStatement(['test']); }
        if (str_starts_with($query, 'SELECT XACT_STATE()')) { return new PatchFakeStatement([1, 1, 'test']); }
        $this->attempted[] = $query;
        if ($query === 'FAIL') { throw new RuntimeException('SQL failed'); }
        $this->pending[] = $query;

        return new PatchFakeStatement([]);
    }
}

final class PatchFakeStatement extends PDOStatement
{
    public function __construct(private array $row) {}
    public function fetchColumn(int $column = 0): mixed { return $this->row[$column] ?? false; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->row; }
    public function nextRowset(): bool { return false; }
    public function closeCursor(): bool { return true; }
}
