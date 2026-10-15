<?php

namespace App\Services\GlobalSearch\Support;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\Contracts\AccessContextResolver;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * مجوزها از همان منبعِ بقیهٔ ماژول‌ها (sp_GetUserPermissions — الگویِ CrmApiController / WorkflowApiController).
 * IsActive از مدلِ کاربر خوانده می‌شود که SessionGuard در هر درخواست از دیتابیس بارگذاری می‌کند.
 */
final class SqlAccessContextResolver implements AccessContextResolver
{
    public function resolve(Authenticatable $user): AccessContext
    {
        $userId = (int) $user->getAuthIdentifier();
        $isActive = (bool) ($user->IsActive ?? false);

        $permissions = [];
        if ($userId > 0 && $isActive) {
            $rows = DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$userId]);
            $permissions = array_values(array_unique(array_map(fn ($r) => (string) $r->PermissionCode, $rows)));
        }

        return new AccessContext($userId, $isActive, $permissions);
    }
}
