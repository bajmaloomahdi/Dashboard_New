<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Services\Calendar\Jalali;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * هماهنگیِ اعلان‌ها (پچِ ۱۱۳): اطلاعِ لغو/حذفِ جلسه، یادآوریِ منقضی، جلو بردنِ خودکارِ یادآوریِ تکرارشونده،
 * TTL تا پایانِ جلسه و ترتیبِ دعوت پیش از یادآوری — با حفظِ حریمِ خصوصی و مجوزها. Transportِ ساختگی؛ همه Rollback.
 */
class CalendarNotificationCoordinationTest extends PushTestCase
{
    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS', 'CALENDAR_SHARE'];
    private const CANCEL_BODY = 'یکی از جلسه‌هایتان لغو شد.';

    private function calendarUser(array $perms = self::CAL): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    private function sqlTime(int $minutes): string
    {
        return DB::selectOne('SELECT CONVERT(varchar(19), DATEADD(MINUTE, ?, SYSDATETIME()), 120) AS t', [$minutes])->t;
    }

    private function meeting(int $creator, array $attendees, array $extra = []): int
    {
        return (int) $this->actingAs(User::find($creator))->postJson('/calendar/events', $extra + [
            'Title' => 'جلسهٔ محرمانهٔ ادغام ' . bin2hex(random_bytes(3)),
            'StartDateTime' => $this->sqlTime(1440), 'EndDateTime' => $this->sqlTime(1500),
            'RecurrenceType' => 'NONE', 'AttendeeUserIDs' => $attendees,
        ])->assertOk()->json('eventId');
    }

    private function setStatus(int $as, int $eventId, string $status, array $attendees)
    {
        $e = DB::selectOne('SELECT Title, StartDateTime, EndDateTime FROM dbo.CalendarEvents WHERE EventID = ?', [$eventId]);

        return $this->actingAs(User::find($as))->putJson("/calendar/events/{$eventId}", [
            'Title' => $e->Title, 'StartDateTime' => substr($e->StartDateTime, 0, 19), 'EndDateTime' => substr($e->EndDateTime, 0, 19),
            'RecurrenceType' => 'NONE', 'Status' => $status, 'AttendeeUserIDs' => $attendees,
        ]);
    }

    private function respond(int $userId, int $eventId, string $status): void
    {
        $this->actingAs(User::find($userId))->postJson("/calendar/events/{$eventId}/respond", ['ResponseStatus' => $status])->assertOk();
    }

    private function age(int $eventId): void
    {
        DB::update("UPDATE dbo.PushOutbox SET SourceVersion = DATEADD(MINUTE, -5, SourceVersion) WHERE SourceType = 'INVITE' AND SourceID IN (SELECT CalendarEventAttendeeID FROM dbo.CalendarEventAttendees WHERE EventID = ?)", [$eventId]);
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(MINUTE, -5, InvitedAt) WHERE EventID = ?', [$eventId]);
    }

    private function bodies(string $endpoint): array
    {
        return array_map(fn ($s) => $s['payload']['body'], $this->fake->sentTo($endpoint));
    }

    private function notices(int $userId): array
    {
        return $this->actingAs(User::find($userId))->getJson('/calendar/notices')->assertOk()->json('notices');
    }

    public function test_cancel_notifies_only_authorized_attendees_once_with_generic_text(): void
    {
        $creator = $this->calendarUser();
        [$yes, $no, $silent] = [$this->calendarUser(['CALENDAR_VIEW']), $this->calendarUser(['CALENDAR_VIEW']), $this->calendarUser(['CALENDAR_VIEW'])];
        $noPerm = $this->createUserWithoutRoles();
        [$reader, $busy] = [$this->calendarUser(['CALENDAR_VIEW']), $this->calendarUser(['CALENDAR_VIEW'])];
        DB::insert("INSERT INTO dbo.CalendarShares (OwnerUserID, SharedWithUserID, AccessLevel) VALUES (?, ?, N'READ'), (?, ?, N'FREEBUSY')", [$creator, $reader, $creator, $busy]);
        $ep = array_map(fn ($u) => $this->subscribe($u), [$yes, $no, $silent, $noPerm, $reader, $busy, $creator]);
        $guests = [$yes, $no, $silent, $noPerm];
        $eventId = $this->meeting($creator, $guests, ['IsPrivate' => true]);
        $this->dispatch();
        $this->respond($yes, $eventId, 'ACCEPTED');
        $this->respond($no, $eventId, 'REJECTED');

        $this->setStatus($creator, $eventId, 'CANCELLED', $guests)->assertOk();
        $this->dispatch();
        $this->dispatch();

        $this->assertSame(self::CANCEL_BODY, $this->bodies($ep[0])[1] ?? null);
        $this->assertSame(self::CANCEL_BODY, $this->bodies($ep[2])[1] ?? null);
        $this->assertCount(2, $this->fake->sentTo($ep[0]), 'یک دعوت + یک اطلاعِ لغو، بدونِ تکرار');
        $this->assertNotContains(self::CANCEL_BODY, $this->bodies($ep[1]), 'ردکننده اطلاع نمی‌گیرد');
        foreach ([3, 4, 5, 6] as $i) { // بی‌مجوز، READ، FREEBUSY، خودِ لغوکننده
            $this->assertNotContains(self::CANCEL_BODY, $this->bodies($ep[$i]));
        }
        $title = DB::table('CalendarEvents')->where('EventID', $eventId)->value('Title');
        $this->assertStringNotContainsString($title, $this->fake->sentTo($ep[0])[1]['rawPayload']);

        // اعلانِ داخلِ برنامه: فقط شرکت‌کننده، با عنوان (مجاز)؛ بینندهٔ READ هیچ
        $this->assertSame([$eventId], array_map(fn ($n) => (int) $n['EventID'], $this->notices($yes)));
        $this->assertSame($title, $this->notices($yes)[0]['Title']);
        $this->assertSame([], $this->notices($reader));
        $this->assertSame([], $this->notices($no));
        // دعوتِ در انتظارِ جلسهٔ لغوشده از پنل حذف شده
        $this->assertSame([], $this->actingAs(User::find($silent))->getJson('/calendar/invitations')->json('invitations'));
    }

