<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\ConditionFieldService;
use Illuminate\Http\Request;

/**
 * فیلدهایِ شرط (WorkflowConditionFields) — Global Registry (Phase 2).
 *
 * یک Field یک‌بار تعریف می‌شود و در RuleJsonِ هر تعداد Definition/Version قابلِ
 * استفاده است — دیگر Definition-level نیست. ساختار عمداً هم‌الگو با
 * WorkflowTemplateParameterController/TemplateParameterService.
 *
 * مشاهده با WORKFLOW_VIEW؛ ایجاد/ویرایش/فعال‌سازی با Permissionِ جداگانهٔ
 * WORKFLOW_MANAGE_CONDITION_FIELDS (بدونِ تغییر).
 */
class WorkflowConditionFieldController extends WorkflowApiController
{
    public function __construct(private ConditionFieldService $conditionFields)
    {
    }

    /** GET workflow/condition-fields */
    public function index(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'includeInactive' => 'nullable|boolean',
        ]);

        return $this->runWorkflow(fn () => [
            'items' => $this->conditionFields->list((bool) ($validated['includeInactive'] ?? false)),
        ]);
    }

    /** POST workflow/condition-fields — Upsert: بدونِ fieldId = ایجاد؛ با fieldId = ویرایش. */
    public function store(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_CONDITION_FIELDS');

        $validated = $request->validate([
            'fieldId'         => 'nullable|integer|exists:WorkflowConditionFields,FieldID',
            // Code هرگز از کلاینت گرفته نمی‌شود — Service آن را از displayName/latinName
            // می‌سازد؛ latinName فقط وقتی لازم است که displayName حرفِ لاتینِ کافی نداشته باشد.
            // SourceType/SourceKey هم جزئیاتِ فنیِ صرفِ Backend‌اند و اینجا گرفته نمی‌شوند.
            'displayName'     => 'required|string|max:200',
            'latinName'       => 'nullable|string|max:50',
            'dataType'        => 'required|string|max:20',
            'allowedValues'   => 'nullable|array',
            'allowedValues.*' => 'string|max:200',
            'description'     => 'nullable|string|max:500',
            'sortOrder'       => 'nullable|integer',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $res = $this->conditionFields->save($validated, $this->actorId());

            return [
                'message' => ($res->Message ?? 'فیلد ذخیره شد.') . " (Code: {$res->Code})",
                'fieldId' => (int) $res->FieldID,
                'code'    => $res->Code,
            ];
        });
    }

    /** POST workflow/condition-fields/{fieldId}/toggle */
    public function toggleActive(int $fieldId)
    {
        $this->authorizeWorkflow('WORKFLOW_MANAGE_CONDITION_FIELDS');

        return $this->runWorkflow(function () use ($fieldId) {
            $res = $this->conditionFields->toggleActive($fieldId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ فیلد تغییر کرد.'];
        });
    }

    /**
     * سازگاریِ عقب‌رو با Designer UIِ موجود (ConditionFieldManagerModal.tsx) که هنوز
     * روی مسیرِ قدیمیِ definitions/{definitionId}/condition-fields کار می‌کند —
     * definitionId فقط در URL باقی می‌ماند و در منطق استفاده نمی‌شود، چون Registry
     * دیگر Definition-level نیست. تغییرِ خودِ UI به این PR موکول نشده (خارج از Scope).
     */
    public function indexForDefinition(int $definitionId, Request $request)
    {
        return $this->index($request);
    }

    /** سازگاریِ عقب‌رو — نگاه کن به indexForDefinition(). */
    public function storeForDefinition(int $definitionId, Request $request)
    {
        return $this->store($request);
    }
}
