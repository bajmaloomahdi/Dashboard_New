<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Support\WorkflowStore;

/**
 * ثبتِ رویدادهای تاریخچه/حسابرسی به‌صورتِ یکنواخت.
 *
 * EventCode ها رشتهٔ آزادند (جدولِ WorkflowHistory روی این ستون CHECK ندارد)
 * ولی برای یکدستی از ثابت‌های همین کلاس استفاده می‌شود.
 */
class WorkflowHistoryRecorder
{
    public const INSTANCE_STARTED   = 'INSTANCE_STARTED';
    public const INSTANCE_COMPLETED = 'INSTANCE_COMPLETED';
    public const INSTANCE_CANCELLED = 'INSTANCE_CANCELLED';
    public const INSTANCE_SUSPENDED = 'INSTANCE_SUSPENDED';
    public const INSTANCE_RESUMED   = 'INSTANCE_RESUMED';
    public const INSTANCE_FAILED    = 'INSTANCE_FAILED';
    public const STEP_ENTERED       = 'STEP_ENTERED';
    public const STEP_COMPLETED     = 'STEP_COMPLETED';
    public const STEP_SKIPPED       = 'STEP_SKIPPED';
    public const TASK_CREATED       = 'TASK_CREATED';
    public const TASK_OPENED        = 'TASK_OPENED';
    public const TASK_DECISION      = 'TASK_DECISION';
    public const TASK_COMPLETED     = 'TASK_COMPLETED';
    public const TASK_CANCELLED     = 'TASK_CANCELLED';
    public const TASK_FORWARDED          = 'TASK_FORWARDED';
    public const TASK_DELEGATED          = 'TASK_DELEGATED';
    public const TASK_DELEGATION_REVOKED = 'TASK_DELEGATION_REVOKED';
    public const TRANSITION_TAKEN   = 'TRANSITION_TAKEN';

    public function __construct(private WorkflowStore $store)
    {
    }

    public function record(array $p): void
    {
        $this->store->insertHistory($p);
    }

    public function instanceStarted(object $instanceCtx, ?int $actorUserId): void
    {
        $this->store->insertHistory([
            'entityType'   => $instanceCtx->entityType,
            'entityId'     => $instanceCtx->entityId,
            'eventCode'    => self::INSTANCE_STARTED,
            'instanceId'   => $instanceCtx->instanceId,
            'actorUserId'  => $actorUserId,
            'actorType'    => $actorUserId ? 'USER' : 'SYSTEM',
            'summary'      => 'فرایند آغاز شد',
            'detail'       => ['definitionId' => $instanceCtx->definitionId, 'versionId' => $instanceCtx->versionId],
        ]);
    }
}
