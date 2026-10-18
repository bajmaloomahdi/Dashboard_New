<?php

namespace App\Http\Controllers;

use App\Services\Push\PushBroadcastText;
use App\Services\Push\PushStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «ارسال اعلان عمومی»: اعلانِ گوشی/مرورگر به همهٔ کاربرانِ فعال یا کاربرانِ انتخاب‌شده، رویِ همان Outbox و Schedulerِ Web Push.
 * فقط کاربرانی که اعلان‌ها را فعال کرده‌اند (اشتراکِ فعال دارند) گیرنده می‌شوند؛ در UserNotifications/کارتابل چیزی ثبت نمی‌شود.
 * همهٔ مسیرها نیازمندِ دسترسیِ PUSH_BROADCAST هستند (دیدنِ منو کافی نیست).
 */
class PushBroadcastController extends Controller
{
    public const PERMISSION = 'PUSH_BROADCAST';

    public function __construct(private PushStore $store)
    {
    }

    private function authorizeBroadcast(): void
    {
        $codes = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [(int) Auth::id()]))->pluck('PermissionCode')->all();
        if (! in_array(self::PERMISSION, $codes, true)) {
            abort(403, 'شما دسترسی لازم برای ارسالِ اعلانِ عمومی را ندارید.');
        }
    }

    private function serviceActive(): bool
    {
        try {
            $state = $this->store->state();

            return config('push.enabled') && (int) ($state->IsEnabled ?? 0) === 1 && ! empty($state->ActivatedAt);
        } catch (\Throwable) {
            return false;
        }
    }

    public function index(): Response
    {
        $this->authorizeBroadcast();

        return Inertia::render('Push/Broadcasts', [
            'serviceActive' => $this->serviceActive(),
            'limits' => ['title' => PushBroadcastText::TITLE_MAX, 'body' => PushBroadcastText::BODY_MAX],
        ]);
    }

    /** کاربرانِ فعال برایِ انتخاب (با وضعیتِ «اعلان فعال است / نیست») */
    public function users(): JsonResponse
    {
        $this->authorizeBroadcast();
        $items = array_map(fn ($u) => [
            'UserID' => (int) $u->UserID,
            'FullName' => (string) $u->FullName,
            'HasSubscription' => (int) $u->HasSubscription === 1,
            'DeviceCount' => (int) $u->DeviceCount,
        ], $this->store->broadcastAudience('ALL'));

        return response()->json(['success' => true, 'items' => $items]);
    }

    /** پیش‌نمایش پیش از تأییدِ نهایی: متنِ پاک‌سازی‌شده + تعدادِ گیرندگان */
    public function preview(Request $request): JsonResponse
    {
        $this->authorizeBroadcast();
        [$title, $body, $mode, $ids] = $this->validated($request);
        $audience = $this->store->broadcastAudience($mode, $ids);
        $withSub = array_filter($audience, fn ($u) => (int) $u->HasSubscription === 1);

        return response()->json([
            'success' => true,
            'title' => $title,
            'body' => $body,
            'targetMode' => $mode,
            'selectedUsers' => count($audience),
            'recipients' => count($withSub),
            'devices' => array_sum(array_map(fn ($u) => (int) $u->DeviceCount, $withSub)),
            'withoutSubscription' => count($audience) - count($withSub),
            'serviceActive' => $this->serviceActive(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeBroadcast();
        [$title, $body, $mode, $ids] = $this->validated($request, true);
        if (! $this->serviceActive()) {
            return response()->json(['success' => false, 'message' => 'ارسالِ اعلان رویِ سرور فعال نیست.'], 422);
        }

        $r = $this->store->createBroadcast((int) Auth::id(), $title, $body, $mode, $ids, (string) $request->input('idempotencyKey'));
        $ok = (int) ($r->Success ?? 0) === 1;

        return response()->json([
            'success' => $ok,
            'message' => (string) ($r->Message ?? ''),
            'broadcastId' => isset($r->PushBroadcastID) ? (int) $r->PushBroadcastID : null,
            'duplicate' => (int) ($r->IsDuplicate ?? 0) === 1,
        ], $ok ? 200 : 422);
    }

    /** سوابقِ اعلان‌هایِ عمومی با وضعیت: در انتظار / موفق / ناموفق / ردشده */
    public function list(): JsonResponse
    {
        $this->authorizeBroadcast();
        $items = array_map(fn ($b) => [
            'PushBroadcastID' => (int) $b->PushBroadcastID,
            'Title' => (string) $b->Title,
            'Body' => (string) $b->Body,
            'TargetMode' => (string) $b->TargetMode,
            'SelectedUserCount' => (int) $b->SelectedUserCount,
            'RecipientCount' => (int) $b->RecipientCount,
            'CreatedAt' => (string) $b->Date_InsertFirst,
            'CreatedByName' => (string) $b->CreatedByName,
            'Pending' => (int) $b->PendingCount,
            'Sent' => (int) $b->SentCount,
            'Failed' => (int) $b->FailedCount,
            'Skipped' => (int) $b->SkippedCount,
            'DevicesSent' => (int) $b->DevicesSent,
            'DevicesFailed' => (int) $b->DevicesFailed,
        ], $this->store->broadcasts(50));

        return response()->json(['success' => true, 'items' => $items]);
    }

    /**
     * اعتبارسنجی + پاک‌سازی (طول پس از پاک‌سازی سنجیده می‌شود).
     *
     * @return array{0:string,1:string,2:string,3:int[]}
     */
    private function validated(Request $request, bool $forSend = false): array
    {
        $request->validate([
            'title' => 'required|string|max:500',
            'body' => 'required|string|max:2000',
            'targetMode' => ['required', Rule::in(['ALL', 'USERS'])],
            'userIds' => 'required_if:targetMode,USERS|array|max:2000',
            'userIds.*' => 'integer|min:1',
            'idempotencyKey' => $forSend ? 'required|uuid' : 'nullable',
            'confirmed' => $forSend ? 'accepted' : 'nullable',
        ], [
            'confirmed.accepted' => 'ارسال باید صریحاً تأیید شود.',
            'userIds.required_if' => 'دست‌کم یک کاربر انتخاب کنید.',
        ], [
            'title' => 'عنوان', 'body' => 'متن', 'userIds' => 'کاربران',
        ]);

        $title = PushBroadcastText::title($request->input('title'));
        $body = PushBroadcastText::body($request->input('body'));
        $errors = [];
        if ($title === '') {
            $errors['title'] = 'عنوانِ اعلان الزامی است.';
        } elseif (PushBroadcastText::length($title) > PushBroadcastText::TITLE_MAX) {
            $errors['title'] = 'عنوان حداکثر ' . PushBroadcastText::TITLE_MAX . ' نویسه است.';
        }
        if ($body === '') {
            $errors['body'] = 'متنِ اعلان الزامی است.';
        } elseif (PushBroadcastText::length($body) > PushBroadcastText::BODY_MAX) {
            $errors['body'] = 'متن حداکثر ' . PushBroadcastText::BODY_MAX . ' نویسه است.';
        }
        $mode = (string) $request->input('targetMode');
        $ids = $mode === 'USERS' ? array_values(array_unique(array_map('intval', (array) $request->input('userIds', [])))) : [];
        if ($mode === 'USERS' && ! $ids) {
            $errors['userIds'] = 'دست‌کم یک کاربر انتخاب کنید.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [$title, $body, $mode, $ids];
    }
}
