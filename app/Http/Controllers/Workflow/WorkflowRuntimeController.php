<?php

namespace App\Http\Controllers\Workflow;

use App\Services\Message\MessageAccessChecker;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\LetterStartRules;
use App\Services\Workflow\LetterTemplateService;
use App\Services\Workflow\TemplateRenderer;
use App\Services\Workflow\WorkflowEngine;
use App\Services\Workflow\WorkflowQueryService;
use App\Services\Workflow\WorkflowStartContextBuilder;
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
        private WorkflowStartContextBuilder $startContext,
        private LetterTemplateService $letterTemplates,
        private TemplateRenderer $renderer,
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

    /**
     * GET workflow/definitions/{definitionId}/preview-assignees
     * پیش‌نمایشِ Read-Only گیرندهٔ اولین Task — بدونِ نوشتن، بدونِ Instanceِ واقعی.
     * برایِ فرمِ «ارسالِ بر اساسِ فرایند» در Messages/Create.
     */
    public function previewAssignees(int $definitionId)
    {
        $this->authorizeWorkflow('WORKFLOW_VIEW');

        // Workflow Start Context (فقط نمایش): starter + Stepِ اول + گیرندگانِ واقعی + startable/message.
        // کلیدهایِ قدیمیِ resolved/reason/users حفظ شده‌اند. Submit این مقدار را ملاک قرار نمی‌دهد.
        return $this->runWorkflow(fn () => $this->startContext->build($definitionId, $this->actorId())->toArray());
    }

    /**
     * POST workflow/letters — ثبتِ «نامهٔ فرایندی» و شروعِ Workflow در یک Transaction.
     *
     * Submit هیچ اطلاعاتی دربارهٔ آغازکننده/گیرنده از کلاینت نمی‌پذیرد: آغازکننده همیشه Auth::id()
     * است و گیرندگان از Assignmentِ واقعیِ Stepِ اولِ Definition در سرور دوباره محاسبه می‌شوند
     * (Preview فقط برایِ نمایش بود). ترتیب: Context → پیش‌شرط‌ها → Template/Form → (Message «وظیفه»
     * + CC + Adopt + Start در یک Transaction). در هر خطا هیچ Message/Instance/Taskی باقی نمی‌ماند.
     */
    public function startLetter(Request $request)
    {
        $this->authorizeWorkflow('WORKFLOW_START');

        // فیلدهایِ ناشناخته (مثلاً RecipientUserIDs) عمداً نادیده گرفته می‌شوند.
        $validated = $request->validate([
            'definitionId'     => 'required|integer|min:1',
            'letterTemplateId' => 'required|integer|min:1',
            'formValues'       => 'nullable|array',
            'Subject'          => 'nullable|string|max:500',
            'MessageText'      => 'nullable|string|max:5000',
            'msgPriorityID'    => 'nullable|integer',
            'CopyUserIDs'      => 'nullable|array',
            'CopyUserIDs.*'    => 'integer',
            'CopyDescription'  => 'nullable|string|max:1000',
            'DueDate'          => 'nullable|date',
        ]);

        return $this->runWorkflow(function () use ($validated) {
            $starterId = $this->actorId();
            $definitionId = (int) $validated['definitionId'];
            $templateId = (int) $validated['letterTemplateId'];

            // ۱) Context در سرور، از نو — پیش از هر نوشتن
            $context = $this->startContext->build($definitionId, $starterId);
            if (! $context->startable) {
                throw new WorkflowValidationException(
                    $context->message ?? LetterStartRules::MESSAGES['NO_ASSIGNEE_FOUND']
                );
            }

            // ۲) قالب باید متعلق به همین Definition باشد (اختصاصی یا عمومیِ همان EntityType)
            $allowed = array_map(fn ($t) => (int) $t->LetterTemplateID, $this->letterTemplates->listForDefinition($definitionId));
            if (! in_array($templateId, $allowed, true)) {
                throw new WorkflowValidationException('قالبِ انتخاب‌شده برایِ این فرایند مجاز نیست.');
            }

            // ۳) رندرِ نهایی (All-or-Nothing) — خطا یعنی هیچ نوشتنی انجام نشده
            $rendered = $this->renderer->render($templateId, $validated['formValues'] ?? [], $starterId);
            $subject = ! empty($validated['Subject']) ? $validated['Subject'] : $rendered['subject'];
            $text = ! empty($validated['MessageText']) ? $validated['MessageText'] : $rendered['body'];

            // ۴) Message «وظیفه» + CC + Adopt + Start — یک Transaction (داخلِ Engine)
            $result = $this->engine->startWithNewTaskMessage(
                definitionId: $definitionId,
                startedByUserId: $starterId,
                subject: $subject,
                messageText: $text,
                priorityId: isset($validated['msgPriorityID']) ? (int) $validated['msgPriorityID'] : null,
                dueDate: $validated['DueDate'] ?? null,
                copyUserIds: $validated['CopyUserIDs'] ?? null,
                copyDescription: $validated['CopyDescription'] ?? null,
                // فقط برایِ مسیرِ Deferred (CONDITION) مصرف می‌شود — از میانِ این مقادیر، فقط
                // فیلدهایِ واقعاً مرتبط با CONDITIONهایِ همین Version به Context تبدیل می‌شوند.
                formValues: $validated['formValues'] ?? [],
            );

            return [
                'message'    => 'نامه ثبت و فرایند آغاز شد.',
                'messageId'  => $result->createdMessageIds[0] ?? null,
                'instanceId' => $result->instanceId,
                'instanceStatus' => $result->instanceStatus,
                'recipients' => $context->recipients,
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
