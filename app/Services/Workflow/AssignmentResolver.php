<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Dto\ResolvedAssignee;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;

/**
 * تبدیلِ ردیف‌های WorkflowStepAssignments به فهرستِ کاربرانِ واقعی.
 *
 * منطقِ هر AssigneeType در رویهٔ dbo.sp_Wf_ResolveAssignees است؛ این کلاس فقط
 * زمینه (context) را می‌سازد، چند ردیفِ Assignment را با هم ادغام می‌کند و
 * تکراری‌ها را حذف می‌کند. برای افزودنِ نوعِ جدیدِ Assignee کافی است رویه گسترش
 * یابد؛ موتور دست‌نخورده می‌ماند.
 */
class AssignmentResolver
{
    /** انواعی که در فاز ۱ پشتیبانی می‌شوند. */
    public const SUPPORTED = [
        'USER', 'ROLE', 'POSITION', 'UNIT',
        'UNIT_MANAGER', 'DIRECT_MANAGER', 'INITIATOR', 'ENTITY_OWNER',
    ];

    public function __construct(private WorkflowStore $store)
    {
    }

    /**
     * @param  array<int,object>  $assignmentRows  ردیف‌های Assignment یک مرحله (مرتب بر SortOrder)
     * @param  object             $context         شاملِ initiatorUserId, entityOwnerUserId, entityUnitId
     * @return ResolvedAssignee[]
     */
    public function resolve(array $assignmentRows, object $context): array
    {
        $out = [];
        $seen = [];

        foreach ($assignmentRows as $row) {
            $type = $row->AssigneeType;

            if (! in_array($type, self::SUPPORTED, true)) {
                throw new WorkflowValidationException("نوعِ انجام‌دهندهٔ «{$type}» در فاز ۱ پشتیبانی نمی‌شود.");
            }

            $users = $this->store->resolveAssignees($type, [
                'refId'             => $row->RefID ?? null,
                'refExpression'     => $row->RefExpression ?? null,
                'initiatorUserId'   => $context->initiatorUserId ?? null,
                'entityOwnerUserId' => $context->entityOwnerUserId ?? null,
                'entityUnitId'      => $context->entityUnitId ?? null,
            ]);

            foreach ($users as $u) {
                if (isset($seen[$u->UserID])) {
                    continue;
                }
                $seen[$u->UserID] = true;
                $out[] = new ResolvedAssignee(
                    userId: (int) $u->UserID,
                    sourceType: $type,
                    sourceRefId: $row->RefID ?? null,
                    fullName: $u->FullName ?? null,
                );
            }
        }

        return $out;
    }
}
