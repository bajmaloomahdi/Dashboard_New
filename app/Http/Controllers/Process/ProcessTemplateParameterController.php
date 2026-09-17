<?php

namespace App\Http\Controllers\Process;

use App\Http\Controllers\Controller;
use App\Services\Workflow\TemplateParameterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * صفحهٔ Inertiaِ مدیریتِ TemplateParameters — جدا از فضایِ Workflow\* (JSON-only).
 * فقط UI را رندر می‌کند؛ ایجاد/ویرایش/فعال‌سازی از سمتِ کلاینت به همان
 * endpointِ JSONِ موجود (/workflow/template-parameters) می‌رود.
 */
class ProcessTemplateParameterController extends Controller
{
    public function __construct(private TemplateParameterService $params)
    {
    }

    /** GET process/template-parameters → Pages/Process/TemplateParameters/Index.tsx */
    public function index(Request $request)
    {
        $permissions = $this->authorizeView();

        $validated = $request->validate([
            'search'     => 'nullable|string|max:200',
            'groupCode'  => 'nullable|string|max:20',
            'entityType' => 'nullable|string|max:64',
            'isActive'   => 'nullable|boolean',
        ]);

        $items = $this->params->list(
            $validated['search'] ?? null,
            $validated['groupCode'] ?? null,
            $validated['entityType'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        );

        return Inertia::render('Process/TemplateParameters/Index', [
            'templateParameters' => $items,
            'filters'            => [
                'search'     => $validated['search'] ?? null,
                'groupCode'  => $validated['groupCode'] ?? null,
                'entityType' => $validated['entityType'] ?? null,
                'isActive'   => $validated['isActive'] ?? null,
            ],
            'permissions' => $permissions,
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
