<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * اعلانِ یادآوریِ رویدادِ تقویم از مسیرِ واقعی: ثبتِ رویداد/یادآوری با همان Controllerها، اعلانِ داخلِ برنامه
 * (/calendar/reminders/due → ReminderWatcher) و Push از همان Outbox/Scheduler — بدونِ زیرساختِ موازی.
 * Transportِ ساختگی: هیچ اعلانِ واقعی ارسال نمی‌شود؛ همه‌چیز با DatabaseTransactions برمی‌گردد.
 */
class CalendarReminderNotificationTest extends PushTestCase
{
    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate(60); // مرزِ شروعِ Push یک ساعت قبل؛ یادآوریِ چند دقیقهٔ قبل در محدوده است
    }

    private function calendarUser(array $perms = self::CAL): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    /** ساعتِ دیواریِ SQL Server با فاصلهٔ دقیقه — داده‌هایِ تقویم بر همین مبنا ذخیره می‌شوند */
    private function sqlTime(int $minutes): string
    {
        return DB::selectOne('SELECT CONVERT(varchar(19), DATEADD(MINUTE, ?, SYSDATETIME()), 120) AS t', [$minutes])->t;
    }

    /** رویدادی که ۵ دقیقهٔ دیگر شروع می‌شود با یادآوریِ «۱۰ دقیقه قبل» → یادآوری همین الان سررسیده است */
    private function dueEvent(int $as, array $data = []): int
    {
        return (int) $this->actingAs(User::find($as))->postJson('/calendar/events', $data + [
            'Title' => 'جلسهٔ محرمانهٔ آزمون ' . bin2hex(random_bytes(3)),
            'StartDateTime' => $this->sqlTime(5),
            'EndDateTime' => $this->sqlTime(65),
            'RecurrenceType' => 'NONE',
            'ReminderOffsetMinutes' => 10,
        ])->assertOk()->json('eventId');
    }

    private function reminderId(int $eventId, int $userId): int
    {
        return (int) DB::selectOne("SELECT ReminderID FROM dbo.Reminders WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ? AND UserID = ?", [$eventId, $userId])->ReminderID;
    }

    private function dueIds(int $userId): array
    {
        return collect($this->actingAs(User::find($userId))->getJson('/calendar/reminders/due')->assertOk()->json('reminders'))
            ->pluck('ReminderID')->map(fn ($v) => (int) $v)->all();
    }

    public function test_due_event_reminder_is_shown_in_app_and_pushed_once(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u);
        $rid = $this->reminderId($eventId, $u);

        $this->assertContains($rid, $this->dueIds($u)); // اعلانِ داخلِ برنامه (ReminderWatcher)

        $this->dispatch();
        $this->dispatch(); // اجرایِ دوبارهٔ Scheduler
        $sent = $this->fake->sentTo($endpoint);
        $this->assertCount(1, $sent, 'برایِ یک یادآوری فقط یک Push');
        $this->assertSame(['REMINDER', '/calendar'], [$sent[0]['payload']['kind'], $sent[0]['payload']['url']]);
        // موضوعِ یادآوری برایِ صاحبش در اعلان می‌آید (رویدادِ خصوصی عمومی می‌ماند — CalendarReminderContentTest)
        $this->assertStringStartsWith('یادآوری: جلسهٔ محرمانهٔ آزمون', $sent[0]['payload']['title']);
        $this->assertSame('high', $sent[0]['options']['urgency']);
        $this->assertCount(1, $this->outbox($u, 'REMINDER'));

        // پس از ارسالِ Push، یادآوری تا «متوجه شدم» در برنامه می‌ماند (معنایِ IsSent تغییر نکرده)
        $this->assertContains($rid, $this->dueIds($u));
    }

    public function test_acknowledged_reminder_is_not_pushed_and_disappears_in_app(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u);
        $rid = $this->reminderId($eventId, $u);

        $this->actingAs(User::find($u))->postJson("/calendar/reminders/{$rid}/seen")->assertOk()->assertJson(['success' => true]);
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertNotContains($rid, $this->dueIds($u));
    }

    public function test_reminder_acknowledged_after_queueing_is_skipped(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u);
        $rid = $this->reminderId($eventId, $u);

        $this->store()->enqueue(null); // در صف، هنوز ارسال‌نشده
        $this->actingAs(User::find($u))->postJson("/calendar/reminders/{$rid}/seen")->assertOk();
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_ACKNOWLEDGED', $this->outbox($u, 'REMINDER')[0]->Status);
    }

    public function test_deleted_event_reminder_is_neither_shown_nor_pushed(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u);
        $rid = $this->reminderId($eventId, $u);

        $this->actingAs(User::find($u))->deleteJson("/calendar/events/{$eventId}")->assertOk();
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertNotContains($rid, $this->dueIds($u));
    }

    public function test_reminder_set_for_another_user_goes_only_to_that_user(): void
    {
        $creator = $this->calendarUser();
        $attendee = $this->calendarUser(['CALENDAR_VIEW']);
        $creatorEndpoint = $this->subscribe($creator);
        $attendeeEndpoint = $this->subscribe($attendee);
        $eventId = $this->dueEvent($creator, ['ReminderOffsetMinutes' => null, 'AttendeeUserIDs' => [$attendee], 'AttendeeReminders' => [$attendee => 10]]);
        $rid = $this->reminderId($eventId, $attendee);

        $this->assertContains($rid, $this->dueIds($attendee));
        $this->assertNotContains($rid, $this->dueIds($creator));
        $this->dispatch();

        // از پچِ ۱۰۹ شرکت‌کننده دعوت (INVITE) هم می‌گیرد؛ اینجا فقط Pushِ یادآوری شمرده می‌شود
        $reminderPushes = fn (string $endpoint) => array_values(array_filter($this->fake->sentTo($endpoint), fn ($s) => $s['payload']['kind'] === 'REMINDER'));
        $this->assertCount(1, $reminderPushes($attendeeEndpoint));
        $this->assertCount(0, $this->fake->sentTo($creatorEndpoint)); // سازنده هیچ Pushی (نه یادآوری نه دعوت) نمی‌گیرد
        // دیگری نمی‌تواند یادآوریِ او را تأیید کند
        $this->actingAs(User::find($creator))->postJson("/calendar/reminders/{$rid}/seen")->assertStatus(404);
    }

    public function test_removed_attendee_gets_no_reminder(): void
    {
        $creator = $this->calendarUser();
        $attendee = $this->calendarUser(['CALENDAR_VIEW']);
        $attendeeEndpoint = $this->subscribe($attendee);
        $start = $this->sqlTime(5);
        $end = $this->sqlTime(65);
        $eventId = $this->dueEvent($creator, ['StartDateTime' => $start, 'EndDateTime' => $end, 'AttendeeUserIDs' => [$attendee], 'AttendeeReminders' => [$attendee => 10]]);

        // ویرایش بدونِ آن شرکت‌کننده → یادآوریِ او غیرفعال می‌شود
        $this->actingAs(User::find($creator))->putJson("/calendar/events/{$eventId}", [
            'Title' => 'جلسه', 'StartDateTime' => $start, 'EndDateTime' => $end, 'RecurrenceType' => 'NONE', 'ReminderOffsetMinutes' => 10, 'AttendeeUserIDs' => [],
        ])->assertOk();
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($attendeeEndpoint));
        $this->assertSame([], $this->dueIds($attendee));
    }

    public function test_recurring_event_gets_one_push_per_occurrence(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u, ['RecurrenceType' => 'DAILY', 'RecurrenceInterval' => 1]);
        $rid = $this->reminderId($eventId, $u);

        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint));

        // «متوجه شدم» → یادآوری به رخدادِ فردا منتقل می‌شود (منطقِ فعلیِ تکرار)
        $this->actingAs(User::find($u))->postJson("/calendar/reminders/{$rid}/seen")->assertOk()->assertJson(['rescheduled' => true]);
        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint), 'رخدادِ بعدی هنوز سررسید نشده');

        // گذشتِ زمان تا رخدادِ بعدی (شبیه‌سازی) → دقیقاً یک Pushِ تازه
        DB::update('UPDATE dbo.Reminders SET RemindAt = DATEADD(MINUTE, -1, CAST(SYSDATETIME() AS DATETIME2(0))) WHERE ReminderID = ?', [$rid]);
        $this->dispatch();
        $this->dispatch();
        $this->assertCount(2, $this->fake->sentTo($endpoint));
    }

    public function test_cancelled_event_has_no_in_app_or_push_reminder(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u, ['Status' => 'CANCELLED']);
        $rid = $this->reminderId($eventId, $u);

        $this->assertNotContains($rid, $this->dueIds($u)); // ReminderWatcher / پنجرهٔ یادآوری
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertCount(0, $this->outbox($u, 'REMINDER')); // اصلاً وارد صفِ Push نمی‌شود
    }

    public function test_event_cancelled_after_queueing_is_not_pushed(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $start = $this->sqlTime(5);
        $end = $this->sqlTime(65);
        $eventId = $this->dueEvent($u, ['StartDateTime' => $start, 'EndDateTime' => $end]);
        $rid = $this->reminderId($eventId, $u);

        $this->store()->enqueue(null); // یادآوری در صف، هنوز ارسال‌نشده
        $this->actingAs(User::find($u))->putJson("/calendar/events/{$eventId}", [
            'Title' => 'جلسه', 'StartDateTime' => $start, 'EndDateTime' => $end, 'Status' => 'CANCELLED', 'RecurrenceType' => 'NONE', 'ReminderOffsetMinutes' => 10,
        ])->assertOk();
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_INVALID', $this->outbox($u, 'REMINDER')[0]->Status);
        $this->assertNotContains($rid, $this->dueIds($u));
    }

    public function test_reminder_cannot_be_set_for_unrelated_user(): void
    {
        $creator = $this->calendarUser();
        $attendee = $this->calendarUser(['CALENDAR_VIEW']);
        $stranger = $this->calendarUser(['CALENDAR_VIEW']);
        $strangerEndpoint = $this->subscribe($stranger);
        $start = $this->sqlTime(5);
        $end = $this->sqlTime(65);

        // ایجاد: یادآوری برایِ کسی که نه مالک است نه شرکت‌کننده → رد
        $this->actingAs(User::find($creator))->postJson('/calendar/events', [
            'Title' => 'جلسه', 'StartDateTime' => $start, 'EndDateTime' => $end, 'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => [$attendee], 'AttendeeReminders' => [$stranger => 10],
        ])->assertStatus(422);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.Reminders WHERE UserID = ?', [$stranger])->c);

        // ویرایش: همان قاعده
        $eventId = $this->dueEvent($creator, ['StartDateTime' => $start, 'EndDateTime' => $end, 'AttendeeUserIDs' => [$attendee]]);
        $this->actingAs(User::find($creator))->putJson("/calendar/events/{$eventId}", [
            'Title' => 'جلسه', 'StartDateTime' => $start, 'EndDateTime' => $end, 'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => [$attendee], 'AttendeeReminders' => [$stranger => 10],
        ])->assertStatus(422);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.Reminders WHERE UserID = ?', [$stranger])->c);

        // کاربرِ بی‌ارتباط هیچ یادآوری‌ای از این رویداد نمی‌بیند یا نمی‌گیرد؛ مالک و شرکت‌کننده مجازند
        $this->actingAs(User::find($creator))->putJson("/calendar/events/{$eventId}", [
            'Title' => 'جلسه', 'StartDateTime' => $start, 'EndDateTime' => $end, 'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => [$attendee], 'AttendeeReminders' => [$attendee => 10],
        ])->assertOk();
        $this->dispatch();
        $this->assertSame([], $this->dueIds($stranger));
        $this->assertCount(0, $this->fake->sentTo($strangerEndpoint));
        $this->assertContains($this->reminderId($eventId, $attendee), $this->dueIds($attendee));
    }

    public function test_user_without_create_for_others_cannot_set_reminders_for_others(): void
    {
        $limited = $this->calendarUser(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT']);
        $other = $this->calendarUser(['CALENDAR_VIEW']);
        $this->actingAs(User::find($limited))->postJson('/calendar/events', [
            'Title' => 'جلسه', 'StartDateTime' => $this->sqlTime(5), 'EndDateTime' => $this->sqlTime(65), 'RecurrenceType' => 'NONE',
            'AttendeeReminders' => [$other => 10],
        ])->assertStatus(403);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.Reminders WHERE UserID = ?', [$other])->c);
    }

    // ---------- پچِ ۱۰۸: کاربرِ فعال، مجوزِ CALENDAR_VIEW، دعوتِ ردشده ----------

    public function test_inactive_user_gets_no_reminder(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u);
        $rid = $this->reminderId($eventId, $u);
        DB::update('UPDATE dbo.Users SET IsActive = 0 WHERE UserID = ?', [$u]);

        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertCount(0, $this->outbox($u, 'REMINDER')); // وارد صف نمی‌شود
        $this->assertSame([], array_map(fn ($r) => (int) $r->ReminderID, DB::select('EXEC dbo.sp_GetDueCalendarReminders @UserID = ?', [$u])));
        $this->assertNotEmpty($rid);
    }

    public function test_user_deactivated_after_queueing_is_skipped(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $this->dueEvent($u);

        $this->store()->enqueue(null);
        DB::update('UPDATE dbo.Users SET IsActive = 0 WHERE UserID = ?', [$u]);
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_INVALID', $this->outbox($u, 'REMINDER')[0]->Status);
    }

    public function test_user_without_calendar_view_gets_no_reminder(): void
    {
        $creator = $this->calendarUser();
        $noPermission = $this->createUserWithoutRoles(); // هیچ مجوزی، از جمله CALENDAR_VIEW
        $endpoint = $this->subscribe($noPermission);
        $eventId = $this->dueEvent($creator, ['AttendeeUserIDs' => [$noPermission], 'AttendeeReminders' => [$noPermission => 10]]);
        $rid = $this->reminderId($eventId, $noPermission);

        $this->assertNotContains($rid, $this->dueIds($noPermission)); // پنجرهٔ یادآوری
        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertCount(0, $this->outbox($noPermission, 'REMINDER'));

        // یادآوریِ مستقل هم همین‌طور
        DB::insert("INSERT INTO dbo.Reminders (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, Title, IsActive, CreatedByUserID)
                    VALUES (N'STANDALONE', 0, ?, 0, DATEADD(MINUTE, -1, CAST(SYSDATETIME() AS DATETIME2(0))), N'یادآوری', 1, ?)", [$noPermission, $creator]);
        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame([], $this->dueIds($noPermission));
    }

    public function test_calendar_view_revoked_after_queueing_is_skipped(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $this->dueEvent($u);

        $this->store()->enqueue(null);
        DB::update('UPDATE dbo.UserRoles SET IsActive = 0 WHERE UserID = ?', [$u]); // نقش (و مجوزِ تقویم) گرفته شد
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_INVALID', $this->outbox($u, 'REMINDER')[0]->Status);
        $this->assertSame([], $this->dueIds($u));
    }

    public function test_attendee_who_declined_gets_no_reminder_but_others_do(): void
    {
        $creator = $this->calendarUser();
        $declined = $this->calendarUser(['CALENDAR_VIEW']);
        $accepted = $this->calendarUser(['CALENDAR_VIEW']);
        $declinedEndpoint = $this->subscribe($declined);
        $acceptedEndpoint = $this->subscribe($accepted);
        $eventId = $this->dueEvent($creator, ['AttendeeUserIDs' => [$declined, $accepted], 'AttendeeReminders' => [$declined => 10, $accepted => 10]]);

        $this->actingAs(User::find($declined))->postJson("/calendar/events/{$eventId}/respond", ['ResponseStatus' => 'REJECTED'])->assertOk();
        $this->actingAs(User::find($accepted))->postJson("/calendar/events/{$eventId}/respond", ['ResponseStatus' => 'ACCEPTED'])->assertOk();

        $this->assertSame([], $this->dueIds($declined));
        $this->assertContains($this->reminderId($eventId, $accepted), $this->dueIds($accepted));
        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($declinedEndpoint));
        $this->assertCount(1, $this->fake->sentTo($acceptedEndpoint));
    }

    public function test_attendee_declining_after_queueing_is_skipped(): void
    {
        $creator = $this->calendarUser();
        $attendee = $this->calendarUser(['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($attendee);
        $eventId = $this->dueEvent($creator, ['AttendeeUserIDs' => [$attendee], 'AttendeeReminders' => [$attendee => 10]]);

        $this->store()->enqueue(null);
        $this->actingAs(User::find($attendee))->postJson("/calendar/events/{$eventId}/respond", ['ResponseStatus' => 'REJECTED'])->assertOk();
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_INVALID', $this->outbox($attendee, 'REMINDER')[0]->Status);
    }

    public function test_permission_function_matches_sp_check_user_permission(): void
    {
        $with = $this->calendarUser(['CALENDAR_VIEW']);
        $without = $this->createUserWithoutRoles();
        foreach ([$with, $without] as $uid) {
            $sp = (int) DB::selectOne('EXEC dbo.sp_CheckUserPermission @UserID = ?, @PermissionCode = ?', [$uid, 'CALENDAR_VIEW'])->HasPermission;
            $fn = (int) DB::selectOne("SELECT COUNT(*) c FROM dbo.fn_UserHasPermission(?, N'CALENDAR_VIEW')", [$uid])->c;
            $this->assertSame($sp, $fn);
        }
    }

    public function test_moved_event_reminder_follows_new_time_without_duplicate(): void
    {
        $u = $this->calendarUser();
        $endpoint = $this->subscribe($u);
        $eventId = $this->dueEvent($u);

        $this->store()->enqueue(null); // یادآوریِ زمانِ قبلی در صف
        // جابه‌جایی به یک ساعتِ بعد → یادآوری ۵۰ دقیقهٔ دیگر؛ ردیفِ قبلیِ صف کنار گذاشته می‌شود
        $this->actingAs(User::find($u))->postJson("/calendar/events/{$eventId}/move", ['StartDateTime' => $this->sqlTime(60), 'EndDateTime' => $this->sqlTime(120)])->assertOk();
        $this->dispatch();

        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_ACKNOWLEDGED', $this->outbox($u, 'REMINDER')[0]->Status);
        $this->assertSame([], $this->dueIds($u));
    }
}
