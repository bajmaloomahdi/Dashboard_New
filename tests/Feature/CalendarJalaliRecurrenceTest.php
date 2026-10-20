<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Calendar\Jalali;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * تکرارِ شمسیِ رویدادها (فازِ دوم، بخشِ ۴) از مسیرِ واقعیِ API: تقویمِ خود، تقویمِ مشترک (READ/FREEBUSY)،
 * تقویمِ واحد، یادآوری و اعتبارسنجیِ پایانِ تکرار. همه با DatabaseTransactions برمی‌گردد.
 */
class CalendarJalaliRecurrenceTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE'];

    private function user(array $perms = self::CAL): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    private function event(int $as, array $data): int
    {
        return (int) $this->actingAs(User::find($as))->postJson('/calendar/events', $data + [
            'Title' => 'جلسهٔ ماهانه', 'StartDateTime' => '2025-09-22 09:30:00', 'EndDateTime' => '2025-09-22 10:30:00',
        ])->assertOk()->json('eventId');
    }

    /** @return list<string> تاریخِ شمسیِ شروعِ وقوع‌ها */
    private function jalaliStarts(array $events): array
    {
        return array_map(function ($e) {
            $d = Carbon::parse($e['StartDateTime']);

            return implode('/', Jalali::fromGregorian($d->year, $d->month, $d->day)) . ' ' . $d->format('H:i');
        }, $events);
    }

    private const MONTHLY_31 = ['1404/6/31 09:30', '1404/7/30 09:30', '1404/8/30 09:30', '1404/9/30 09:30', '1404/10/30 09:30', '1404/11/30 09:30', '1404/12/29 09:30', '1405/1/31 09:30'];

    public function test_monthly_event_follows_jalali_months_in_own_and_shared_calendars(): void
    {
        $owner = $this->user();
        $reader = $this->user(['CALENDAR_VIEW']);
        $busy = $this->user(['CALENDAR_VIEW']);
        $eventId = $this->event($owner, ['RecurrenceType' => 'MONTHLY', 'RecurrenceInterval' => 1]);
        DB::insert("INSERT INTO dbo.CalendarShares (OwnerUserID, SharedWithUserID, AccessLevel) VALUES (?, ?, N'READ'), (?, ?, N'FREEBUSY')", [$owner, $reader, $owner, $busy]);

        $q = '/calendar/events?from=2025-09-01&to=2026-04-25';
        $own = $this->actingAs(User::find($owner))->getJson($q)->assertOk()->json('events');
        $this->assertSame(self::MONTHLY_31, $this->jalaliStarts($own));
        $this->assertSame(["{$eventId}", "{$eventId}_1", "{$eventId}_7"], [$own[0]['OccurrenceKey'], $own[1]['OccurrenceKey'], $own[7]['OccurrenceKey']]);

        // READ: همان وقوع‌ها با همان کلید؛ FREEBUSY: همان بازه‌ها فقط «مشغول»
        $read = $this->actingAs(User::find($reader))->getJson("{$q}&userId={$owner}")->assertOk()->json('events');
        $this->assertSame(self::MONTHLY_31, $this->jalaliStarts($read));
        $this->assertSame(array_column($own, 'OccurrenceKey'), array_column($read, 'OccurrenceKey'));
        $fb = $this->actingAs(User::find($busy))->getJson("{$q}&userId={$owner}")->assertOk()->json('events');
        $this->assertSame(self::MONTHLY_31, $this->jalaliStarts($fb));
        $this->assertSame([true], array_values(array_unique(array_column($fb, 'IsBusyOnly'))));

        // بازهٔ کوچک (یک ماه) همان وقوعِ درست را می‌دهد
        $one = $this->actingAs(User::find($owner))->getJson('/calendar/events?from=2026-03-15&to=2026-03-25')->json('events');
        $this->assertSame(['1404/12/29 09:30'], $this->jalaliStarts($one));
        $this->assertSame("{$eventId}_6", $one[0]['OccurrenceKey']);
    }

    public function test_yearly_esfand_30_and_unit_calendar(): void
    {
        $code = 'TU' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert('INSERT INTO dbo.OrganizationalUnits (UnitCode, UnitName, ParentUnitID, IsActive) VALUES (?, ?, NULL, 1)', [$code, 'واحدِ آزمون']);
        $unit = (int) DB::table('OrganizationalUnits')->where('UnitCode', $code)->value('UnitID');
        $manager = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $member = $this->user(['CALENDAR_VIEW']);
        foreach ([$manager, $member] as $u) {
            $pc = 'TP' . strtoupper(bin2hex(random_bytes(4)));
            DB::insert('INSERT INTO dbo.Positions (PositionCode, PositionName, UnitID, IsActive) VALUES (?, ?, ?, 1)', [$pc, 'سمت', $unit]);
            $pid = (int) DB::table('Positions')->where('PositionCode', $pc)->value('PositionID');
            DB::insert('INSERT INTO dbo.UserPositions (UserID, UnitID, PositionID, StartDate, EndDate, IsActive) VALUES (?, ?, ?, SYSDATETIME(), NULL, 1)', [$u, $unit, $pid]);
        }
        $this->event($manager, [
            'Title' => 'جشنِ پایانِ سال', 'StartDateTime' => '2025-03-20 08:00:00', 'EndDateTime' => '2025-03-20 09:00:00', // ۳۰ اسفند ۱۴۰۳
            'RecurrenceType' => 'YEARLY', 'RecurrenceInterval' => 1, 'RecurrenceEndDate' => '2030-12-31', 'OrganizationalUnitID' => $unit,
        ]);

        $events = $this->actingAs(User::find($member))->getJson("/calendar/units/{$unit}/events?from=2025-01-01&to=2032-01-01")->assertOk()->json('events');
        $this->assertSame(['1403/12/30 08:00', '1404/12/29 08:00', '1405/12/29 08:00', '1406/12/29 08:00', '1407/12/29 08:00', '1408/12/30 08:00'], $this->jalaliStarts($events));
    }

    public function test_daily_series_started_years_ago_still_appears(): void
    {
        $owner = $this->user();
        $id = $this->event($owner, ['Title' => 'روزانه', 'StartDateTime' => '2021-01-01 09:00:00', 'EndDateTime' => '2021-01-01 09:15:00', 'RecurrenceType' => 'DAILY', 'RecurrenceInterval' => 1]);

        $events = $this->actingAs(User::find($owner))->getJson('/calendar/events?from=2026-06-01&to=2026-06-07')->assertOk()->json('events');
        $this->assertCount(7, $events);
        $index = (int) Carbon::parse('2021-01-01')->diffInDays(Carbon::parse('2026-06-01'));
        $this->assertSame(["{$id}_{$index}", '2026-06-01 09:00:00'], [$events[0]['OccurrenceKey'], substr($events[0]['StartDateTime'], 0, 19)]);
    }

    public function test_reminder_anchor_uses_next_jalali_occurrence(): void
    {
        $owner = $this->user();
        $now = Carbon::parse(DB::selectOne('SELECT CONVERT(varchar(19), SYSDATETIME(), 120) AS n')->n);
        // شروعِ سری: روزِ ۳۱ِ شهریورِ سالِ شمسیِ گذشته، ساعتِ ۰۹:۳۰
        [$jy] = Jalali::fromGregorian($now->year, $now->month, $now->day);
        [$gy, $gm, $gd] = Jalali::toGregorian($jy - 1, 6, 31);
        $master = Carbon::create($gy, $gm, $gd, 9, 30);
        $id = $this->event($owner, ['StartDateTime' => $master->toDateTimeString(), 'EndDateTime' => $master->copy()->addHour()->toDateTimeString(),
            'RecurrenceType' => 'MONTHLY', 'RecurrenceInterval' => 1, 'ReminderOffsetMinutes' => 15]);

        // پیش‌بینیِ مستقل: ماه به ماه، روز = min(31, طولِ ماه)
        $expected = null;
        for ($k = 0; $k < 40 && $expected === null; $k++) {
            $m = 5 + $k; // شهریور = ماهِ ششم (اندیسِ ۵)
            $y = $jy - 1 + intdiv($m, 12);
            $mm = $m % 12 + 1;
            [$a, $b, $c] = Jalali::toGregorian($y, $mm, min(31, Jalali::monthLength($y, $mm)));
            $cand = Carbon::create($a, $b, $c, 9, 30);
            if ($cand->gte($now)) {
                $expected = $cand;
            }
        }
        $remindAt = (string) DB::table('Reminders')->where('EntityType', 'CALENDAR_EVENT')->where('EntityID', $id)->where('UserID', $owner)->value('RemindAt');
        $this->assertSame($expected->copy()->subMinutes(15)->toDateTimeString(), substr($remindAt, 0, 19));
    }

    public function test_recurrence_end_before_start_is_rejected(): void
    {
        $owner = $this->user();
        $base = ['Title' => 'x', 'StartDateTime' => '2099-05-10 10:00:00', 'EndDateTime' => '2099-05-10 11:00:00', 'RecurrenceType' => 'WEEKLY'];
        $this->actingAs(User::find($owner))->postJson('/calendar/events', $base + ['RecurrenceEndDate' => '2099-05-09'])
            ->assertStatus(422)->assertJson(['message' => 'پایانِ تکرار نمی‌تواند پیش از تاریخِ شروعِ رویداد باشد.']);
        $id = $this->event($owner, $base + ['RecurrenceEndDate' => '2099-05-10']); // همان روز مجاز (یک وقوع)
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$id}", $base + ['RecurrenceEndDate' => '2099-01-01'])->assertStatus(422);
        $this->assertCount(1, $this->actingAs(User::find($owner))->getJson('/calendar/events?from=2099-05-01&to=2099-06-30')->json('events'));
    }
}
