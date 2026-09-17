<?php

namespace App\Http\Controllers\Process;

use App\Http\Controllers\Controller;
use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * صفحهٔ Inertiaِ مدیریتِ WorkflowEntityTypes — جدا از فضایِ Workflow\* (JSON-only).
 * فقط UI را رندر می‌کند؛ ایجاد/ویرایش/فعال‌سازی از سمتِ کلاینت به همان
 * endpointِ JSONِ موجود (/workflow/entity-types) می‌رود.
 */
class ProcessEntityTypeController extends Controller
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /** GET process/entity-types → Pages/Process/EntityTypes/Index.tsx */
    public function index(Request $request)
    {
        $permissions = $this->authorizeView();

        $validated = $request->validate([
            'search'   => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
        ]);

        $items = $this->defs->listEntityTypes(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        );

        return Inertia::render('Process/EntityTypes/Index', [
            'entityTypes' => $items,
            'filters'     => [
                'search'   => $validated['search'] ?? null,
                'isActive' => $validated['isActive'] ?? null,
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
