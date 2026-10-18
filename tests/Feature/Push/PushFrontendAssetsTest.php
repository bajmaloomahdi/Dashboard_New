<?php

namespace Tests\Feature\Push;

use Tests\TestCase;

/**
 * Service Worker و آیکون‌هایِ PWA: Service Worker هیچ fetch/cache ای ندارد (با React/Inertia/Vite تداخل نمی‌کند)،
 * فقط مسیرِ نسبیِ همین برنامه را باز می‌کند، و مانیفست آیکون‌هایِ PNGِ مربعِ واقعی ۱۹۲ و ۵۱۲ دارد.
 */
class PushFrontendAssetsTest extends TestCase
{
    public function test_service_worker_does_not_intercept_network_or_cache(): void
    {
        $sw = (string) file_get_contents(public_path('push-sw.js'));
        $this->assertStringNotContainsString("addEventListener('fetch'", $sw);
        $this->assertStringNotContainsString('addEventListener("fetch"', $sw);
        $this->assertStringNotContainsString('caches.', $sw);
        $this->assertStringNotContainsString('importScripts', $sw);
        $this->assertStringContainsString("addEventListener('push'", $sw);
        $this->assertStringContainsString("addEventListener('notificationclick'", $sw);
        $this->assertStringContainsString("url.startsWith('/') && !url.startsWith('//')", $sw); // فقط مسیرِ داخلی
        // کنترلِ تکرار: Tagِ ثابت و جایگزینیِ بی‌صدا
        $this->assertStringContainsString('renotify: !duplicate', $sw);
        $this->assertStringContainsString('silent: duplicate', $sw);
        // خارج از public/build — Build شدنِ Vite آن را حذف/جابه‌جا نمی‌کند
        $this->assertFileDoesNotExist(public_path('build/push-sw.js'));
    }

    public function test_auto_sync_never_asks_permission_and_respects_opt_out(): void
    {
        $client = (string) file_get_contents(resource_path('js/Components/Push/pushClient.ts'));
        $start = strpos($client, 'export async function syncOnLoad');
        $end = strpos($client, 'export function supportMessage');
        $this->assertNotFalse($start);
        $sync = substr($client, $start, $end - $start);
        $this->assertStringNotContainsString('requestPermission', $sync);   // فقط با کلیکِ کاربر
        $this->assertStringContainsString("Notification.permission !== 'granted'", $sync);
        $this->assertStringContainsString('isOptedOut()', $sync);
        $this->assertStringContainsString("saveOnServer(sub, 'auto')", $sync);
        $this->assertStringContainsString('subscribeWith(reg', $sync);       // مجوز هست ولی اشتراک نیست → ساختِ اشتراک
    }

    public function test_permission_request_is_the_first_await_of_the_click_handler(): void
    {
        $client = (string) file_get_contents(resource_path('js/Components/Push/pushClient.ts'));
        $start = strpos($client, 'async function enablePushInner');
        $ask = strpos($client, 'await askPermission()', $start);
        $this->assertNotFalse($ask);
        // Safari/iOS: پیش از درخواستِ مجوز هیچ await دیگری نباشد (وگرنه «اقدامِ کاربر» از دست می‌رود)
        $this->assertStringNotContainsString('await ', substr($client, $start, $ask - $start));
        // granted به‌تنهایی کافی نیست: پس از مجوز، Service Worker، اشتراک و ثبت در سرور جدا بررسی می‌شوند
        foreach (["code: 'sw-failed'", "code: 'subscribe-failed'", "saveOnServer(sub, 'manual')", "code: 'denied'", "code: 'dismissed'"] as $needle) {
            $this->assertStringContainsString($needle, substr($client, $start), $needle);
        }
    }

    public function test_in_app_prompt_texts_snooze_and_no_direct_permission_request(): void
    {
        $prompt = (string) file_get_contents(resource_path('js/Components/Push/PushPrompt.tsx'));
        $this->assertStringContainsString('آیا می‌خواهید اعلان‌های راگا ۳۶۰ را فعال کنید؟', $prompt);
        $this->assertStringContainsString('با فعال‌سازی، پیام‌های جدید، ارجاع وظایف و یادآوری‌های مهم را دریافت می‌کنید.', $prompt);
        $this->assertStringContainsString('بله، فعال کن', $prompt);
        $this->assertStringContainsString('فعلاً نه', $prompt);
        $this->assertStringContainsString('اعلان‌ها با موفقیت فعال شدند', $prompt);
        // پنجرهٔ فعال‌سازی دکمهٔ اعلانِ آزمایشی ندارد (فقط در تنظیماتِ اعلان)
        $this->assertStringNotContainsString('sendTest', $prompt);
        // مجوز فقط از راهِ enablePush (کلیکِ کاربر) خواسته می‌شود
        $this->assertStringNotContainsString('requestPermission', $prompt);
        // «فعلاً نه» و بستن: تعویق؛ هر Refresh دوباره نمایش داده نمی‌شود
        $this->assertStringContainsString('onCancel={mode === \'success\' ? close : later}', $prompt);
        $this->assertStringContainsString('SNOOZE_DAYS', $prompt);
        $this->assertStringContainsString('markShownThisSession()', $prompt);

        $layout = (string) file_get_contents(resource_path('js/Layouts/MainLayout.tsx'));
        $this->assertStringContainsString('<PushPrompt userId={auth?.user?.UserID} />', $layout);
    }

    public function test_manifest_has_real_square_png_icons(): void
    {
        $manifest = $this->get('/manifest.json')->assertOk()->json();
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $sizes = collect($manifest['icons'])->pluck('sizes')->unique()->values()->all();
        $this->assertEqualsCanonicalizing(['192x192', '512x512'], $sizes);

        foreach ([180, 192, 512] as $size) {
            $png = $this->get("/company/pwa-icon/{$size}")->assertOk()->assertHeader('Content-Type', 'image/png')->getContent();
            $info = getimagesizefromstring($png);
            $this->assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
        }
        $this->get('/company/pwa-icon/64')->assertNotFound();
    }
}
