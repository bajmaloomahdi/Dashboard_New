<?php

namespace Tests\Unit;

use App\Services\Calendar\Jalali;
use App\Services\Calendar\JalaliRecurrence;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/** تبدیلِ شمسی و قواعدِ تکرارِ شمسی (بدونِ دیتابیس) */
class JalaliRecurrenceTest extends TestCase
{
    private function j(Carbon $d): string
    {
        return implode('/', Jalali::fromGregorian($d->year, $d->month, $d->day));
    }

    public function test_known_conversions_and_leap_years(): void
    {
        $this->assertSame([2024, 3, 20], Jalali::toGregorian(1403, 1, 1));
        $this->assertSame([2025, 3, 20], Jalali::toGregorian(1403, 12, 30)); // ۱۴۰۳ کبیسه
        $this->assertSame([2025, 3, 21], Jalali::toGregorian(1404, 1, 1));
        $this->assertSame([2026, 3, 20], Jalali::toGregorian(1404, 12, 29));
        $this->assertSame([1404, 7, 15], Jalali::fromGregorian(2025, 10, 7));
        $this->assertTrue(Jalali::isLeapYear(1403));
        $this->assertFalse(Jalali::isLeapYear(1404));
        $this->assertTrue(Jalali::isLeapYear(1408));
        $this->assertSame([31, 30, 29, 30], [Jalali::monthLength(1404, 6), Jalali::monthLength(1404, 7), Jalali::monthLength(1404, 12), Jalali::monthLength(1403, 12)]);

        // رفت‌وبرگشتِ همهٔ روزهایِ ۱۴۰۰ تا ۱۴۲۰
        for ($jy = 1400; $jy <= 1420; $jy++) {
            for ($jm = 1; $jm <= 12; $jm++) {
                for ($jd = 1; $jd <= Jalali::monthLength($jy, $jm); $jd++) {
                    $this->assertSame([$jy, $jm, $jd], Jalali::fromGregorian(...Jalali::toGregorian($jy, $jm, $jd)));
                }
            }
        }
    }

    public function test_monthly_keeps_jalali_day_and_clamps_to_month_end(): void
    {
        $master = Carbon::parse('2025-09-22 09:30:00'); // ۳۱ شهریور ۱۴۰۴
        $got = array_map(fn ($i) => $this->j(JalaliRecurrence::occurrenceStart($master, 'MONTHLY', 1, $i)), range(0, 7));
        $this->assertSame(['1404/6/31', '1404/7/30', '1404/8/30', '1404/9/30', '1404/10/30', '1404/11/30', '1404/12/29', '1405/1/31'], $got);
        $this->assertSame('09:30:00', JalaliRecurrence::occurrenceStart($master, 'MONTHLY', 1, 5)->format('H:i:s'));

        // ۱۵ مهر ← ۱۵ آبان (نه ۷ نوامبر = ۱۶ آبان مثلِ تکرارِ میلادیِ قبلی)
        $this->assertSame('2025-11-06', JalaliRecurrence::occurrenceStart(Carbon::parse('2025-10-07 10:00:00'), 'MONTHLY', 1, 1)->toDateString());
        // هر ۳ ماه
        $this->assertSame(['1404/1/10', '1404/4/10', '1404/7/10', '1404/10/10', '1405/1/10'],
            array_map(fn ($i) => $this->j(JalaliRecurrence::occurrenceStart(Carbon::parse('2025-03-30 08:00:00'), 'MONTHLY', 3, $i)), range(0, 4)));
    }

