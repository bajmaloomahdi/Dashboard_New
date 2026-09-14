<?php

namespace App\Http\Controllers\Process;

use App\Http\Controllers\Controller;
use App\Services\Workflow\WorkflowDefinitionService;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * صفحاتِ Inertiaِ ماژولِ Workflow — عمداً جدا از فضایِ نامِ Workflow\* (که JSON-only
 * است، طبقِ bootstrap/app.php::shouldRenderJsonWhen رویِ مسیرِ workflow/*).
 *
 * این کنترلر فقط UI را رندر می‌کند؛ برایِ لودِ اولیه مستقیماً از همان
 * WorkflowQueryService که /workflow/instances/{id} هم استفاده می‌کند بهره می‌برد
 * (بدونِ فراخوانیِ داخلیِ HTTP و بدونِ تکرارِ منطق) — یک فراخوانیِ سرویس به‌جایِ
 * دو درخواستِ جداگانه‌ای که خودِ APIِ JSON (show + history) انجام می‌دهد.
 * اقدام‌ها (Cancel/Suspend/Resume) و رفرشِ پس‌از‌اقدام از سمتِ کلاینت مستقیماً به
 * همان endpointهایِ JSONِ موجود می‌روند — هیچ Contractِ جدیدی اینجا اختراع نشد.
 */
class ProcessInstanceController extends Controller
{
    public function __construct(
        private WorkflowQueryService $query,
        private WorkflowDefinitionService $defs,
    ) {
    }

    /** GET process/instances → Pages/Process/Instances/Index.tsx */
    public function index(Request $request)
    {
        $permissions = $this->workflowPermissions((int) Auth::id());

        if (! in_array('WORKFLOW_VIEW', $permissions, true)) {
            abort(403, 'شما دسترسیِ لازم برایِ مشاهدهٔ فرایندها را ندارید.');
        }

        $validated = $request->validate([
            'definitionId'    => 'nullable|integer',
            'status'          => 'nullable|string|in:RUNNING,COMPLETED,CANCELLED,SUSPENDED,FAILED',
            'entityType'      => 'nullable|string|max:128',
            'entityId'        => 'nullable|integer',
            'startedByUserId' => 'nullable|integer',
            'dateFrom'        => 'nullable|date',
            'dateTo'          => 'nullable|date',
            'page'            => 'nullable|integer|min:1',
            'pageSize'        => 'nullable|integer|min:1|max:200',
        ]);

        $page = $validated['page'] ?? 1;
        $pageSize = $validated['pageSize'] ?? 20;

        $result = $this->query->instances($validated, $page, $pageSize);

        $definitionOptions = collect($this->defs->list())
            ->map(fn ($d) => ['DefinitionID' => $d->DefinitionID, 'Code' => $d->Code, 'Name' => $d->Name])
            ->values()
            ->all();

        return Inertia::render('Process/Instances/Index', [
            'instances'         => $result['rows'],
            'totalCount'        => $result['totalCount'],
            'filters'           => [
                'definitionId'    => $validated['definitionId'] ?? null,
                'status'          => $validated['status'] ?? null,
                'entityType'      => $validated['entityType'] ?? null,
                'entityId'        => $validated['entityId'] ?? null,
                'startedByUserId' => $validated['startedByUserId'] ?? null,
                'dateFrom'        => $validated['dateFrom'] ?? null,
                'dateTo'          => $validated['dateTo'] ?? null,
            ],
            'page'              => $page,
            'pageSize'          => $pageSize,
            'permissions'       => $permissions,
            'definitionOptions' => $definitionOptions,
            'users'             => DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1'),
        ]);
    }

    /** GET process/instances/{instanceId} → Pages/Process/Instances/Show.tsx */
    public function show(int $instanceId)
    {
        $userId = (int) Auth::id();
        $permissions = $this->workflowPermissions($userId);

        if (! in_array('WORKFLOW_VIEW', $permissions, true)) {
            abort(403, 'شما دسترسیِ لازم برایِ مشاهدهٔ این فرایند را ندارید.');
        }

        $data = $this->query->instance($instanceId);
        abort_if(! $data['instance'], 404, 'نمونهٔ فرایند یافت نشد.');

        return Inertia::render('Process/Instances/Show', [
            'instance'    => $data['instance'],
            'steps'       => $data['steps'],
            'tasks'       => $data['tasks'],
            'history'     => $data['history'],
            'permissions' => $permissions,
        ]);
    }

    /**
     * کدهایِ WORKFLOW_* کاربرِ جاری — همان الگویِ
     * CalendarController::calendarPermissions() / MessageController::workflowPermissions().
     * فقط برایِ UX (نمایش/پنهان‌کردنِ دکمه‌های Cancel/Suspend/Resume)؛ مرجعِ نهاییِ
     * ۴۰۳ همچنان WorkflowApiController::authorizeWorkflow() سمتِ Backend است.
     *
     * @return string[]
     */
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
