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
 * گروهِ نامه‌ها و وظایف — فقط شماره و موضوع (تصمیمِ تأییدشده؛ متنِ نامه جست‌وجو نمی‌شود).
 *
 * دسترسی (§7.1) — دقیقاً همان قاعدهٔ صفحهٔ نامه (MessageAccessChecker / MessageController::show):
 * فرستنده، هر گیرنده در زنجیرهٔ MessageDetails (فعلی یا قبلیِ ارجاع)، یا گیرندهٔ رونوشت.
 * هیچ نقش یا مجوزی (حتی مدیر) دسترسیِ اضافه نمی‌دهد؛ بنابراین دروازهٔ مجوز ندارد و قاعدهٔ
 * سطحِ رکورد کاملاً در dbo.sp_Gs_SearchMessages اعمال می‌شود (پیش از تطبیق و رتبه‌بندی،
 * تا عنوان، شمارش و hasMore فقط از نامه‌هایِ مجاز بیاید). لینکِ نتیجه همان /messages/{id} است.
 */
class MessageSearchProvider implements SearchProvider
{
    /** نامِ رویه — در آزمونِ خطایِ ارائه‌دهنده با زیرکلاس تغییر می‌کند */
    protected string $procedure = 'dbo.sp_Gs_SearchMessages';

    private const NO_NUMBER = 'بدون شماره';

    public function __construct(private readonly PersianNormalizer $normalizer)
    {
    }

    public function key(): string
    {
        return 'messages';
    }

    public function label(): string
    {
        return 'نامه‌ها و وظایف';
    }

    public function requiredPermission(): ?string
    {
        return null; // قاعدهٔ دسترسی سطحِ رکورد است، نه مجوزِ نقش
    }

    public function search(SearchQuery $query, AccessContext $access, int $limit, int $timeoutSeconds): SearchGroupResult
    {
        $tokens = array_slice($query->tokens, 0, 6);
        $params = [$access->userId, $query->normalized, $this->normalizer->escapeLike($query->normalized)];
        for ($i = 0; $i < 6; $i++) {
            $params[] = isset($tokens[$i]) ? $this->normalizer->escapeLike($tokens[$i]) : null;
        }
        $params[] = max(1, $limit);

        $rows = $this->execute(
            "EXEC {$this->procedure} @UserID = ?, @Query = ?, @QueryLike = ?, "
            . '@T1 = ?, @T2 = ?, @T3 = ?, @T4 = ?, @T5 = ?, @T6 = ?, @Top = ?',
            $params,
            $timeoutSeconds
        );

        $items = array_map(fn (array $r) => $this->toItem($r), $rows);

        return new SearchGroupResult(
            $this->key(),
            $this->label(),
            $items,
            count($items) > $limit,
            '/messages', // بدونِ متنِ جست‌وجو در URL (یافتهٔ ۱ ممیزی)
        );
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

    private function toItem(array $r): SearchResultItem
    {
        $isTask = ($r['ItemType'] ?? '') === 'task';
        $number = trim((string) ($r['MessageNumber'] ?? ''));
        $parts = [
            trim((string) ($r['TypeName'] ?? '')),
            $number !== '' ? $number : self::NO_NUMBER,
            trim((string) ($r['CreatedOn'] ?? '')),
        ];
        if ($isTask && trim((string) ($r['LastStatusName'] ?? '')) !== '') {
            $parts[] = trim((string) $r['LastStatusName']);
        }

        return new SearchResultItem(
            type: $isTask ? 'task' : 'message',
            id: (int) $r['ItemID'],
            title: (string) $r['Title'],
            subtitle: implode(' · ', array_filter($parts, fn ($p) => $p !== '')),
            url: '/messages/' . (int) $r['ItemID'],
            badges: [],
            match: $this->matchKind((int) $r['Score']),
        );
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
