<?php

namespace App\Http\Controllers\Process;

use App\Http\Controllers\Controller;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * صفحهٔ Inertiaِ Version Editor — جدا از فضایِ Workflow\* (JSON-only، طبقِ
 * bootstrap/app.php::shouldRenderJsonWhen رویِ workflow/*).
 *
 * فقط UI را رندر می‌کند؛ لودِ اولیه مستقیماً از همان WorkflowDefinitionService::getGraph()
 * که GET /workflow/versions/{id}/graph هم استفاده می‌کند (یک فراخوانیِ PHP، بدونِ
 * HTTP داخلی). Save/Validate/Publish/Clone همگی از سمتِ کلاینت به همان
 * endpointهایِ JSONِ موجودِ workflow/* می‌روند — هیچ Contractِ جدیدی اینجا نیست.
 *
 * فهرست‌هایِ users/roles/positions/units از همان SPهایی می‌آیند که
 * UserController/RoleController/PositionController/OrganizationalUnitController
 * از قبل برایِ صفحاتِ خودشان استفاده می‌کنند (sp_GetUsers/sp_GetRoles/
 * sp_GetPositions/sp_GetOrganizationalUnits) — بدونِ endpointِ جدید، فقط
 * فراخوانیِ مستقیمِ همان SPها برایِ پرکردنِ Selectِ هدفِ Assignment.
 */
class ProcessVersionController extends Controller
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /** GET process/versions/{versionId} → Pages/Process/Versions/Show.tsx */
    public function show(int $versionId)
    {
        $permissions = $this->workflowPermissions((int) Auth::id());

        if (! in_array('WORKFLOW_VIEW', $permissions, true)) {
            abort(403, 'شما دسترسیِ لازم برایِ مشاهدهٔ این نسخه را ندارید.');
        }

        $data = $this->defs->getGraph($versionId);
        abort_if(! $data['meta'], 404, 'نسخهٔ فرایند یافت نشد.');

        return Inertia::render('Process/Versions/Show', [
            'meta'        => $data['meta'],
            'steps'       => $data['graph']['steps'],
            'actions'     => $data['graph']['actions'],
            'assignments' => $data['graph']['assignments'],
            'transitions' => $data['graph']['transitions'],
            'permissions' => $permissions,
            'users'       => DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1'),
            'roles'       => DB::select('EXEC sp_GetRoles @SearchText = NULL, @IsActive = 1'),
            'positions'   => DB::select('EXEC sp_GetPositions @SearchText = NULL, @UnitID = NULL, @IsActive = 1'),
            'units'       => DB::select('EXEC sp_GetOrganizationalUnits @SearchText = NULL, @IsActive = 1'),
            'categories'  => $this->defs->listCategories(),
        ]);
    }

    /** @return string[] کدهایِ WORKFLOW_* کاربرِ جاری — همان الگویِ سه Controllerِ قبلیِ /process/* */
    private function workflowPermissions(int $userId): array
    {
        $rows = DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$userId]);

        return collect($rows)
            ->pluck('PermissionCode')
            ->filter(fn ($code) => str_starts_with($code, 'WORKFLOW_'))
            ->values()
            ->all();
    }
}