    public function test_yearly_handles_esfand_30_in_non_leap_years(): void
    {
        $master = Carbon::parse('2025-03-20 08:00:00'); // ۳۰ اسفند ۱۴۰۳
        $got = array_map(fn ($i) => $this->j(JalaliRecurrence::occurrenceStart($master, 'YEARLY', 1, $i)), range(0, 5));
        $this->assertSame(['1403/12/30', '1404/12/29', '1405/12/29', '1406/12/29', '1407/12/29', '1408/12/30'], $got);
        // نوروز همیشه ۱ فروردین (میلادی ۲۰ یا ۲۱ مارس)
        $nowruz = Carbon::parse('2024-03-20 00:00:00');
        $this->assertSame(['2024-03-20', '2025-03-21', '2026-03-21', '2027-03-21', '2028-03-20'],
            array_map(fn ($i) => JalaliRecurrence::occurrenceStart($nowruz, 'YEARLY', 1, $i)->toDateString(), range(0, 4)));
    }

    public function test_daily_and_weekly_are_calendar_independent(): void
    {
        $master = Carbon::parse('2025-03-19 18:00:00');
        $this->assertSame('2025-03-22 18:00:00', JalaliRecurrence::occurrenceStart($master, 'DAILY', 3, 1)->toDateTimeString());
        $this->assertSame('2025-04-02 18:00:00', JalaliRecurrence::occurrenceStart($master, 'WEEKLY', 2, 1)->toDateTimeString());
    }

    public function test_between_handles_old_series_range_overlap_and_series_end(): void
    {
        // رویدادِ روزانه از چند سال پیش در بازهٔ امروز دیده می‌شود (قبلاً پس از ۱۰۰۰ تکرار قطع می‌شد)
        $master = Carbon::parse('2021-01-01 09:00:00');
        $occ = JalaliRecurrence::between($master, $master->copy()->addHour(), 'DAILY', 1, null, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07 23:59:59'));
        $this->assertCount(7, $occ);
        $this->assertSame([(int) $master->diffInDays(Carbon::parse('2026-06-01 09:00:00')), '2026-06-01 09:00:00'], [$occ[0][0], $occ[0][1]->toDateTimeString()]);

        // رویدادِ چندروزه که پیش از بازه شروع و در بازه تمام می‌شود
        $multi = Carbon::parse('2026-01-01 20:00:00');
        $occ = JalaliRecurrence::between($multi, $multi->copy()->addDays(2), 'WEEKLY', 1, null, Carbon::parse('2026-01-09'), Carbon::parse('2026-01-09 23:59:59'));
        $this->assertSame(['2026-01-08 20:00:00'], array_map(fn ($o) => $o[1]->toDateTimeString(), $occ));

        // پایانِ تکرار شاملِ همان روز
        $occ = JalaliRecurrence::between(Carbon::parse('2026-01-01 10:00:00'), Carbon::parse('2026-01-01 11:00:00'), 'WEEKLY', 1,
            Carbon::parse('2026-01-15')->endOfDay(), Carbon::parse('2026-01-01'), Carbon::parse('2026-03-01'));
        $this->assertSame(['2026-01-01', '2026-01-08', '2026-01-15'], array_map(fn ($o) => $o[1]->toDateString(), $occ));
    }

    public function test_next_occurrence(): void
    {
        $master = Carbon::parse('2025-09-22 09:30:00'); // ۳۱ شهریور
        $this->assertSame('2026-03-20 09:30:00', JalaliRecurrence::next($master, 'MONTHLY', 1, Carbon::parse('2026-02-19 09:30:00'))->toDateTimeString());
        $this->assertSame('2026-02-19 09:30:00', JalaliRecurrence::next($master, 'MONTHLY', 1, Carbon::parse('2026-02-19 09:30:00'), null, true)->toDateTimeString());
        $this->assertNull(JalaliRecurrence::next($master, 'MONTHLY', 1, Carbon::parse('2026-02-19 09:30:00'), Carbon::parse('2026-03-01')->endOfDay()));
        $this->assertSame('2030-03-20 08:00:00', JalaliRecurrence::next(Carbon::parse('2025-03-20 08:00:00'), 'YEARLY', 1, Carbon::parse('2029-03-20'))->toDateTimeString());
    }
}
