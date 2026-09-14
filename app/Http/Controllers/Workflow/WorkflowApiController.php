<?php

namespace App\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use App\Services\Workflow\Exceptions\WorkflowConcurrencyException;
use App\Services\Workflow\Exceptions\WorkflowException;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پایهٔ کنترلرهای API ماژولِ فرایند.
 *
 * الگوی دسترسی همان CalendarController است (sp_GetUserPermissions + کشِ درخواستی +
 * abort(403)). نگاشتِ استثناهای موتور به کدِ HTTP هم اینجا متمرکز شده تا کنترلرها
 * فقط: Request → Validation → Permission → Service → Response بمانند.
 */
abstract class WorkflowApiController extends Controller
{
    /**
     * کدهای دسترسی — بر اساسِ شناسهٔ کاربر کش می‌شوند.
     * (کلیدگذاری با UserID لازم است چون Laravel نمونهٔ کنترلر را روی Route نگه می‌دارد
     * و در تست‌ها یک نمونه چند درخواست با کاربرانِ متفاوت را سرویس می‌دهد.)
     *
     * @var array<int,string[]>
     */
    private array $permissionCache = [];

    protected function actorId(): int
    {
        return (int) Auth::id();
    }

    /** @return string[] */
    protected function loadPermissions(): array
    {
        $userId = (int) Auth::id();

        if (! isset($this->permissionCache[$userId])) {
            $rows = DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$userId]);
            $this->permissionCache[$userId] = collect($rows)->pluck('PermissionCode')->all();
        }

        return $this->permissionCache[$userId];
    }

    protected function userCan(string $code): bool
    {
        return in_array($code, $this->loadPermissions(), true);
    }

    protected function authorizeWorkflow(string $code): void
    {
        if (! $this->userCan($code)) {
            abort(403, 'شما دسترسی لازم برای این عملیات را ندارید.');
        }
    }

    /**
     * اجرای منطقِ سرویس و تبدیلِ استثناهای موتور به پاسخِ استاندارد.
     *
     *   WorkflowValidationException → 422
     *   WorkflowConcurrencyException → 409
     *   WorkflowStateException       → 409  (تعارض با وضعیتِ فعلی)
     *   WorkflowException (پایه)      → 422
     *
     * ValidationException و abort(403/404) از این‌جا عبور می‌کنند و توسط
     * هندلرِ پیش‌فرضِ Laravel به 422/403/404 تبدیل می‌شوند.
     *
     * @param  \Closure():?array  $fn
     */
    protected function runWorkflow(\Closure $fn): JsonResponse
    {
        try {
            $data = $fn() ?? [];

            return response()->json(array_merge(['success' => true], $data));
        } catch (WorkflowConcurrencyException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'concurrency' => true], 409);
        } catch (WorkflowValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (WorkflowStateException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        } catch (WorkflowException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** RowVersion دودویی را برای انتقالِ امن در JSON به رشتهٔ hex («0x…») تبدیل می‌کند. */
    protected function hexRowVersion(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return '0x' . bin2hex((string) $value);
    }
}
