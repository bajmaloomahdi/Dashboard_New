<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\WorkflowDefinitionService;
use Illuminate\Http\Request;

/**
 * نسخه‌های فرایند (WorkflowVersions) + گرافِ نسخه + اعتبارسنجی + انتشار.
 */
class WorkflowVersionController extends WorkflowApiController
{
    public function __construct(private WorkflowDefinitionService $defs)
    {
    }

    /** POST workflow/definitions/{definitionId}/versions  — ایجادِ نسخهٔ پیش‌نویس */
    public function createDraft(int $definitionId)
    {
        $this->authorizeWorkflow('WORKFLOW_DESIGN');

        abort_if(! $this->defs->show($definitionId)['definition'], 404, 'فرایند یافت نشد.');

        return $this->runWorkflow(function () use ($definitionId) {
            $res = $this->defs->createDraft($definitionId, $this->actorId());

            return [
                'message'   => $res->Message ?? 'نسخهٔ پیش‌نویس ایجاد شد.',
                'versionId' => (int) $res->VersionID,
                'versionNo' => (int) $res->VersionNo,
            ];
        });
    }

    /** POST workflow/versions/{versionId}/clone */
    public function clone(int $versionId)
    {
        $this->authorizeWorkflow('WORKFLOW_DESIGN');

        abort_if(! $this->defs->getGraph($versionId)['meta'], 404, 'نسخهٔ فرایند یافت نشد.');

        return $this->runWorkflow(function () use ($versionId) {
            $res = $this->defs->cloneVersion($versionId, $this->actorId());

            return [
                'message'   => $res->Message ?? 'نسخهٔ جدید ساخته شد.',
                'versionId' => (int) $res->VersionID,
                'versionNo' => (int) $res->VersionNo,
            ];
        });
    }

    /** GET workflow/versions/{versionId}  — متادادهٔ نسخه */
    public function show(int $versionId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $meta = $this->defs->getGraph($versionId)['meta'];
        abort_if(! $meta, 404, 'نسخهٔ فرایند یافت نشد.');

        return $this->runWorkflow(fn () => ['meta' => $meta]);
    }

    /** GET workflow/versions/{versionId}/graph  — steps + actions + assignments + transitions */
    public function graph(int $versionId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $data = $this->defs->getGraph($versionId);
        abort_if(! $data['meta'], 404, 'نسخهٔ فرایند یافت نشد.');

        return $this->runWorkflow(fn () => [
            'meta'        => $data['meta'],
            'steps'       => $data['graph']['steps'],
            'actions'     => $data['graph']['actions'],
            'assignments' => $data['graph']['assignments'],
            'transitions' => $data['graph']['transitions'],
        ]);
    }

    /** GET workflow/versions/{versionId}/steps */
    public function steps(int $versionId)
    {
        return $this->graphSlice($versionId, 'steps');
    }

    /** GET workflow/versions/{versionId}/actions */
    public function actions(int $versionId)
    {
        return $this->graphSlice($versionId, 'actions');
    }

    /** GET workflow/versions/{versionId}/assignments */
    public function assignments(int $versionId)
    {
        return $this->graphSlice($versionId, 'assignments');
    }

    /** GET workflow/versions/{versionId}/transitions */
    public function transitions(int $versionId)
    {
        return $this->graphSlice($versionId, 'transitions');
    }

    private function graphSlice(int $versionId, string $key)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $data = $this->defs->getGraph($versionId);
        abort_if(! $data['meta'], 404, 'نسخهٔ فرایند یافت نشد.');

