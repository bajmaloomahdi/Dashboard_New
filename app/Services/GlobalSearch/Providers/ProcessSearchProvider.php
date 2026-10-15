<?php

namespace App\Services\GlobalSearch\Providers;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\Contracts\SearchProvider;
use App\Services\GlobalSearch\PersianNormalizer;
use App\Services\GlobalSearch\SearchGroupResult;
use App\Services\GlobalSearch\SearchQuery;
use App\Services\GlobalSearch\SearchResultItem;
use App\Services\GlobalSearch\Support\SearchConnection;
use PDO;

/**
 * گروهِ فرایندها — تعریف‌ها و نمونه‌هایِ اجرا، به‌صورتِ دو نوعِ جدا.
 *
 * دسترسی (§7.1) — همان قاعدهٔ صفحه‌ها (ProcessDefinitionController / ProcessInstanceController):
 * WORKFLOW_VIEW همهٔ تعریف‌ها (فعال و غیرفعال) و همهٔ نمونه‌ها (هر وضعیت) را می‌بیند؛ فیلترِ
 * سطحِ رکورد ندارد. دروازهٔ مجوز در GlobalSearchService و بررسیِ مستقلِ دوباره در
 * dbo.sp_Gs_SearchProcesses. موجودیتِ متصل به نمونه (EntityType/EntityID، مثلاً نامه) هرگز
 * برگردانده نمی‌شود؛ پس WORKFLOW_VIEW دسترسی به نامه یا CRM ایجاد نمی‌کند.
 */
class ProcessSearchProvider implements SearchProvider
{
    protected string $procedure = 'dbo.sp_Gs_SearchProcesses';

    private const INACTIVE_BADGE = 'غیرفعال';

    private const STATUS_LABELS = [
        'RUNNING' => 'در جریان',
        'COMPLETED' => 'تکمیل‌شده',
        'CANCELLED' => 'لغوشده',
        'SUSPENDED' => 'معلق',
        'FAILED' => 'ناموفق',
    ];

    public function __construct(private readonly PersianNormalizer $normalizer)
    {
    }

    public function key(): string
    {
        return 'processes';
    }

    public function label(): string
    {
        return 'فرایندها';
    }

    public function requiredPermission(): ?string
    {
        return 'WORKFLOW_VIEW';
    }

    public function search(SearchQuery $query, AccessContext $access, int $limit, int $timeoutSeconds): SearchGroupResult
    {
        $rows = $this->execute(
            "EXEC {$this->procedure} @UserID = ?, @Query = ?, @QueryLike = ?, "
            . '@T1 = ?, @T2 = ?, @T3 = ?, @T4 = ?, @T5 = ?, @T6 = ?, @Top = ?',
            $this->bindings($query, $access, $limit),
            $timeoutSeconds
        );

        $items = array_map(fn (array $r) => $this->toItem($r), $rows);

        return new SearchGroupResult(
            $this->key(),
            $this->label(),
            $items,
            count($items) > $limit,
            '/process/instances',
        );
    }

    private function toItem(array $r): SearchResultItem
    {
        $id = (int) $r['ItemID'];
        $isInstance = ($r['ItemType'] ?? '') === 'process_instance';
        $code = trim((string) ($r['RefCode'] ?? ''));

        if ($isInstance) {
            $status = (string) ($r['StatusCode'] ?? '');
            $subtitle = implode(' · ', array_filter([
                'نمونهٔ اجرا',
                $code,
                self::STATUS_LABELS[$status] ?? $status,
                trim((string) ($r['StartedOn'] ?? '')),
            ], fn ($p) => $p !== ''));

            return new SearchResultItem('process_instance', $id, (string) $r['Title'], $subtitle,
                '/process/instances/' . $id, [], $this->matchKind((int) $r['Score']));
        }

        return new SearchResultItem('process_definition', $id, (string) $r['Title'],
            implode(' · ', array_filter(['تعریف فرایند', $code], fn ($p) => $p !== '')),
            '/process/definitions/' . $id,
            ((int) $r['IsInactive']) === 1 ? [self::INACTIVE_BADGE] : [],
            $this->matchKind((int) $r['Score']));
    }

    private function bindings(SearchQuery $query, AccessContext $access, int $limit): array
    {
        $tokens = array_slice($query->tokens, 0, 6);
        $params = [$access->userId, $query->normalized, $this->normalizer->escapeLike($query->normalized)];
        for ($i = 0; $i < 6; $i++) {
            $params[] = isset($tokens[$i]) ? $this->normalizer->escapeLike($tokens[$i]) : null;
        }
        $params[] = max(1, $limit);

        return $params;
    }

    /** @return array<int,array<string,mixed>> */
    protected function execute(string $sql, #[\SensitiveParameter] array $bindings, int $timeoutSeconds): array
    {
        // سهمِ این گروه از بودجهٔ مشترکِ درخواست (GlobalSearchService)؛ 0 در pdo_sqlsrv یعنی بی‌نهایت، پس حداقل ۱
        $timeout = max(1, $timeoutSeconds);
        $options = defined('PDO::SQLSRV_ATTR_QUERY_TIMEOUT') ? [PDO::SQLSRV_ATTR_QUERY_TIMEOUT => $timeout] : [];

        // اتصالِ اختصاصیِ بدونِ Pooling؛ اتصالِ مجدد در صورتِ نیاز و ممنوعیتِ تراکنشِ باز (SearchConnection)
        $stmt = SearchConnection::pdo()->prepare(SearchConnection::guard($sql), $options);
        $stmt->execute($bindings);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function matchKind(int $score): string
    {
        return match (true) {
            $score >= 1000 => 'code',
            $score >= 500 => 'exact',
            $score >= 400 => 'prefix',
            $score >= 300 => 'word',
            $score >= 200 => 'contains',
            default => 'secondary',
        };
    }
}
