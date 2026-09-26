<?php

namespace App\Services\BusinessCalendar;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * پیاده‌سازیِ BusinessCalendarService بر پایهٔ جداولِ BusinessCalendar* و
 * رویهٔ dbo.sp_BusinessCalendar_GetDefinition.
 *
 * محاسبه به‌صورتِ «روز به روز» انجام می‌شود: برای هر روز، بازه‌های کاریِ آن روز
 * از روی ساعاتِ هفتگی + استثناها ساخته می‌شود و دقیقه‌های کاری روی همین بازه‌ها
 * جمع/تفریق می‌گردد. تقویمِ ۲۴×۷ حالتِ خاصِ همین الگوریتم است
 * (EndTime = 23:59:59 به‌عنوانِ پایانِ روز = نیمه‌شبِ بعدی تفسیر می‌شود).
 */
class DbBusinessCalendarService implements BusinessCalendarService
{
    /** سقفِ ایمنیِ پیمایش (روز) تا از حلقهٔ بی‌پایان جلوگیری شود. */
    private const MAX_DAYS = 4000;

    /**
     * کشِ درون‌درخواستیِ تعریفِ تقویم‌ها.
     * ساختار: [calendarCode => ['tz'=>string, 'hours'=>[dow=>[[from,to],...]], 'exceptions'=>[y=>[Y-m-d=>row]]]]
     *
     * @var array<string,array>
     */
    private array $cache = [];

    public function addWorkingMinutes(\DateTimeInterface $startAt, int $minutes, string $calendarCode = 'DEFAULT'): \DateTimeImmutable
    {
        $cursor = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($startAt));

        if ($minutes <= 0) {
            return $cursor->toDateTimeImmutable();
        }

        $remaining = $minutes;
        $guard = 0;

        while ($guard++ < self::MAX_DAYS) {
            foreach ($this->intervalsForDate($cursor, $calendarCode) as [$intStart, $intEnd]) {
                if ($cursor->greaterThanOrEqualTo($intEnd)) {
                    continue;
                }

                $effectiveStart = $cursor->greaterThan($intStart) ? $cursor : $intStart;
                $available = $effectiveStart->diffInMinutes($intEnd, true);

                if ($available >= $remaining) {
                    return $effectiveStart->addMinutes($remaining)->toDateTimeImmutable();
                }

                $remaining -= $available;
            }

            $cursor = $cursor->addDay()->startOfDay();
        }

