<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * جابه‌جایی/تغییرِ مدتِ رویداد با Drag & Drop (POST /calendar/events/{id}/move):
 * فقط شروع و پایان عوض می‌شود؛ شرکت‌کنندگان (نقش و پاسخ)، یادآوری‌ها و اطلاعاتِ تکرار دست نمی‌خورند؛
 * دسترسی‌هایِ فعلیِ ویرایش رعایت می‌شود. همه‌چیز با DatabaseTransactions برمی‌گردد.
 */
class CalendarMoveEventTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const ALL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];

    private function userWith(array $permissions): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $permissions);

        return $id;
    }

    private function createEvent(int $as, array $data = []): int
    {
        $r = $this->actingAs(User::find($as))->postJson('/calendar/events', $data + [
            'Title' => 'جلسهٔ آزمون ' . bin2hex(random_bytes(3)),
            'Description' => 'توضیح',
            'StartDateTime' => '2099-03-10 10:00:00',
            'EndDateTime' => '2099-03-10 11:30:00',
            'IsAllDay' => false,
            'Color' => '#ff5500',
            'Status' => 'TENTATIVE',
            'RecurrenceType' => 'NONE',
        ])->assertOk()->json();

        return (int) $r['eventId'];
    }

    private function eventRow(int $id): object
    {
        return DB::selectOne('SELECT * FROM dbo.CalendarEvents WHERE EventID = ?', [$id]);
    }

    private function move(int $as, int $id, string $start, string $end)
    {
        return $this->actingAs(User::find($as))->postJson("/calendar/events/{$id}/move", ['StartDateTime' => $start, 'EndDateTime' => $end]);
    }

    public function test_move_changes_only_times_and_keeps_attendees_reminders_and_details(): void
    {
        $a = $this->userWith(self::ALL);
        $b = $this->createUserWithoutRoles();
        $c = $this->createUserWithoutRoles();
        $id = $this->createEvent($a, [
            'AttendeeUserIDs' => [$b, $c],
            'ReminderOffsetMinutes' => 15,
            'AttendeeReminders' => [$b => 60],
        ]);
        DB::update("UPDATE dbo.CalendarEventAttendees SET ResponseStatus = N'ACCEPTED' WHERE EventID = ? AND UserID = ?", [$id, $b]);
        DB::update("UPDATE dbo.CalendarEventAttendees SET RelationType = N'OPTIONAL' WHERE EventID = ? AND UserID = ?", [$id, $c]);
        DB::update("UPDATE dbo.Reminders SET IsSent = 1 WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ? AND UserID = ?", [$id, $a]);
        $before = $this->eventRow($id);

        $this->move($a, $id, '2099-03-11 14:00:00', '2099-03-11 16:00:00')->assertOk()->assertJson(['success' => true]);

        $after = $this->eventRow($id);
        $this->assertSame(['2099-03-11 14:00:00', '2099-03-11 16:00:00'], [substr($after->StartDateTime, 0, 19), substr($after->EndDateTime, 0, 19)]);
        foreach (['Title', 'Description', 'Color', 'Status', 'IsAllDay', 'RecurrenceType', 'RecurrenceInterval', 'RecurrenceEndDate', 'CreatedByUserID', 'OwnerUserID', 'OrganizationalUnitID', 'IsActive'] as $col) {
            $this->assertEquals($before->$col, $after->$col, $col);
        }

        // بخشِ ۵ (پچِ ۱۱۲): جابه‌جایی تغییرِ زمان است → پاسخ «در انتظار» و پاسخِ قبلی نگه داشته می‌شود؛ نقش و عضویت دست نمی‌خورد
        $att = collect(DB::select('SELECT UserID, RelationType, ResponseStatus, PreviousResponseStatus, IsActive FROM dbo.CalendarEventAttendees WHERE EventID = ?', [$id]))->keyBy('UserID');
        $this->assertSame(['PARTICIPANT', 'PENDING', 'ACCEPTED', 1], [$att[$b]->RelationType, $att[$b]->ResponseStatus, $att[$b]->PreviousResponseStatus, (int) $att[$b]->IsActive]);
        $this->assertSame(['OPTIONAL', 1], [$att[$c]->RelationType, (int) $att[$c]->IsActive]);

        $rem = collect(DB::select("SELECT UserID, OffsetMinutes, RemindAt, IsSent, IsActive FROM dbo.Reminders WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ?", [$id]))->keyBy('UserID');
        $this->assertCount(2, $rem);
        $this->assertSame([15, '2099-03-11 13:45:00', 0, 1], [(int) $rem[$a]->OffsetMinutes, substr($rem[$a]->RemindAt, 0, 19), (int) $rem[$a]->IsSent, (int) $rem[$a]->IsActive]);
        $this->assertSame([60, '2099-03-11 13:00:00', 1], [(int) $rem[$b]->OffsetMinutes, substr($rem[$b]->RemindAt, 0, 19), (int) $rem[$b]->IsActive]);
    }

    public function test_resize_changes_only_end_time(): void
    {
        $a = $this->userWith(self::ALL);
        $id = $this->createEvent($a, ['ReminderOffsetMinutes' => 30]);

        $this->move($a, $id, '2099-03-10 10:00:00', '2099-03-10 12:15:00')->assertOk();

        $row = $this->eventRow($id);
        $this->assertSame(['2099-03-10 10:00:00', '2099-03-10 12:15:00'], [substr($row->StartDateTime, 0, 19), substr($row->EndDateTime, 0, 19)]);
        $rem = DB::selectOne("SELECT OffsetMinutes, RemindAt FROM dbo.Reminders WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ? AND IsActive = 1", [$id]);
        $this->assertSame([30, '2099-03-10 09:30:00'], [(int) $rem->OffsetMinutes, substr($rem->RemindAt, 0, 19)]);
    }

    public function test_all_day_event_stays_all_day(): void
    {
        $a = $this->userWith(self::ALL);
        $id = $this->createEvent($a, ['IsAllDay' => true, 'StartDateTime' => '2099-03-10 00:00:00', 'EndDateTime' => '2099-03-10 23:59:00']);

        $this->move($a, $id, '2099-03-12 00:00:00', '2099-03-12 23:59:00')->assertOk();

        $row = $this->eventRow($id);
        $this->assertSame(1, (int) $row->IsAllDay);
        $this->assertSame('2099-03-12 00:00:00', substr($row->StartDateTime, 0, 19));
    }

    public function test_recurring_event_is_not_moved_and_recurrence_untouched(): void
    {
        $a = $this->userWith(self::ALL);
        $id = $this->createEvent($a, ['RecurrenceType' => 'WEEKLY', 'RecurrenceInterval' => 2, 'RecurrenceEndDate' => '2099-12-31', 'ReminderOffsetMinutes' => 10]);
        $before = $this->eventRow($id);
        $remBefore = DB::selectOne("SELECT RemindAt FROM dbo.Reminders WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ?", [$id])->RemindAt;

        $this->move($a, $id, '2099-03-11 10:00:00', '2099-03-11 11:30:00')->assertStatus(422);

        $after = $this->eventRow($id);
        foreach (['StartDateTime', 'EndDateTime', 'RecurrenceType', 'RecurrenceInterval', 'RecurrenceEndDate'] as $col) {
            $this->assertEquals($before->$col, $after->$col, $col);
        }
        $this->assertSame($remBefore, DB::selectOne("SELECT RemindAt FROM dbo.Reminders WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ?", [$id])->RemindAt);
    }

    public function test_requires_edit_permission(): void
    {
        $u = $this->userWith(['CALENDAR_VIEW', 'CALENDAR_CREATE']);
        $id = $this->createEvent($u);

        $this->move($u, $id, '2099-03-11 10:00:00', '2099-03-11 11:00:00')->assertStatus(403);
        $this->assertSame('2099-03-10 10:00:00', substr($this->eventRow($id)->StartDateTime, 0, 19));
    }

    public function test_attendee_or_unrelated_user_cannot_move(): void
    {
        $a = $this->userWith(self::ALL);
        $attendee = $this->userWith(['CALENDAR_VIEW', 'CALENDAR_EDIT']);
        $stranger = $this->userWith(['CALENDAR_VIEW', 'CALENDAR_EDIT', 'CALENDAR_CREATE_FOR_OTHERS']);
        $id = $this->createEvent($a, ['AttendeeUserIDs' => [$attendee]]);

        $this->move($attendee, $id, '2099-03-11 10:00:00', '2099-03-11 11:00:00')->assertStatus(403);
        $this->move($stranger, $id, '2099-03-11 10:00:00', '2099-03-11 11:00:00')->assertStatus(403);
        $this->assertSame('2099-03-10 10:00:00', substr($this->eventRow($id)->StartDateTime, 0, 19));
    }

    public function test_event_involving_others_requires_create_for_others(): void
    {
        $limited = $this->userWith(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT']);
        $other = $this->createUserWithoutRoles();
        // رویدادی که (مثلاً پیش از تغییرِ نقش) با شرکت‌کنندهٔ دیگر ساخته شده
        $id = (int) DB::selectOne(
            "EXEC sp_InsertCalendarEvent @Title = N'جلسه', @StartDateTime = '2099-03-10 10:00:00', @EndDateTime = '2099-03-10 11:00:00', @OwnerUserID = ?, @AttendeeUserIDs = ?, @CreateUser = ?",
            [$limited, (string) $other, $limited]
        )->NewEventID;

        $this->move($limited, $id, '2099-03-11 10:00:00', '2099-03-11 11:00:00')->assertStatus(403);
        $this->assertSame('2099-03-10 10:00:00', substr($this->eventRow($id)->StartDateTime, 0, 19));
    }

    public function test_end_before_start_and_missing_event_are_rejected(): void
    {
        $a = $this->userWith(self::ALL);
        $id = $this->createEvent($a);

        $this->move($a, $id, '2099-03-11 12:00:00', '2099-03-11 10:00:00')->assertStatus(422);
        $this->move($a, 999999999, '2099-03-11 10:00:00', '2099-03-11 11:00:00')->assertStatus(404);
    }

    public function test_requires_login(): void
    {
        $this->postJson('/calendar/events/1/move', ['StartDateTime' => '2099-03-11 10:00:00', 'EndDateTime' => '2099-03-11 11:00:00'])->assertStatus(401);
    }
}
