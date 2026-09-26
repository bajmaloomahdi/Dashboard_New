<?php

namespace App\Services\Crm;

use App\Services\Crm\Exceptions\CrmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * پیدا کردنِ اولیهٔ محل در فرمِ آدرسِ طرف‌حساب با وب‌سرویسِ رسمیِ «تبدیل آدرس به نقطه» نِشان
 * (Geocoding v1؛ یا Geocoding Plus وقتی NESHAN_GEOCODING_PLUS=true و سرویسِ پلاس رویِ کلید فعال باشد —
 * قالبِ درخواست/پاسخ یکسان است). تا ۵ نامزد برمی‌گرداند؛ نامزدهایِ تقریباً هم‌مکان حذف می‌شوند.
 *
 * - کلیدِ Service (جدا از Map Key) فقط سمتِ سرور و از .env (NESHAN_SERVICE_KEY)؛ هرگز به مرورگر نمی‌رود.
 * - مرکزِ نقشه (location) عمداً ارسال نمی‌شود: در تست‌هایِ واقعی نتایج را به سمتِ مرکز منحرف می‌کرد؛
 *   به‌جایِ آن نامِ شهرِ انتخاب‌شده در فرمِ CRM ارسال می‌شود.
 * - هیچ داده‌ای از نشان در CRM ذخیره نمی‌شود؛ فقط مختصاتی که کاربر انتخاب و ذخیره می‌کند.
 */
class NeshanLocationSearch
{
    private const ENDPOINT = 'https://api.neshan.org/geocoding/v1';
    private const ENDPOINT_PLUS = 'https://api.neshan.org/geocoding/v1/plus';

    /** نامزدهایِ هم‌ناحیه که کمتر از این فاصله (متر) از نامزدِ بالاتر دارند تکراری حساب می‌شوند */
    private const DEDUPE_METERS = 150;

    /** پیام‌هایِ فارسی برایِ کدهایِ خطایِ مستندِ نشان */
    private const ERRORS = [
        400 => 'عبارتِ جست‌وجو معتبر نیست.',
        470 => 'درخواست به سرویسِ نشان ناقص یا نامعتبر بود.',
        480 => 'کلیدِ سرویسِ نشان نامعتبر است.',
        481 => 'سهمیهٔ ماهانهٔ سرویسِ نشان تمام شده است.',
        482 => 'تعدادِ درخواست‌ها به سرویسِ نشان زیاد است؛ کمی بعد دوباره تلاش کنید.',
        483 => 'نوعِ کلیدِ نشان برایِ این سرویس مناسب نیست (کلیدِ Service لازم است، نه کلیدِ نقشه).',
        484 => 'این سرور/دامنه در فهرستِ مجازِ کلیدِ نشان نیست.',
        485 => 'سرویسِ «تبدیل آدرس به نقطه» برایِ این کلیدِ نشان فعال نشده است.',
    ];

    public function isConfigured(): bool
    {
        return (string) config('services.neshan.service_key') !== '';
    }

    public function usesPlus(): bool
    {
        return filter_var(config('services.neshan.geocoding_plus'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<int, array{title: string, address: ?string, region: ?string, neighbourhood: ?string, unmatched: ?string, latitude: float, longitude: float}>
     */
    public function search(string $term, ?string $city = null): array
    {
        if (! $this->isConfigured()) {
            throw new CrmException('کلیدِ سرویسِ نشان (NESHAN_SERVICE_KEY) تنظیم نشده است.');
        }

        $payload = ['address' => $term];
        $city = $this->clean($city);
        if ($city !== null) {
            $payload['city'] = $city;
        }

        try {
            $response = Http::withHeaders(['Api-Key' => (string) config('services.neshan.service_key')])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout(8)
                ->get($this->usesPlus() ? self::ENDPOINT_PLUS : self::ENDPOINT, ['json' => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        } catch (ConnectionException) {
            throw new CrmException('ارتباط با سرویسِ نشان برقرار نشد.');
        }

        if (! $response->successful()) {
            throw new CrmException(self::ERRORS[$response->status()] ?? 'سرویسِ نشان خطا داد.');
        }

        $results = [];
        foreach ($response->json('items') ?? [] as $item) {
            $lat = $item['location']['latitude'] ?? null;
            $lng = $item['location']['longitude'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                continue;
            }
            $candidate = [
                'province' => $this->clean($item['province'] ?? null),
                'city' => $this->clean($item['city'] ?? null),
                'neighbourhood' => $this->clean($item['neighbourhood'] ?? null),
                'unmatched' => $this->clean($item['unMatchedTerm'] ?? null),
                'latitude' => round((float) $lat, 6),
                'longitude' => round((float) $lng, 6),
            ];
            if (! $this->isDuplicate($candidate, $results)) {
                $results[] = $candidate;
            }
        }

        // عنوان: محله، وگرنه شهر، وگرنه همان عبارت؛ توضیح: بقیهٔ سطوح بدونِ تکرارِ عنوان (مثلاً نه «تهران، تهران»)
        return array_map(fn ($r) => [
            'title' => $r['neighbourhood'] !== null ? "محلهٔ {$r['neighbourhood']}" : ($r['city'] ?? $term),
            'address' => implode('، ', array_unique(array_filter(
                $r['neighbourhood'] !== null ? [$r['city'], $r['province']] : [$r['province']],
                fn ($v) => $v !== null && $v !== ($r['neighbourhood'] ?? $r['city']),
            ))) ?: null,
            'region' => $r['province'],
            'neighbourhood' => $r['neighbourhood'],
            'unmatched' => $r['unmatched'],
            'latitude' => $r['latitude'],
            'longitude' => $r['longitude'],
        ], $results);
    }

    /** نشان گاهی مقدارِ «nan» یا خالی برمی‌گرداند */
    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return ($value === '' || strtolower($value) === 'nan') ? null : $value;
    }

    /** همان استان/شهر/محله و فاصلهٔ کمتر از DEDUPE_METERS از یک نامزدِ قبلی (اولویتِ بالاتر) = تکراری */
    private function isDuplicate(array $candidate, array $kept): bool
    {
        foreach ($kept as $k) {
            if ($k['province'] === $candidate['province'] && $k['city'] === $candidate['city'] && $k['neighbourhood'] === $candidate['neighbourhood']
                && $this->distanceMeters($k['latitude'], $k['longitude'], $candidate['latitude'], $candidate['longitude']) < self::DEDUPE_METERS) {
                return true;
            }
        }

        return false;
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }
}
