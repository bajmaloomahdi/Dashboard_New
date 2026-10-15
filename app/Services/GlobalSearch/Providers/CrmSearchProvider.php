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
 * گروهِ CRM — طرف‌حساب‌ها، اشخاص، برندها، آدرس‌ها، اطلاعاتِ تماس و تعامل‌ها (§6 مشخصات).
 *
 * دسترسی (§7.1): دروازهٔ CRM_VIEW در GlobalSearchService؛ dbo.sp_Gs_Search همان قاعده را
 * مستقلاً دوباره بررسی می‌کند (کاربرِ فعال + CRM_VIEW). رکوردِ غیرفعال برایِ دارندهٔ مجوز با
 * برچسبِ «غیرفعال» نمایش داده می‌شود. از تعامل‌ها هیچ دادهٔ پروژه‌ای خوانده نمی‌شود.
 *
 * فقط فراخوانیِ پارامتری؛ wildcardهایِ LIKE با PersianNormalizer::escapeLike خنثی می‌شوند.
 */
class CrmSearchProvider implements SearchProvider
{
    /** نامِ رویه — در آزمونِ خطایِ ارائه‌دهنده با زیرکلاس تغییر می‌کند */
    protected string $procedure = 'dbo.sp_Gs_Search';

    private const INACTIVE_BADGE = 'غیرفعال';

    private const TYPE_LABELS = [
        'party' => 'طرف‌حساب',
        'person' => 'شخص',
        'brand' => 'برند',
        'address' => 'آدرس',
        'contact' => 'اطلاعات تماس',
        'interaction' => 'تعامل',
    ];

    private const NATURE_LABELS = ['LEGAL' => 'حقوقی', 'INDIVIDUAL' => 'حقیقی'];

    public function __construct(private readonly PersianNormalizer $normalizer)
    {
    }

    public function key(): string
    {
        return 'crm';
    }

    public function label(): string
    {
        return 'طرف‌حساب‌ها و CRM';
    }

    public function requiredPermission(): ?string
    {
        return 'CRM_VIEW';
    }

    public function search(SearchQuery $query, AccessContext $access, int $limit, int $timeoutSeconds): SearchGroupResult
    {
        $tokens = array_slice($query->tokens, 0, 6);
        $params = [
            $access->userId,
            'crm',
            $query->normalized,
            $this->normalizer->escapeLike($query->normalized),
        ];
        for ($i = 0; $i < 6; $i++) {
            $params[] = isset($tokens[$i]) ? $this->normalizer->escapeLike($tokens[$i]) : null;
        }
        $params[] = $this->digitsQuery($query->normalized);
        $params[] = max(1, $limit);

        $rows = $this->execute(
            "EXEC {$this->procedure} @UserID = ?, @Group = ?, @Query = ?, @QueryLike = ?, "
            . '@T1 = ?, @T2 = ?, @T3 = ?, @T4 = ?, @T5 = ?, @T6 = ?, @Digits = ?, @Top = ?',
            $params,
            $timeoutSeconds
        );

        $items = array_map(fn (array $r) => $this->toItem($r), $rows);

        return new SearchGroupResult(
            $this->key(),
            $this->label(),
            $items,
            count($items) > $limit,
            '/crm/parties', // بدونِ متنِ جست‌وجو در URL (یافتهٔ ۱ ممیزی)
        );
    }

    /** اگر پرس‌وجو دست‌کم ۴ رقم دارد، نسخهٔ «فقط ارقام» برایِ تطبیقِ شمارهٔ تماس (§4) */
    private function digitsQuery(string $normalized): ?string
    {
        $digits = preg_replace('/\D+/', '', $normalized);

        return strlen($digits) >= 4 ? $digits : null;
    }

    /**
     * اجرا با Query Timeout (پاسخِ دیر = خطا ← فقط همین گروه حذف می‌شود، complete = false).
     *
     * @return array<int,array<string,mixed>>
     */
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
        $type = (string) $r['ItemType'];
        $targetType = (string) $r['TargetType'];
        $targetId = (int) $r['TargetID'];

        return new SearchResultItem(
            type: $type,
            id: (int) $r['ItemID'],
            title: (string) $r['Title'],
            subtitle: $this->subtitle($type, $r),
            url: $this->url($targetType, $targetId),
            badges: ((int) $r['IsInactive']) === 1 ? [self::INACTIVE_BADGE] : [],
            match: $this->matchKind((int) $r['Score']),
        );
    }

    private function subtitle(string $type, array $r): string
    {
        $label = self::TYPE_LABELS[$type] ?? '';
        $sub1 = trim((string) ($r['Sub1'] ?? ''));
        $sub2 = trim((string) ($r['Sub2'] ?? ''));

        $detail = match ($type) {
            'party' => trim((self::NATURE_LABELS[$sub2] ?? '') . ($sub1 !== '' ? ' · ' . $sub1 : ''), ' ·'),
            'address', 'contact' => trim($sub1 . ($sub2 !== '' ? ': ' . $sub2 : ''), ': '),
            'interaction' => trim($sub1 . ($sub2 !== '' ? ' · ' . $sub2 : ''), ' ·'),
            default => '',
        };

        return $detail === '' ? $label : $label . ' — ' . $detail;
    }

    private function url(string $targetType, int $id): string
    {
        return match ($targetType) {
            'person' => '/crm/persons/' . $id,
            'brand' => '/crm/brands/' . $id,
            default => '/crm/parties/' . $id,
        };
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
