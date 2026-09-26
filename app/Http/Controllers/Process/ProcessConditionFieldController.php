<?php

namespace App\Http\Controllers\Process;

use App\Http\Controllers\Controller;
use App\Services\Workflow\ConditionFieldService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * صفحهٔ Inertiaِ مدیریتِ Registryِ سراسریِ فیلدهایِ شرط (WorkflowConditionFields) —
 * جدا از فضایِ Workflow\* (JSON-only). فقط UI را رندر می‌کند؛ ایجاد/ویرایش/فعال‌سازی
 * از سمتِ کلاینت به همان endpointِ JSONِ موجود (/workflow/condition-fields) می‌رود —
 * دقیقاً هم‌الگو با ProcessTemplateParameterController.
 *
 * این تنها محلِ مدیریتِ Field است؛ Designer/RuleBuilder فقط مصرف‌کننده‌اند.
 */
class ProcessConditionFieldController extends Controller
{
    public function __construct(private ConditionFieldService $conditionFields)
    {
    }

    /** GET process/condition-fields → Pages/Process/ConditionFields/Index.tsx */
    public function index()
    {
        $permissions = $this->authorizeView();

        return Inertia::render('Process/ConditionFields/Index', [
            // همیشه شاملِ Inactiveها — خودِ صفحه مسئولِ نمایشِ وضعیت است (نیازِ صریح:
            // «Inactive Field باید در Registry قابلِ‌مشاهده و قابلِ Edit بماند»).
            'conditionFields' => $this->conditionFields->list(includeInactive: true),
            'permissions'     => $permissions,
        ]);
    }

    /** @return string[] کدهایِ WORKFLOW_* کاربرِ جاری؛ ۴۰۳ اگر حتی WORKFLOW_VIEW را نداشته باشد */
    private function authorizeView(): array
    {
        $permissions = $this->workflowPermissions((int) Auth::id());

        if (! in_array('WORKFLOW_VIEW', $permissions, true)) {
            abort(403, 'شما دسترسیِ لازم برایِ مشاهدهٔ این بخش را ندارید.');
        }

        return $permissions;
    }

    /** @return string[] */
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
