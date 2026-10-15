<?php

namespace Tests\Feature\GlobalSearch\Fakes;

use App\Services\GlobalSearch\Providers\ProjectSearchProvider;

/**
 * ارائه‌دهندهٔ کُند فقط برایِ آزمونِ بودجهٔ زمانی: همان execute() واقعی (PDO + Query Timeout)
 * ولی با یک WAITFOR بی‌اثر به‌جایِ رویه — بدونِ خواندن یا نوشتنِ هیچ داده‌ای.
 */
class SlowSqlSearchProvider extends ProjectSearchProvider
{
    public static int $calls = 0;

    /** @var int[] */
    public static array $timeouts = [];

    protected function execute(string $sql, #[\SensitiveParameter] array $bindings, int $timeoutSeconds): array
    {
        static::$calls++;
        static::$timeouts[] = $timeoutSeconds;
        $started = hrtime(true);

        try {
            return parent::execute("WAITFOR DELAY '00:00:06'; SELECT 1 AS Never", [], $timeoutSeconds);
        } catch (\Throwable $e) {
            static::$outcomes[] = sprintf('%s(%s) after %.2fs', $e::class, $e->getCode(), (hrtime(true) - $started) / 1e9);
            throw $e;
        } finally {
            if (count(static::$outcomes) < static::$calls) {
                static::$outcomes[] = sprintf('completed after %.2fs', (hrtime(true) - $started) / 1e9);
            }
        }
    }

    /** @var string[] نتیجهٔ هر اجرا (برایِ پیامِ خطایِ آزمون) */
    public static array $outcomes = [];

    public static function reset(): void
    {
        static::$calls = 0;
        static::$timeouts = [];
        static::$outcomes = [];
    }
}
