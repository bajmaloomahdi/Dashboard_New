<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\LetterTemplateService;
use App\Services\Workflow\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Registryِ قالب‌هایِ نامه (LetterTemplates) — Phase 3, Phase C.
 *
 * مشاهده/فیلتر با WORKFLOW_VIEW؛ ایجاد/ویرایش/فعال‌سازی با Permissionِ جداگانهٔ
 * WORKFLOW_MANAGE_TEMPLATES.
 */
class WorkflowLetterTemplateController extends WorkflowApiController
{
    public function __construct(
        private LetterTemplateService $templates,
        private TemplateRenderer $renderer,
    ) {
    }

    /** GET workflow/templates */
    public function index(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'search'       => 'nullable|string|max:200',
            'entityType'   => 'nullable|string|max:64',
            'definitionId' => 'nullable|integer',
            'isActive'     => 'nullable|boolean',
        ]);

        return $this->runWorkflow(fn () => [
            'items' => $this->templates->list(
                $validated['search'] ?? null,
                $validated['entityType'] ?? null,
                $validated['definitionId'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
            ),
        ]);
    }

    /** GET workflow/definitions/{definitionId}/templates — Templateهایِ مجاز برایِ یک Workflow انتخاب‌شده. */
    public function forDefinition(int $definitionId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        return $this->runWorkflow(fn () => [
            'items' => $this->templates->listForDefinition($definitionId),
        ]);
    }

    /** POST workflow/templates — Upsert: بدونِ letterTemplateId = ایجاد؛ با آن = ویرایش. */
    public function store(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_TEMPLATES');

        $validated = $request->validate([
            'letterTemplateId' => 'nullable|integer|exists:LetterTemplates,LetterTemplateID',
            'code'             => 'required|string|max:64',
            'name'             => 'required|string|max:200',
            'entityType'       => 'required|string|max:64',
            'definitionId'     => 'nullable|integer',
            'subjectTemplate'  => 'required|string|max:500',
            'bodyTemplate'     => 'required|string',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $res = $this->templates->save($validated, $this->actorId());

            return [
                'message'          => $res->Message ?? 'قالب ذخیره شد.',
                'letterTemplateId' => (int) $res->LetterTemplateID,
            ];
        });
    }

    /** POST workflow/templates/{letterTemplateId}/toggle */
    public function toggleActive(int $letterTemplateId)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_TEMPLATES');

        return $this->runWorkflow(function () use ($letterTemplateId) {
            $res = $this->templates->toggleActive($letterTemplateId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ قالب تغییر کرد.'];
        });
    }

    /**
     * POST workflow/templates/{letterTemplateId}/render — پیش‌نمایشِ Read-Only.
     * برایِ Editor (اعتبارسنجیِ زنده) و برایِ Composeِ پیام (پیش‌نمایشِ قبل از ارسال).
     * هیچ نوشتنی در DB انجام نمی‌دهد.
     */
    public function render(Request $request, int $letterTemplateId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'formValues' => 'nullable|array',
        ]);

        return $this->runWorkflow(fn () => $this->renderer->render(
            $letterTemplateId,
            $validated['formValues'] ?? [],
            (int) Auth::id(),
        ));
    }
}
