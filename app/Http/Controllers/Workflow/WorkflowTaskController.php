<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Workflow\Dto\TaskActionRequest;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use Illuminate\Http\Request;

/**
 * «تسکِ مرحله»ی فرایند بر مبنای MessageID.
 *
 * کارتابلِ تسک‌های فرایند جداگانه نیست — از همان مسیرِ موجودِ /messages
 * (sp_GetMessages) دیده می‌شود. این کنترلر فقط نمای تجمیعیِ workflow برای یک
 * تسک + اجرای اقدام را می‌دهد.
 */
class WorkflowTaskController extends WorkflowApiController
{
    public function __construct(
        private WorkflowEngine $engine,
        private WorkflowQueryService $query,
    ) {
    }

    /** GET workflow/messages/{messageId}  — نمای تجمیعیِ workflow برای یک تسکِ کارتابلی */
    public function show(int $messageId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $detail = $this->query->stepTaskDetail($messageId);
        abort_if(! $detail['task'], 404, 'تسکِ فرایند یافت نشد.');

        $isAssignee = collect($detail['assignees'])
            ->contains(fn ($a) => (int) $a->UserID === $this->actorId() && (int) $a->IsActive === 1);

        if (! $isAssignee && ! $this->userCan('WORKFLOW_VIEW_ALL_TASKS')) {
            abort(403, 'شما به این تسک دسترسی ندارید.');
        }

        if ($isAssignee) {
            $detail = $this->query->stepTaskDetail($messageId, $this->actorId());
        }

        $detail['task']->RowVersion = $this->hexRowVersion($detail['task']->RowVersion ?? null);

        return $this->runWorkflow(fn () => [
            'task'      => $detail['task'],
            'assignees' => $detail['assignees'],
            'actions'   => $detail['actions'],
            'history'   => $detail['history'],
            'canAct'    => $isAssignee,
        ]);
    }

    /** POST workflow/messages/{messageId}/actions  — اجرای یک اقدام روی تسک */
    public function act(Request $request, int $messageId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        $validated = $request->validate([
            'actionCode' => 'required|string|max:64',
            'comment'    => 'nullable|string|max:4000',
            'rowVersion' => 'nullable|string|max:34',
        ]);

        return $this->runWorkflow(function () use ($validated, $messageId, $request) {
            $result = $this->engine->performAction(new TaskActionRequest(
                messageId: $messageId,
                userId: $this->actorId(),                    // Actor هرگز از Request گرفته نمی‌شود
                actionCode: $validated['actionCode'],
                comment: $validated['comment'] ?? null,
                expectedRowVersion: $validated['rowVersion'] ?? null,
                ipAddress: $request->ip(),
            ));

            return [
                'message'          => $result->message ?? 'اقدام ثبت شد.',
                'instanceStatus'   => $result->instanceStatus,
                'enteredStepCode'  => $result->enteredStepCode,
                'createdMessageIds' => $result->createdMessageIds,
            ];
        });
    }

    /**
     * POST workflow/messages/{messageId}/forward  — ارجاعِ دائمیِ تسک به کاربرِ دیگر.
     *
     * Actor همیشه از کاربرِ احراز هویت‌شده است؛ فقط مقصد (targetUserId) از Request می‌آید.
     * قواعدِ کسب‌وکار (انجام‌دهندهٔ فعال، Decision، SUSPENDED، AllowForward، ForwardMax)
     * در Engine/SP بررسی می‌شوند، نه اینجا.
     */
    public function forward(Request $request, int $messageId)
    {
        $this->authorizeWorkflow('WORKFLOW_FORWARD');

        $validated = $request->validate([
            'targetUserId' => 'required|integer|exists:Users,UserID',
            'comment'      => 'nullable|string|max:1000',
        ]);

        return $this->runWorkflow(fn () => $this->reassignResponse(
            $this->engine->forward(
                $messageId,
                $this->actorId(),
                (int) $validated['targetUserId'],
                $validated['comment'] ?? null,
            )
        ));
    }

    /**
     * POST workflow/messages/{messageId}/delegate  — تفویضِ موقتِ تسک به کاربرِ دیگر.
     */
    public function delegate(Request $request, int $messageId)
    {
        $this->authorizeWorkflow('WORKFLOW_DELEGATE');

        $validated = $request->validate([
            'targetUserId' => 'required|integer|exists:Users,UserID',
            'comment'      => 'nullable|string|max:1000',
        ]);

        return $this->runWorkflow(fn () => $this->reassignResponse(
            $this->engine->delegate(
                $messageId,
                $this->actorId(),
                (int) $validated['targetUserId'],
                $validated['comment'] ?? null,
            )
        ));
    }

    /**
     * POST workflow/messages/{messageId}/revoke-delegation  — بازپس‌گیریِ تفویض.
     *
     * revokedByUserId همیشه کاربرِ احراز هویت‌شده است. نمایندهٔ هدف (delegateUserId)
     * می‌تواند صریح از Request بیاید؛ در نبودِ آن، تفویضِ فعالی که *خودِ* Actor انجام
     * داده از رویِ همین پیام تشخیص داده می‌شود (صرفاً یک lookup، نه قاعدهٔ کسب‌وکار).
     */
    public function revokeDelegation(Request $request, int $messageId)
    {
        $this->authorizeWorkflow('WORKFLOW_DELEGATE');

        $validated = $request->validate([
            'delegateUserId' => 'nullable|integer|exists:Users,UserID',
        ]);

        $delegateUserId = isset($validated['delegateUserId']) ? (int) $validated['delegateUserId'] : null;

        if ($delegateUserId === null) {
            $detail = $this->query->stepTaskDetail($messageId);
            abort_if(! $detail['task'], 404, 'تسکِ فرایند یافت نشد.');

            $mine = collect($detail['assignees'])->first(fn ($a) => ($a->SourceType ?? null) === 'DELEGATION'
                && (int) $a->IsActive === 1
                && ($a->Decision ?? null) === null
                && (int) ($a->SourceRefID ?? 0) === $this->actorId());

            abort_if(! $mine, 422, 'نماینده مشخص نشده و تفویضِ فعالی که توسطِ شما انجام شده باشد برای این تسک یافت نشد.');

            $delegateUserId = (int) $mine->UserID;
        }

        return $this->runWorkflow(fn () => $this->reassignResponse(
            $this->engine->revokeDelegation($messageId, $delegateUserId, $this->actorId())
        ));
    }

    /** شکلِ یکنواختِ پاسخِ ارجاع/تفویض/باطل‌سازی. */
    private function reassignResponse(\App\Services\Workflow\Dto\EngineResult $result): array
    {
        return [
            'message'         => $result->message,
            'instanceStatus'  => $result->instanceStatus,
            'enteredStepCode' => $result->enteredStepCode,
        ];
    }
}
