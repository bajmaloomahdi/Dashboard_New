<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Dto\ResolvedAssignee;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;

/**
 * ساخت و بستنِ تسک‌های فرایند + ارزیابیِ سیاستِ تصمیم‌گیری (ANY / ALL / N_OF_M).
 *
 * این سرویس «مرزِ تراکنش» ندارد؛ WorkflowEngine آن را فراهم می‌کند.
 */
class TaskService
{
    public function __construct(
        private WorkflowStore $store,
        private WorkflowHistoryRecorder $history,
    ) {
    }

    /**
     * ساختِ «تسکِ مرحله» = یک آیتمِ کارتابل در dbo.Messages (نوعِ «وظیفه») + یک
     * MessageDetails به‌ازای هر assignee + ثبتِ ledgerِ رأی در WorkflowTaskAssignees.
     * هیچ جدولِ تسکِ مستقلی وجود ندارد؛ این پیام دقیقاً مثلِ Taskهای عادی/پروژه در
     * sp_GetMessages دیده می‌شود.
     *
     * @param  ResolvedAssignee[]  $assignees
     * @return int  MessageID
     */
    public function createStepTask(object $ctx, object $step, int $stepInstanceId, array $assignees, ?int $actorUserId, ?string $title = null): int
    {
        if ($assignees === []) {
            throw new WorkflowValidationException(
                "مرحلهٔ «{$step->Name}» هیچ انجام‌دهنده‌ای ندارد؛ فرایند نمی‌تواند ادامه یابد."
            );
        }

        $policy = $step->AssignPolicy ?? 'ANY';
        $required = $step->RequiredApprovals !== null ? (int) $step->RequiredApprovals : null;

        if ($policy === 'N_OF_M' && ($required === null || $required < 1)) {
            throw new WorkflowValidationException("مرحلهٔ «{$step->Name}» با سیاستِ N_OF_M نیازمندِ RequiredApprovals معتبر است.");
        }

        $senderUserId = $ctx->initiatorUserId
            ?? throw new WorkflowValidationException('فرایندِ بدونِ آغازگر نمی‌تواند آیتمِ کارتابلی بسازد.');

        $msg = $this->store->createTaskMessage([
            'subject'         => $title ?? $this->defaultTitle($ctx, $step),
            'messageText'     => $ctx->entityTitle ?? null,
            'priorityId'      => $ctx->priorityId ?? null,
            'dueDate'         => $ctx->dueDate ?? null,
            'senderUserId'    => (int) $senderUserId,
            'assigneeUserIds' => array_map(fn ($a) => $a->userId, $assignees),
            'createUser'      => $actorUserId ?? (int) $senderUserId,
        ]);

        $messageId = (int) $msg->MessageID;
        $messageNumber = $msg->MessageNumber ?? '';

        $this->store->attachStepMessage($stepInstanceId, $messageId, $policy, $required, $actorUserId);

        foreach ($assignees as $a) {
            $this->store->insertTaskAssignee($stepInstanceId, $a->userId, $a->sourceType, $a->sourceRefId, $actorUserId);
        }

        $this->history->record([
            'entityType'  => $ctx->entityType,
            'entityId'    => $ctx->entityId,
            'eventCode'   => WorkflowHistoryRecorder::TASK_CREATED,
            'instanceId'  => $ctx->instanceId,
            'stepInstanceId' => $stepInstanceId,
            'messageId'   => $messageId,
            'actorUserId' => $actorUserId,
            'actorType'   => $actorUserId ? 'USER' : 'SYSTEM',
            'summary'     => "تسکِ «{$step->Name}» ایجاد شد ({$messageNumber})",
            'detail'      => ['assignees' => array_map(fn ($a) => $a->userId, $assignees), 'policy' => $policy, 'messageNumber' => $messageNumber],
        ]);

        return $messageId;
    }

