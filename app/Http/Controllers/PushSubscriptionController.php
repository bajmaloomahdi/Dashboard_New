<?php

namespace App\Http\Controllers;

use App\Services\Push\PushEndpointValidator;
use App\Services\Push\PushStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * مدیریتِ اشتراکِ Web Pushِ دستگاه‌هایِ خودِ کاربر (همه نیازمندِ ورود؛ مالکیت در SP هم کنترل می‌شود).
 * Endpoint و کلیدها هرگز به مرورگر برگردانده نمی‌شوند.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private PushStore $store)
    {
    }

    /** کلیدِ عمومیِ VAPID و وضعیتِ کلیِ سرویس — برایِ subscribe در مرورگر */
    public function config(): JsonResponse
    {
        $publicKey = (string) config('push.vapid.public_key');
        $active = false;
        try {
            $state = $this->store->state();
            $active = config('push.enabled') && (int) ($state->IsEnabled ?? 0) === 1 && ! empty($state->ActivatedAt);
        } catch (\Throwable) {
        }

        return response()->json([
            'success' => true,
            'configured' => $publicKey !== '',
            'publicKey' => $publicKey ?: null,
            'serviceActive' => (bool) $active,
        ]);
    }

    /**
     * فهرستِ دستگاه‌هایِ کاربر؛ اگر endpointِ همین مرورگر داده شود، IsCurrent مشخص می‌شود و وضعیتِ ثبتِ همین
     * مرورگر رویِ سرور (current) هم برمی‌گردد — فقط برایِ اشتراکِ خودِ کاربر و بدونِ Endpoint/کلید.
     */
    public function index(Request $request): JsonResponse
    {
        $v = $request->validate(['endpoint' => 'nullable|string|max:2000']);
        $current = null;
        if (! empty($v['endpoint'])) {
            $row = $this->store->subscriptionByEndpoint($v['endpoint']);
            $mine = $row && (int) $row->UserID === (int) Auth::id();
            $current = [
                'registered' => $mine,
                'active' => $mine && (int) $row->IsActive === 1,
                'reason' => $mine ? $row->DeactivationReason : null,
                'lastSuccessAt' => $mine ? $row->LastSuccessAt : null,
                'lastFailureAt' => $mine ? $row->LastFailureAt : null,
            ];
        }

        return response()->json([
            'success' => true,
            'items' => $this->store->userSubscriptions((int) Auth::id(), $v['endpoint'] ?? null),
            'current' => $current,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = $request->validate([
            'endpoint' => 'required|string|max:2000',
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-=+\/]+$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-=+\/]+$/'],
            'contentEncoding' => 'nullable|string|in:aes128gcm,aesgcm',
            // manual = کلیکِ کاربر (فعال‌سازیِ دوباره هم مجاز است)؛ auto = همگام‌سازیِ خودکار پس از ورود
            'source' => 'nullable|string|in:manual,auto',
        ]);

        $host = (string) parse_url($v['endpoint'], PHP_URL_HOST);
        if (! PushEndpointValidator::fromConfig()->isAllowed($v['endpoint'])) {
            // فقط نامِ میزبان در لاگ (نه Endpointِ کامل) — برایِ تشخیصِ مرورگرهایِ پشتیبانی‌نشده
            Log::channel('push')->warning('push: subscription rejected (host not allowed)', ['user' => (int) Auth::id(), 'host' => mb_substr($host, 0, 100)]);

            return response()->json(['success' => false, 'message' => 'این مرورگر از سرویسِ اعلانِ پشتیبانی‌شده استفاده نمی‌کند (' . mb_substr($host, 0, 100) . ').'], 422);
        }

        // همگام‌سازیِ خودکار دستگاهی را که کاربر خودش حذف/غیرفعال کرده دوباره فعال نمی‌کند (فقط کلیکِ دوبارهٔ کاربر)
        if (($v['source'] ?? 'manual') === 'auto') {
            $existing = $this->store->subscriptionByEndpoint($v['endpoint']);
            if ($existing && (int) $existing->IsActive === 0 && $existing->DeactivationReason === 'USER') {
                return response()->json(['success' => false, 'optedOut' => true, 'message' => 'اعلان‌ها رویِ این دستگاه قبلاً غیرفعال شده است.'], 409);
            }
        }

        $r = $this->store->saveSubscription(
            (int) Auth::id(),
            $v['endpoint'],
            $v['keys']['p256dh'],
            $v['keys']['auth'],
            $v['contentEncoding'] ?? 'aes128gcm',
            mb_substr((string) $request->userAgent(), 0, 400) ?: null
        );

        if ((int) ($r->Success ?? 0) !== 1) {
            Log::channel('push')->warning('push: subscription not saved', ['user' => (int) Auth::id(), 'host' => mb_substr($host, 0, 100), 'message' => (string) ($r->Message ?? '')]);
        }

        return response()->json([
            'success' => (int) ($r->Success ?? 0) === 1,
            'message' => (string) ($r->Message ?? ''),
            'subscriptionId' => isset($r->PushSubscriptionID) ? (int) $r->PushSubscriptionID : null,
        ], (int) ($r->Success ?? 0) === 1 ? 200 : 422);
    }

    /** لغو با endpointِ همین مرورگر یا شناسهٔ یکی از دستگاه‌هایِ خودِ کاربر */
    public function unsubscribe(Request $request): JsonResponse
    {
        $v = $request->validate([
            'endpoint' => 'nullable|string|max:2000|required_without:subscriptionId',
            'subscriptionId' => 'nullable|integer|required_without:endpoint',
        ]);

        $r = $this->store->deactivateSubscription((int) Auth::id(), $v['subscriptionId'] ?? null, $v['subscriptionId'] ?? null ? null : ($v['endpoint'] ?? null));
        $ok = (int) ($r->Success ?? 0) === 1;

        return response()->json(['success' => $ok, 'message' => (string) ($r->Message ?? '')], $ok ? 200 : 404);
    }

    /** اعلانِ آزمایشی به دستگاه‌هایِ خودِ کاربر (برایِ تستِ واقعی روی دستگاه) */
    public function test(): JsonResponse
    {
        $r = $this->store->enqueueTest((int) Auth::id());
        $ok = (int) ($r->Success ?? 0) === 1;

        return response()->json(['success' => $ok, 'message' => (string) ($r->Message ?? '')], $ok ? 200 : 422);
    }
}
