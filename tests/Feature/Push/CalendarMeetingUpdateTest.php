<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * تغییرِ جلسه (پچِ ۱۱۲): تغییرِ زمان/مدت/تمام‌روز/تکرار پاسخِ همهٔ شرکت‌کنندگانِ قبلی (حتی ردکرده‌ها) را بازنشانی می‌کند،
 * پاسخِ قبلی و زمانش نگه داشته و نمایش داده می‌شود، و برایِ هر نسخه فقط یک اعلانِ «تغییرِ جلسه» (Push عمومی) می‌رود.
 * ویرایشِ نامرتبط، ویرایشگر، سازنده و بینندگانِ READ/FREEBUSY دست نمی‌خورند. Transportِ ساختگی؛ همه‌چیز Rollback می‌شود.
 */
class CalendarMeetingUpdateTest extends PushTestCase
{
    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];
    private const UPDATE_BODY = 'یکی از جلسه‌هایتان تغییر کرد؛ لطفاً دوباره پاسخ دهید.';
    private const INVITE_BODY = 'دعوت به جلسهٔ تازه‌ای دارید.';

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
            'StartDateTime' => $this->sqlTime(1440),
            'EndDateTime' => $this->sqlTime(1500),
            'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => $attendees,
        ])->assertOk()->json('eventId');
    }

    /** ویرایش با همان مقادیرِ فعلی + تغییراتِ داده‌شده */
    private function edit(int $as, int $eventId, array $changes = [], ?array $attendees = null)
    {
        $e = DB::selectOne('SELECT Title, StartDateTime, EndDateTime, IsAllDay, RecurrenceType, RecurrenceInterval, CONVERT(varchar(10), RecurrenceEndDate, 120) AS RecurrenceEndDate, Status, OwnerUserID FROM dbo.CalendarEvents WHERE EventID = ?', [$eventId]);
        $current = collect(DB::select("SELECT UserID FROM dbo.CalendarEventAttendees WHERE EventID = ? AND IsActive = 1 AND RelationType <> N'ORGANIZER'", [$eventId]))
            ->pluck('UserID')->map(fn ($v) => (int) $v)->reject(fn ($u) => $u === (int) $e->OwnerUserID)->values()->all();

        return $this->actingAs(User::find($as))->putJson("/calendar/events/{$eventId}", $changes + [
            'Title' => $e->Title,
            'StartDateTime' => substr($e->StartDateTime, 0, 19),
            'EndDateTime' => substr($e->EndDateTime, 0, 19),
            'IsAllDay' => (bool) $e->IsAllDay,
            'Status' => $e->Status,
            'RecurrenceType' => $e->RecurrenceType,
            'RecurrenceInterval' => (int) $e->RecurrenceInterval,
            'RecurrenceEndDate' => $e->RecurrenceEndDate,
            'OwnerUserID' => (int) $e->OwnerUserID,
            'AttendeeUserIDs' => $attendees ?? $current,
        ]);
    }

    private function attendee(int $eventId, int $userId): object
    {
        return DB::selectOne('SELECT CalendarEventAttendeeID, ResponseStatus, CONVERT(varchar(23), ResponseDate, 121) AS ResponseDate, InvitedAt, IsActive, RelationType,
            InviteIsUpdate, PreviousResponseStatus, CONVERT(varchar(23), PreviousResponseDate, 121) AS PreviousResponseDate
            FROM dbo.CalendarEventAttendees WHERE EventID = ? AND UserID = ?', [$eventId, $userId]);
    }

    private function respond(int $userId, int $eventId, string $status): void
    {
        $this->actingAs(User::find($userId))->postJson("/calendar/events/{$eventId}/respond", ['ResponseStatus' => $status])->assertOk();
    }

    /** شبیه‌سازیِ گذشتِ زمان (نسخهٔ دعوت و ردیفِ Pushِ همان نسخه چند دقیقه قبل) — آزمون زیرِ یک ثانیه اجرا می‌شود */
    private function age(int $eventId, int $minutes = 5): void
    {
        DB::update('UPDATE dbo.PushOutbox SET SourceVersion = DATEADD(MINUTE, ?, SourceVersion) WHERE SourceType = \'INVITE\' AND SourceID IN (SELECT CalendarEventAttendeeID FROM dbo.CalendarEventAttendees WHERE EventID = ?)', [-$minutes, $eventId]);
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(MINUTE, ?, InvitedAt) WHERE EventID = ? AND InvitedAt IS NOT NULL', [-$minutes, $eventId]);
    }

    private function bodies(string $endpoint): array
    {
        return array_map(fn ($s) => $s['payload']['body'], $this->fake->sentTo($endpoint));
    }

    public function test_every_timing_change_resets_all_responses_and_keeps_the_previous_one(): void
    {
        $cases = [
            'زمانِ شروع' => fn () => ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)],
            'مدت' => fn () => ['EndDateTime' => $this->sqlTime(1530)],
            'تمام‌روز' => fn () => ['IsAllDay' => true],
            'نوعِ تکرار' => fn () => ['RecurrenceType' => 'WEEKLY'],
            'فاصلهٔ تکرار' => fn () => ['RecurrenceInterval' => 2],
            'پایانِ تکرار' => fn () => ['RecurrenceEndDate' => '2099-12-31'],
        ];
        $creator = $this->calendarUser();
        foreach ($cases as $label => $change) {
            [$yes, $no, $maybe, $silent] = [$this->calendarUser(['CALENDAR_VIEW']), $this->calendarUser(['CALENDAR_VIEW']), $this->calendarUser(['CALENDAR_VIEW']), $this->calendarUser(['CALENDAR_VIEW'])];
            $recurring = in_array($label, ['فاصلهٔ تکرار', 'پایانِ تکرار'], true) ? ['RecurrenceType' => 'DAILY'] : [];
            $eventId = $this->meeting($creator, [$yes, $no, $maybe, $silent], $recurring);
            $this->respond($yes, $eventId, 'ACCEPTED');
            $this->respond($no, $eventId, 'REJECTED');
            $this->respond($maybe, $eventId, 'TENTATIVE');
            $before = $this->attendee($eventId, $yes);
            $this->age($eventId);

            $res = $this->edit($creator, $eventId, $change())->assertOk();
            $this->assertSame(4, $res->json('responsesReset'), $label);
            $this->assertStringContainsString('پاسخِ 4 شرکت‌کننده', $res->json('message'), $label);

            foreach ([$yes => 'ACCEPTED', $no => 'REJECTED', $maybe => 'TENTATIVE', $silent => null] as $u => $prev) {
                $a = $this->attendee($eventId, $u);
                $this->assertSame(['PENDING', 1, $prev], [$a->ResponseStatus, (int) $a->InviteIsUpdate, $a->PreviousResponseStatus], "$label / $u");
                $this->assertNull($a->ResponseDate, $label);
            }
            $this->assertSame($before->ResponseDate, $this->attendee($eventId, $yes)->PreviousResponseDate, "$label: زمانِ پاسخِ قبلی حفظ شد");
        }
    }

    public function test_unrelated_edits_do_not_reset(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->age($eventId);
        $before = $this->attendee($eventId, $guest);

        foreach ([['Title' => 'عنوانِ تازه'], ['Description' => 'توضیحِ تازه'], ['Color' => '#00aa00'], ['Status' => 'TENTATIVE']] as $change) {
            $res = $this->edit($creator, $eventId, $change)->assertOk();
            $this->assertSame(0, $res->json('responsesReset'));
        }
        // تغییرِ زمانی که دقیقاً همان مقدارِ فعلی است هم تغییر نیست
        $sameStart = substr((string) DB::table('CalendarEvents')->where('EventID', $eventId)->value('StartDateTime'), 0, 19);
        $this->assertSame(0, $this->edit($creator, $eventId, ['StartDateTime' => $sameStart])->assertOk()->json('responsesReset'));
        $this->dispatch();

        $after = $this->attendee($eventId, $guest);
        $this->assertSame(['ACCEPTED', $before->ResponseDate, $before->InvitedAt, 0], [$after->ResponseStatus, $after->ResponseDate, $after->InvitedAt, (int) $after->InviteIsUpdate]);
        $this->assertSame([self::INVITE_BODY], $this->bodies($endpoint));
    }

    public function test_change_sends_exactly_one_generic_update_push_and_shows_in_panels(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest], ['IsPrivate' => true]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'REJECTED');
        $this->age($eventId);

        $this->edit($creator, $eventId, ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)])->assertOk();
        $this->dispatch();
        $this->dispatch(); // اجرایِ دوبارهٔ زمان‌بند اعلانِ تکراری نمی‌سازد

        $sent = $this->fake->sentTo($endpoint);
        $this->assertSame([self::INVITE_BODY, self::UPDATE_BODY], $this->bodies($endpoint));
        $this->assertSame(['INVITE', '/calendar?invites=1'], [$sent[1]['payload']['kind'], $sent[1]['payload']['url']]);
        $title = DB::table('CalendarEvents')->where('EventID', $eventId)->value('Title');
        $this->assertStringNotContainsString($title, $sent[1]['rawPayload']); // بدونِ عنوانِ جلسه

        // پنلِ دعوت‌ها و جزئیات: برچسبِ تغییر و پاسخِ قبلی (رد شده)
        $inv = collect($this->actingAs(User::find($guest))->getJson('/calendar/invitations')->assertOk()->json('invitations'))->firstWhere('EventID', $eventId);
        $this->assertSame([1, 'REJECTED'], [(int) $inv['InviteIsUpdate'], $inv['PreviousResponseStatus']]);
        $this->assertNotNull($inv['PreviousResponseDate']);
        $row = collect($this->actingAs(User::find($guest))->getJson("/calendar/events/{$eventId}")->assertOk()->json('attendees'))->firstWhere('UserID', $guest);
        $this->assertSame([1, 'REJECTED', 'PENDING'], [(int) $row['InviteIsUpdate'], $row['PreviousResponseStatus'], $row['ResponseStatus']]);
    }

    public function test_consecutive_changes_produce_one_push_and_keep_the_original_answer(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'ACCEPTED');
        $original = $this->attendee($eventId, $guest);
        $this->age($eventId);

        // تغییرِ ۱ صف شد ولی هنوز ارسال نشده؛ تغییرِ ۲ (چند دقیقه بعد) قبل از ارسال
        $this->edit($creator, $eventId, ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)])->assertOk();
        DB::selectOne('EXEC dbo.sp_Push_Enqueue @MaxAgeMinutes = NULL');
        $this->age($eventId);
        $this->edit($creator, $eventId, ['EndDateTime' => $this->sqlTime(1680)])->assertOk();
        $this->dispatch();

        $this->assertSame([self::INVITE_BODY, self::UPDATE_BODY], $this->bodies($endpoint), 'فقط آخرین نسخه ارسال می‌شود');
        $statuses = collect($this->outbox($guest, 'INVITE'))->pluck('Status')->all();
        $this->assertSame(['SENT', 'SKIPPED_ACKNOWLEDGED', 'SENT'], $statuses);
        $a = $this->attendee($eventId, $guest);
        $this->assertSame(['PENDING', 'ACCEPTED', $original->ResponseDate], [$a->ResponseStatus, $a->PreviousResponseStatus, $a->PreviousResponseDate]);
    }

    public function test_new_answer_clears_previous_and_stops_the_update_push(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($guest);
        $eventId = $this->meeting($creator, [$guest]);
        $this->dispatch();
        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->age($eventId);
        $this->edit($creator, $eventId, ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)])->assertOk();
        DB::selectOne('EXEC dbo.sp_Push_Enqueue @MaxAgeMinutes = NULL');

        $this->respond($guest, $eventId, 'TENTATIVE'); // پاسخِ تازه پیش از ارسال
        $this->dispatch();

        $a = $this->attendee($eventId, $guest);
        $this->assertSame(['TENTATIVE', 0, null, null], [$a->ResponseStatus, (int) $a->InviteIsUpdate, $a->PreviousResponseStatus, $a->PreviousResponseDate]);
        $this->assertSame([self::INVITE_BODY], $this->bodies($endpoint));
        $this->assertSame('SKIPPED_ACKNOWLEDGED', collect($this->outbox($guest, 'INVITE'))->last()->Status);
        $this->assertNotContains($eventId, collect($this->actingAs(User::find($guest))->getJson('/calendar/invitations')->json('invitations'))->pluck('EventID')->map(fn ($v) => (int) $v)->all());
    }

    public function test_new_attendee_with_time_change_gets_one_new_invitation(): void
    {
        $creator = $this->calendarUser();
        $old = $this->calendarUser(['CALENDAR_VIEW']);
        $new = $this->calendarUser(['CALENDAR_VIEW']);
        [$oldEp, $newEp] = [$this->subscribe($old), $this->subscribe($new)];
        $eventId = $this->meeting($creator, [$old]);
        $this->dispatch();
        $this->respond($old, $eventId, 'ACCEPTED');
        $this->age($eventId);

        $res = $this->edit($creator, $eventId, ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)], [$old, $new])->assertOk();
        $this->dispatch();

        $this->assertSame(1, $res->json('responsesReset')); // فقط شرکت‌کنندهٔ قبلی
        $n = $this->attendee($eventId, $new);
        $this->assertSame(['PENDING', 0, null], [$n->ResponseStatus, (int) $n->InviteIsUpdate, $n->PreviousResponseStatus]);
        $this->assertSame([self::INVITE_BODY], $this->bodies($newEp), 'نفرِ تازه فقط یک «دعوتِ تازه»');
        $this->assertSame([self::INVITE_BODY, self::UPDATE_BODY], $this->bodies($oldEp));
    }

    public function test_editor_and_creator_are_not_reset_and_moving_counts_as_a_change(): void
    {
        $creator = $this->calendarUser();
        $owner = $this->calendarUser(); // مالک (غیرِ سازنده) که خودش ویرایش می‌کند
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $eventId = $this->meeting($creator, [$guest], ['OwnerUserID' => $owner]);
        $this->respond($owner, $eventId, 'ACCEPTED');
        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->age($eventId);

        // جابه‌جایی (Drag & Drop) توسطِ مالک
        $res = $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/move", ['StartDateTime' => $this->sqlTime(2880), 'EndDateTime' => $this->sqlTime(2940)])->assertOk();
        $this->assertSame(1, $res->json('responsesReset'));
        $this->assertSame('ACCEPTED', $this->attendee($eventId, $owner)->ResponseStatus, 'ویرایشگر بازنشانی نمی‌شود');
        $this->assertSame(['PENDING', 'ACCEPTED'], [$this->attendee($eventId, $guest)->ResponseStatus, $this->attendee($eventId, $guest)->PreviousResponseStatus]);
        $this->assertSame('ORGANIZER', $this->attendee($eventId, $creator)->RelationType); // ردیفِ برگزارکننده دست نخورد
    }

    public function test_viewers_and_unauthorized_users_get_nothing(): void
    {
        $creator = $this->calendarUser([...self::CAL, 'CALENDAR_SHARE']);
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $reader = $this->calendarUser(['CALENDAR_VIEW', 'CALENDAR_EDIT']);
        $busy = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoints = array_map(fn ($u) => $this->subscribe($u), [$reader, $busy]);
        DB::insert("INSERT INTO dbo.CalendarShares (OwnerUserID, SharedWithUserID, AccessLevel) VALUES (?, ?, N'READ'), (?, ?, N'FREEBUSY')", [$creator, $reader, $creator, $busy]);
        $eventId = $this->meeting($creator, [$guest]);
        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->age($eventId);

        // بینندهٔ READ نمی‌تواند زمان را عوض کند (و چیزی بازنشانی نمی‌شود)
        $this->edit($reader, $eventId, ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)])->assertStatus(403);
        $this->actingAs(User::find($reader))->postJson("/calendar/events/{$eventId}/move", ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)])->assertStatus(403);
        $this->assertSame('ACCEPTED', $this->attendee($eventId, $guest)->ResponseStatus);

        // تغییرِ واقعی توسطِ سازنده → فقط شرکت‌کننده؛ بینندگانِ READ/FREEBUSY نه Push نه دعوت
        $this->edit($creator, $eventId, ['StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)])->assertOk();
        $this->dispatch();
        foreach ([$reader, $busy] as $i => $viewer) {
            $this->assertSame([], $this->outbox($viewer, 'INVITE'));
            $this->assertSame([], $this->fake->sentTo($endpoints[$i]));
            $this->assertSame([], $this->actingAs(User::find($viewer))->getJson('/calendar/invitations')->json('invitations'));
        }
    }

    /** بخشِ ۶: تغییرِ زمان از فرم، یادآوریِ شرکت‌کننده‌ای را که در درخواست نیامده هم به زمانِ تازه می‌برد */
    public function test_time_change_moves_every_participants_reminder(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $eventId = $this->meeting($creator, [$guest], ['StartDateTime' => '2099-07-01 10:00:00', 'EndDateTime' => '2099-07-01 11:00:00', 'ReminderOffsetMinutes' => 15]);
        $this->actingAs(User::find($guest))->postJson("/calendar/events/{$eventId}/reminder", ['ReminderOffsetMinutes' => 30])->assertOk();

        // بدونِ AttendeeReminders (مثلِ ویرایشگرِ بدونِ «برای دیگران» یا کلاینتِ دیگر)
        $this->edit($creator, $eventId, ['StartDateTime' => '2099-07-02 14:00:00', 'EndDateTime' => '2099-07-02 15:00:00', 'ReminderOffsetMinutes' => 15])->assertOk();

        $remindAt = fn (int $u) => substr((string) DB::table('Reminders')->where('EntityType', 'CALENDAR_EVENT')->where('EntityID', $eventId)->where('UserID', $u)->where('IsActive', 1)->value('RemindAt'), 0, 19);
        $this->assertSame('2099-07-02 13:30:00', $remindAt($guest));
        $this->assertSame('2099-07-02 13:45:00', $remindAt($creator));

        // ویرایشِ فقط عنوان یادآوری را دست نمی‌زند
        DB::update("UPDATE dbo.Reminders SET RemindAt = '2099-07-02 13:31:00' WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ? AND UserID = ?", [$eventId, $guest]);
        $this->edit($creator, $eventId, ['Title' => 'عنوانِ دیگر', 'ReminderOffsetMinutes' => 15])->assertOk();
        $this->assertSame('2099-07-02 13:31:00', $remindAt($guest));
    }

    public function test_cancelling_with_a_time_change_does_not_reset_yet(): void
    {
        $creator = $this->calendarUser();
        $guest = $this->calendarUser(['CALENDAR_VIEW']);
        $eventId = $this->meeting($creator, [$guest]);
        $this->respond($guest, $eventId, 'ACCEPTED');
        $this->age($eventId);

        // لغو (بخشِ ۶) — حتی همراه با تغییرِ زمان، پاسخ‌ها بازنشانی نمی‌شوند
        $res = $this->edit($creator, $eventId, ['Status' => 'CANCELLED', 'StartDateTime' => $this->sqlTime(1560), 'EndDateTime' => $this->sqlTime(1620)])->assertOk();
        $this->assertSame(0, $res->json('responsesReset'));
        $this->assertSame('ACCEPTED', $this->attendee($eventId, $guest)->ResponseStatus);
    }
}
