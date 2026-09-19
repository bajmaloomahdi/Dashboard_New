<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\Request;

/**
 * تعریفِ فرایندها (WorkflowDefinitions).
 */
class WorkflowDefinitionController extends WorkflowApiController
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /** GET workflow/definitions */
    public function index(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'search'     => 'nullable|string|max:200',
            'isActive'   => 'nullable|boolean',
            'categoryId' => 'nullable|integer',
        ]);

        return $this->runWorkflow(fn () => [
            'items' => $this->defs->list(
                $validated['search'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
                $validated['categoryId'] ?? null,
            ),
        ]);
    }

    /** GET workflow/definitions/{definitionId} */
    public function show(int $definitionId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $data = $this->defs->show($definitionId);
        abort_if(! $data['definition'], 404, 'فرایند یافت نشد.');

        return $this->runWorkflow(fn () => [
            'definition' => $data['definition'],
            'versions'   => $data['versions'],
        ]);
    }

    /**
     * POST workflow/definitions
     *
     * Upsert: بدونِ `definitionId` = ایجاد؛ با `definitionId` = ویرایشِ همان تعریف.
     * هر دو مسیر از همین یک متد و همان WorkflowDefinitionService::save() عبور می‌کنند
     * (که خودش از sp_Wf_SaveDefinition — قبلاً هم شاخهٔ ایجاد و هم ویرایش را پوشش
     * می‌داد — استفاده می‌کند)؛ هیچ منطقِ موازی اضافه نشده.
     */
    public function store(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_DESIGN');

        $validated = $request->validate([
            'definitionId' => 'nullable|integer|exists:WorkflowDefinitions,DefinitionID',
            // Code هرگز از کلاینت گرفته نمی‌شود — Service آن را از name/latinName می‌سازد؛
            // latinName فقط وقتی لازم است که name حرفِ لاتینِ کافی نداشته باشد.
            'name'         => 'required|string|max:200',
            'latinName'    => 'nullable|string|max:50',
            'description'  => 'nullable|string|max:1000',
            'entityType'   => 'required|string|max:64',
            'isActive'     => 'nullable|boolean',
            'categoryId'   => 'nullable|integer|exists:WorkflowCategories,CategoryID',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $res = $this->defs->save([
                'definitionId' => $validated['definitionId'] ?? null,
                'name'         => $validated['name'],
                'latinName'    => $validated['latinName'] ?? null,
                'description'  => $validated['description'] ?? null,
                'entityType'   => $validated['entityType'],
                'isActive'     => (int) ($validated['isActive'] ?? 1),
                'categoryId'   => $validated['categoryId'] ?? null,
            ], $this->actorId());

            return [
                'message'      => ($res->Message ?? 'فرایند ذخیره شد.') . " (Code: {$res->Code})",
                'definitionId' => (int) $res->DefinitionID,
                'code'         => $res->Code,
            ];
        });
    }

    /**
     * POST workflow/definitions/{definitionId}/toggle
     *
     * فعال/غیرفعال‌کردنِ یک Definition. IsActive فقط جلویِ Startِ نمونهٔ *جدید* را
     * می‌گیرد (سنجه‌اش sp_Wf_GetDefinitions/WorkflowEngine::locateActiveDefinition
     * است که فقط رویِ Definitionهایِ IsActive=1 جست‌وجو می‌کند)؛ نمونه‌هایِ در‌حالِ‌اجرا
     * به VersionID خودشان قفل‌اند و از این پرچم تأثیر نمی‌گیرند — پس toggle هیچ‌وقت
     * رفتارِ Runtimeِ موجود را نمی‌شکند. بدونِ SP جدید: همان save() با مقادیرِ فعلیِ
     * سایرِ فیلدها و IsActiveِ معکوس‌شده صدا زده می‌شود.
     */
    public function toggleActive(int $definitionId)
    {
        $this->authorizeWorkflow('WORKFLOW_DESIGN');

        $current = $this->defs->show($definitionId)['definition'];
        abort_if(! $current, 404, 'فرایند یافت نشد.');

        return $this->runWorkflow(function () use ($definitionId, $current) {
            $newIsActive = ! (bool) $current->IsActive;

            $res = $this->defs->save([
                'definitionId' => $definitionId,
                'name'         => $current->Name,
                'description'  => $current->Description,
                'entityType'   => $current->EntityType,
                'isActive'     => (int) $newIsActive,
                'categoryId'   => $current->CategoryID ?? null,
            ], $this->actorId());

            return [
                'message'      => $res->Message ?? 'وضعیتِ فرایند تغییر کرد.',
                'definitionId' => (int) $res->DefinitionID,
                'isActive'     => $newIsActive,
            ];
        });
    }
}
