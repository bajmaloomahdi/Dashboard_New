<?php

namespace Tests\Unit;

use App\Services\BusinessCalendar\BusinessCalendarService;
use Tests\TestCase;

/**
 * تستِ ماژولِ تقویمِ کاری روی تقویمِ پیش‌فرضِ ۲۴×۷ (کد DEFAULT).
 * در این تقویم، رفتارِ همهٔ متدها باید با «ساعتِ دیواری» یکی باشد.
 */
class BusinessCalendarServiceTest extends TestCase
{
    private BusinessCalendarService $cal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cal = $this->app->make(BusinessCalendarService::class);
    }

    public function test_add_working_minutes_is_wall_clock_on_24x7(): void
    {
        $start = new \DateTimeImmutable('2026-03-10 10:00:00');
        $out = $this->cal->addWorkingMinutes($start, 120);

        $this->assertSame('2026-03-10 12:00:00', $out->format('Y-m-d H:i:s'));
    }

    public function test_add_working_minutes_crosses_midnight(): void
    {
        $start = new \DateTimeImmutable('2026-03-10 23:00:00');
        $out = $this->cal->addWorkingMinutes($start, 120);

        $this->assertSame('2026-03-11 01:00:00', $out->format('Y-m-d H:i:s'));
    }

    public function test_add_zero_minutes_returns_same_instant(): void
    {
        $start = new \DateTimeImmutable('2026-03-10 08:30:00');
        $this->assertSame(
            $start->format('Y-m-d H:i:s'),
            $this->cal->addWorkingMinutes($start, 0)->format('Y-m-d H:i:s')
        );
    }

    public function test_subtract_working_minutes_is_inverse_of_add(): void
    {
        $from = new \DateTimeImmutable('2026-03-11 01:00:00');
        $out = $this->cal->subtractWorkingMinutes($from, 120);

        $this->assertSame('2026-03-10 23:00:00', $out->format('Y-m-d H:i:s'));
    }

    public function test_is_working_time_always_true_on_24x7(): void
    {
        $this->assertTrue($this->cal->isWorkingTime(new \DateTimeImmutable('2026-03-13 03:15:00'))); // جمعه، نیمه‌شب
        $this->assertTrue($this->cal->isWorkingTime(new \DateTimeImmutable('2026-03-10 12:00:00')));
    }

    public function test_next_working_moment_is_identity_on_24x7(): void
    {
        $at = new \DateTimeImmutable('2026-03-13 04:00:00');
        $this->assertSame(
            $at->format('Y-m-d H:i:s'),
            $this->cal->nextWorkingMoment($at)->format('Y-m-d H:i:s')
        );
    }

    public function test_working_minutes_between_same_day(): void
    {
        $from = new \DateTimeImmutable('2026-03-10 09:00:00');
        $to = new \DateTimeImmutable('2026-03-10 17:00:00');

        $this->assertSame(480, $this->cal->workingMinutesBetween($from, $to));
    }

    public function test_working_minutes_between_across_two_days(): void
    {
        $from = new \DateTimeImmutable('2026-03-10 23:00:00');
        $to = new \DateTimeImmutable('2026-03-11 01:00:00');

        $this->assertSame(120, $this->cal->workingMinutesBetween($from, $to));
    }

    public function test_working_minutes_between_returns_zero_when_reversed(): void
    {
        $from = new \DateTimeImmutable('2026-03-11 01:00:00');
        $to = new \DateTimeImmutable('2026-03-10 23:00:00');

        $this->assertSame(0, $this->cal->workingMinutesBetween($from, $to));
    }

    public function test_unknown_calendar_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cal->isWorkingTime(new \DateTimeImmutable('2026-03-10 10:00:00'), 'NO_SUCH_CAL');
    }
}
