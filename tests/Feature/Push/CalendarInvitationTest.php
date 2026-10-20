<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * دعوت به جلسه (پچِ ۱۰۹): نسخهٔ دعوت (InvitedAt)، پنلِ «دعوت‌ها» (/calendar/invitations) و Pushِ INVITE از همان Outbox.
 * هر نسخهٔ دعوت یک‌بار؛ ویرایشِ نامرتبط دعوت را تکرار نمی‌کند؛ حذف و افزودنِ دوباره = دعوتِ تازه؛
 * رویدادِ لغوشده/تمام‌شده، کاربرِ غیرفعال یا بی‌مجوز → بدونِ دعوت. Transportِ ساختگی؛ همه‌چیز Rollback می‌شود.
 */
class CalendarInvitationTest extends PushTestCase
{
    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];

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

    /** جلسه‌ای فردا با شرکت‌کنندگانِ داده‌شده */
    private function meeting(int $creator, array $attendees, array $extra = []): int
    {
        return (int) $this->actingAs(User::find($creator))->postJson('/calendar/events', $extra + [
            'Title' => 'جلسهٔ محرمانهٔ بودجه ' . bin2hex(random_bytes(3)),
            'StartDateTime' => $this->sqlTime(1440),
            'EndDateTime' => $this->sqlTime(1500),
            'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => $attendees,
        ])->assertOk()->json('eventId');
    }

    private function update(int $as, int $eventId, array $attendees, array $extra = [])
    {
        $e = DB::selectOne('SELECT Title, StartDateTime, EndDateTime FROM dbo.CalendarEvents WHERE EventID = ?', [$eventId]);

        return $this->actingAs(User::find($as))->putJson("/calendar/events/{$eventId}", $extra + [
            'Title' => $e->Title,
            'StartDateTime' => substr($e->StartDateTime, 0, 19),
            'EndDateTime' => substr($e->EndDateTime, 0, 19),
            'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => $attendees,
        ]);
    }

    private function attendee(int $eventId, int $userId): object
    {
        return DB::selectOne('SELECT CalendarEventAttendeeID, ResponseStatus, InvitedAt, IsActive, RelationType FROM dbo.CalendarEventAttendees WHERE EventID = ? AND UserID = ?', [$eventId, $userId]);
    }

    private function pendingIds(int $userId): array
    {
        return collect($this->actingAs(User::find($userId))->getJson('/calendar/invitations')->assertOk()->json('invitations'))
            ->pluck('EventID')->map(fn ($v) => (int) $v)->all();
    }

    private function respond(int $userId, int $eventId, string $status): void
    {
        $this->actingAs(User::find($userId))->postJson("/calendar/events/{$eventId}/respond", ['ResponseStatus' => $status])->assertOk();
    }

    public function test_invitation_is_listed_and_pushed_once_with_generic_text(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $creatorEndpoint = $this->subscribe($creator);
        $guestEndpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);

        $row = $this->attendee($eventId, $guest);
        $this->assertSame(['PENDING', 'PARTICIPANT'], [$row->ResponseStatus, $row->RelationType]);
        $this->assertNotNull($row->InvitedAt);
        $this->assertSame([$eventId], $this->pendingIds($guest));
        $this->assertSame([], $this->pendingIds($creator)); // برگزارکننده دعوت ندارد

        $this->dispatch();
        $this->dispatch();
        $sent = $this->fake->sentTo($guestEndpoint);
        $this->assertCount(1, $sent, 'هر نسخهٔ دعوت فقط یک Push');
        $this->assertSame(['INVITE', '/calendar?invites=1'], [$sent[0]['payload']['kind'], $sent[0]['payload']['url']]);
        $this->assertStringNotContainsString('بودجه', $sent[0]['rawPayload']); // عنوانِ جلسه رویِ صفحهٔ قفل نمی‌آید
        $this->assertCount(0, $this->fake->sentTo($creatorEndpoint));
    }

    public function test_answered_invitation_is_not_pushed_and_leaves_the_panel(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);

        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertCount(0, $this->outbox($guest, 'INVITE'));
        $this->assertSame([], $this->pendingIds($guest));
    }

    public function test_invitation_answered_after_queueing_is_skipped(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);

        $this->store()->enqueue(null);
        $this->respond($guest, $eventId, 'REJECTED');
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_ACKNOWLEDGED', $this->outbox($guest, 'INVITE')[0]->Status);
    }

    public function test_unrelated_edit_does_not_reinvite_or_reset_answer(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $other = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'ACCEPTED');
        $before = $this->attendee($eventId, $guest);

        // عنوان/توضیح/رنگ عوض شد و یک نفرِ دیگر اضافه شد — دعوتِ مهمانِ قبلی دست نمی‌خورد
        $this->update($creator, $eventId, [$guest, $other], ['Title' => 'عنوانِ تازه', 'Description' => 'توضیح', 'Color' => '#00aa00'])->assertOk();
        $this->dispatch();

        $after = $this->attendee($eventId, $guest);
        $this->assertSame(['ACCEPTED', $before->InvitedAt], [$after->ResponseStatus, $after->InvitedAt]);
        $this->assertCount(1, $this->fake->sentTo($endpoint), 'ویرایشِ نامرتبط دعوت را تکرار نمی‌کند');
        $this->assertSame('PENDING', $this->attendee($eventId, $other)->ResponseStatus); // فقط نفرِ تازه دعوت می‌شود
    }

    public function test_removed_and_readded_attendee_gets_a_new_invitation(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'ACCEPTED');
        $first = $this->attendee($eventId, $guest);

        $this->update($creator, $eventId, [])->assertOk(); // حذف
        $this->assertSame(0, (int) $this->attendee($eventId, $guest)->IsActive);
        $this->assertSame([], $this->pendingIds($guest));
        // شبیه‌سازیِ گذشتِ زمان: دعوتِ اول (و ردیفِ Pushِ همان نسخه) ۵ دقیقه پیش بوده — در آزمونِ زیرِ یک ثانیه
        // افزودنِ دوباره در همان ثانیهٔ دعوتِ اول رخ می‌داد و نسخه (دقتِ ثانیه) یکسان می‌شد
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(MINUTE, -5, InvitedAt) WHERE CalendarEventAttendeeID = ?', [$first->CalendarEventAttendeeID]);
        DB::update("UPDATE dbo.PushOutbox SET SourceVersion = DATEADD(MINUTE, -5, SourceVersion) WHERE SourceType = 'INVITE' AND SourceID = ?", [$first->CalendarEventAttendeeID]);
        $first = $this->attendee($eventId, $guest);
        $this->update($creator, $eventId, [$guest])->assertOk(); // افزودنِ دوباره

        $again = $this->attendee($eventId, $guest);
        $this->assertSame(['PENDING', 1, (int) $first->CalendarEventAttendeeID], [$again->ResponseStatus, (int) $again->IsActive, (int) $again->CalendarEventAttendeeID]);
        $this->assertNotSame($first->InvitedAt, $again->InvitedAt);
        $this->assertSame([$eventId], $this->pendingIds($guest));
        $this->dispatch();
        $this->assertCount(2, $this->fake->sentTo($endpoint), 'دعوتِ دوباره قابلِ تشخیص و ارسال است');
    }

    /** پچِ ۱۱۰: حذف و افزودنِ دوباره در همان ثانیهٔ دعوتِ قبلی هم نسخهٔ تازه (+۱ ثانیه) می‌گیرد */
    public function test_readded_in_the_same_second_still_gets_a_new_invitation(): void
    {
        $this->activate(10); // مرزِ Push کمی عقب‌تر تا نسخه‌هایِ شبیه‌سازی‌شدهٔ چند ثانیه قبل هم صف شوند
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $first = $this->attendee($eventId, $guest);

        // بدونِ جابه‌جاییِ زمان: نسخهٔ قبلی را عمداً در «همین ثانیه» یا آینده قرار می‌دهیم
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = CAST(SYSDATETIME() AS DATETIME2(0)) WHERE CalendarEventAttendeeID = ?', [$first->CalendarEventAttendeeID]);
        DB::update("UPDATE dbo.PushOutbox SET SourceVersion = (SELECT InvitedAt FROM dbo.CalendarEventAttendees WHERE CalendarEventAttendeeID = ?) WHERE SourceType = 'INVITE' AND SourceID = ?", [$first->CalendarEventAttendeeID, $first->CalendarEventAttendeeID]);
        $sameSecond = $this->attendee($eventId, $guest)->InvitedAt;
        $this->update($creator, $eventId, [])->assertOk();
        $this->update($creator, $eventId, [$guest])->assertOk();

        $again = $this->attendee($eventId, $guest);
        $this->assertTrue(strtotime($again->InvitedAt) > strtotime($sameSecond), 'نسخهٔ دعوتِ دوباره باید جلوتر باشد');
        // نسخهٔ تازه (S+1) ممکن است هنوز در آینده باشد؛ برایِ صف‌شدن بدونِ انتظار، ۲ ثانیه عقب (S−1 ≠ S)
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(SECOND, -2, InvitedAt) WHERE CalendarEventAttendeeID = ?', [$first->CalendarEventAttendeeID]);
        $this->assertNotSame($sameSecond, $this->attendee($eventId, $guest)->InvitedAt);
        $this->dispatch();
        $this->assertCount(2, $this->fake->sentTo($endpoint));
    }

    public function test_cancelled_event_sends_no_invitation(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest], ['Status' => 'CANCELLED']);

        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertCount(0, $this->outbox($guest, 'INVITE'));
        $this->assertSame([], $this->pendingIds($guest));
        $this->assertNotEmpty($eventId);
    }

    public function test_event_cancelled_after_queueing_is_skipped(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);

        $this->store()->enqueue(null);
        $this->update($creator, $eventId, [$guest], ['Status' => 'CANCELLED'])->assertOk();
        $this->dispatch();

        // دعوتِ صف‌شده ارسال نمی‌شود؛ بخشِ ۶ (پچِ ۱۱۳): به‌جایش دقیقاً یک «اطلاعِ لغو» (شرکت‌کنندهٔ غیرِ ردکننده)
        $this->assertSame('SKIPPED_INVALID', $this->outbox($guest, 'INVITE')[0]->Status);
        $this->assertSame(['CANCEL'], array_map(fn ($s) => $s['payload']['kind'], $this->fake->sentTo($endpoint)));
    }

    public function test_inactive_or_unauthorized_invitee_gets_nothing(): void
    {
        $creator = $this->calendarUser();
        $noPermission = $this->createUserWithoutRoles();
        $inactive = $this->calendarUser(['CALENDAR_VIEW']);
        $noPermEndpoint = $this->subscribe($noPermission);
        $inactiveEndpoint = $this->subscribe($inactive);
        $this->meeting($creator, [$noPermission, $inactive]);
        DB::update('UPDATE dbo.Users SET IsActive = 0 WHERE UserID = ?', [$inactive]);

        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($noPermEndpoint));
        $this->assertCount(0, $this->fake->sentTo($inactiveEndpoint));
        $this->actingAs(User::find($noPermission))->getJson('/calendar/invitations')->assertStatus(403);
        $this->assertSame([], array_map(fn ($r) => (int) $r->EventID, DB::select('EXEC dbo.sp_GetPendingCalendarInvitations @UserID = ?', [$inactive])));
    }

    public function test_finished_event_is_not_invited(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $this->meeting($creator, [$guest], ['StartDateTime' => $this->sqlTime(-180), 'EndDateTime' => $this->sqlTime(-120)]);

        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame([], $this->pendingIds($guest));
    }

    public function test_owner_assigned_by_someone_else_is_invited(): void
    {
        $creator = $this->calendarUser();
        $owner = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($owner);
        $eventId = $this->meeting($creator, [], ['OwnerUserID' => $owner]);

        $this->assertNotNull($this->attendee($eventId, $owner)->InvitedAt);
        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint));
    }

    public function test_invitation_before_push_activation_is_not_pushed(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(DAY, -1, InvitedAt) WHERE EventID = ?', [$eventId]);

        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame([$eventId], $this->pendingIds($guest)); // در پنل هست، فقط Push برایِ قبل از فعال‌سازی نمی‌رود
    }

    /** بخشِ ۵ (پچِ ۱۱۲): جابه‌جایی = تغییرِ زمان → هر شرکت‌کننده یک‌بار «تغییرِ جلسه»؛ پاسخِ قبلی حفظ، نقش و عضویت دست‌نخورده */
    public function test_moving_the_event_reasks_each_attendee_exactly_once(): void
    {
        $creator = $this->calendarUser();
        $accepted = $this->calendarUser(['CALENDAR_VIEW']);
        $pending = $this->calendarUser(['CALENDAR_VIEW']);
        $maybe = $this->calendarUser(['CALENDAR_VIEW']);
        $optional = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoints = array_map(fn ($u) => $this->subscribe($u), [$accepted, $pending, $maybe, $optional]);
        $eventId = $this->meeting($creator, [$accepted, $pending, $maybe, $optional]);
        $this->dispatch();
        $this->respond($accepted, $eventId, 'ACCEPTED');
        $this->respond($maybe, $eventId, 'TENTATIVE');
        DB::update("UPDATE dbo.CalendarEventAttendees SET RelationType = N'OPTIONAL' WHERE EventID = ? AND UserID = ?", [$eventId, $optional]);
        $before = collect([$accepted, $pending, $maybe, $optional])->mapWithKeys(fn ($u) => [$u => $this->attendee($eventId, $u)]);

        // شبیه‌سازیِ گذشتِ زمان (نسخهٔ دعوت و ردیفِ Pushِ آن ۵ دقیقه قبل)
        DB::update("UPDATE dbo.PushOutbox SET SourceVersion = DATEADD(MINUTE, -5, SourceVersion) WHERE SourceType = 'INVITE' AND SourceID IN (SELECT CalendarEventAttendeeID FROM dbo.CalendarEventAttendees WHERE EventID = ?)", [$eventId]);
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(MINUTE, -5, InvitedAt) WHERE EventID = ?', [$eventId]);

        // جابه‌جاییِ فازِ اول (Drag & Drop)
        $res = $this->actingAs(User::find($creator))->postJson("/calendar/events/{$eventId}/move", ['StartDateTime' => $this->sqlTime(2880), 'EndDateTime' => $this->sqlTime(2940)])->assertOk();
        $this->assertSame(4, $res->json('responsesReset'));
        $this->dispatch();
        $this->dispatch();

        foreach ($before as $u => $b) {
            $a = DB::selectOne('SELECT ResponseStatus, PreviousResponseStatus, RelationType, IsActive, InviteIsUpdate FROM dbo.CalendarEventAttendees WHERE EventID = ? AND UserID = ?', [$eventId, $u]);
            $previous = $b->ResponseStatus === 'PENDING' ? null : $b->ResponseStatus;
            $this->assertSame(['PENDING', $previous, $b->RelationType, 1, 1], [$a->ResponseStatus, $a->PreviousResponseStatus, $a->RelationType, (int) $a->IsActive, (int) $a->InviteIsUpdate], "user $u");
        }
        foreach ($endpoints as $e) {
            $this->assertCount(2, $this->fake->sentTo($e), 'دعوتِ اول + دقیقاً یک «تغییرِ جلسه»');
        }
    }

    public function test_invitations_endpoint_requires_login(): void
    {
        $this->getJson('/calendar/invitations')->assertStatus(401);
    }

    public function test_recurring_meeting_whose_first_occurrence_passed_is_still_invited(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        // نخستین رخداد دیروز بوده، سری تا آخرِ سال ادامه دارد
        $eventId = $this->meeting($creator, [$guest], [
            'StartDateTime' => $this->sqlTime(-1440), 'EndDateTime' => $this->sqlTime(-1380),
            'RecurrenceType' => 'WEEKLY', 'RecurrenceInterval' => 1, 'RecurrenceEndDate' => '2099-12-31',
        ]);

        $this->assertSame([$eventId], $this->pendingIds($guest));
        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint));
    }

    public function test_answer_set_back_to_pending_does_not_push_the_same_invitation_again(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->respond($guest, $eventId, 'PENDING'); // همان نسخهٔ دعوت

        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint), 'یک نسخهٔ دعوت فقط یک‌بار');
        $this->assertSame([$eventId], $this->pendingIds($guest));
    }

    public function test_only_own_invitations_are_returned(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $stranger = $this->calendarUser(['CALENDAR_VIEW']);
        $eventId = $this->meeting($creator, [$guest]);

        $this->assertSame([$eventId], $this->pendingIds($guest));
        $this->assertSame([], $this->pendingIds($stranger));
    }
}
