<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Services\Push\PushBroadcastText;
use App\Services\Push\PushPayloadFactory;
use App\Services\Push\PushResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * «ارسال اعلان عمومی»: دسترسی، پیش‌نمایش/شمارشِ گیرندگان، فقط کاربرانِ فعالِ دارایِ اشتراک، جلوگیری از تکرار،
 * پاک‌سازیِ متن و سقفِ طول، وضعیتِ ارسال، عدمِ ثبت در UserNotifications و اولویتِ اعلان‌هایِ کاری.
 */
class PushBroadcastTest extends PushTestCase
{
    private function admin(): User
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, ['PUSH_BROADCAST']);

        return User::find($id);
    }

    private function send(User $as, array $data): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as)->postJson('/push/broadcasts', $data + [
            'title' => 'اطلاعیه',
            'body' => 'سامانه امشب به‌روزرسانی می‌شود.',
            'targetMode' => 'USERS',
            'idempotencyKey' => (string) Str::uuid(),
            'confirmed' => true,
        ]);
    }

    private function broadcastRow(int $id): object
    {
        return collect($this->store()->broadcasts(50, $id))->first();
    }

    public function test_requires_login_and_push_broadcast_permission(): void
    {
        $this->getJson('/push/broadcasts/users')->assertStatus(401);
        $this->postJson('/push/broadcasts', [])->assertStatus(401);

        $plain = User::find($this->createUserWithoutRoles());
        $this->actingAs($plain)->get('/push/broadcasts')->assertStatus(403);
        $this->actingAs($plain)->getJson('/push/broadcasts/users')->assertStatus(403);
        $this->actingAs($plain)->getJson('/push/broadcasts/list')->assertStatus(403);
        $this->actingAs($plain)->postJson('/push/broadcasts/preview', ['title' => 'a', 'body' => 'b', 'targetMode' => 'ALL'])->assertStatus(403);
        $this->send($plain, ['targetMode' => 'ALL'])->assertStatus(403);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.PushBroadcasts WHERE CreatedByUserID = ?', [$plain->UserID])->c);

        $this->actingAs($this->admin())->get('/push/broadcasts')->assertOk();
    }

    public function test_preview_counts_only_active_users_with_subscription(): void
    {
        $admin = $this->admin();
        $withTwo = $this->createUserWithoutRoles();
        $none = $this->createUserWithoutRoles();
        $inactive = $this->createUserWithoutRoles();
        $this->subscribe($withTwo);
        $this->subscribe($withTwo, $this->endpoint('updates.push.services.mozilla.com'));
        $this->subscribe($inactive);
        DB::update('UPDATE dbo.Users SET IsActive = 0 WHERE UserID = ?', [$inactive]);

        $p = $this->actingAs($admin)->postJson('/push/broadcasts/preview', [
            'title' => '  اطلاعیه  ', 'body' => 'متن', 'targetMode' => 'USERS', 'userIds' => [$withTwo, $none, $inactive],
        ])->assertOk()->json();

        $this->assertSame(['اطلاعیه', 2, 1, 2, 1], [$p['title'], $p['selectedUsers'], $p['recipients'], $p['devices'], $p['withoutSubscription']]);
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.PushBroadcasts WHERE CreatedByUserID = ?', [$admin->UserID])->c); // پیش‌نمایش چیزی ثبت نمی‌کند

        $users = collect($this->actingAs($admin)->getJson('/push/broadcasts/users')->assertOk()->json('items'))->keyBy('UserID');
        $this->assertTrue($users[$withTwo]['HasSubscription']);
        $this->assertFalse($users[$none]['HasSubscription']);
        $this->assertArrayNotHasKey($inactive, $users->all());
    }

    public function test_send_queues_subscribed_users_delivers_custom_text_and_not_in_user_notifications(): void
    {
        $admin = $this->admin();
        $a = $this->createUserWithoutRoles();
        $b = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($a);
        $notificationsBefore = (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.UserNotifications')->c;

        $r = $this->send($admin, ['userIds' => [$a, $b]])->assertOk()->assertJson(['success' => true, 'duplicate' => false])->json();
        $id = (int) $r['broadcastId'];

        $this->assertCount(1, $this->outbox($a, 'BROADCAST'));
        $this->assertCount(0, $this->outbox($b, 'BROADCAST')); // بدونِ اشتراک: نه خطا، نه ردیف
        $row = DB::selectOne('SELECT * FROM dbo.PushBroadcasts WHERE PushBroadcastID = ?', [$id]);
        $this->assertSame([(int) $admin->UserID, 'USERS', 2, 1], [(int) $row->CreatedByUserID, $row->TargetMode, (int) $row->SelectedUserCount, (int) $row->RecipientCount]);
        $this->assertNotEmpty($row->Date_InsertFirst);

        $this->assertSame(1, (int) $this->broadcastRow($id)->PendingCount);
        $this->dispatch();

        $sent = $this->fake->sentTo($endpoint);
        $this->assertCount(1, $sent);
        $this->assertSame(['BROADCAST', 'اطلاعیه', 'سامانه امشب به‌روزرسانی می‌شود.', "bc-{$id}", '/dashboard'],
            [$sent[0]['payload']['kind'], $sent[0]['payload']['title'], $sent[0]['payload']['body'], $sent[0]['payload']['tag'], $sent[0]['payload']['url']]);
        $this->assertSame(86400, $sent[0]['options']['TTL']);

        $stats = $this->broadcastRow($id);
        $this->assertSame([1, 0, 0, 1], [(int) $stats->SentCount, (int) $stats->PendingCount, (int) $stats->FailedCount, (int) $stats->DevicesSent]);
        // نه پیامِ کاری، نه اعلانِ خوانده‌نشده
        $this->assertSame($notificationsBefore, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.UserNotifications')->c);

        $list = $this->actingAs($admin)->getJson('/push/broadcasts/list')->assertOk()->json('items');
        $mine = collect($list)->firstWhere('PushBroadcastID', $id);
        $this->assertSame([1, 0, 0], [$mine['Sent'], $mine['Failed'], $mine['Pending']]);
    }

    public function test_same_idempotency_key_does_not_send_twice(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $this->subscribe($u);
        $key = (string) Str::uuid();

        $first = $this->send($admin, ['userIds' => [$u], 'idempotencyKey' => $key])->assertOk()->json();
        $second = $this->send($admin, ['userIds' => [$u], 'idempotencyKey' => $key])->assertOk()->json();

        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['broadcastId'], $second['broadcastId']);
        $this->assertCount(1, $this->outbox($u, 'BROADCAST'));
        $this->assertSame(1, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.PushBroadcasts WHERE IdempotencyKey = ?', [$key])->c);
    }

    public function test_send_requires_explicit_confirmation_valid_key_and_targets(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $this->subscribe($u);

        $this->send($admin, ['userIds' => [$u], 'confirmed' => false])->assertStatus(422)->assertJsonValidationErrors('confirmed');
        $this->send($admin, ['userIds' => [$u], 'idempotencyKey' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('idempotencyKey');
        $this->send($admin, ['userIds' => []])->assertStatus(422)->assertJsonValidationErrors('userIds');
        $this->send($admin, ['userIds' => [$u], 'targetMode' => 'EVERYONE'])->assertStatus(422);
        $this->assertCount(0, $this->outbox($u, 'BROADCAST'));
    }

    public function test_html_and_unsafe_characters_are_removed_and_lengths_enforced(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $this->subscribe($u);

        $id = $this->send($admin, [
            'userIds' => [$u],
            'title' => "<b>اطلاعیه</b>\u{202E}\u{0007} <script>x</script>",
            'body' => "<img src=x onerror=alert(1)>کمتر از <۵ دقیقه\r\n\r\n\r\n\r\nخطِ دوم\u{200B}",
        ])->assertOk()->json('broadcastId');
        $row = DB::selectOne('SELECT Title, Body FROM dbo.PushBroadcasts WHERE PushBroadcastID = ?', [$id]);
        $this->assertSame('اطلاعیه x', $row->Title);
        $this->assertSame("کمتر از <۵ دقیقه\n\nخطِ دوم", $row->Body);

        // نیم‌فاصله حفظ می‌شود
        $this->assertSame('می‌شود', PushBroadcastText::body('می‌شود'));

        $this->send($admin, ['userIds' => [$u], 'title' => str_repeat('ا', 61)])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->send($admin, ['userIds' => [$u], 'body' => str_repeat('ب', 241)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->send($admin, ['userIds' => [$u], 'body' => '<p></p>'])->assertStatus(422)->assertJsonValidationErrors('body');
        // ایموجی در SQL دو واحد حساب می‌شود؛ ۳۱ ایموجی = ۶۲ > ۶۰
        $this->send($admin, ['userIds' => [$u], 'title' => str_repeat('😀', 31)])->assertStatus(422)->assertJsonValidationErrors('title');
    }

    public function test_longest_persian_text_fits_the_payload_limit(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($u);
        $title = mb_substr(str_repeat("اطلاعیه‌", 10), 0, 60);
        $body = mb_substr(str_repeat("به‌روزرسانی‌ «سامانه» \"راگا\" ", 20), 0, 240);
        $this->assertSame(60, PushBroadcastText::length($title));

        $this->send($admin, ['userIds' => [$u], 'title' => $title, 'body' => trim($body)])->assertOk();
        $this->dispatch();

        $sent = $this->fake->sentTo($endpoint);
        $this->assertCount(1, $sent);
        $this->assertSame($title, $sent[0]['payload']['title']);
        $this->assertLessThanOrEqual(PushPayloadFactory::MAX_BYTES, strlen($sent[0]['rawPayload']));
    }

    public function test_all_mode_targets_every_active_subscribed_user(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $this->subscribe($u);
        $this->createUserWithoutRoles(); // بدونِ اشتراک

        $expected = (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.Users U WHERE U.IsActive = 1 AND EXISTS (SELECT 1 FROM dbo.PushSubscriptions PS WHERE PS.UserID = U.UserID AND PS.IsActive = 1)')->c;
        $active = (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.Users WHERE IsActive = 1')->c;

        $p = $this->actingAs($admin)->postJson('/push/broadcasts/preview', ['title' => 'همه', 'body' => 'متن', 'targetMode' => 'ALL'])->assertOk()->json();
        $this->assertSame([$active, $expected], [$p['selectedUsers'], $p['recipients']]);

        $id = $this->send($admin, ['targetMode' => 'ALL'])->assertOk()->json('broadcastId');
        $this->assertSame($expected, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.PushBroadcastRecipients WHERE PushBroadcastID = ?', [$id])->c);
        $this->assertCount(1, $this->outbox($u, 'BROADCAST'));
    }

    public function test_failed_and_expired_devices_are_counted(): void
    {
        $admin = $this->admin();
        $ok = $this->createUserWithoutRoles();
        $bad = $this->createUserWithoutRoles();
        $this->subscribe($ok);
        $dead = $this->subscribe($bad);
        $this->fake->plan($dead, PushResult::fromHttp(410, null, 'gone'));

        $id = $this->send($admin, ['userIds' => [$ok, $bad]])->assertOk()->json('broadcastId');
        $this->dispatch();

        $s = $this->broadcastRow($id);
        $this->assertSame([1, 1, 0, 1, 1], [(int) $s->SentCount, (int) $s->FailedCount, (int) $s->PendingCount, (int) $s->DevicesSent, (int) $s->DevicesFailed]);
        $this->assertSame(0, (int) DB::selectOne('SELECT IsActive FROM dbo.PushSubscriptions WHERE UserID = ?', [$bad])->IsActive);
    }

    public function test_user_deactivated_after_queueing_is_skipped(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($u);
        $id = $this->send($admin, ['userIds' => [$u]])->assertOk()->json('broadcastId');
        DB::update('UPDATE dbo.Users SET IsActive = 0 WHERE UserID = ?', [$u]);

        $this->dispatch();
        $this->assertCount(0, $this->fake->sentTo($endpoint));
        $this->assertSame('SKIPPED_INVALID', $this->outbox($u, 'BROADCAST')[0]->Status);
        $this->assertSame(1, (int) $this->broadcastRow($id)->SkippedCount);
    }

    public function test_work_notifications_are_claimed_before_broadcast_rows_and_keep_generic_text(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($u);
        $this->send($admin, ['userIds' => [$u]])->assertOk();
        DB::update("UPDATE dbo.PushOutbox SET NextAttemptAt = DATEADD(MINUTE, -5, NextAttemptAt) WHERE UserID = ? AND SourceType = 'BROADCAST'", [$u]);
        $this->sendMessage([$u]);
        $this->store()->enqueue(null);
        $this->makeDue();

        // با وجودِ قدیمی‌تر بودنِ اعلانِ عمومی، ردیفِ پیام زودتر برداشته می‌شود
        $claimed = [];
        for ($i = 0; $i < 50 && count(array_intersect($claimed, ['NOTIFICATION', 'BROADCAST'])) < 2; $i++) {
            $batch = $this->store()->claimBatch('order-test', 1, 300);
            if (! $batch) {
                break;
            }
            if ((int) $batch[0]->UserID === $u) {
                $claimed[] = $batch[0]->SourceType;
            }
        }
        $this->assertSame(['NOTIFICATION', 'BROADCAST'], array_values(array_unique($claimed)));

        // ارسالِ واقعی: هر دو می‌رسند، و پیامِ کاری همان متنِ عمومیِ بدونِ جزئیات را دارد
        DB::update("UPDATE dbo.PushOutbox SET Status = 'PENDING', LockedBy = NULL, LockedUntil = NULL, Attempts = 0 WHERE UserID = ?", [$u]);
        $this->makeDue();
        $this->dispatch();
        $kinds = collect($this->fake->sentTo($endpoint))->pluck('payload.kind')->sort()->values()->all();
        $this->assertSame(['BROADCAST', 'MESSAGE'], $kinds);
        $message = collect($this->fake->sentTo($endpoint))->firstWhere('payload.kind', 'MESSAGE');
        $this->assertSame('راگا ۳۶۰', $message['payload']['title']);
        $this->assertStringNotContainsString('اطلاعیه', $message['rawPayload']);
    }

    public function test_cannot_send_when_service_is_not_active(): void
    {
        $admin = $this->admin();
        $u = $this->createUserWithoutRoles();
        $this->subscribe($u);
        DB::selectOne('EXEC dbo.sp_Push_Activate @Enable = 0');

        $this->send($admin, ['userIds' => [$u]])->assertStatus(422)->assertJson(['success' => false]);
        $this->assertCount(0, $this->outbox($u, 'BROADCAST'));
    }
}
