<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * موضوع و متنِ یادآوری در Push و پنجرهٔ داخلِ برنامه: فقط برایِ صاحبِ یادآوری، با کوتاه‌سازیِ مناسب،
 * جایگزینِ درست برایِ متنِ خالی، و بدونِ جزئیات برایِ رویدادِ خصوصی رویِ صفحهٔ قفل. همه Rollback.
 */
class CalendarReminderContentTest extends PushTestCase
{
    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate(10);
    }

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

    /** رویدادی که ۵ دقیقهٔ دیگر شروع می‌شود با یادآوریِ «۱۰ دقیقه قبل» (یعنی سررسیدشده) برایِ $user */
    private function dueEventReminder(int $user, array $extra = []): int
    {
        $eventId = (int) $this->actingAs(User::find($user))->postJson('/calendar/events', $extra + [
            'Title' => 'جلسهٔ بودجه', 'Description' => 'بررسیِ بودجهٔ سه‌ماههٔ اول',
            'StartDateTime' => $this->sqlTime(5), 'EndDateTime' => $this->sqlTime(65), 'RecurrenceType' => 'NONE', 'ReminderOffsetMinutes' => 10,
        ])->assertOk()->json('eventId');
        DB::update("UPDATE dbo.Reminders SET RemindAt = DATEADD(MINUTE, -10, (SELECT StartDateTime FROM dbo.CalendarEvents WHERE EventID = ?)) WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ?", [$eventId, $eventId]);

        return $eventId;
    }

    private function due(int $user): array
    {
        return $this->actingAs(User::find($user))->getJson('/calendar/reminders/due')->assertOk()->json('reminders');
    }

    public function test_push_and_in_app_show_subject_and_text(): void
    {
        $user = $this->user();
        $ep = $this->subscribe($user);
        $eventId = $this->dueEventReminder($user);

        $this->dispatch();

        $p = $this->fake->sentTo($ep)[0]['payload'];
        $this->assertSame(['یادآوری: جلسهٔ بودجه', 'بررسیِ بودجهٔ سه‌ماههٔ اول', 'REMINDER'], [$p['title'], $p['body'], $p['kind']]);
        $r = collect($this->due($user))->firstWhere('EventID', $eventId);
        $this->assertSame(['جلسهٔ بودجه', 'بررسیِ بودجهٔ سه‌ماههٔ اول'], [$r['Title'], $r['Description']]);
    }

    public function test_long_text_is_shortened_in_push_but_complete_in_app(): void
    {
        $user = $this->user();
        $ep = $this->subscribe($user);
        $title = str_repeat('عنوانِ بلند ', 12);
        $desc = str_repeat("متنِ بسیار طولانیِ یادآوری\n", 20);
        $eventId = $this->dueEventReminder($user, ['Title' => $title, 'Description' => $desc]);

        $this->dispatch();

        $sent = $this->fake->sentTo($ep)[0];
        $this->assertLessThanOrEqual(\App\Services\Push\PushPayloadFactory::MAX_BYTES, strlen($sent['rawPayload']));
        $this->assertSame(mb_strlen('یادآوری: ') + 60, mb_strlen($sent['payload']['title']));
        $this->assertSame(150, mb_strlen($sent['payload']['body']));
        $this->assertStringEndsWith('…', $sent['payload']['body']);
        $this->assertStringNotContainsString("\n", $sent['payload']['body']);
        // متنِ کامل در جزئیاتِ رویداد
        $this->assertSame(trim($desc), $this->actingAs(User::find($user))->getJson("/calendar/events/{$eventId}")->json('event.Description')); // TrimStringsِ Laravel
    }

    public function test_empty_text_falls_back_to_start_time(): void
    {
        $user = $this->user();
        $ep = $this->subscribe($user);
        $eventId = $this->dueEventReminder($user, ['Description' => null]);

        $this->dispatch();

        $p = $this->fake->sentTo($ep)[0]['payload'];
        $this->assertSame('یادآوری: جلسهٔ بودجه', $p['title']);
        $this->assertMatchesRegularExpression('#^شروع: 14\d\d/\d\d/\d\d ساعت \d\d:\d\d$#u', $p['body']);
        $this->assertNull(collect($this->due($user))->firstWhere('EventID', $eventId)['Description']);
    }

    public function test_private_event_push_stays_generic_but_participant_sees_details_in_app(): void
    {
        $owner = $this->user();
        $guest = $this->user(['CALENDAR_VIEW']);
        $ep = $this->subscribe($guest);
        $eventId = $this->dueEventReminder($owner, ['IsPrivate' => true, 'AttendeeUserIDs' => [$guest], 'AttendeeReminders' => [$guest => 10]]);
        DB::update("UPDATE dbo.Reminders SET RemindAt = DATEADD(MINUTE, -10, (SELECT StartDateTime FROM dbo.CalendarEvents WHERE EventID = ?)) WHERE EntityType = N'CALENDAR_EVENT' AND EntityID = ?", [$eventId, $eventId]);
        DB::update('UPDATE dbo.CalendarEventAttendees SET InvitedAt = DATEADD(MINUTE, -5, InvitedAt) WHERE EventID = ?', [$eventId]);

        $this->dispatch();

        $reminderPush = collect($this->fake->sentTo($ep))->firstWhere('payload.kind', 'REMINDER');
        $this->assertSame(['راگا ۳۶۰', 'یادآوریِ یک رویدادِ خصوصی دارید.'], [$reminderPush['payload']['title'], $reminderPush['payload']['body']]);
        foreach (['جلسهٔ بودجه', 'بررسیِ بودجه'] as $secret) {
            $this->assertStringNotContainsString($secret, $reminderPush['rawPayload']);
        }
        $r = collect($this->due($guest))->firstWhere('EventID', $eventId);
        $this->assertSame(['جلسهٔ بودجه', 'بررسیِ بودجهٔ سه‌ماههٔ اول'], [$r['Title'], $r['Description']]);
    }

    public function test_switch_off_keeps_the_old_generic_push(): void
    {
        config(['push.reminder_details' => false]);
        $user = $this->user();
        $ep = $this->subscribe($user);
        $this->dueEventReminder($user);

        $this->dispatch();

        $p = $this->fake->sentTo($ep)[0]['payload'];
        $this->assertSame(['راگا ۳۶۰', 'یک یادآوریِ سررسیدشده دارید.'], [$p['title'], $p['body']]);
    }

    public function test_viewers_without_participation_get_no_reminder_content(): void
    {
        $owner = $this->user();
        $reader = $this->user(['CALENDAR_VIEW']);
        DB::insert("INSERT INTO dbo.CalendarShares (OwnerUserID, SharedWithUserID, AccessLevel) VALUES (?, ?, N'READ')", [$owner, $reader]);
        $eventId = $this->dueEventReminder($owner);

        // بینندهٔ READ نمی‌تواند برایِ رویدادِ دیگری یادآوری بگذارد و هیچ یادآوری/متنی نمی‌گیرد
        $this->actingAs(User::find($reader))->postJson("/calendar/events/{$eventId}/reminder", ['ReminderOffsetMinutes' => 10])->assertStatus(403);
        $this->assertSame([], $this->due($reader));
    }
}
