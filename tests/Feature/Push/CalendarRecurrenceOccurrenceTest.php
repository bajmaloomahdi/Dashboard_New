<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Services\Calendar\JalaliRecurrence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * پچِ ۱۲۷ — (۱) تکرارِ هفتگی با روزهایِ انتخابی (مثلاً دوشنبه و چهارشنبه)؛ (۲) وضعیتِ هر وقوعِ رویدادِ تکرارشونده جداست:
 * «انجام شده»ِ وقوعِ اول بقیه را عوض نمی‌کند و یادآوریِ همان وقوع به وقوعِ بعدی می‌رود؛ «همهٔ تکرارها» وضعیتِ سری را عوض می‌کند.
 */
class CalendarRecurrenceOccurrenceTest extends PushTestCase
{
    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];

    private function user(): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, self::CAL);

        return $id;
    }

    private function create(int $as, array $data): int
    {
        return (int) $this->actingAs(User::find($as))->postJson('/calendar/events', $data + ['Title' => 'جلسهٔ تکراری'])->assertOk()->json('eventId');
    }

    private function occurrences(int $as, int $eventId, string $from, string $to): array
    {
        return collect($this->actingAs(User::find($as))->getJson("/calendar/events?from={$from}&to={$to}")->assertOk()->json('events'))
            ->where('EventID', (string) $eventId)->values()->all();
    }

    public function test_weekly_recurrence_on_selected_weekdays(): void
    {
        $owner = $this->user();
        // شنبه ۲۰۹۹-۰۳-۰۷ (اندیسِ ۰)؛ دوشنبه = ۲، چهارشنبه = ۴
        $this->assertSame(0, JalaliRecurrence::jalaliWeekIndex(Carbon::parse('2099-03-07')));
        $eventId = $this->create($owner, [
            'StartDateTime' => '2099-03-07 10:00:00', 'EndDateTime' => '2099-03-07 10:30:00',
            'RecurrenceType' => 'WEEKLY', 'RecurrenceInterval' => 1, 'RecurrenceWeekDays' => [4, 2, 2],
        ]);
        $this->assertSame('2,4', DB::table('CalendarEvents')->where('EventID', $eventId)->value('RecurrenceWeekDays'));

        $occ = $this->occurrences($owner, $eventId, '2099-03-07', '2099-03-20');
        $this->assertSame(['2099-03-09 10:00:00', '2099-03-11 10:00:00', '2099-03-16 10:00:00', '2099-03-18 10:00:00'], array_column($occ, 'StartDateTime'));
        $this->assertSame('2099-03-09 10:30:00', $occ[0]['EndDateTime']);

        // هر دو هفته
        $biweekly = $this->create($owner, [
            'StartDateTime' => '2099-03-07 10:00:00', 'EndDateTime' => '2099-03-07 10:30:00',
            'RecurrenceType' => 'WEEKLY', 'RecurrenceInterval' => 2, 'RecurrenceWeekDays' => [0, 2],
        ]);
        $this->assertSame(['2099-03-07 10:00:00', '2099-03-09 10:00:00', '2099-03-21 10:00:00', '2099-03-23 10:00:00'],
            array_column($this->occurrences($owner, $biweekly, '2099-03-07', '2099-03-27'), 'StartDateTime'));

        // ویرایشِ تاریخ/ساعت بدونِ فرستادنِ روزها → روزها می‌مانند
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$eventId}", ['StartDateTime' => '2099-03-07 11:00:00', 'EndDateTime' => '2099-03-07 11:30:00'])->assertOk();
        $this->assertSame('2,4', DB::table('CalendarEvents')->where('EventID', $eventId)->value('RecurrenceWeekDays'));
        $this->assertSame('2099-03-09 11:00:00', $this->occurrences($owner, $eventId, '2099-03-07', '2099-03-10')[0]['StartDateTime']);
    }

    /** پچِ ۱۲۸: روزانه/ماهانه با فیلترِ روزهایِ هفته (مثلاً بدونِ جمعه)؛ همهٔ ۷ روز = بدونِ فیلتر */
    public function test_daily_and_monthly_recurrence_skip_unselected_weekdays(): void
    {
        $owner = $this->user();
        // ۲۰۹۹-۰۳-۰۷ شنبه است؛ جمعه = ۶
        $daily = $this->create($owner, [
            'StartDateTime' => '2099-03-07 08:00:00', 'EndDateTime' => '2099-03-07 08:15:00',
            'RecurrenceType' => 'DAILY', 'RecurrenceInterval' => 1, 'RecurrenceWeekDays' => [0, 1, 2, 3, 4, 5],
        ]);
        $starts = array_column($this->occurrences($owner, $daily, '2099-03-07', '2099-03-14'), 'StartDateTime');
        $this->assertCount(7, $starts); // ۸ روز منهایِ جمعه
        $this->assertNotContains('2099-03-13 08:00:00', $starts);

        $all = $this->create($owner, [
            'StartDateTime' => '2099-03-07 08:00:00', 'EndDateTime' => '2099-03-07 08:15:00',
            'RecurrenceType' => 'DAILY', 'RecurrenceInterval' => 1, 'RecurrenceWeekDays' => [0, 1, 2, 3, 4, 5, 6],
        ]);
        $this->assertNull(DB::table('CalendarEvents')->where('EventID', $all)->value('RecurrenceWeekDays'), 'همهٔ روزها = بدونِ فیلتر');
        $this->assertCount(8, $this->occurrences($owner, $all, '2099-03-07', '2099-03-14'));

        // ماهانه: ۱۳ِ هر ماهِ میلادیِ معادل؛ وقوعی که جمعه باشد حذف می‌شود
        $monthly = $this->create($owner, [
            'StartDateTime' => '2099-03-07 08:00:00', 'EndDateTime' => '2099-03-07 08:15:00',
            'RecurrenceType' => 'MONTHLY', 'RecurrenceInterval' => 1, 'RecurrenceWeekDays' => [0, 1, 2, 3, 4, 5],
        ]);
        foreach ($this->occurrences($owner, $monthly, '2099-03-01', '2100-03-01') as $o) {
            $this->assertNotSame(6, JalaliRecurrence::jalaliWeekIndex(Carbon::parse($o['StartDateTime'])), 'وقوعِ جمعه نباید باشد');
        }
    }

    public function test_marking_one_occurrence_done_keeps_the_others(): void
    {
        $owner = $this->user();
        $eventId = $this->create($owner, [
            'StartDateTime' => '2099-04-01 09:00:00', 'EndDateTime' => '2099-04-01 09:30:00', 'RecurrenceType' => 'DAILY', 'RecurrenceInterval' => 1,
        ]);

        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/status", ['Status' => 'DONE', 'OccurrenceStart' => '2099-04-01 09:00:00'])
            ->assertOk()->assertJson(['success' => true]);
        $statuses = array_column($this->occurrences($owner, $eventId, '2099-04-01', '2099-04-03'), 'Status', 'StartDateTime');
        $this->assertSame(['2099-04-01 09:00:00' => 'DONE', '2099-04-02 09:00:00' => 'CONFIRMED', '2099-04-03 09:00:00' => 'CONFIRMED'], $statuses);
        $this->assertSame('CONFIRMED', DB::table('CalendarEvents')->where('EventID', $eventId)->value('Status'), 'وضعیتِ سری دست نمی‌خورد');

        // جزئیاتِ همان وقوع
        $show = $this->actingAs(User::find($owner))->getJson("/calendar/events/{$eventId}?occurrence=" . urlencode('2099-04-02 09:00:00'))->assertOk();
        $this->assertSame('CONFIRMED', $show->json('occurrenceStatus'));
        $this->assertSame('DONE', $this->actingAs(User::find($owner))->getJson("/calendar/events/{$eventId}?occurrence=" . urlencode('2099-04-01 09:00:00'))->json('occurrenceStatus'));

        // برگرداندنِ همان وقوع، و «همهٔ تکرارها»
        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/status", ['Status' => 'CONFIRMED', 'OccurrenceStart' => '2099-04-01 09:00:00'])->assertOk();
        $this->assertSame('CONFIRMED', $this->occurrences($owner, $eventId, '2099-04-01', '2099-04-01')[0]['Status']);
        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/status", ['Status' => 'CANCELLED', 'AllOccurrences' => true])->assertOk();
        $this->assertSame('CANCELLED', DB::table('CalendarEvents')->where('EventID', $eventId)->value('Status'));
        $this->assertSame('CANCELLED', $this->occurrences($owner, $eventId, '2099-04-03', '2099-04-03')[0]['Status']);
    }

    /** ویرایشِ رنگ: فقط مدیرِ رویداد (سازنده/مالک) با دسترسیِ ویرایش؛ بقیهٔ اطلاعات و پاسخ‌ها دست نمی‌خورند */
    public function test_event_color_can_be_edited_only_by_its_manager(): void
    {
        $owner = $this->user();
        $guest = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($guest, ['CALENDAR_VIEW', 'CALENDAR_EDIT']);
        $eventId = $this->create($owner, ['StartDateTime' => '2099-05-01 09:00:00', 'EndDateTime' => '2099-05-01 09:30:00', 'RecurrenceType' => 'NONE', 'Color' => '#1677ff', 'AttendeeUserIDs' => [$guest]]);
        $times = ['StartDateTime' => '2099-05-01 09:00:00', 'EndDateTime' => '2099-05-01 09:30:00'];

        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$eventId}", $times + ['Color' => '#eb2f96'])->assertOk()->assertJson(['responsesReset' => 0]);
        $row = DB::table('CalendarEvents')->where('EventID', $eventId)->first(['Color', 'Title']);
        $this->assertSame('#eb2f96', $row->Color);
        $this->assertSame('جلسهٔ تکراری', $row->Title);

        $this->actingAs(User::find($guest))->putJson("/calendar/events/{$eventId}", $times + ['Color' => '#000000'])->assertForbidden(); // شرکت‌کننده مدیرِ رویداد نیست
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$eventId}", $times + ['Color' => 'red; x'])->assertStatus(422);
        $this->assertSame('#eb2f96', DB::table('CalendarEvents')->where('EventID', $eventId)->value('Color'));
    }

    public function test_reminder_of_a_done_occurrence_moves_to_the_next_one(): void
    {
        $owner = $this->user();
        $first = DB::selectOne("SELECT CONVERT(varchar(19), DATEADD(HOUR, 10, CAST(DATEADD(DAY, 2, CAST(SYSDATETIME() AS date)) AS datetime2(0))), 120) AS t")->t;
        $eventId = $this->create($owner, [
            'StartDateTime' => $first, 'EndDateTime' => Carbon::parse($first)->addMinutes(30)->format('Y-m-d H:i:s'),
            'RecurrenceType' => 'DAILY', 'RecurrenceInterval' => 1, 'ReminderOffsetMinutes' => 15,
        ]);
        $remindAt = fn () => substr((string) DB::table('Reminders')->where('EntityType', 'CALENDAR_EVENT')->where('EntityID', $eventId)->where('UserID', $owner)->value('RemindAt'), 0, 19);
        $this->assertSame(Carbon::parse($first)->subMinutes(15)->format('Y-m-d H:i:s'), $remindAt());

        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/status", ['Status' => 'DONE', 'OccurrenceStart' => $first])->assertOk();
        $this->assertSame(Carbon::parse($first)->addDay()->subMinutes(15)->format('Y-m-d H:i:s'), $remindAt(), 'یادآوری به وقوعِ بعدی رفت');
    }
}