    public function test_reinstating_before_send_drops_the_cancel_notice_and_reasks_everyone(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $ep = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'REJECTED');
        $this->age($eventId);

        $this->setStatus($creator, $eventId, 'CANCELLED', [$guest])->assertOk(); // ردکننده → بدونِ اطلاعِ لغو
        $res = $this->setStatus($creator, $eventId, 'CONFIRMED', [$guest])->assertOk();
        $this->dispatch();

        $this->assertSame(1, $res->json('responsesReset'));
        $a = DB::selectOne('SELECT ResponseStatus, PreviousResponseStatus, InviteIsUpdate, CancelNoticeAt FROM dbo.CalendarEventAttendees WHERE EventID = ? AND UserID = ?', [$eventId, $guest]);
        $this->assertSame(['PENDING', 'REJECTED', 1, null], [$a->ResponseStatus, $a->PreviousResponseStatus, (int) $a->InviteIsUpdate, $a->CancelNoticeAt]);
        $this->assertSame(['دعوت به جلسهٔ تازه‌ای دارید.', 'یکی از جلسه‌هایتان تغییر کرد؛ لطفاً دوباره پاسخ دهید.'], $this->bodies($ep));
        $this->assertSame([], $this->notices($guest));
    }

    public function test_cancel_then_reinstate_after_queueing_skips_the_cancel_push(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $ep = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->setStatus($creator, $eventId, 'CANCELLED', [$guest])->assertOk();
        $this->store()->enqueue(null);
        $this->setStatus($creator, $eventId, 'CONFIRMED', [$guest])->assertOk();
        $this->dispatch();

        $this->assertSame('SKIPPED_ACKNOWLEDGED', $this->outbox($guest, 'CANCEL')[0]->Status);
        $this->assertNotContains(self::CANCEL_BODY, $this->bodies($ep));
    }

    public function test_delete_notifies_attendees_and_finished_meetings_stay_silent(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $ep = $this->subscribe($guest);
        $future = $this->meeting($creator, [$guest]);
        $past = $this->meeting($creator, [$guest], ['StartDateTime' => $this->sqlTime(-180), 'EndDateTime' => $this->sqlTime(-120)]);
        $this->dispatch();

        $this->actingAs(User::find($creator))->deleteJson("/calendar/events/{$future}")->assertOk();
        $this->actingAs(User::find($creator))->deleteJson("/calendar/events/{$past}")->assertOk();
        $this->dispatch();

        $this->assertSame(1, count(array_filter($this->bodies($ep), fn ($b) => $b === self::CANCEL_BODY)));
        $n = $this->notices($guest);
        $this->assertSame([[$future, 1]], array_map(fn ($x) => [(int) $x['EventID'], (int) $x['IsDeleted']], $n));
    }

    public function test_reminder_of_a_finished_meeting_is_neither_pushed_nor_shown(): void
    {
        $user = $this->calendarUser();
        $ep = $this->subscribe($user);
        $this->activate(240);
        $eventId = $this->meeting($user, [], ['StartDateTime' => $this->sqlTime(-120), 'EndDateTime' => $this->sqlTime(-60)]);
        DB::insert("INSERT INTO dbo.Reminders (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, IsSent, IsActive, CreatedByUserID) VALUES (N'CALENDAR_EVENT', ?, ?, 15, DATEADD(MINUTE, -135, SYSDATETIME()), 0, 1, ?)", [$eventId, $user, $user]);

        $this->dispatch();
        $due = $this->actingAs(User::find($user))->getJson('/calendar/reminders/due')->assertOk()->json('reminders');

        $this->assertSame([], $this->fake->sentTo($ep));
        $this->assertSame('SKIPPED_STALE', collect($this->outbox($user, 'REMINDER'))->last()->Status);
        $this->assertNotContains($eventId, array_map(fn ($r) => (int) $r['EventID'], $due));
    }

    public function test_recurring_reminder_moves_on_without_acknowledgement(): void
    {
        $user = $this->calendarUser();
        $ep = $this->subscribe($user);
        // سری روزانه از ۳ روز پیش؛ یادآوریِ وقوعِ اول هرگز تأیید نشده
        $start = Carbon::parse($this->sqlTime(-3 * 1440))->setTime(9, 0);
        $eventId = $this->meeting($user, [], ['StartDateTime' => $start->toDateTimeString(), 'EndDateTime' => $start->copy()->addHour()->toDateTimeString(), 'RecurrenceType' => 'DAILY']);
        DB::insert("INSERT INTO dbo.Reminders (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, IsSent, IsActive, CreatedByUserID) VALUES (N'CALENDAR_EVENT', ?, ?, 15, ?, 0, 1, ?)",
            [$eventId, $user, $start->copy()->subMinutes(15)->toDateTimeString(), $user]);

        $this->dispatch(); // زمان‌بند (بدونِ «دیدم» در برنامه)

        $now = Carbon::parse($this->sqlTime(0));
        $next = $now->copy()->setTime(9, 0);
        if ($next->lte($now)) {
            $next->addDay();
        }
        $remindAt = substr((string) DB::table('Reminders')->where('EntityID', $eventId)->where('UserID', $user)->value('RemindAt'), 0, 19);
        $this->assertSame($next->copy()->subMinutes(15)->toDateTimeString(), $remindAt);
        $this->assertSame([], $this->fake->sentTo($ep), 'یادآوریِ وقوعِ گذشته ارسال نشد');
    }

    public function test_in_app_due_reminders_roll_for_the_current_user(): void
    {
        $user = $this->calendarUser();
        $start = Carbon::parse($this->sqlTime(-10 * 1440))->setTime(8, 0);
        $eventId = $this->meeting($user, [], ['StartDateTime' => $start->toDateTimeString(), 'EndDateTime' => $start->copy()->addMinutes(30)->toDateTimeString(), 'RecurrenceType' => 'WEEKLY']);
        DB::insert("INSERT INTO dbo.Reminders (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, IsSent, IsActive, CreatedByUserID) VALUES (N'CALENDAR_EVENT', ?, ?, 0, ?, 0, 1, ?)",
            [$eventId, $user, $start->toDateTimeString(), $user]);

        $this->actingAs(User::find($user))->getJson('/calendar/reminders/due')->assertOk();

        $remindAt = Carbon::parse(DB::table('Reminders')->where('EntityID', $eventId)->where('UserID', $user)->value('RemindAt'));
        $this->assertTrue($remindAt->gt(Carbon::parse($this->sqlTime(0))), 'به وقوعِ آینده رفت');
        $this->assertSame($start->dayOfWeek, $remindAt->dayOfWeek);
    }

    public function test_push_ttl_never_outlives_the_meeting(): void
    {
        $user = $this->calendarUser();
        $ep = $this->subscribe($user);
        $this->activate(10);
        $eventId = $this->meeting($user, [], ['StartDateTime' => $this->sqlTime(5), 'EndDateTime' => $this->sqlTime(20)]);
        // RemindAt دقیقاً «شروع − ۱۰ دقیقه» (مثلِ برنامه)؛ SYSDATETIME در ستونِ DATETIME2(0) گرد می‌شود و ±۱ ثانیه خطا می‌ساخت
        DB::insert("INSERT INTO dbo.Reminders (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, IsSent, IsActive, CreatedByUserID) SELECT N'CALENDAR_EVENT', EventID, ?, 10, DATEADD(MINUTE, -10, StartDateTime), 0, 1, ? FROM dbo.CalendarEvents WHERE EventID = ?", [$user, $user, $eventId]);

        $this->dispatch();

        $ttl = $this->fake->sentTo($ep)[0]['options']['TTL'];
        $this->assertLessThanOrEqual(20 * 60, $ttl);
        $this->assertGreaterThanOrEqual(60, $ttl);
    }

    public function test_invitation_is_delivered_before_the_reminder_of_the_same_meeting(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $ep = $this->subscribe($guest);
        $this->activate(10);
        $eventId = $this->meeting($creator, [$guest], ['StartDateTime' => $this->sqlTime(3), 'EndDateTime' => $this->sqlTime(63)]);
        DB::insert("INSERT INTO dbo.Reminders (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, IsSent, IsActive, CreatedByUserID) VALUES (N'CALENDAR_EVENT', ?, ?, 5, DATEADD(MINUTE, -2, SYSDATETIME()), 0, 1, ?)", [$eventId, $guest, $creator]);
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(SECOND, -5, InvitedAt) WHERE EventID = ?', [$eventId]);

        $this->dispatch();

        $this->assertSame(['INVITE', 'REMINDER'], array_map(fn ($s) => $s['payload']['kind'], $this->fake->sentTo($ep)));
    }
}
