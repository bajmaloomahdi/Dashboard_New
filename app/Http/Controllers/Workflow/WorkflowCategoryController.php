<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\Request;

/**
 * دسته‌بندیِ فرایندها (WorkflowCategories).
 *
 * مشاهده/فیلتر با WORKFLOW_VIEW (همان دسترسیِ دیدنِ فهرستِ فرایندها)؛ ایجاد/ویرایش/
 * فعال‌سازی با Permissionِ جداگانهٔ WORKFLOW_MANAGE_CATEGORIES — طراحِ یک فرایند
 * لزوماً نباید بتواند کلِ Taxonomyِ دسته‌بندی‌ها را تغییر دهد.
 */
class WorkflowCategoryController extends WorkflowApiController
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /** GET workflow/categories */
    public function index(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'search'   => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
        ]);

        return $this->runWorkflow(fn () => [
            'items' => $this->defs->listCategories(
                $validated['search'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
            ),
        ]);
    }

    /** POST workflow/categories — Upsert: بدونِ categoryId = ایجاد؛ با categoryId = ویرایش. */
    public function store(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_CATEGORIES');

        $validated = $request->validate([
            'categoryId'  => 'nullable|integer|exists:WorkflowCategories,CategoryID',
            'code'        => 'required|string|max:30',
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $res = $this->defs->saveCategory($validated, $this->actorId());

            return [
                'message'    => $res->Message ?? 'دسته ذخیره شد.',
                'categoryId' => (int) $res->CategoryID,
            ];
        });
    }

    /** POST workflow/categories/{categoryId}/toggle */
    public function toggleActive(int $categoryId)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_CATEGORIES');

        return $this->runWorkflow(function () use ($categoryId) {
            $res = $this->defs->toggleCategoryActive($categoryId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ دسته تغییر کرد.'];
        });
    }
}
