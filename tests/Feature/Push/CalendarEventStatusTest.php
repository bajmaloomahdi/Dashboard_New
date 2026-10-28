<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * وضعیتِ رویداد (پچِ ۱۱۶): ثبت شده / لغو شده / انجام شده — تغییر همان‌جا از پنجرهٔ جزئیات (POST /calendar/events/{id}/status).
 * «انجام شده» مثلِ «لغو شده» یادآوری و دعوتِ بعدی ندارد (ولی اطلاعِ لغو هم نمی‌فرستد). ویرایش پس از ثبت فقط تاریخ/ساعت و وضعیت را
 * عوض می‌کند؛ متن و موضوع ثابت است و یادآوری‌ها حفظ و با زمانِ تازه جابه‌جا می‌شوند.
 */
class CalendarEventStatusTest extends PushTestCase
{
    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];

    private function user(array $perms = self::CAL): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    private function sqlTime(int $minutes): string
    {
        return DB::selectOne('SELECT CONVERT(varchar(19), DATEADD(MINUTE, ?, SYSDATETIME()), 120) AS t', [$minutes])->t;
    }

    private function event(int $as, array $extra = []): int
    {
        return (int) $this->actingAs(User::find($as))->postJson('/calendar/events', $extra + [
            'Title' => 'جلسهٔ پیگیری', 'Description' => 'متنِ اصلی', 'StartDateTime' => $this->sqlTime(1440), 'EndDateTime' => $this->sqlTime(1470),
            'RecurrenceType' => 'NONE',
        ])->assertOk()->json('eventId');
    }

    private function setStatus(int $as, int $eventId, string $status)
    {
        return $this->actingAs(User::find($as))->postJson("/calendar/events/{$eventId}/status", ['Status' => $status]);
    }

    private function row(int $eventId): object
    {
        return DB::selectOne('SELECT Title, Description, Status, Color, RecurrenceType, CONVERT(varchar(19), StartDateTime, 120) s, CONVERT(varchar(19), EndDateTime, 120) e FROM dbo.CalendarEvents WHERE EventID = ?', [$eventId]);
    }

    public function test_owner_changes_status_in_place_and_others_cannot(): void
    {
        $owner = $this->user();
        $guest = $this->user(['CALENDAR_VIEW', 'CALENDAR_EDIT']);
        $eventId = $this->event($owner, ['AttendeeUserIDs' => [$guest]]);

        $this->setStatus($owner, $eventId, 'DONE')->assertOk()->assertJson(['success' => true]);
        $this->assertSame('DONE', $this->row($eventId)->Status);
        $this->setStatus($owner, $eventId, 'CONFIRMED')->assertOk();
        $this->assertSame('CONFIRMED', $this->row($eventId)->Status);

        $this->setStatus($guest, $eventId, 'DONE')->assertStatus(403); // شرکت‌کننده فقط پاسخ می‌دهد
        $this->setStatus($owner, $eventId, 'TENTATIVE')->assertStatus(422); // فقط سه وضعیت
        $viewer = $this->user(['CALENDAR_VIEW']);
        $this->setStatus($viewer, $eventId, 'DONE')->assertStatus(403); // بدونِ CALENDAR_EDIT
        $this->assertSame('CONFIRMED', $this->row($eventId)->Status);
    }

    public function test_done_event_has_no_more_reminders_or_invitations_and_sends_no_cancel_notice(): void
    {
        $owner = $this->user();
        $guest = $this->user(['CALENDAR_VIEW']);
        $ep = $this->subscribe($guest);
        $this->activate(10);
        $eventId = $this->event($owner, ['AttendeeUserIDs' => [$guest], 'StartDateTime' => $this->sqlTime(5), 'EndDateTime' => $this->sqlTime(35),
            'ReminderOffsetMinutes' => 10, 'AttendeeReminders' => [$guest => 10]]);
        DB::update("UPDATE dbo.Reminders SET RemindAt = DATEADD(MINUTE, -10, (SELECT StartDateTime FROM dbo.CalendarEvents WHERE EventID = ?)) WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ?", [$eventId, $eventId]);

        $this->setStatus($owner, $eventId, 'DONE')->assertOk();
        $this->dispatch();

        // انجام‌شده: نه دعوت، نه یادآوری، نه اطلاعِ لغو — فقط اطلاعِ «تغییرِ وضعیت» (CALEVENT، پچِ ۱۳۰)
        $sentTypes = DB::table('PushOutbox')->where('UserID', $guest)->where('Status', 'SENT')->pluck('SourceType')->unique()->values()->all();
        $this->assertSame(['CALEVENT'], $sentTypes, 'انجام‌شده: نه دعوت، نه یادآوری، نه اطلاعِ لغو');
        $this->assertCount(1, $this->fake->sentTo($ep));
        $this->assertSame([], $this->actingAs(User::find($guest))->getJson('/calendar/invitations')->json('invitations'));
        $this->assertNotContains($eventId, array_map(fn ($r) => (int) $r['EventID'], $this->actingAs(User::find($guest))->getJson('/calendar/reminders/due')->json('reminders')));
        $this->assertSame([], $this->actingAs(User::find($guest))->getJson('/calendar/notices')->json('notices'));
    }

    public function test_cancel_from_details_notifies_attendees(): void
    {
        $owner = $this->user();
        $guest = $this->user(['CALENDAR_VIEW']);
        $eventId = $this->event($owner, ['AttendeeUserIDs' => [$guest]]);

        $this->setStatus($owner, $eventId, 'CANCELLED')->assertOk();

        $this->assertSame([$eventId], array_map(fn ($n) => (int) $n['EventID'], $this->actingAs(User::find($guest))->getJson('/calendar/notices')->json('notices')));
    }

    public function test_edit_changes_only_date_time_and_status_and_keeps_reminders(): void
    {
        $owner = $this->user();
        $eventId = $this->event($owner, ['StartDateTime' => '2099-08-01 10:00:00', 'EndDateTime' => '2099-08-01 10:30:00', 'Color' => '#ff0000',
            'RecurrenceType' => 'WEEKLY', 'ReminderOffsetMinutes' => 15]);

        // فرمِ ویرایش فقط تاریخ/ساعت و وضعیت را می‌فرستد؛ حتی اگر عنوان/متن فرستاده شود، تغییر نمی‌کند
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$eventId}", [
            'StartDateTime' => '2099-08-02 14:00:00', 'EndDateTime' => '2099-08-02 14:30:00', 'IsAllDay' => false, 'Status' => 'DONE',
            'Title' => 'عنوانِ دیگر', 'Description' => 'متنِ دیگر',
        ])->assertOk();

        $r = $this->row($eventId);
        $this->assertSame(['جلسهٔ پیگیری', 'متنِ اصلی', 'DONE', '#ff0000', 'WEEKLY', '2099-08-02 14:00:00', '2099-08-02 14:30:00'],
            [$r->Title, $r->Description, $r->Status, $r->Color, $r->RecurrenceType, $r->s, $r->e]);
        // یادآوریِ خودِ کاربر حذف نشد و با همان «۱۵ دقیقه قبل» به زمانِ تازه رفت
        $rem = DB::table('Reminders')->where('EntityType', 'CALENDAR_EVENT')->where('EntityID', $eventId)->where('UserID', $owner)->where('IsActive', 1)->first();
        $this->assertSame([15, '2099-08-02 13:45:00'], [(int) $rem->OffsetMinutes, substr((string) $rem->RemindAt, 0, 19)]);
    }

    /** بازخوردِ کاربر: پس از ویرایشِ ساعت/تاریخ، یادآوریِ قبلاً دیده‌شده باید برایِ زمانِ تازه دوباره اعلان شود */
    public function test_editing_time_rearms_an_already_seen_reminder(): void
    {
        $owner = $this->user();
        $ep = $this->subscribe($owner);
        $this->activate(30);
        $eventId = $this->event($owner, ['StartDateTime' => $this->sqlTime(5), 'EndDateTime' => $this->sqlTime(35), 'ReminderOffsetMinutes' => 10]);
        $remId = (int) DB::table('Reminders')->where('EntityType', 'CALENDAR_EVENT')->where('EntityID', $eventId)->where('UserID', $owner)->value('ReminderID');
        $this->dispatch(); // اعلانِ اول
        $this->actingAs(User::find($owner))->postJson("/calendar/reminders/{$remId}/seen")->assertOk();
        $this->assertSame(1, (int) DB::table('Reminders')->where('ReminderID', $remId)->value('IsSent'));

        // ویرایش از فرم (فقط تاریخ/ساعت/وضعیت) → ۲ دقیقهٔ دیگر؛ یادآوریِ «۱۰ دقیقه قبل» همین حالا سررسید است
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$eventId}", [
            'StartDateTime' => $this->sqlTime(2), 'EndDateTime' => $this->sqlTime(32), 'IsAllDay' => false, 'Status' => 'CONFIRMED',
        ])->assertOk();

        $rem = DB::table('Reminders')->where('ReminderID', $remId)->first();
        $this->assertSame(0, (int) $rem->IsSent, 'یادآوری دوباره فعال شد');
        $due = array_map(fn ($r) => (int) $r['ReminderID'], $this->actingAs(User::find($owner))->getJson('/calendar/reminders/due')->json('reminders'));
        $this->assertContains($remId, $due, 'در برنامه دوباره نمایش داده می‌شود');
        $this->dispatch();
        $this->assertCount(2, array_filter($this->fake->sentTo($ep), fn ($s) => $s['payload']['kind'] === 'REMINDER'), 'Pushِ دوباره');
    }

    public function test_new_event_can_be_registered_as_done(): void
    {
        $owner = $this->user();
        $eventId = $this->event($owner, ['Status' => 'DONE']);
        $this->assertSame('DONE', $this->row($eventId)->Status);
    }
}
