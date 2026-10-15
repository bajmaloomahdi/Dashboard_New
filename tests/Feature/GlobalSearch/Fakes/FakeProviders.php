<?php

namespace Tests\Feature\GlobalSearch\Fakes;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\Contracts\SearchProvider;
use App\Services\GlobalSearch\SearchGroupResult;
use App\Services\GlobalSearch\SearchQuery;
use App\Services\GlobalSearch\SearchResultItem;

/**
 * ارائه‌دهنده‌هایِ ساختگی فقط برایِ آزمونِ قراردادِ API (مرحلهٔ ۱) — بدونِ دیتابیس.
 * تعدادِ ردیف‌ها و آخرین آرگومان‌ها در ویژگی‌هایِ ایستا نگه داشته می‌شود.
 */
abstract class FakeProviderBase implements SearchProvider
{
    public static int $rows = 3;
    public static array $calls = [];
    /** تأخیرِ ساختگی (میکروثانیه) برایِ آزمونِ بودجهٔ زمانی */
    public static int $delayMicros = 0;

    abstract public function key(): string;

    public function label(): string
    {
        return 'Fake ' . $this->key();
    }

    public function search(SearchQuery $query, AccessContext $access, int $limit, int $timeoutSeconds): SearchGroupResult
    {
        static::$calls[] = ['key' => $this->key(), 'normalized' => $query->normalized, 'limit' => $limit, 'userId' => $access->userId, 'timeout' => $timeoutSeconds];
        if (static::$delayMicros > 0) {
            usleep(static::$delayMicros);
        }

        $items = [];
        for ($i = 1; $i <= static::$rows; $i++) {
            $items[] = new SearchResultItem(
                type: $this->key(),
                id: $i,
                title: $this->key() . ' #' . $i,
                subtitle: null,
                url: '/' . $this->key() . '/' . $i,
                badges: $i === 1 ? ['غیرفعال'] : [],
                match: 'prefix',
            );
        }

        return new SearchGroupResult($this->key(), $this->label(), $items, false, '/' . $this->key());
    }

    public static function reset(int $rows = 3, int $delayMicros = 0): void
    {
        static::$rows = $rows;
        static::$calls = [];
        static::$delayMicros = $delayMicros;
    }
}

/** گروهِ دارایِ دروازهٔ مجوز (مثلِ CRM) */
class FakeCrmProvider extends FakeProviderBase
{
    public static int $rows = 3;
    public static array $calls = [];
    public static int $delayMicros = 0;

    public function key(): string
    {
        return 'crm';
    }

    public function requiredPermission(): ?string
    {
        return 'CRM_VIEW';
    }
}

/** گروهِ بدونِ دروازهٔ مجوز (مثلِ نامه‌ها؛ دسترسی در سطحِ رکورد) */
class FakeMessagesProvider extends FakeProviderBase
{
    public static int $rows = 3;
    public static array $calls = [];
    public static int $delayMicros = 0;

    public function key(): string
    {
        return 'messages';
    }

    public function requiredPermission(): ?string
    {
        return null;
    }
}

/** ارائه‌دهنده‌ای که خطا می‌دهد — نباید کلِ پاسخ را خراب کند یا جزئیات افشا کند */
class FakeFailingProvider extends FakeProviderBase
{
    public static int $rows = 0;
    public static array $calls = [];
    public static int $delayMicros = 0;

    public function key(): string
    {
        return 'projects';
    }

    public function requiredPermission(): ?string
    {
        return null;
    }

    public function search(SearchQuery $query, AccessContext $access, int $limit, int $timeoutSeconds): SearchGroupResult
    {
        throw new \RuntimeException('SQLSTATE[42000]: secret table dbo.Something failed');
    }
}