    /**
     * پیش‌بینیِ نتیجهٔ یک اقدام «بدونِ نوشتن» — از روی snapshotِ انجام‌دهندگان.
     *
     * موتور اول این را صدا می‌زند تا تصمیم بگیرد آیا باید تسک را ببندد (و بنابراین
     * sp_Wf_CompleteTask با RowVersionِ کاربر نخستین نوشته باشد) یا فقط تصمیم را
     * ثبت کند.
     *
     * @param  array<int,object>  $assignees    ردیف‌های انجام‌دهنده (از getTaskDetail؛ شاملِ Decision)
     * @param  array<int,object>  $stepActions
     * @return object{action:object, decision:string, willResolve:bool, disposition:string, outcomeActionCode:?string}
     */
    public function preview(object $task, array $assignees, array $stepActions, int $userId, string $actionCode, ?string $comment): object
    {
        $action = $this->findAction($stepActions, $actionCode);
        if ($action === null) {
            throw new WorkflowValidationException("اقدامِ «{$actionCode}» برای این مرحله تعریف نشده است.");
        }
        if (($action->RequiresComment ?? 0) && trim((string) $comment) === '') {
            throw new WorkflowValidationException("اقدامِ «{$action->Label}» نیازمندِ توضیح است.");
        }

        $decision = $this->decisionFromKind($action->Kind);

        // وضعیتِ فعلی + شبیه‌سازیِ افزودنِ تصمیمِ این کاربر
        $active = 0;
        $approvals = 0;
        $rejections = 0;
        $acted = 0;
        $userIsUndecidedActive = false;

        foreach ($assignees as $a) {
            if ((int) $a->IsActive !== 1) {
                continue;
            }
            $active++;
            $d = $a->Decision ?? null;
            if ($d !== null) {
                $acted++;
                if ($d === 'APPROVED') {
                    $approvals++;
                }
                if ($d === 'REJECTED') {
                    $rejections++;
                }
            }
            if ((int) $a->UserID === $userId && $d === null) {
                $userIsUndecidedActive = true;
            }
        }

        if (! $userIsUndecidedActive) {
            throw new WorkflowStateException('شما قبلاً روی این تسک اقدام کرده‌اید یا انجام‌دهندهٔ فعالِ آن نیستید.');
        }

        // اعمالِ تصمیمِ کاربر
        $acted++;
        if ($decision === 'APPROVED') {
            $approvals++;
        }
        if ($decision === 'REJECTED') {
            $rejections++;
        }

        $eval = $this->evaluatePolicy(
            $task,
            $stepActions,
            $action,
            $decision,
            (object) [
                'AssignPolicy'        => $task->AssignPolicy ?? 'ANY',
                'RequiredApprovals'   => $task->RequiredApprovals ?? null,
                'ReceivedApprovals'   => $approvals,
                'ReceivedRejections'  => $rejections,
                'ActiveAssigneeCount' => $active,
                'ActedCount'          => $acted,
            ]
        );

        return (object) [
            'action'            => $action,
            'decision'          => $decision,
            'willResolve'       => $eval->taskResolved,
            'disposition'       => $eval->disposition,
            'outcomeActionCode' => $eval->outcomeActionCode,
        ];
    }

    /**
     * ثبتِ تصمیمِ کاربر در WorkflowTaskAssignees (keyed by StepInstanceID) + رویدادِ تاریخچه.
     */
    public function recordDecision(object $task, object $action, string $decision, int $userId, ?string $comment): void
    {
        $res = $this->store->setAssigneeDecision((int) $task->StepInstanceID, $userId, $decision, $action->Code, $comment);

        if (property_exists($res, 'Success') && (int) $res->Success !== 1) {
            throw new WorkflowStateException($res->Message ?? 'ثبتِ تصمیم ناموفق بود.');
        }

        $this->history->record([
            'entityType'  => $task->EntityType,
            'entityId'    => (int) $task->EntityID,
            'eventCode'   => WorkflowHistoryRecorder::TASK_DECISION,
            'instanceId'  => (int) $task->InstanceID,
            'stepInstanceId' => (int) $task->StepInstanceID,
            'messageId'   => (int) $task->MessageID,
            'actorUserId' => $userId,
            'summary'     => "{$action->Label}" . ($comment ? " — {$comment}" : ''),
            'detail'      => ['actionCode' => $action->Code, 'decision' => $decision],
        ]);
    }

    /* ================================================================== */