        return $this->runWorkflow(fn () => [$key => $data['graph'][$key]]);
    }

    /** PUT workflow/versions/{versionId}/graph  — ذخیرهٔ گرافِ نسخهٔ DRAFT */
    public function saveGraph(Request $request, int $versionId)
    {
        $this->authorizeWorkflow('WORKFLOW_DESIGN');

        abort_if(! $this->defs->getGraph($versionId)['meta'], 404, 'نسخهٔ فرایند یافت نشد.');

        $validated = $request->validate([
            'steps'                      => 'required|array',
            'steps.*.code'               => 'required|string|max:64',
            'steps.*.name'               => 'required|string|max:200',
            'steps.*.stepType'           => 'required|string|max:30',
            'steps.*.assignPolicy'       => 'nullable|string|max:10',
            'steps.*.requiredApprovals'  => 'nullable|integer|min:1',
            'steps.*.allowForward'       => 'nullable|boolean',
            'steps.*.forwardMax'         => 'nullable|integer|min:1',
            'steps.*.allowDelegation'    => 'nullable|boolean',
            'steps.*.sortOrder'          => 'nullable|integer',
            'steps.*.positionX'          => 'nullable|integer',
            'steps.*.positionY'          => 'nullable|integer',
            'steps.*.description'        => 'nullable|string|max:1000',
            'steps.*.dueDurationHours'   => 'nullable|integer|min:1',
            'actions'                    => 'nullable|array',
            'assignments'                => 'nullable|array',
            'assignments.*.stepCode'     => 'required|string|max:64',
            'assignments.*.assigneeType' => 'required|string|max:30',
            'assignments.*.refId'        => 'nullable|integer',
            'assignments.*.refExpression' => 'nullable|string|max:400',
            'assignments.*.sortOrder'    => 'nullable|integer',
            'assignments.*.isBackup'     => 'nullable|boolean',
            'transitions'                       => 'nullable|array',
            'transitions.*.code'                => 'required|string|max:64',
            'transitions.*.fromStepCode'        => 'required|string|max:64',
            'transitions.*.toStepCode'          => 'required|string|max:64',
            'transitions.*.triggerActionCode'   => 'nullable|string|max:64',
            'transitions.*.priority'            => 'nullable|integer',
            'transitions.*.isDefault'           => 'nullable|boolean',
            'transitions.*.label'               => 'nullable|string|max:100',
            'transitions.*.conditionExpression' => 'nullable|string|max:500',
            'transitions.*.ruleJson'            => 'nullable|array',
        ]);

        return $this->runWorkflow(function () use ($versionId, $validated) {
            // اعتبارسنجیِ ساختاری فقط در WorkflowDefinitionService/SP انجام می‌شود
            $this->defs->saveGraph($versionId, [
                'steps'       => $validated['steps'],
                'actions'     => $validated['actions'] ?? [],
                'assignments' => $validated['assignments'] ?? [],
                'transitions' => $validated['transitions'] ?? [],
            ], $this->actorId());

            return ['message' => 'گرافِ نسخه ذخیره شد.'];
        });
    }

    /** POST workflow/versions/{versionId}/validate  — اعتبارسنجیِ گراف */
    public function validateGraph(int $versionId)
    {
        $this->authorizeWorkflow('WORKFLOW_DESIGN');

        abort_if(! $this->defs->getGraph($versionId)['meta'], 404, 'نسخهٔ فرایند یافت نشد.');

        return $this->runWorkflow(function () use ($versionId) {
            $result = $this->defs->validate($versionId, $this->actorId());

            return [
                'ok'       => $result['ok'],
                'errors'   => $result['errors'],
                'warnings' => $result['warnings'],
            ];
        });
    }

    /** POST workflow/versions/{versionId}/publish */
    public function publish(int $versionId)
    {
        $this->authorizeWorkflow('WORKFLOW_PUBLISH');

        abort_if(! $this->defs->getGraph($versionId)['meta'], 404, 'نسخهٔ فرایند یافت نشد.');

        return $this->runWorkflow(function () use ($versionId) {
            $res = $this->defs->publish($versionId, $this->actorId());

            return ['message' => $res->Message ?? 'نسخه منتشر و فعال شد.'];
        });
    }
}
