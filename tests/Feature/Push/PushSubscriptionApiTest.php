<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Services\Push\PushEndpointValidator;
use Illuminate\Support\Facades\DB;

/**
 * APIِ اشتراکِ دستگاه: احرازِ هویت، مالکیت، اعتبارسنجیِ Endpoint (SSRF)، چند دستگاه، اعلانِ آزمایشی.
 */
class PushSubscriptionApiTest extends PushTestCase
{
    private function body(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'], 'contentEncoding' => 'aes128gcm'];
    }

    public function test_all_endpoints_require_login(): void
    {
        $this->getJson('/push/config')->assertStatus(401);
        $this->postJson('/push/subscriptions', $this->body($this->endpoint()))->assertStatus(401);
        $this->postJson('/push/subscriptions/list', [])->assertStatus(401);
        $this->postJson('/push/subscriptions/unsubscribe', ['subscriptionId' => 1])->assertStatus(401);
        $this->postJson('/push/test')->assertStatus(401);
    }

    public function test_subscribe_list_and_unsubscribe_own_devices(): void
    {
        $user = $this->createUserWithoutRoles();
        $phone = $this->endpoint();
        $laptop = $this->endpoint('updates.push.services.mozilla.com');

        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($phone))->assertOk()->assertJson(['success' => true]);
        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($laptop))->assertOk();
        // ثبتِ دوبارهٔ همان مرورگر رکوردِ تازه نمی‌سازد
        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($phone))->assertOk();
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.PushSubscriptions WHERE UserID = ?', [$user])->c);

        $items = $this->actingAs(User::find($user))->postJson('/push/subscriptions/list', ['endpoint' => $phone])->assertOk()->json('items');
        $this->assertCount(2, $items);
        $this->assertArrayNotHasKey('Endpoint', $items[0]); // Endpoint و کلیدها به مرورگر برنمی‌گردند
        $this->assertArrayNotHasKey('P256dh', $items[0]);
        $current = collect($items)->first(fn ($i) => (int) $i['IsCurrent'] === 1);
        $this->assertSame('fcm.googleapis.com', $current['ServiceHost']);

        $this->actingAs(User::find($user))->postJson('/push/subscriptions/unsubscribe', ['endpoint' => $phone])->assertOk();
        $active = collect(DB::select('SELECT Endpoint, IsActive FROM dbo.PushSubscriptions WHERE UserID = ?', [$user]))->pluck('IsActive', 'Endpoint');
        $this->assertSame(0, (int) $active[$phone]);   // فقط همین دستگاه لغو شد
        $this->assertSame(1, (int) $active[$laptop]);
    }

    public function test_user_cannot_touch_another_users_subscription(): void
    {
        $owner = $this->createUserWithoutRoles();
        $other = $this->createUserWithoutRoles();
        $this->subscribe($owner);
        $id = (int) DB::selectOne('SELECT PushSubscriptionID FROM dbo.PushSubscriptions WHERE UserID = ?', [$owner])->PushSubscriptionID;

        $this->actingAs(User::find($other))->postJson('/push/subscriptions/unsubscribe', ['subscriptionId' => $id])->assertStatus(404);
        $this->assertSame(1, (int) DB::selectOne('SELECT IsActive FROM dbo.PushSubscriptions WHERE PushSubscriptionID = ?', [$id])->IsActive);
        $this->assertCount(0, $this->actingAs(User::find($other))->postJson('/push/subscriptions/list', [])->json('items'));
    }

    public function test_endpoint_must_be_https_on_an_allowed_push_host(): void
    {
        $user = $this->createUserWithoutRoles();
        $bad = [
            'http://fcm.googleapis.com/fcm/send/x',
            'https://127.0.0.1/fcm/send/x',
            'https://[::1]/x',
            'https://localhost/x',
            'https://evil.example.com/x',
            'https://fcm.googleapis.com.evil.com/x',
            'https://notfcm.googleapis.com/x',
            'https://fcm.googleapis.com:8443/x',
            'https://user:pass@fcm.googleapis.com/x',
            'https://dashboard_app/x',
        ];
        foreach ($bad as $endpoint) {
            $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($endpoint))->assertStatus(422);
        }
        $this->assertSame(0, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.PushSubscriptions WHERE UserID = ?', [$user])->c);

        $v = PushEndpointValidator::fromConfig();
        foreach (['https://fcm.googleapis.com/fcm/send/a', 'https://web.push.apple.com/QK', 'https://api.push.apple.com/x',
                  'https://updates.push.services.mozilla.com/wpush/v2/a', 'https://wns2-par02p.notify.windows.com/w/?token=a'] as $ok) {
            $this->assertTrue($v->isAllowed($ok), $ok);
        }
    }

    public function test_dispatcher_rechecks_endpoint_before_sending(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        // اشتراکی که قبلاً ذخیره شده ولی میزبانش دیگر مجاز نیست (مثلاً تغییرِ فهرست)
        config(['push.allowed_hosts' => ['web.push.apple.com']]);
        $this->sendMessage([$user]);
        $this->dispatch();

        $this->assertCount(0, $this->fake->sent);
        $sub = DB::selectOne('SELECT IsActive, DeactivationReason FROM dbo.PushSubscriptions WHERE UserID = ?', [$user]);
        $this->assertSame(0, (int) $sub->IsActive);
        $this->assertSame('INVALID_ENDPOINT', $sub->DeactivationReason);
        $this->assertNotEmpty($endpoint);
    }

    public function test_test_notification_goes_to_own_active_devices(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->actingAs(User::find($user))->postJson('/push/test')->assertStatus(422); // بدونِ دستگاه

        $endpoint = $this->subscribe($user);
        $this->actingAs(User::find($user))->postJson('/push/test')->assertOk()->assertJson(['success' => true]);
        $this->dispatch();
        $sent = $this->fake->sentTo($endpoint);
        $this->assertCount(1, $sent);
        $this->assertSame('TEST', $sent[0]['payload']['kind']);
    }

    public function test_list_reports_server_status_of_this_browser_only_for_own_subscription(): void
    {
        $user = $this->createUserWithoutRoles();
        $other = $this->createUserWithoutRoles();
        $mine = $this->endpoint();
        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($mine))->assertOk();

        $current = $this->actingAs(User::find($user))->postJson('/push/subscriptions/list', ['endpoint' => $mine])->assertOk()->json('current');
        $this->assertSame([true, true, null], [$current['registered'], $current['active'], $current['reason']]);

        // Endpointِ کاربرِ دیگر چیزی را لو نمی‌دهد
        $foreign = $this->actingAs(User::find($other))->postJson('/push/subscriptions/list', ['endpoint' => $mine])->assertOk()->json('current');
        $this->assertSame([false, false, null], [$foreign['registered'], $foreign['active'], $foreign['reason']]);

        // ثبت نشده (مثلاً مجوز داده شده ولی ثبت به سرور نرسیده)
        $none = $this->actingAs(User::find($user))->postJson('/push/subscriptions/list', ['endpoint' => $this->endpoint()])->assertOk()->json('current');
        $this->assertFalse($none['registered']);
        $this->assertNull($this->actingAs(User::find($user))->postJson('/push/subscriptions/list', [])->json('current'));
    }

    public function test_auto_sync_does_not_reactivate_a_device_the_user_turned_off_but_a_click_does(): void
    {
        $user = $this->createUserWithoutRoles();
        $phone = $this->endpoint();
        $laptop = $this->endpoint('updates.push.services.mozilla.com');
        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($phone) + ['source' => 'auto'])->assertOk();
        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($laptop) + ['source' => 'auto'])->assertOk();
        $id = (int) DB::selectOne('SELECT PushSubscriptionID FROM dbo.PushSubscriptions WHERE UserID = ? AND Endpoint = ?', [$user, $phone])->PushSubscriptionID;

        // از رویِ لپ‌تاپ، گوشی از فهرستِ دستگاه‌ها حذف می‌شود
        $this->actingAs(User::find($user))->postJson('/push/subscriptions/unsubscribe', ['subscriptionId' => $id])->assertOk();
        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($phone) + ['source' => 'auto'])
            ->assertStatus(409)->assertJson(['success' => false, 'optedOut' => true]);
        $this->assertSame(0, (int) DB::selectOne('SELECT IsActive FROM dbo.PushSubscriptions WHERE PushSubscriptionID = ?', [$id])->IsActive);
        $this->assertSame(1, (int) DB::selectOne('SELECT IsActive FROM dbo.PushSubscriptions WHERE UserID = ? AND Endpoint = ?', [$user, $laptop])->IsActive);

        // کلیکِ دوبارهٔ کاربر رویِ گوشی آن را فعال می‌کند
        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($phone) + ['source' => 'manual'])->assertOk();
        $this->assertSame(1, (int) DB::selectOne('SELECT IsActive FROM dbo.PushSubscriptions WHERE PushSubscriptionID = ?', [$id])->IsActive);
        $this->assertSame(2, (int) DB::selectOne('SELECT COUNT(*) c FROM dbo.PushSubscriptions WHERE UserID = ? AND IsActive = 1', [$user])->c);
    }

    public function test_expired_device_can_be_registered_again_by_auto_sync(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        DB::update("UPDATE dbo.PushSubscriptions SET IsActive = 0, DeactivationReason = N'EXPIRED' WHERE UserID = ?", [$user]);

        $this->actingAs(User::find($user))->postJson('/push/subscriptions', $this->body($endpoint) + ['source' => 'auto'])->assertOk();
        $this->assertSame(1, (int) DB::selectOne('SELECT IsActive FROM dbo.PushSubscriptions WHERE UserID = ?', [$user])->IsActive);
    }

    public function test_config_exposes_only_the_public_key(): void
    {
        $user = $this->createUserWithoutRoles();
        $json = $this->actingAs(User::find($user))->getJson('/push/config')->assertOk()->json();
        $this->assertSame('test-public', $json['publicKey']);
        $this->assertTrue($json['serviceActive']);
        $this->assertStringNotContainsString('test-private', json_encode($json));
    }
}
