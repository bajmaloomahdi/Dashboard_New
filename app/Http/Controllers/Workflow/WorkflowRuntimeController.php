<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Message\MessageAccessChecker;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * زمانِ اجرا: شروعِ فرایند + مشاهدهٔ Instance و تاریخچهٔ آن.
 */
class WorkflowRuntimeController extends WorkflowApiController
{
    public function __construct(
        private WorkflowEngine $engine,
        private WorkflowQueryService $query,
        private MessageAccessChecker $messageAccess,
    ) {
    }

    /** POST workflow/instances  — شروعِ فرایند روی یک موجودیت */
    public function start(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_START');

        $validated = $request->validate([
            'definitionId'      => 'nullable|integer',
            'definitionCode'    => 'nullable|string|max:64',
            'entityType'        => 'required|string|max:64',
            'entityId'          => 'required|integer|min:1',
            'entityOwnerUserId' => 'nullable|integer',
            'entityUnitId'      => 'nullable|integer',
            'context'           => 'nullable|array',
        ]);

        if (empty($validated['definitionId']) && empty($validated['definitionCode'])) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه یا کدِ فرایند الزامی است.',
                'errors'  => ['definition' => ['شناسه یا کدِ فرایند الزامی است.']],
            ], 422);
        }

        return $this->runWorkflow(function () use ($validated) {
            // دسترسیِ کاربر به موجودیت — فقط برایِ EntityTypeهایی که معنایِ
            // Access-Controlِ اختصاصی دارند. برایِ MESSAGE: فرستنده/گیرنده/رونوشت
            // (همان Semanticsِ MessageAccessChecker که MessageController هم
            // برایِ دانلودِ ضمیمه‌ها استفاده می‌کند). وجودِ خودِ Message هم همین‌جا
            // پوشش داده می‌شود چون رکوردِ ناموجود هیچ ردیفِ Participant ندارد.
            if ($validated['entityType'] === 'MESSAGE') {
                if (! $this->messageAccess->isParticipant((int) $validated['entityId'], (int) Auth::id())) {
                    throw new WorkflowValidationException('شما به این پیام دسترسی ندارید یا پیام یافت نشد.');
                }
            }

            $result = $this->engine->start(new StartWorkflowRequest(
                entityType: $validated['entityType'],
                entityId: (int) $validated['entityId'],
                startedByUserId: $this->actorId(),          // Actor همیشه از کاربرِ احراز هویت‌شده
                definitionCode: $validated['definitionCode'] ?? null,
                definitionId: isset($validated['definitionId']) ? (int) $validated['definitionId'] : null,
                entityOwnerUserId: isset($validated['entityOwnerUserId']) ? (int) $validated['entityOwnerUserId'] : null,
                entityUnitId: isset($validated['entityUnitId']) ? (int) $validated['entityUnitId'] : null,
                context: $validated['context'] ?? [],
            ));

            return [
                'message'         => $result->message ?? 'فرایند آغاز شد.',
                'instanceId'      => $result->instanceId,
                'instanceStatus'  => $result->instanceStatus,
                'enteredStepCode' => $result->enteredStepCode,
                'createdMessageIds' => $result->createdMessageIds,
            ];
        });
    }

    /** GET workflow/instances/{instanceId} */
    public function show(int $instanceId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $data = $this->query->instance($instanceId);
        abort_if(! $data['instance'], 404, 'نمونهٔ فرایند یافت نشد.');

        return $this->runWorkflow(fn () => [
            'instance' => $data['instance'],
            'steps'    => $data['steps'],
            'tasks'    => $data['tasks'],
        ]);
    }

    /** GET workflow/instances/{instanceId}/history */
    public function history(int $instanceId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $data = $this->query->instance($instanceId);
        abort_if(! $data['instance'], 404, 'نمونهٔ فرایند یافت نشد.');

        return $this->runWorkflow(fn () => ['history' => $data['history']]);
    }

    /* ---------- چرخهٔ حیات ---------- */

    /** POST workflow/instances/{instanceId}/cancel */
    public function cancel(Request $request, int $instanceId)
    {
        return $this->lifecycle($request, $instanceId, 'WORKFLOW_CANCEL', 'cancel', withReason: true);
    }

    /** POST workflow/instances/{instanceId}/suspend */
    public function suspend(Request $request, int $instanceId)
    {
        return $this->lifecycle($request, $instanceId, 'WORKFLOW_SUSPEND', 'suspend', withReason: true);
    }

    /** POST workflow/instances/{instanceId}/resume */
    public function resume(Request $request, int $instanceId)
    {
        return $this->lifecycle($request, $instanceId, 'WORKFLOW_SUSPEND', 'resume', withReason: false);
    }

    private function lifecycle(Request $request, int $instanceId, string $permission, string $op, bool $withReason)
    {
        $this->authorizeWorkflow($permission);

        abort_if(! $this->query->instance($instanceId)['instance'], 404, 'نمونهٔ فرایند یافت نشد.');

        $reason = null;
        if ($withReason) {
            $reason = $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? null;
        }

        return $this->runWorkflow(function () use ($op, $instanceId, $reason) {
            $result = match ($op) {
                'cancel'  => $this->engine->cancel($instanceId, $this->actorId(), $reason),
                'suspend' => $this->engine->suspend($instanceId, $this->actorId(), $reason),
                'resume'  => $this->engine->resume($instanceId, $this->actorId()),
            };

            return [
                'message'        => $result->message,
                'instanceStatus' => $result->instanceStatus,
            ];
        });
    }
}
