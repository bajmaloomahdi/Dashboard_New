<?php

namespace App\Http\Controllers\Process;

use App\Http\Controllers\Controller;
use App\Services\Workflow\LetterTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * صفحهٔ Inertiaِ مدیریتِ LetterTemplates — جدا از فضایِ Workflow\* (JSON-only).
 * فقط UI را رندر می‌کند؛ ایجاد/ویرایش/فعال‌سازی/Render از سمتِ کلاینت به همان
 * endpointِ JSONِ موجود (/workflow/templates) می‌رود.
 */
class ProcessLetterTemplateController extends Controller
{
    public function __construct(private LetterTemplateService $templates)
    {
    }

    /** GET process/templates → Pages/Process/Templates/Index.tsx */
    public function index(Request $request)
    {
        $permissions = $this->authorizeView();

        $validated = $request->validate([
            'search'       => 'nullable|string|max:200',
            'entityType'   => 'nullable|string|max:64',
            'definitionId' => 'nullable|integer',
            'isActive'     => 'nullable|boolean',
        ]);

        $items = $this->templates->list(
            $validated['search'] ?? null,
            $validated['entityType'] ?? null,
            $validated['definitionId'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        );

        return Inertia::render('Process/Templates/Index', [
            'letterTemplates' => $items,
            'filters'         => [
                'search'       => $validated['search'] ?? null,
                'entityType'   => $validated['entityType'] ?? null,
                'definitionId' => $validated['definitionId'] ?? null,
                'isActive'     => $validated['isActive'] ?? null,
            ],
            'permissions' => $permissions,
        ]);
    }

    /** @return string[] */
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
