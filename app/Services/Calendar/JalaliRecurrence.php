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

    /**
     * وقوع‌هایی که با بازهٔ [$from, $to] هم‌پوشانی دارند: [[index, start, end], ...]
     *
     * @return list<array{0:int,1:Carbon,2:Carbon}>
     */
    public static function between(Carbon $masterStart, Carbon $masterEnd, string $type, int $interval, ?Carbon $seriesEnd, Carbon $from, Carbon $to): array
    {
        $duration = max(0, $masterStart->diffInSeconds($masterEnd, true));
        $limit = $seriesEnd !== null && $seriesEnd->lt($to) ? $seriesEnd : $to;

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

    /** شروعِ نخستین وقوع بعد از $after (یا با $orAfter بر یا بعد از آن)؛ null اگر سری تمام شده باشد */
    public static function next(Carbon $masterStart, string $type, int $interval, Carbon $after, ?Carbon $seriesEnd = null, bool $orAfter = false): ?Carbon
    {
        $index = self::estimateIndex($masterStart, $type, $interval, $after);
        for ($guard = 0; $guard < 1000; $guard++, $index++) {
            $start = self::occurrenceStart($masterStart, $type, $interval, $index);
            if ($seriesEnd !== null && $start->gt($seriesEnd)) {
                return null;
            }
            if ($orAfter ? $start->gte($after) : $start->gt($after)) {
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
