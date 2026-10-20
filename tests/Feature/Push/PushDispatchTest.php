<?php

namespace Tests\Feature\Push;

use App\Services\Push\PushResult;
use Illuminate\Support\Facades\DB;

/**
 * Outbox و ارسالِ Web Push (پچ 103) با Transportِ ساختگی: مرزِ شروع، صف‌بندیِ بدونِ تکرار، Claimِ اتمیک،
 * Lease، Retry، اشتراکِ منقضی، چند دستگاه، پیامِ خوانده‌شده و اجرایِ دوبارهٔ Scheduler.
 */
class PushDispatchTest extends PushTestCase
{
    public function test_events_before_activation_are_never_pushed(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $before = $this->sendMessage([$user]);

        $this->activate(); // مرز: بعد از پیامِ اول
        $after = $this->sendMessage([$user]);
        $this->dispatch();

        $urls = array_map(fn ($s) => $s['payload']['url'], $this->fake->sentTo($endpoint));
        $this->assertSame(["/messages/{$after}"], $urls);
        $this->assertNotContains("/messages/{$before}", $urls);
    }

    public function test_events_during_scheduler_downtime_are_picked_up_once(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $m1 = $this->sendMessage([$user]);
        $m2 = $this->sendMessage([$user]);

        // Scheduler تا اینجا اجرا نشده بود؛ اولین اجرا هر دو را می‌گیرد، اجرایِ دوم هیچ
        $stats = $this->dispatch();
        $this->assertSame(2, $stats['sent']);
        $this->dispatch();
        $this->assertCount(2, $this->fake->sentTo($endpoint));
        $this->assertEqualsCanonicalizing(["msg-{$m1}", "msg-{$m2}"], array_map(fn ($s) => $s['payload']['tag'], $this->fake->sent));
    }