        throw new \RuntimeException("محاسبهٔ مهلتِ کاری از سقفِ مجاز فراتر رفت (calendar={$calendarCode}).");
    }

    public function subtractWorkingMinutes(\DateTimeInterface $fromAt, int $minutes, string $calendarCode = 'DEFAULT'): \DateTimeImmutable
    {
        $cursor = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($fromAt));

        if ($minutes <= 0) {
            return $cursor->toDateTimeImmutable();
        }

        $remaining = $minutes;
        $guard = 0;
        $day = $cursor;

        while ($guard++ < self::MAX_DAYS) {
            $intervals = array_reverse($this->intervalsForDate($day, $calendarCode));

            foreach ($intervals as [$intStart, $intEnd]) {
                // مرزِ بالای بازه در همین روز؛ در روزهای قبل، کل بازه در دسترس است
                $upper = ($day->isSameDay($cursor) && $cursor->lessThan($intEnd)) ? $cursor : $intEnd;

                if ($upper->lessThanOrEqualTo($intStart)) {
                    continue;
                }

                $available = $intStart->diffInMinutes($upper, true);

                if ($available >= $remaining) {
                    return $upper->subMinutes($remaining)->toDateTimeImmutable();
                }

                $remaining -= $available;
            }

            $day = $day->subDay()->startOfDay();
        }

        throw new \RuntimeException("محاسبهٔ زمانِ کاری به عقب از سقفِ مجاز فراتر رفت (calendar={$calendarCode}).");
    }

    public function isWorkingTime(\DateTimeInterface $at, string $calendarCode = 'DEFAULT'): bool
    {
        $moment = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($at));

        foreach ($this->intervalsForDate($moment, $calendarCode) as [$intStart, $intEnd]) {
            if ($moment->greaterThanOrEqualTo($intStart) && $moment->lessThan($intEnd)) {
                return true;
            }
        }

        return false;
    }

    public function nextWorkingMoment(\DateTimeInterface $at, string $calendarCode = 'DEFAULT'): \DateTimeImmutable
    {
        $moment = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($at));
        $day = $moment;
        $guard = 0;

        while ($guard++ < self::MAX_DAYS) {
            foreach ($this->intervalsForDate($day, $calendarCode) as [$intStart, $intEnd]) {
                if ($moment->lessThan($intStart)) {
                    return $intStart->toDateTimeImmutable();
                }
                if ($moment->greaterThanOrEqualTo($intStart) && $moment->lessThan($intEnd)) {
                    return $moment->toDateTimeImmutable();
                }
            }

            $day = $day->addDay()->startOfDay();
        }

        throw new \RuntimeException("هیچ لحظهٔ کاری‌ای در بازهٔ مجاز یافت نشد (calendar={$calendarCode}).");
    }

    public function workingMinutesBetween(\DateTimeInterface $from, \DateTimeInterface $to, string $calendarCode = 'DEFAULT'): int
    {
        $start = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($from));
        $end = CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($to));

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $total = 0;
        $day = $start->startOfDay();
        $guard = 0;

        while ($day->lessThan($end) && $guard++ < self::MAX_DAYS) {
            foreach ($this->intervalsForDate($day, $calendarCode) as [$intStart, $intEnd]) {
                $lo = $intStart->greaterThan($start) ? $intStart : $start;
                $hi = $intEnd->lessThan($end) ? $intEnd : $end;

                if ($hi->greaterThan($lo)) {
                    $total += $lo->diffInMinutes($hi, true);
                }
            }

            $day = $day->addDay()->startOfDay();
        }

        return (int) round($total);
    }

    /* ================================================================== */

    /**
     * بازه‌های کاریِ یک روزِ مشخص به‌صورتِ آرایه‌ای از [CarbonImmutable $start, CarbonImmutable $end).
     *
     * @return array<int,array{0:CarbonImmutable,1:CarbonImmutable}>
     */
    private function intervalsForDate(CarbonImmutable $date, string $calendarCode): array
    {
        $cal = $this->loadCalendar($calendarCode);
        $dayStart = $date->startOfDay();
        $ymd = $dayStart->format('Y-m-d');

        // استثناها اولویت دارند
        $exception = $this->exceptionForDate($calendarCode, $dayStart);

        if ($exception !== null) {
            $type = $exception->ExceptionType;

            if ($type === 'HOLIDAY' || $type === 'NON_WORKING') {
                return [];
            }

            if ($type === 'WORKING_OVERRIDE') {
                $s = $exception->OverrideStartTime;
                $e = $exception->OverrideEndTime;
                if ($s === null || $e === null) {
                    return [];
                }

                return $this->buildInterval($dayStart, $this->timeString($s), $this->timeString($e));
            }
        }

        // ساعاتِ هفتگی
        $dow = $this->persianDow($dayStart);
        $rows = $cal['hours'][$dow] ?? [];
        $intervals = [];

        foreach ($rows as [$fromTime, $toTime]) {
            foreach ($this->buildInterval($dayStart, $fromTime, $toTime) as $iv) {
                $intervals[] = $iv;
            }
        }

        return $intervals;
    }

    /**
     * ساختِ یک بازهٔ زمانی از رشتهٔ ساعت. EndTime برابرِ 23:59:xx به‌عنوانِ پایانِ روز
     * (نیمه‌شبِ روزِ بعد) تفسیر می‌شود تا تقویمِ ۲۴×۷ دقیقاً ۱۴۴۰ دقیقه شود.
     *
     * @return array<int,array{0:CarbonImmutable,1:CarbonImmutable}>
     */
    private function buildInterval(CarbonImmutable $dayStart, string $fromTime, string $toTime): array
    {
        [$fh, $fm, $fs] = array_pad(array_map('intval', explode(':', $fromTime)), 3, 0);
        [$th, $tm, $ts] = array_pad(array_map('intval', explode(':', $toTime)), 3, 0);

        $start = $dayStart->setTime($fh, $fm, $fs);

        if ($th === 23 && $tm === 59) {
            $end = $dayStart->addDay()->startOfDay();
        } else {
            $end = $dayStart->setTime($th, $tm, $ts);
        }

        if ($end->lessThanOrEqualTo($start)) {
            return [];
        }

        return [[$start, $end]];
    }

    private function timeString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        return substr((string) $value, 0, 8);
    }

    /** نگاشتِ روزِ هفتهٔ PHP (0=یکشنبه) به اسکیمای پروژه (0=شنبه..6=جمعه). */
    private function persianDow(CarbonImmutable $date): int
    {
        return ((int) $date->format('w') + 1) % 7;
    }

    private function loadCalendar(string $calendarCode): array
    {
        if (isset($this->cache[$calendarCode])) {
            return $this->cache[$calendarCode];
        }

        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare('EXEC dbo.sp_BusinessCalendar_GetDefinition @Code = :code, @FromDate = NULL, @ToDate = NULL');
        $stmt->execute(['code' => $calendarCode === 'DEFAULT' ? null : $calendarCode]);

        $header = $stmt->fetchAll(\PDO::FETCH_OBJ);
        if (empty($header)) {
            throw new \InvalidArgumentException("تقویمِ کاری با کد «{$calendarCode}» یافت نشد.");
        }

        $stmt->nextRowset();
        $hoursRows = $stmt->fetchAll(\PDO::FETCH_OBJ);

        $hours = [];
        foreach ($hoursRows as $row) {
            if (! (int) $row->IsWorkingDay) {
                continue;
            }
            if ($row->StartTime === null || $row->EndTime === null) {
                continue;
            }
            $hours[(int) $row->DayOfWeek][] = [
                $this->timeString($row->StartTime),
                $this->timeString($row->EndTime),
            ];
        }

        $this->cache[$calendarCode] = [
            'tz'         => $header[0]->TimeZone ?? 'Asia/Tehran',
            'hours'      => $hours,
            'exceptions' => [], // بارگذاریِ تنبل، سال‌به‌سال
        ];

        return $this->cache[$calendarCode];
    }

    private function exceptionForDate(string $calendarCode, CarbonImmutable $date): ?object
    {
        $this->loadCalendar($calendarCode);
        $year = (int) $date->format('Y');

        if (! array_key_exists($year, $this->cache[$calendarCode]['exceptions'])) {
            // نتیجهٔ سومِ رویه (استثناها) فقط از طریقِ PDO چندنتیجه‌ای قابلِ دسترسی است
            $pdo = DB::connection()->getPdo();
            $stmt = $pdo->prepare('EXEC dbo.sp_BusinessCalendar_GetDefinition @Code = :code, @FromDate = :f, @ToDate = :t');
            $stmt->execute([
                'code' => $calendarCode === 'DEFAULT' ? null : $calendarCode,
                'f'    => "$year-01-01",
                't'    => "$year-12-31",
            ]);
            $stmt->nextRowset(); // رد کردنِ نتیجهٔ ۱ (تقویم)
            $stmt->nextRowset(); // رد کردنِ نتیجهٔ ۲ (ساعاتِ هفتگی)
            $exRows = $stmt->fetchAll(\PDO::FETCH_OBJ);

            $byDate = [];
            foreach ($exRows as $ex) {
                $key = $ex->ExceptionDate instanceof \DateTimeInterface
                    ? $ex->ExceptionDate->format('Y-m-d')
                    : substr((string) $ex->ExceptionDate, 0, 10);
                $byDate[$key] = $ex;
            }

            $this->cache[$calendarCode]['exceptions'][$year] = $byDate;
        }

        return $this->cache[$calendarCode]['exceptions'][$year][$date->format('Y-m-d')] ?? null;
    }
}