    private function evaluatePolicy(object $task, array $stepActions, object $action, string $decision, object $c): object
    {
        $policy   = $c->AssignPolicy ?? $task->AssignPolicy ?? 'ANY';
        $required = $c->RequiredApprovals !== null ? (int) $c->RequiredApprovals : null;
        $approvals   = (int) ($c->ReceivedApprovals ?? 0);
        $rejections  = (int) ($c->ReceivedRejections ?? 0);
        $active      = (int) ($c->ActiveAssigneeCount ?? 1);
        $acted       = (int) ($c->ActedCount ?? 1);

        $resolved = false;
        $disposition = 'PENDING'; // PENDING | APPROVED | REJECTED | RETURNED | COMPLETED

        if ($decision === 'RETURNED') {
            // بازگشت با نخستین درخواستِ کاربر نهایی می‌شود (مثلِ ANY)
            $resolved = true;
            $disposition = 'RETURNED';
        } elseif ($policy === 'ANY') {
            $resolved = true;
            $disposition = $decision === 'REJECTED' ? 'REJECTED' : ($decision === 'APPROVED' ? 'APPROVED' : 'COMPLETED');
        } elseif ($policy === 'ALL') {
            if ($decision === 'REJECTED') {
                $resolved = true;
                $disposition = 'REJECTED';
            } elseif ($acted >= $active) {
                $resolved = true;
                $disposition = $rejections > 0 ? 'REJECTED' : ($approvals > 0 ? 'APPROVED' : 'COMPLETED');
            }
        } elseif ($policy === 'N_OF_M') {
            $n = $required ?? 1;
            if ($approvals >= $n) {
                $resolved = true;
                $disposition = 'APPROVED';
            } elseif ($rejections > ($active - $n)) {
                // دیگر رسیدن به N تأیید ممکن نیست
                $resolved = true;
                $disposition = 'REJECTED';
            }
        } else {
            throw new WorkflowValidationException("سیاستِ انتساب «{$policy}» نامعتبر است.");
        }

        return (object) [
            'taskResolved'      => $resolved,
            'disposition'       => $disposition,
            'decision'          => $decision,
            'outcomeActionCode' => $resolved ? $this->outcomeActionCode($stepActions, $action, $disposition) : null,
        ];
    }

    /** کدِ Action ای که گذارِ بعدی را تعیین می‌کند. */
    private function outcomeActionCode(array $stepActions, object $actingAction, string $disposition): string
    {
        // اگر اقدامِ کاربر با نتیجه هم‌جهت است، همان
        $kind = $actingAction->Kind;
        $aligned = match ($disposition) {
            'APPROVED', 'COMPLETED' => in_array($kind, ['APPROVE', 'COMPLETE', 'CUSTOM'], true),
            'REJECTED'              => $kind === 'REJECT',
            'RETURNED'              => $kind === 'RETURN',
            default                 => false,
        };
        if ($aligned) {
            return $actingAction->Code;
        }

        $wantKinds = match ($disposition) {
            'APPROVED'  => ['APPROVE', 'COMPLETE'],
            'COMPLETED' => ['COMPLETE', 'APPROVE'],
            'REJECTED'  => ['REJECT'],
            'RETURNED'  => ['RETURN'],
            default     => [],
        };

        foreach ($wantKinds as $wk) {
            foreach ($stepActions as $sa) {
                if ($sa->Kind === $wk) {
                    return $sa->Code;
                }
            }
        }

        // در نبودِ Action متناظر، همان کدِ اقدامِ کاربر
        return $actingAction->Code;
    }

    private function findAction(array $stepActions, string $code): ?object
    {
        foreach ($stepActions as $a) {
            if ($a->Code === $code) {
                return $a;
            }
        }

        return null;
    }

    private function decisionFromKind(string $kind): string
    {
        return match ($kind) {
            'APPROVE' => 'APPROVED',
            'REJECT'  => 'REJECTED',
            'RETURN'  => 'RETURNED',
            default   => 'APPROVED', // COMPLETE / CUSTOM / FORWARD / CANCEL → به‌عنوانِ «انجام‌شده»
        };
    }

    private function defaultTitle(object $ctx, object $step): string
    {
        $entityTitle = $ctx->entityTitle ?? "{$ctx->entityType} #{$ctx->entityId}";

        return "{$step->Name} — {$entityTitle}";
    }
}
