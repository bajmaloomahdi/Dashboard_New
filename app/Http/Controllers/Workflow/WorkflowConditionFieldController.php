<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\Request;

/**
 * فیلدهایِ شرط (WorkflowConditionFields) — Definition-level.
 *
 * مشاهده با WORKFLOW_VIEW (هم‌الگو با WorkflowCategoryController)؛ ایجاد/ویرایش/
 * فعال‌سازی با Permissionِ جداگانهٔ WORKFLOW_MANAGE_CONDITION_FIELDS.
 */
class WorkflowConditionFieldController extends WorkflowApiController
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /** GET workflow/definitions/{definitionId}/condition-fields */
    public function index(int $definitionId, Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'includeInactive' => 'nullable|boolean',
        ]);

        return $this->runWorkflow(fn () => [
            'items' => $this->defs->listConditionFields(
                $definitionId,
                (bool) ($validated['includeInactive'] ?? false)
            ),
        ]);
    }

    /** POST workflow/definitions/{definitionId}/condition-fields — Upsert: بدونِ fieldId = ایجاد؛ با fieldId = ویرایش. */
    public function store(int $definitionId, Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_CONDITION_FIELDS');

        $validated = $request->validate([
            'fieldId'         => 'nullable|integer|exists:WorkflowConditionFields,FieldID',
            'code'            => 'required|string|max:50',
            'displayName'     => 'required|string|max:200',
            'dataType'        => 'required|string|max:20',
            'sourceType'      => 'required|string|max:20',
            'sourceKey'       => 'required|string|max:100',
            'allowedValues'   => 'nullable|array',
            'allowedValues.*' => 'string|max:200',
            'sortOrder'       => 'nullable|integer',
        ]);

        return $this->runWorkflow(function () use ($definitionId, $validated) {
            $res = $this->defs->saveConditionField(
                array_merge($validated, ['definitionId' => $definitionId]),
                $this->actorId()
            );

            return [
                'message' => $res->Message ?? 'فیلد ذخیره شد.',
                'fieldId' => (int) $res->FieldID,
            ];
        });
    }

    /** POST workflow/condition-fields/{fieldId}/toggle */
    public function toggleActive(int $fieldId)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_CONDITION_FIELDS');

        return $this->runWorkflow(function () use ($fieldId) {
            $res = $this->defs->toggleConditionFieldActive($fieldId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ فیلد تغییر کرد.'];
        });
    }
}
