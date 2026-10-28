<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * پچِ ۱۳۲ — «بعداً یادآوری کن» رویِ هر اعلان (نامه، دعوت، فعالیتِ رویداد، …): تا پایانِ تعویق نه در برنامه نه Push؛
 * پس از آن هم در برنامه (یک‌بار) هم Pushِ تازه؛ از دکمهٔ اعلانِ گوشی (PushOutboxID) هم؛ فقط گیرندهٔ همان اعلان.
 */
class NotificationSnoozeTest extends PushTestCase
{
    private function snooze(int $userId, array $body): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::find($userId))->postJson('/notifications/snooze', $body);
    }

    private function due(int $userId): array
    {
        return $this->actingAs(User::find($userId))->getJson('/notifications/snoozes/due')->assertOk()->json('snoozes');
    }

    private function expire(int $snoozeId): void
    {
        DB::update('UPDATE dbo.NotificationSnoozes SET SnoozedUntil = DATEADD(SECOND, -5, CAST(SYSDATETIME() AS DATETIME2(0))) WHERE SnoozeID = ?', [$snoozeId]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->activate(10); // پایانِ تعویقِ «به گذشته برده‌شده» پیش از مرزِ فعال‌سازی نیفتد
    }

    /** رویدادِ فردا با یک مهمانِ مشترک‌شده؛ خروجی: [eventId, owner, guest, guestEndpoint] */
    private function meeting(): array
    {
        $owner = $this->createUserWithoutRoles();
        $guest = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($owner, ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_CREATE_FOR_OTHERS', 'CALENDAR_COMMENT_VIEW', 'CALENDAR_COMMENT_ADD']);
        $this->grantPermissionsTo($guest, ['CALENDAR_VIEW', 'CALENDAR_COMMENT_VIEW']);
        $endpoint = $this->subscribe($guest);
        $start = DB::selectOne('SELECT CONVERT(varchar(19), DATEADD(DAY, 1, SYSDATETIME()), 120) AS t')->t;
        $end = DB::selectOne('SELECT CONVERT(varchar(19), DATEADD(MINUTE, 30, DATEADD(DAY, 1, SYSDATETIME())), 120) AS t')->t;
        $eventId = (int) $this->actingAs(User::find($owner))->postJson('/calendar/events', [
            'Title' => 'جلسهٔ تعویق', 'StartDateTime' => $start, 'EndDateTime' => $end, 'RecurrenceType' => 'NONE', 'AttendeeUserIDs' => [$guest],
        ])->assertOk()->json('eventId');

        return [$eventId, $owner, $guest, $endpoint];
    }

    public function test_message_snooze_returns_in_app_once_and_pushes_again(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $messageId = $this->sendMessage([$user]);
        $subject = DB::table('Messages')->where('MessageID', $messageId)->value('Subject');
        $this->dispatch();
        $first = $this->fake->sentTo($endpoint);
        $this->assertCount(1, $first);
        $this->assertSame(1, $first[0]['payload']['snz'] ?? null, 'دکمهٔ تعویق رویِ اعلانِ گوشی');

        $res = $this->snooze($user, ['Kind' => 'MESSAGE', 'SourceID' => $messageId, 'Minutes' => 10])->assertOk();
        $this->assertStringContainsString('10 دقیقهٔ دیگر', $res->json('message'));
        $snoozeId = (int) $res->json('snoozeId');
        $this->assertSame([], $this->due($user), 'در حالِ تعویق نمایش داده نمی‌شود');
        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint), 'در حالِ تعویق Push نمی‌شود');

        $this->expire($snoozeId);
        $due = $this->due($user);
        $this->assertCount(1, $due);
        $this->assertSame(['MESSAGE', $messageId, $subject, '/messages/' . $messageId], [$due[0]['Kind'], $due[0]['SourceID'], $due[0]['Title'], $due[0]['TargetUrl']]);

        $this->dispatch();
        $sent = $this->fake->sentTo($endpoint);
        $this->assertCount(2, $sent);
        $p = $sent[1]['payload'];
        $this->assertSame(['MESSAGE', '/messages/' . $messageId, 'snz-' . $snoozeId], [$p['kind'], $p['url'], $p['tag']]);
        $this->assertStringStartsWith('یادآوریِ دوباره', $p['body']);
        $this->assertStringNotContainsString($subject, $p['body'], 'موضوع رویِ صفحهٔ قفل نمی‌آید');

        // نمایش در برنامه → دیگر برنمی‌گردد
        $this->actingAs(User::find($user))->postJson("/notifications/snoozes/{$snoozeId}/shown")->assertOk()->assertJson(['success' => true]);
        $this->assertSame([], $this->due($user));

        // تعویقِ دوباره از دکمهٔ همان Pushِ تکراری → همان ردیف، زمانِ تازه
        $pushId = (int) $p['id'];
        $again = $this->snooze($user, ['PushOutboxID' => $pushId, 'Minutes' => 5])->assertOk();
        $this->assertSame([$snoozeId, 'MESSAGE', $messageId], [(int) $again->json('snoozeId'), $again->json('kind'), (int) $again->json('sourceId')]);
        $row = DB::table('NotificationSnoozes')->where('SnoozeID', $snoozeId)->first(['ShownAt', 'IsActive']);
        $this->assertNull($row->ShownAt);
    }

    public function test_validation_and_only_the_recipient_can_snooze(): void
    {
        $user = $this->createUserWithoutRoles();
        $outsider = $this->createUserWithoutRoles();
        $this->subscribe($user);
        $messageId = $this->sendMessage([$user]);
        $this->dispatch();
        $pushId = (int) $this->outbox($user, 'NOTIFICATION')[0]->PushOutboxID;

        $this->snooze($user, ['Kind' => 'MESSAGE', 'SourceID' => $messageId, 'Minutes' => 7])->assertStatus(422);
        $this->snooze($user, ['Kind' => 'MESSAGE', 'SourceID' => $messageId, 'Minutes' => 90])->assertStatus(422);
        $this->snooze($user, ['Kind' => 'NOPE', 'SourceID' => $messageId, 'Minutes' => 5])->assertStatus(422);
        $this->snooze($user, ['Minutes' => 5])->assertStatus(422);
        $this->snooze($outsider, ['Kind' => 'MESSAGE', 'SourceID' => $messageId, 'Minutes' => 5])->assertStatus(422);
        $this->snooze($outsider, ['PushOutboxID' => $pushId, 'Minutes' => 5])->assertStatus(422); // Pushِ کاربرِ دیگر
        $this->assertSame(0, DB::table('NotificationSnoozes')->whereIn('UserID', [$user, $outsider])->count());

        // دکمهٔ اعلانِ گوشی: منبع از خودِ ردیفِ Push
        $ok = $this->snooze($user, ['PushOutboxID' => $pushId, 'Minutes' => 15])->assertOk();
        $this->assertSame(['MESSAGE', $messageId], [$ok->json('kind'), (int) $ok->json('sourceId')]);
        // نمایشِ تعویقِ دیگری ممکن نیست
        $this->actingAs(User::find($outsider))->postJson('/notifications/snoozes/' . (int) $ok->json('snoozeId') . '/shown')->assertOk()->assertJson(['success' => false]);
        $this->getJson('/notifications/snoozes/due')->assertOk(); // همان نشستِ قبلی؛ مسیر فقط با ورود
    }

    public function test_invitation_and_event_activity_can_be_snoozed(): void
    {
        [$eventId, $owner, $guest, $endpoint] = $this->meeting();
        $this->dispatch();
        $invite = $this->outbox($guest, 'INVITE');
        $this->assertCount(1, $invite);
        $this->assertSame(1, $this->fake->sentTo($endpoint)[0]['payload']['snz'] ?? null);

        // دعوت: از دکمهٔ اعلانِ گوشی
        $inv = $this->snooze($guest, ['PushOutboxID' => (int) $invite[0]->PushOutboxID, 'Minutes' => 5])->assertOk();
        $this->assertSame('INVITE', $inv->json('kind'));
        // فعالیتِ رویداد: از اعلانِ داخلِ برنامه
        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/comments", ['Text' => 'دستورِ جلسه'])->assertOk();
        $act = $this->snooze($guest, ['Kind' => 'CALEVENT', 'SourceID' => $eventId, 'Minutes' => 10])->assertOk();
        // صاحبِ جلسه اعلانِ فعالیت نگرفته (نویسنده است) → نمی‌تواند همان را تعویق کند
        $this->snooze($owner, ['Kind' => 'CALEVENT', 'SourceID' => $eventId, 'Minutes' => 10])->assertStatus(422);

        $this->expire((int) $inv->json('snoozeId'));
        $this->expire((int) $act->json('snoozeId'));
        $due = collect($this->due($guest))->keyBy('Kind');
        $this->assertSame(['جلسهٔ تعویق', 'دعوت به جلسه', '/calendar?invites=1'], [$due['INVITE']['Title'], $due['INVITE']['Subtitle'], $due['INVITE']['TargetUrl']]);
        $this->assertSame(['جلسهٔ تعویق', '/calendar'], [$due['CALEVENT']['Title'], $due['CALEVENT']['TargetUrl']]);
        $this->assertNotNull($due['INVITE']['StartDateTime']);

        $before = count($this->fake->sentTo($endpoint));
        $this->dispatch();
        $kinds = array_map(fn ($s) => [$s['payload']['kind'], str_starts_with($s['payload']['body'], 'یادآوریِ دوباره')], array_slice($this->fake->sentTo($endpoint), $before));
        $this->assertContains(['INVITE', true], $kinds);
        $this->assertContains(['CALEVENT', true], $kinds);
    }
}
