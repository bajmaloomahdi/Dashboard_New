<?php

namespace App\Services\Calendar;

use Carbon\Carbon;

/**
 * محاسبهٔ وقوع‌هایِ رویدادِ تکرارشونده بر اساسِ تقویمِ شمسی.
 *
 *  - DAILY / WEEKLY: هر N روز / N هفته (هم‌روزِ هفته) — مستقل از تقویم.
 *  - MONTHLY: همان «روزِ ماهِ شمسی» هر N ماه؛ اگر ماه آن روز را ندارد (۳۱ در مهر تا اسفند، ۳۰ در اسفندِ
 *    غیرکبیسه) آخرین روزِ همان ماه. هر وقوع از شروعِ اصلیِ سری حساب می‌شود (روزِ ۳۱ بعد از ماهِ کوتاه دوباره ۳۱ است).
 *  - YEARLY: همان روز و ماهِ شمسی هر N سال؛ ۳۰ اسفند در سالِ غیرکبیسه ← ۲۹ اسفند.
 * ساعتِ شروع و مدتِ رویداد در همهٔ وقوع‌ها ثابت است. پایانِ تکرار (RecurrenceEndDate) شاملِ همان روز است.
 * شمارهٔ وقوع (index) مطلق و از شروعِ سری است، پس کلیدِ وقوع در همهٔ بازه‌ها و تقویم‌ها یکسان می‌ماند.
 */
final class JalaliRecurrence
{
    public const TYPES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /** سقفِ وقوع در یک درخواست (بازهٔ یک‌ساله با تکرارِ روزانه = ۳۶۶) */
    private const MAX_OCCURRENCES = 1000;

    /** شروعِ وقوعِ شمارهٔ $index (۰ = خودِ رویدادِ اصلی) */
    public static function occurrenceStart(Carbon $masterStart, string $type, int $interval, int $index): Carbon
    {
        $interval = max(1, $interval);
        $step = $interval * $index;

        if ($type === 'DAILY' || $type === 'WEEKLY') {
            return $masterStart->copy()->addDays($step * ($type === 'WEEKLY' ? 7 : 1));
        }
        if ($type !== 'MONTHLY' && $type !== 'YEARLY') {
            throw new \InvalidArgumentException('نوع تکرار نامعتبر: ' . $type);
        }

        [$jy, $jm, $jd] = Jalali::fromGregorian((int) $masterStart->year, (int) $masterStart->month, (int) $masterStart->day);
        if ($type === 'MONTHLY') {
            $months = ($jm - 1) + $step;
            $ny = $jy + intdiv($months, 12);
            $nm = $months % 12 + 1;
        } else {
            $ny = $jy + $step;
            $nm = $jm;
        }
        $nd = min($jd, Jalali::monthLength($ny, $nm));
        [$gy, $gm, $gd] = Jalali::toGregorian($ny, $nm, $nd);

        return $masterStart->copy()->setDate($gy, $gm, $gd);
    }

    /** اندیسِ روزِ هفتهٔ شمسی: شنبه = ۰ … جمعه = ۶ (همان jalaliWeekIndexِ فرانت) */
    public static function jalaliWeekIndex(Carbon $date): int
    {
        return ((int) $date->dayOfWeek + 1) % 7;
    }

    /** «1,3» یا [1,3] → فهرستِ مرتب و یکتایِ اندیس‌هایِ ۰…۶ (خالی = همان روزِ شروعِ سری) */
    public static function parseWeekDays(string|array|null $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);
        $days = array_values(array_unique(array_filter(
            array_map(fn ($v) => is_numeric(trim((string) $v)) ? (int) trim((string) $v) : -1, $items),
            fn ($d) => $d >= 0 && $d <= 6
        )));
        sort($days);