    public function test_enqueue_is_idempotent_and_unique_key_blocks_duplicates(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->subscribe($user);
        $this->sendMessage([$user]);

        $this->store()->enqueue(null);
        $this->store()->enqueue(null);
        $rows = $this->outbox($user, 'NOTIFICATION');
        $this->assertCount(1, $rows);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::insert("INSERT INTO dbo.PushOutbox (SourceType, SourceID, SourceVersion, UserID, EventAt, Kind, TargetUrl, Tag)
                    VALUES ('NOTIFICATION', ?, '19000101', ?, SYSDATETIME(), 'MESSAGE', N'/x', 'x')", [$rows[0]->SourceID, $user]);
    }

    public function test_claims_are_disjoint_and_expired_lease_returns_the_record(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->subscribe($user);
        foreach (range(1, 3) as $_) {
            $this->sendMessage([$user]);
        }
        $this->store()->enqueue(null);

        $a = $this->store()->claimBatch('worker-A', 2, 300);
        $b = $this->store()->claimBatch('worker-B', 2, 300);
        $c = $this->store()->claimBatch('worker-C', 2, 300);
        $this->assertCount(2, $a);
        $this->assertCount(1, $b);
        $this->assertCount(0, $c);
        $this->assertEmpty(array_intersect(array_column($a, 'PushOutboxID'), array_column($b, 'PushOutboxID')));

        // پردازشگرِ A از کار افتاد → پس از انقضایِ Lease رکوردهایش به پردازشگرِ دیگر می‌رسد
        DB::update("UPDATE dbo.PushOutbox SET LockedUntil = DATEADD(SECOND, -1, SYSDATETIME()) WHERE LockedBy = 'worker-A'");
        $reclaimed = $this->store()->claimBatch('worker-C', 5, 300);
        $this->assertEqualsCanonicalizing(array_column($a, 'PushOutboxID'), array_column($reclaimed, 'PushOutboxID'));
        $this->assertSame(2, (int) $reclaimed[0]->Attempts);

        // نتیجهٔ دیرهنگامِ A پذیرفته نمی‌شود
        $this->assertFalse($this->store()->completeOutbox((int) $a[0]->PushOutboxID, 'worker-A', 'DONE'));
        $this->assertSame('SENDING', DB::selectOne('SELECT Status FROM dbo.PushOutbox WHERE PushOutboxID = ?', [$a[0]->PushOutboxID])->Status);
    }

    public function test_network_error_retries_with_increasing_delay_then_fails(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $this->sendMessage([$user]);
        config(['push.max_attempts' => 3]);
        $this->fake->plan($endpoint, ...array_fill(0, 5, PushResult::fromHttp(null, null, 'timeout')));

        $this->dispatch();
        $row = $this->outbox($user)[0];
        $this->assertSame('PENDING', $row->Status);
        $this->assertSame(1, (int) $row->Attempts);
        $delay1 = (int) DB::selectOne('SELECT DATEDIFF(SECOND, SYSDATETIME(), NextAttemptAt) AS s FROM dbo.PushOutbox WHERE PushOutboxID = ?', [$row->PushOutboxID])->s;
        $this->assertGreaterThanOrEqual(55, $delay1);

        $this->makeDue();
        $this->dispatch();
        $delay2 = (int) DB::selectOne('SELECT DATEDIFF(SECOND, SYSDATETIME(), NextAttemptAt) AS s FROM dbo.PushOutbox WHERE PushOutboxID = ?', [$row->PushOutboxID])->s;
        $this->assertGreaterThan($delay1 + 30, $delay2); // فاصلهٔ افزایشی

        $this->makeDue();
        $this->dispatch(); // تلاشِ سوم = سقف
        $row = $this->outbox($user)[0];
        $this->assertSame('FAILED', $row->Status);
        $this->assertSame(3, (int) $row->Attempts);
        $this->assertCount(3, $this->fake->sentTo($endpoint));
        $this->assertSame('FAILED', DB::selectOne('SELECT Status FROM dbo.PushDeliveries WHERE PushOutboxID = ?', [$row->PushOutboxID])->Status);
    }

    public function test_retry_after_header_is_respected(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $this->sendMessage([$user]);
        $this->fake->plan($endpoint, PushResult::fromHttp(429, 900, 'rate limited'));

        $this->dispatch();
        $row = $this->outbox($user)[0];
        $this->assertSame('PENDING', $row->Status);
        $delay = (int) DB::selectOne('SELECT DATEDIFF(SECOND, SYSDATETIME(), NextAttemptAt) AS s FROM dbo.PushOutbox WHERE PushOutboxID = ?', [$row->PushOutboxID])->s;
        $this->assertGreaterThanOrEqual(890, $delay);
    }

    public function test_expired_subscription_is_deactivated_without_affecting_other_devices(): void
    {
        $user = $this->createUserWithoutRoles();
        $dead = $this->subscribe($user);
        $alive = $this->subscribe($user, $this->endpoint('web.push.apple.com'));
        $this->sendMessage([$user]);
        $this->fake->plan($dead, PushResult::fromHttp(410, null, 'gone'));

        $this->dispatch();

        $subs = collect(DB::select('SELECT Endpoint, IsActive, DeactivationReason FROM dbo.PushSubscriptions WHERE UserID = ?', [$user]))->keyBy('Endpoint');
        $this->assertSame(0, (int) $subs[$dead]->IsActive);
        $this->assertSame('EXPIRED', $subs[$dead]->DeactivationReason);
        $this->assertSame(1, (int) $subs[$alive]->IsActive);
        $this->assertSame('SENT', $this->outbox($user)[0]->Status);

        // پیامِ بعدی فقط به دستگاهِ سالم می‌رود
        $this->sendMessage([$user]);
        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($dead));
        $this->assertCount(2, $this->fake->sentTo($alive));
    }

