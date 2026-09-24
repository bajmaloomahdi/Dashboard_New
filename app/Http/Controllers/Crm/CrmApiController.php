<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\Exceptions\CrmException;
use App\Services\Crm\Exceptions\CrmValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پایهٔ کنترلرهای API ماژولِ CRM — هم‌الگو با WorkflowApiController
 * (sp_GetUserPermissions + کشِ درخواستی + abort(403)).
 */
abstract class CrmApiController extends Controller
{
    /** @var array<int,string[]> */
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

    protected function authorizeCrm(string $code): void
    {
        if (! $this->userCan($code)) {
            abort(403, 'شما دسترسی لازم برای این عملیات را ندارید.');
        }
    }

    /**
     * اجرای منطقِ سرویس و تبدیلِ استثناهای ماژول به پاسخِ استاندارد.
     *
     * @param  \Closure():?array  $fn
     */
    protected function runCrm(\Closure $fn): JsonResponse
    {
        try {
            $data = $fn() ?? [];

            return response()->json(array_merge(['success' => true], $data));
        } catch (CrmValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (CrmException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
