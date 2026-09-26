<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\TemplateParameterService;
use Illuminate\Http\Request;

/**
 * Registryِ پارامترهایِ Template (TemplateParameters) — Phase 3, Phase B.
 *
 * مشاهده/فیلتر با WORKFLOW_VIEW؛ ایجاد/ویرایش/فعال‌سازی با Permissionِ جداگانهٔ
 * WORKFLOW_MANAGE_TEMPLATE_PARAMETERS — هم‌الگو با WorkflowEntityTypeController.
 */
class WorkflowTemplateParameterController extends WorkflowApiController
{
    public function __construct(private TemplateParameterService $params)
    {
    }

    /** GET workflow/template-parameters */
    public function index(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'search'     => 'nullable|string|max:200',
            'groupCode'  => 'nullable|string|max:20',
            'entityType' => 'nullable|string|max:64',
            'isActive'   => 'nullable|boolean',
        ]);

        return $this->runWorkflow(fn () => [
            'items' => $this->params->list(
                $validated['search'] ?? null,
                $validated['groupCode'] ?? null,
                $validated['entityType'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
            ),
        ]);
    }

    /** POST workflow/template-parameters — Upsert: بدونِ templateParameterId = ایجاد؛ با آن = ویرایش. */
    public function store(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_TEMPLATE_PARAMETERS');

        $validated = $request->validate([
            'templateParameterId' => 'nullable|integer|exists:TemplateParameters,TemplateParameterID',
            // Code هرگز از کلاینت گرفته نمی‌شود — Service آن را از caption/latinName
            // می‌سازد؛ latinName فقط وقتی لازم است که caption حرفِ لاتینِ کافی نداشته باشد.
            'caption'             => 'required|string|max:200',
            'latinName'           => 'nullable|string|max:50',
            'entityType'          => 'nullable|string|max:64',
            'dataType'            => 'required|string|max:20',
            'sourceType'          => 'required|string|max:20',
            // SourceKey برایِ Source=SYSTEM در Service خودکار تعیین می‌شود؛ برایِ USER
            // باید یکی از مقادیرِ ثابتِ شناخته‌شده باشد؛ فقط برایِ FORM از کاربر می‌آید.
            'sourceKey'           => 'nullable|string|max:100',
            'description'         => 'nullable|string|max:500',
            'sortOrder'           => 'nullable|integer',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $res = $this->params->save($validated, $this->actorId());

            return [
                'message'             => ($res->Message ?? 'پارامتر ذخیره شد.') . " (Code: {$res->Code})",
                'templateParameterId' => (int) $res->TemplateParameterID,
                'code'                => $res->Code,
            ];
        });
    }

    /** POST workflow/template-parameters/{templateParameterId}/toggle */
    public function toggleActive(int $templateParameterId)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_TEMPLATE_PARAMETERS');

        return $this->runWorkflow(function () use ($templateParameterId) {
            $res = $this->params->toggleActive($templateParameterId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ پارامتر تغییر کرد.'];
        });
    }
}
