<?php

namespace App\Services\GlobalSearch\Support;

use Illuminate\Support\Facades\DB;
use PDO;

/**
 * دسترسیِ ارائه‌دهنده‌ها به اتصالِ اختصاصیِ جست‌وجو (config: global_search.connection ← sqlsrv_search، بدونِ Pooling).
 *
 * - اگر اتصال قطع شده باشد (DB::disconnect یا Lost Connection) پیش از getPdo() دوباره برقرار می‌شود؛
 *   getPdo() به‌تنهایی در این حالت null برمی‌گرداند.
 * - جست‌وجو هرگز رویِ اتصالی که تراکنشِ باز دارد اجرا نمی‌شود: Timeout (Attention) تراکنشِ باز را نمی‌بندد
 *   (اندازه‌گیری‌شده)، پس اجرا در آن حالت ممکن است وضعیتِ تراکنشِ دیگری را تحتِ تأثیر قرار دهد.
 *   تراکنشِ لاراول/PDO پیش از ارسالِ هر چیزی شناسایی می‌شود و BEGIN TRANِ خامِ T-SQL با نگهبانِ سمتِ
 *   سرور در همان Batch (بدونِ رفت‌وبرگشتِ اضافه). در هر دو حالت فقط SearchConnectionBusy پرتاب می‌شود
 *   که GlobalSearchService فقط نامِ کلاسِ آن را لاگ می‌کند و هیچ جزئیاتی در پاسخ نمی‌آید.
 */
final class SearchConnection
{
    /** کدِ خطایِ نگهبانِ سمتِ سرور (بازهٔ خطاهایِ کاربر، ≥ 50000) */
    public const SERVER_GUARD_ERROR = 51000;

    public static function name(): string
    {
        return (string) config('global_search.connection', 'sqlsrv_search');
    }

    public static function pdo(): PDO
    {
        $connection = DB::connection(self::name());
        $connection->reconnectIfMissingConnection();

        $pdo = $connection->getPdo();
        if ($connection->transactionLevel() > 0 || $pdo->inTransaction()) {
            throw new SearchConnectionBusy();
        }

        return $pdo;
    }

    /** نگهبانِ سمتِ سرور برایِ تراکنشی که خارج از لاراول/PDO (BEGIN TRANِ خام) باز شده است */
    public static function guard(string $sql): string
    {
        return 'IF @@TRANCOUNT > 0 THROW ' . self::SERVER_GUARD_ERROR . ", N'GS_SEARCH_CONNECTION_BUSY', 1;\n" . $sql;
    }
}