        return $days;
    }

    /**
     * WEEKLY با روزهایِ انتخابی (پچِ ۱۲۷): آیا $day روزِ وقوع است؟ هفته‌ها از شنبهٔ هفتهٔ شروعِ سری شمرده می‌شوند و هر N هفته
     * یک‌بار فعال‌اند. شمارهٔ وقوع = فاصلهٔ روز از شروعِ سری (پایدار؛ ۰ = خودِ رویدادِ اصلی اگر روزش انتخاب شده باشد).
     */
    private static function weekDayIndex(Carbon $masterStart, int $interval, array $weekDays, Carbon $day): ?int
    {
        $k = (int) round($masterStart->copy()->startOfDay()->diffInDays($day->copy()->startOfDay(), false));
        if ($k < 0 || ! in_array(self::jalaliWeekIndex($day), $weekDays, true)) {
            return null;
        }
        $weekNo = intdiv($k + self::jalaliWeekIndex($masterStart), 7);

        return $weekNo % max(1, $interval) === 0 ? $k : null;
    }

    /** وقوع‌هایِ هفتگیِ روزهایِ انتخابی، روز به روز از $fromDay تا $limit */
    private static function weekDaysBetween(Carbon $masterStart, int $interval, array $weekDays, int $duration, Carbon $from, Carbon $limit): array
    {
        $cursor = $from->copy()->subSeconds($duration)->startOfDay();
        if ($cursor->lt($masterStart->copy()->startOfDay())) {
            $cursor = $masterStart->copy()->startOfDay();
        }
        $result = [];
        for ($guard = 0; $guard < self::MAX_OCCURRENCES * 7 && $cursor->lte($limit); $guard++, $cursor->addDay()) {
            $k = self::weekDayIndex($masterStart, $interval, $weekDays, $cursor);
            if ($k === null) {
                continue;
            }
            $start = $cursor->copy()->setTime((int) $masterStart->hour, (int) $masterStart->minute, (int) $masterStart->second);
            if ($start->gt($limit)) {
                break;
            }
            $end = $start->copy()->addSeconds($duration);
            if ($end->gte($from)) {
                $result[] = [$k, $start, $end];
                if (count($result) >= self::MAX_OCCURRENCES) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * وقوع‌هایی که با بازهٔ [$from, $to] هم‌پوشانی دارند: [[index, start, end], ...]
     * $weekDays (پچِ ۱۲۷): فقط برایِ WEEKLY — روزهایِ هفتهٔ شمسی؛ خالی = همان روزِ شروع.
     *
     * @return list<array{0:int,1:Carbon,2:Carbon}>
     */
    public static function between(Carbon $masterStart, Carbon $masterEnd, string $type, int $interval, ?Carbon $seriesEnd, Carbon $from, Carbon $to, array $weekDays = []): array
    {
        $duration = max(0, $masterStart->diffInSeconds($masterEnd, true));
        $limit = $seriesEnd !== null && $seriesEnd->lt($to) ? $seriesEnd : $to;
        if ($type === 'WEEKLY' && $weekDays) {
            return self::weekDaysBetween($masterStart, $interval, $weekDays, (int) $duration, $from, $limit);
        }
        // پچِ ۱۲۸: روزانه/ماهانه/سالانه — روزهایِ هفته فیلتر است (وقوعی که رویِ روزِ انتخاب‌نشده بیفتد حذف می‌شود)
        if ($weekDays && count($weekDays) < 7) {
            return array_values(array_filter(
                self::between($masterStart, $masterEnd, $type, $interval, $seriesEnd, $from, $to),
                fn (array $o) => in_array(self::jalaliWeekIndex($o[1]), $weekDays, true)
            ));
        }

        // پرش به نزدیکیِ ابتدایِ بازه (رویدادِ روزانهٔ چندساله هم بی‌حلقهٔ طولانی)
        $index = self::estimateIndex($masterStart, $type, $interval, $from->copy()->subSeconds($duration));
        $result = [];
        $guard = 0;
        while ($guard++ < self::MAX_OCCURRENCES * 2) {
            $start = self::occurrenceStart($masterStart, $type, $interval, $index);
            if ($start->gt($limit)) {
                break;
            }
            $end = $start->copy()->addSeconds($duration);
            if ($end->gte($from)) {
                $result[] = [$index, $start, $end];
                if (count($result) >= self::MAX_OCCURRENCES) {
                    break;
                }
            }
            $index++;
        }

        return $result;
    }

    /**
     * شروعِ نخستین وقوع بعد از $after (یا با $orAfter بر یا بعد از آن)؛ null اگر سری تمام شده باشد.
     * $weekDays: روزهایِ هفته برایِ WEEKLY (پچِ ۱۲۷)؛ $skipStarts: شروع‌هایِ «Y-m-d H:i:s» که رد می‌شوند (وقوعِ انجام/لغو شده).
     */
    public static function next(Carbon $masterStart, string $type, int $interval, Carbon $after, ?Carbon $seriesEnd = null, bool $orAfter = false, array $weekDays = [], array $skipStarts = []): ?Carbon
    {
        // پچِ ۱۲۸: غیرِ هفتگی با روزهایِ انتخابی → وقوعِ رویِ روزِ انتخاب‌نشده رد می‌شود
        $dayFilter = $type !== 'WEEKLY' && $weekDays && count($weekDays) < 7 ? $weekDays : null;
        $accept = function (Carbon $start) use ($after, $orAfter, $skipStarts, $dayFilter): bool {
            return ($orAfter ? $start->gte($after) : $start->gt($after))
                && ! in_array($start->format('Y-m-d H:i:s'), $skipStarts, true)
                && ($dayFilter === null || in_array(self::jalaliWeekIndex($start), $dayFilter, true));
        };

        if ($type === 'WEEKLY' && $weekDays) {
            $cursor = $after->copy()->startOfDay();
            if ($cursor->lt($masterStart->copy()->startOfDay())) {
                $cursor = $masterStart->copy()->startOfDay();
            }
            for ($guard = 0; $guard < 7 * 366; $guard++, $cursor->addDay()) {
                if (self::weekDayIndex($masterStart, $interval, $weekDays, $cursor) === null) {
                    continue;
                }
                $start = $cursor->copy()->setTime((int) $masterStart->hour, (int) $masterStart->minute, (int) $masterStart->second);
                if ($seriesEnd !== null && $start->gt($seriesEnd)) {
                    return null;
                }
                if ($accept($start)) {
                    return $start;
                }
            }

            return null;
        }

        $index = self::estimateIndex($masterStart, $type, $interval, $after);
        for ($guard = 0; $guard < 1000; $guard++, $index++) {
            $start = self::occurrenceStart($masterStart, $type, $interval, $index);
            if ($seriesEnd !== null && $start->gt($seriesEnd)) {
                return null;
            }
            if ($accept($start)) {
                return $start;
            }
        }

        return null;
    }

    /** شماره‌ای که وقوعش قطعاً پیش از (یا برابرِ) $target است — نقطهٔ شروعِ جست‌وجو */
    private static function estimateIndex(Carbon $masterStart, string $type, int $interval, Carbon $target): int
    {
        if ($target->lte($masterStart)) {
            return 0;
        }
        $interval = max(1, $interval);
        $units = match ($type) {
            'DAILY'   => intdiv((int) $masterStart->diffInSeconds($target, true), 86400),
            'WEEKLY'  => intdiv((int) $masterStart->diffInSeconds($target, true), 86400 * 7),
            'MONTHLY' => self::jalaliMonthsBetween($masterStart, $target),
            'YEARLY'  => intdiv(self::jalaliMonthsBetween($masterStart, $target), 12),
            default   => throw new \InvalidArgumentException('نوع تکرار نامعتبر: ' . $type),
        };

        return max(0, intdiv($units, $interval) - 1);
    }

    private static function jalaliMonthsBetween(Carbon $a, Carbon $b): int
    {
        [$ay, $am] = Jalali::fromGregorian((int) $a->year, (int) $a->month, (int) $a->day);
        [$by, $bm] = Jalali::fromGregorian((int) $b->year, (int) $b->month, (int) $b->day);

        return max(0, ($by - $ay) * 12 + ($bm - $am));
    }
}