    public function test_multiple_devices_and_failed_device_retry_does_not_resend_to_others(): void
    {
        $user = $this->createUserWithoutRoles();
        $a = $this->subscribe($user);
        $b = $this->subscribe($user, $this->endpoint('wns2-par02p.notify.windows.com'));
        $this->sendMessage([$user]);
        $this->fake->plan($a, PushResult::fromHttp(503, null, 'unavailable'));

        $this->dispatch();
        $row = $this->outbox($user)[0];
        $this->assertSame('PENDING', $row->Status);
        $this->assertCount(1, $this->fake->sentTo($a));
        $this->assertCount(1, $this->fake->sentTo($b));

        $this->makeDue();
        $this->dispatch();
        $this->assertCount(2, $this->fake->sentTo($a));
        $this->assertCount(1, $this->fake->sentTo($b)); // دستگاهِ موفق دوباره ارسال نگرفت
        $this->assertSame('SENT', $this->outbox($user)[0]->Status);
        $statuses = DB::select('SELECT Status FROM dbo.PushDeliveries WHERE PushOutboxID = ?', [$row->PushOutboxID]);
        $this->assertSame(['SENT', 'SENT'], array_column($statuses, 'Status'));
    }

    public function test_crash_after_accept_resends_with_the_same_tag(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $messageId = $this->sendMessage([$user]);
        $this->store()->enqueue(null);

        // پردازشگرِ اول Claim کرد، ارسال را شروع کرد (SENDING) و پیش از ثبتِ نتیجه از کار افتاد
        $claimed = $this->store()->claimBatch('crashed-worker', 10, 300);
        $sub = DB::selectOne('SELECT PushSubscriptionID FROM dbo.PushSubscriptions WHERE UserID = ?', [$user]);
        $this->assertTrue($this->store()->beginDelivery((int) $claimed[0]->PushOutboxID, (int) $sub->PushSubscriptionID, 'crashed-worker'));
        DB::update("UPDATE dbo.PushOutbox SET LockedUntil = DATEADD(SECOND, -1, SYSDATETIME()) WHERE LockedBy = 'crashed-worker'");

        // At-least-once: دوباره ارسال می‌شود، با همان Tag تا Service Worker آن را جایگزین/بی‌صدا کند
        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint));
        $this->assertSame("msg-{$messageId}", $this->fake->sent[0]['payload']['tag']);
        $this->assertSame(md5("msg-{$messageId}"), $this->fake->sent[0]['options']['topic']); // Topicِ سازگار با اپل
        $d = DB::selectOne('SELECT Status, Attempts FROM dbo.PushDeliveries WHERE PushOutboxID = ?', [$claimed[0]->PushOutboxID]);
        $this->assertSame('SENT', $d->Status);
        $this->assertSame(2, (int) $d->Attempts);
    }

    public function test_message_read_before_send_is_skipped(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $messageId = $this->sendMessage([$user]);
        $this->store()->enqueue(null);
        $this->assertSame('PENDING', $this->outbox($user)[0]->Status);

        $this->actingAs(\App\Models\User::find($user))->get("/messages/{$messageId}")->assertOk();
        $this->dispatch();

        $this->assertSame('SKIPPED_READ', $this->outbox($user)[0]->Status);
        $this->assertCount(0, $this->fake->sentTo($endpoint));
    }

    public function test_user_without_subscription_receives_nothing(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->sendMessage([$user]);
        $this->dispatch();

        $this->assertSame('SKIPPED_NO_SUBSCRIPTION', $this->outbox($user)[0]->Status);
        $this->assertCount(0, $this->fake->sent);
    }

    public function test_device_subscribed_after_the_event_does_not_get_it(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->sendMessage([$user]);
        DB::update('UPDATE dbo.UserNotifications SET CreateDate = DATEADD(MINUTE, -2, CreateDate) WHERE UserID = ?', [$user]);
        $this->store()->saveSubscription($user, $this->endpoint(), 'BPkey', 'auth', 'aes128gcm', 'PHPUnit'); // اکنون

        $this->dispatch();
        $this->assertSame('SKIPPED_NO_SUBSCRIPTION', $this->outbox($user)[0]->Status);
        $this->assertCount(0, $this->fake->sent);
    }

    public function test_second_notification_for_an_unread_message_is_coalesced(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $messageId = $this->sendMessage([$user]);
        // رویدادِ دیگری (مثلاً تغییرِ وضعیت) برایِ همان پیامِ هنوز خوانده‌نشده اعلانِ دوم ساخت
        DB::insert('INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead) VALUES (?, ?, SYSDATETIME(), 0)', [$user, $messageId]);

        $this->dispatch();
        $statuses = array_column($this->outbox($user, 'NOTIFICATION'), 'Status');
        $this->assertSame(['SENT', 'SKIPPED_COALESCED'], $statuses);
        $this->assertCount(1, $this->fake->sentTo($endpoint));
    }

    public function test_disabled_service_sends_nothing(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->subscribe($user);
        $this->sendMessage([$user]);

        config(['push.enabled' => false]);
        $this->assertSame('disabled-config', $this->dispatch()['status']);

        config(['push.enabled' => true]);
        DB::selectOne('EXEC dbo.sp_Push_Activate @Enable = 0');
        $this->assertSame('not-activated', $this->dispatch()['status']);
        $this->assertCount(0, $this->fake->sent);
        $this->assertCount(0, $this->outbox($user));
    }

    public function test_payload_is_generic_persian_and_small(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->subscribe($user);
        $this->sendMessage([$user]);
        $this->dispatch();

        $sent = $this->fake->sent[0];
        $this->assertSame('راگا ۳۶۰', $sent['payload']['title']);
        $this->assertStringContainsString('کارتابل', $sent['payload']['body']);
        $this->assertStringNotContainsString('Push آزمون', $sent['rawPayload']); // موضوع/متنِ پیام در اعلان نیست
        $this->assertStringNotContainsString('محرمانه', $sent['rawPayload']);
        $this->assertLessThanOrEqual(\App\Services\Push\PushPayloadFactory::MAX_BYTES, strlen($sent['rawPayload']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sent['options']['topic']);
        $this->assertSame('normal', $sent['options']['urgency']);
    }

    public function test_topic_is_apple_safe_alphanumeric_and_unique_for_every_tag_format(): void
    {
        // سرویسِ اپل با 400 BadWebPushTopic رد کرد: Topicِ دارایِ «-» (msg-1) و Topicِ کوتاه (bcx61)؛
        // MD5ِ ۳۲نویسه‌ای پذیرفته شد (پاسخِ واقعیِ web.push.apple.com، ۲۰۲۶-۱۰-۱۸)
        $tags = ['msg-1', 'msg-12', 'rem-1-20261018170000', 'rem-12-20261018170000', 'rem-1-20261018170500',
                 'test-1', 'test-7', 'bc-61', 'bc-6', 'rem-2147483647-20991231235959'];
        $topics = array_map([\App\Services\Push\PushPayloadFactory::class, 'topic'], $tags);
        foreach ($topics as $t) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $t); // طولِ ثابت، فقط حروف/ارقام
        }
        $this->assertSame(count($tags), count(array_unique($topics)));
        // Tagِ یکسان → Topicِ یکسان (جایگزینیِ اعلانِ تحویل‌نشده)
        $this->assertSame('71b381779a0d39c5e858da4b9a52fdbd', \App\Services\Push\PushPayloadFactory::topic('bc-61'));
    }

    public function test_cleanup_never_removes_pending_or_in_progress_records(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->subscribe($user);
        $this->sendMessage([$user]);
        $this->sendMessage([$user]);
        $this->store()->enqueue(null);
        $rows = $this->outbox($user);
        $this->store()->claimBatch('worker-X', 1, 300); // یکی SENDING، دیگری PENDING
        // حتی اگر «قدیمی» به نظر برسند
        DB::update('UPDATE dbo.PushOutbox SET CompletedAt = DATEADD(DAY, -90, SYSDATETIME()), Date_InsertFirst = DATEADD(DAY, -90, SYSDATETIME()) WHERE UserID = ?', [$user]);
        DB::update('UPDATE dbo.PushState SET NotificationScanFromID = 9223372036854775807 WHERE PushStateID = 1');

        $this->store()->cleanup(30);
        $this->assertCount(2, $this->outbox($user));
        $this->assertEqualsCanonicalizing(['SENDING', 'PENDING'], array_column($this->outbox($user), 'Status'));
        $this->assertSame(count($rows), count($this->outbox($user)));
    }

    public function test_scheduler_entry_uses_shared_lock_and_no_overlap(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'push:dispatch'));
        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('* * * * *', $event->expression);
    }
}
