<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\Request;

/**
 * Registryِ نوعِ موجودیت‌ها (WorkflowEntityTypes).
 *
 * مشاهده/فیلتر با WORKFLOW_VIEW (همان دسترسیِ دیدنِ فهرستِ فرایندها)؛ ایجاد/ویرایش/
 * فعال‌سازی با Permissionِ جداگانهٔ WORKFLOW_MANAGE_ENTITY_TYPES — هم‌الگو با
 * WorkflowCategoryController.
 */
class WorkflowEntityTypeController extends WorkflowApiController
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /**
     * GET workflow/entity-types
     * پارامترِ usableOnly=1: فقط ردیف‌هایی که هم IsActive=1 در Registry باشند
     * هم واقعاً در config/workflow.php یک Resolver داشته باشند — همان چیزی که
     * فرمِ طراحیِ Workflow برایِ Selectِ EntityType نیاز دارد (طبقِ isEntityTypeUsable).
     */
    public function index(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'search'     => 'nullable|string|max:200',
            'isActive'   => 'nullable|boolean',
            'usableOnly' => 'nullable|boolean',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $items = $this->defs->listEntityTypes(
                $validated['search'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
            );

            if (! empty($validated['usableOnly'])) {
                $items = array_values(array_filter(
                    $items,
                    fn ($row) => $this->defs->isEntityTypeUsable($row->Code)
                ));
            }

            return ['items' => $items];
        });
    }

    /** POST workflow/entity-types — Upsert: بدونِ entityTypeId = ایجاد؛ با entityTypeId = ویرایش. */
    public function store(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_ENTITY_TYPES');

        $validated = $request->validate([
            'entityTypeId'  => 'nullable|integer|exists:WorkflowEntityTypes,EntityTypeID',
            'code'          => 'required|string|max:64',
            'displayName'   => 'required|string|max:200',
            'resolverClass' => 'nullable|string|max:200',
            'sortOrder'     => 'nullable|integer',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $res = $this->defs->saveEntityType($validated, $this->actorId());

            return [
                'message'      => $res->Message ?? 'موجودیت ذخیره شد.',
                'entityTypeId' => (int) $res->EntityTypeID,
            ];
        });
    }

    /** POST workflow/entity-types/{entityTypeId}/toggle */
    public function toggleActive(int $entityTypeId)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_ENTITY_TYPES');

        return $this->runWorkflow(function () use ($entityTypeId) {
            $res = $this->defs->toggleEntityTypeActive($entityTypeId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ موجودیت تغییر کرد.'];
        });
    }
}
