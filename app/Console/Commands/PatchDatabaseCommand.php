<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Throwable;

class PatchDatabaseCommand extends Command
{
    protected $signature = 'db:patch {--status : Read the patch ledger without changing it}';

    protected $description = 'Apply SQL Server patches and record their checksums';

    public function handle(): int
    {
        try {
            $directory = is_dir(base_path('sql/patches')) ? base_path('sql/patches') : base_path('sql');
            $patches = [];
            foreach (File::files($directory) as $file) {
                if (strtolower($file->getExtension()) !== 'sql') {
                    continue;
                }
                $name = $file->getFilename();
                if (!preg_match('/^(\d+)_.*\.sql$/i', $name, $parts) || (int) $parts[1] < 1 || (int) $parts[1] > 2147483647) {
                    throw new RuntimeException("Invalid patch filename: {$name}");
                }
                $number = (int) $parts[1];
                if (isset($patches[$number])) {
                    throw new RuntimeException("Duplicate patch number: {$number}");
                }
                $sql = File::get($file->getPathname());
                $patches[$number] = ['name' => $name, 'number' => $number, 'hash' => hash('sha256', $sql), 'sql' => $sql];
            }
            ksort($patches, SORT_NUMERIC);

            $config = DB::connection()->getConfig();
            if (($config['driver'] ?? null) !== 'sqlsrv') {
                throw new RuntimeException('The patch command requires a SQL Server connection.');
            }
            unset($config['name'], $config['read'], $config['write']);
            $config['multiple_active_result_sets'] = false;
            $connection = DB::build($config);
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO) {
                throw new RuntimeException('Could not open the patch session.');
            }

            $development = ($config['host'] ?? null) === '192.168.100.11'
                && ($config['database'] ?? null) === 'Dashboard_DB';

            return $this->runPatches($pdo, $patches, (bool) $this->option('status'), $development);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    protected function runPatches(PDO $pdo, array $patches, bool $statusOnly, bool $development = false): int
    {
        $schema = $pdo->query("SELECT CASE WHEN OBJECT_ID(N'dbo.SysPatches', N'U') IS NULL THEN 0
            WHEN COL_LENGTH('dbo.SysPatches','PatchNumber') IS NULL OR COL_LENGTH('dbo.SysPatches','Sha256') IS NULL
            OR COL_LENGTH('dbo.SysPatches','Evidence') IS NULL
            OR NOT EXISTS (SELECT 1 FROM sys.indexes
                WHERE object_id = OBJECT_ID(N'dbo.SysPatches', N'U')
                AND name = N'UX_SysPatches_PatchNumber' AND is_unique = 1
                AND is_disabled = 0 AND filter_definition = N'([PatchNumber] IS NOT NULL)')
            THEN 1 ELSE 2 END")->fetchColumn();

        if ($statusOnly) {
            $history = $this->history($pdo, (int) $schema);
            $this->table(['Patch', 'Status'], array_map(fn ($patch) => [
                $patch['name'], $this->patchStatus($patch, $history, $development),
            ], $patches));
            if ((int) $schema === 0) {
                $this->warn('The patch ledger does not exist.');
            }

            return self::SUCCESS;
        }

        if ((int) $schema !== 2) {
            $pdo->exec(File::get(base_path('docs/patch-ledger-upgrade.sql')));
            $this->info('Patch ledger upgraded.');
        }

        $history = $this->history($pdo, 2);
        foreach ($patches as $patch) {
            $status = $this->patchStatus($patch, $history, $development);
            if (!in_array($status, ['Applied', 'Verified existing', 'Legacy SUCCESS (no hash)', 'Pending'], true)) {
                throw new RuntimeException("{$patch['name']}: {$status}. Review the ledger before continuing.");
            }
        }

        foreach ($patches as $patch) {
            $pdo->beginTransaction();
            $line = null;
            $batchNumber = null;
            $executing = false;
            $committing = false;
            try {
                $this->acquirePatchLock($pdo);
                $history = $this->history($pdo, 2);
                $status = $this->patchStatus($patch, $history, $development);
                if (in_array($status, ['Applied', 'Verified existing', 'Legacy SUCCESS (no hash)'], true)) {
                    $pdo->rollBack();
                    continue;
                }
                if ($status !== 'Pending') {
                    throw new RuntimeException("{$patch['name']}: {$status}. Review the ledger before continuing.");
                }

                $batches = PatchBatches::split($patch['sql']);
                if ($batches === []) {
                    throw new RuntimeException("{$patch['name']}: no SQL batches.");
                }

                $this->line("[RUNNING] {$patch['name']}");
                $start = hrtime(true);
                $pdo->exec('SET XACT_ABORT ON; SET IMPLICIT_TRANSACTIONS OFF;');
                $database = $pdo->query('SELECT DB_NAME()')->fetchColumn();
                foreach ($batches as $index => $batch) {
                    $line = $batch['line'];
                    $batchNumber = $index + 1;
                    $executing = true;
                    $statement = $pdo->query($batch['sql']);
                    // SQL Server can surface errors while advancing through result sets.
                    while ($statement->nextRowset()) {
                    }
                    $statement->closeCursor();
                    $state = $pdo->query('SELECT XACT_STATE(), @@TRANCOUNT, DB_NAME()')->fetch(PDO::FETCH_NUM);
                    if ((int) $state[0] !== 1 || (int) $state[1] !== 1 || $state[2] !== $database) {
                        throw new RuntimeException('The batch changed the runner transaction or database context.');
                    }
                }
                $this->record($pdo, $patch, 'SUCCESS', (int) ((hrtime(true) - $start) / 1_000_000));
                $committing = true;
                if (!$pdo->commit()) {
                    throw new RuntimeException('Commit did not report success.');
                }
                $this->info("[SUCCESS] {$patch['name']}");
            } catch (Throwable $e) {
                $location = $batchNumber === null ? '' : " batch {$batchNumber} (starts at file line {$line})";
                $message = "{$patch['name']}{$location}: ".$e->getMessage();
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($executing && !$committing) {
                        $this->record($pdo, $patch, 'FAILED', null, $message);
                    }
                } catch (Throwable $cleanup) {
                    $this->error('Rollback or failure recording could not be confirmed: '.$cleanup->getMessage());
                }
                if ($committing) {
                    $this->error('Commit outcome is uncertain. Check the ledger on a fresh connection before retrying.');
                }
                throw new RuntimeException($message, 0, $e);
            }
        }

        return self::SUCCESS;
    }

    protected function acquirePatchLock(PDO $pdo): void
    {
        $result = $pdo->query("SET NOCOUNT ON; DECLARE @result int;
            EXEC @result = sys.sp_getapplock @Resource=N'Raga360:db:patch', @LockMode='Exclusive',
                @LockOwner='Transaction', @LockTimeout=0;
            SELECT @result;")->fetchColumn();
        if ($result === false || (int) $result < 0) {
            throw new RuntimeException('Could not acquire the patch lock. Another runner may be active.');
        }
    }

    protected function history(PDO $pdo, int $schema): array
    {
        if ($schema === 0) {
            return [];
        }
        $columns = $schema === 2 ? 'PatchNumber, Sha256, Evidence' : 'NULL AS PatchNumber, NULL AS Sha256, NULL AS Evidence';

        return $pdo->query("SELECT PatchName, Status, {$columns} FROM dbo.SysPatches ORDER BY PatchID DESC")->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function patchStatus(array $patch, array $history, bool $development = false): string
    {
        $failed = false;
        foreach ($history as $entry) {
            $sameName = $entry['PatchName'] === $patch['name'];
            $sameNumber = $entry['PatchNumber'] !== null
                ? (int) $entry['PatchNumber'] === $patch['number']
                : preg_match('/^(\d+)_/', $entry['PatchName'], $parts) && (int) $parts[1] === $patch['number'];
            if (!$sameName && !$sameNumber) {
                continue;
            }
            if ($entry['Status'] === 'FAILED') {
                $failed = true;
                continue;
            }
            if (!$sameName) {
                return 'Patch number already used under a different filename';
            }
            if ($entry['Sha256'] !== null && !hash_equals(strtolower($entry['Sha256']), $patch['hash'])) {
                return 'Checksum mismatch';
            }
            if ($entry['Status'] === 'SUCCESS') {
                return $entry['Sha256'] === null ? 'Legacy SUCCESS (no hash)' : 'Applied';
            }
            if ($entry['Status'] === 'VERIFIED_EXISTING' && $entry['Sha256'] !== null && trim((string) $entry['Evidence']) !== '') {
                return 'Verified existing';
            }

            return 'Unrecognized or incomplete ledger entry';
        }

        if ($failed) {
            return 'Failed previously (review required)';
        }

        return $development && in_array($patch['number'], [139, 140, 141, 142, 143], true)
            ? 'Development reconciliation required'
            : 'Pending';
    }

    protected function record(PDO $pdo, array $patch, string $status, ?int $duration, ?string $error = null): void
    {
        $statement = $pdo->prepare('INSERT INTO dbo.SysPatches
            (PatchName, PatchNumber, Sha256, AppliedAt, AppVersion, ExecutionMs, Status, ErrorMessage)
            VALUES (?, ?, ?, GETDATE(), ?, ?, ?, ?)');
        $statement->execute([
            $patch['name'], $status === 'SUCCESS' ? $patch['number'] : null, $patch['hash'],
            config('app.version', '1.0.0'), $duration, $status, $error,
        ]);
    }
}
