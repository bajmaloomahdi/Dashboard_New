<?php

namespace App\Http\Controllers\Process;

use App\Http\Controllers\Controller;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * صفحاتِ Inertiaِ مدیریتِ WorkflowDefinitions — جدا از فضایِ Workflow\* (که
 * JSON-only است، طبقِ bootstrap/app.php::shouldRenderJsonWhen رویِ workflow/*).
 *
 * فقط UI را رندر می‌کند؛ برایِ لودِ اولیه مستقیماً از همان WorkflowDefinitionService
 * که /workflow/definitions هم استفاده می‌کند بهره می‌برد (بدونِ HTTP داخلی).
 * ایجاد/ویرایش/فعال‌سازی/نسخهٔ پیش‌نویس/Clone همگی از سمتِ کلاینت به همان
 * endpointهایِ JSONِ موجود می‌روند — هیچ Contractِ جدیدی اینجا اختراع نشد.
 */
class ProcessDefinitionController extends Controller
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /** GET process/definitions → Pages/Process/Definitions/Index.tsx */
    public function index(Request $request)
    {
        $permissions = $this->authorizeView();

        $validated = $request->validate([
            'search'   => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
        ]);

        $items = $this->defs->list(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        );

        return Inertia::render('Process/Definitions/Index', [
            'definitions' => $items,
            'filters'     => [
                'search'   => $validated['search'] ?? null,
                'isActive' => $validated['isActive'] ?? null,
            ],
            'permissions' => $permissions,
        ]);
    }

    /** GET process/definitions/{definitionId} → Pages/Process/Definitions/Show.tsx */
    public function show(int $definitionId)
    {
        $permissions = $this->authorizeView();

        $data = $this->defs->show($definitionId);
        abort_if(! $data['definition'], 404, 'فرایند یافت نشد.');

        return Inertia::render('Process/Definitions/Show', [
            'definition'  => $data['definition'],
            'versions'    => $data['versions'],
            'permissions' => $permissions,
        ]);
    }

    /** @return string[] کدهایِ WORKFLOW_* کاربرِ جاری؛ ۴۰۳ اگر حتی WORKFLOW_VIEW را نداشته باشد */
    private function authorizeView(): array
    {
        $permissions = $this->workflowPermissions((int) Auth::id());

        if (! in_array('WORKFLOW_VIEW', $permissions, true)) {
            abort(403, 'شما دسترسیِ لازم برایِ مشاهدهٔ فرایندها را ندارید.');
        }

        return $permissions;
    }

    /**
     * همان الگویِ CalendarController::calendarPermissions() /
     * MessageController::workflowPermissions() / ProcessInstanceController — فقط
     * برایِ UX (نمایش/پنهان‌کردنِ دکمه‌ها)؛ مرجعِ نهاییِ ۴۰۳ همچنان
     * WorkflowApiController::authorizeWorkflow() سمتِ Backend است.
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
