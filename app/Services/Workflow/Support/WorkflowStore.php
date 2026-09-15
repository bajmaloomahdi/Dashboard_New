<?php

namespace App\Services\Workflow\Support;

use App\Services\Workflow\Exceptions\WorkflowConcurrencyException;
use App\Services\Workflow\Exceptions\WorkflowException;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * تنها نقطهٔ تماسِ ماژولِ Workflow با پایگاه‌داده.
 *
 * همهٔ فراخوانی‌ها از طریقِ رویه‌های dbo.sp_Wf_* / dbo.sp_BusinessCalendar_* انجام
 * می‌شود (طبقِ معماریِ SP-Driven پروژه). قراردادِ خروجیِ رویه‌های نوشتنی
 * «SELECT ... AS Success, ... AS Message» است؛ این کلاس آن را به آرایه/استثنا
 * ترجمه می‌کند.
 *
 * این کلاس «مرزِ تراکنش» ندارد؛ WorkflowEngine با DB::transaction آن را می‌گیرد.
 */
class WorkflowStore
{
    /* ---------- شماره‌گذاری ---------- */

    public function nextNumber(string $entityName, string $prefix): string
    {
        $row = DB::selectOne('EXEC dbo.sp_Wf_NextNumber @EntityName = ?, @Prefix = ?', [$entityName, $prefix]);

        return $row->Number;
    }

    /* ---------- Definition / Version ---------- */

    public function getDefinitions(?string $search = null, ?bool $isActive = null, ?int $categoryId = null): array
    {
        return DB::select(
            'EXEC dbo.sp_Wf_GetDefinitions @SearchText = ?, @IsActive = ?, @CategoryID = ?',
            [$search, $isActive, $categoryId]
        );
    }

    /** @return array{definition:?object, versions:array} */
    public function getDefinition(int $definitionId): array
    {
        [$def, $versions] = $this->multi(
            'EXEC dbo.sp_Wf_GetDefinition @DefinitionID = :id',
            ['id' => $definitionId],
            2
        );

        return ['definition' => $def[0] ?? null, 'versions' => $versions];
    }

    public function saveDefinition(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_SaveDefinition @DefinitionID = ?, @Code = ?, @Name = ?, @Description = ?, @EntityType = ?, @IsActive = ?, @CategoryID = ?, @UserID = ?',
            [
                $p['definitionId'] ?? null, $p['code'], $p['name'], $p['description'] ?? null, $p['entityType'],
                $p['isActive'] ?? 1, $p['categoryId'] ?? null, $p['userId'],
            ]
        );
    }

    /* ---------- دسته‌بندیِ فرایندها (WorkflowCategories) ---------- */

    public function getCategories(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Wf_GetCategories @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveCategory(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_SaveCategory @CategoryID = ?, @Code = ?, @Name = ?, @Description = ?, @SortOrder = ?, @UserID = ?',
            [$p['categoryId'] ?? null, $p['code'], $p['name'], $p['description'] ?? null, $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleCategoryActive(int $categoryId, int $userId): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_ToggleCategoryActive @CategoryID = ?, @UserID = ?',
            [$categoryId, $userId]
        );
    }

    /* ---------- فیلدهایِ شرط (WorkflowConditionFields) ---------- */

    public function getConditionFields(int $definitionId, bool $includeInactive = false): array
    {
        return DB::select(
            'EXEC dbo.sp_Wf_GetConditionFields @DefinitionID = ?, @IncludeInactive = ?',
            [$definitionId, $includeInactive]
        );
    }

    public function saveConditionField(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_SaveConditionField @FieldID = ?, @DefinitionID = ?, @Code = ?, @DisplayName = ?, @DataType = ?, @SourceType = ?, @SourceKey = ?, @AllowedValuesJson = ?, @SortOrder = ?, @UserID = ?',
            [
                $p['fieldId'] ?? null, $p['definitionId'], $p['code'], $p['displayName'], $p['dataType'],
                $p['sourceType'], $p['sourceKey'], $p['allowedValuesJson'] ?? null, $p['sortOrder'] ?? 0, $p['userId'],
            ]
        );
    }

    public function toggleConditionFieldActive(int $fieldId, int $userId): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_ToggleConditionFieldActive @FieldID = ?, @UserID = ?',
            [$fieldId, $userId]
        );
    }

    /** همهٔ RuleJsonهایِ غیرِNull در همهٔ نسخه‌هایِ یک Definition — برایِ Guardِ Immutabilityِ Code. */
    public function getDefinitionRuleJsons(int $definitionId): array
    {
        return DB::select('EXEC dbo.sp_Wf_GetDefinitionRuleJsons @DefinitionID = ?', [$definitionId]);
    }

    public function getInstanceContext(int $instanceId): ?string
    {
        $row = DB::selectOne('EXEC dbo.sp_Wf_GetInstanceContext @InstanceID = ?', [$instanceId]);

        return $row?->ContextJson;
    }

    public function createDraftVersion(int $definitionId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Wf_CreateDraftVersion @DefinitionID = ?, @UserID = ?', [$definitionId, $userId]);
    }

    public function cloneVersion(int $sourceVersionId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Wf_CloneVersion @SourceVersionID = ?, @UserID = ?', [$sourceVersionId, $userId]);
    }

    public function getVersionMeta(int $versionId): ?object
    {
        return DB::selectOne('EXEC dbo.sp_Wf_GetVersionMeta @VersionID = ?', [$versionId]);
    }

    /** @return array{steps:array,actions:array,assignments:array,transitions:array} */
    public function getVersionGraph(int $versionId): array
    {
        return [
            'steps'       => DB::select('EXEC dbo.sp_Wf_GetVersionSteps @VersionID = ?', [$versionId]),
            'actions'     => DB::select('EXEC dbo.sp_Wf_GetVersionStepActions @VersionID = ?', [$versionId]),
            'assignments' => DB::select('EXEC dbo.sp_Wf_GetVersionStepAssignments @VersionID = ?', [$versionId]),
            'transitions' => DB::select('EXEC dbo.sp_Wf_GetVersionTransitions @VersionID = ?', [$versionId]),
        ];
    }

    public function saveVersionGraph(int $versionId, array $graph, int $userId): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_SaveVersionGraph @VersionID = ?, @StepsJson = ?, @ActionsJson = ?, @AssignmentsJson = ?, @TransitionsJson = ?, @UserID = ?',
            [
                $versionId,
                json_encode($graph['steps'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($graph['actions'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($graph['assignments'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($graph['transitions'] ?? [], JSON_UNESCAPED_UNICODE),
                $userId,
            ]
        );
    }

    public function setValidationResult(int $versionId, ?array $result, int $userId): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_SetValidationResult @VersionID = ?, @Json = ?, @UserID = ?',
            [$versionId, $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE), $userId]
        );
    }

    public function publishVersion(int $versionId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Wf_PublishVersion @VersionID = ?, @UserID = ?', [$versionId, $userId]);
    }

    /* ---------- Runtime ---------- */

    public function hasActiveInstance(int $definitionId, string $entityType, int $entityId): ?object
    {
        return DB::selectOne(
            'EXEC dbo.sp_Wf_HasActiveInstance @DefinitionID = ?, @EntityType = ?, @EntityID = ?',
            [$definitionId, $entityType, $entityId]
        );
    }

    public function startInstance(array $p): object
    {
        try {
            return $this->write(
                'EXEC dbo.sp_Wf_StartInstance @DefinitionID = ?, @VersionID = ?, @EntityType = ?, @EntityID = ?, @StartedByUserID = ?, @InstanceNumber = ?, @ContextJson = ?',
                [
                    $p['definitionId'], $p['versionId'], $p['entityType'], $p['entityId'], $p['startedByUserId'] ?? null,
                    $p['instanceNumber'], $p['contextJson'] ?? null,
                ]
            );
        } catch (UniqueConstraintViolationException $e) {
            // بردِ از دست‌رفتهٔ Startِ هم‌زمان: ایندکسِ یکتای فیلترشدهٔ «یک RUNNING به‌ازای هر
            // موجودیت» (پچ 020) نقض شده — همان معنایِ pre-checkِ hasActiveInstance، این‌بار در
            // سطحِ دیتابیس ⇒ WorkflowStateException ⇒ HTTP 409. هر نقضِ یکتای دیگر بالا می‌رود.
            if (str_contains($e->getMessage(), 'UX_WorkflowInstances_Running_Entity')) {
                throw new WorkflowStateException('برای این مورد از قبل یک فرایندِ فعال وجود دارد.');
            }
            throw $e;
        }
    }

    public function bumpTransitionCount(int $instanceId): int
    {
        $row = DB::selectOne('EXEC dbo.sp_Wf_BumpTransitionCount @InstanceID = ?', [$instanceId]);

        return (int) $row->TransitionCount;
    }

    public function setInstanceStatus(int $instanceId, string $status, ?int $userId = null): void
    {
        $this->write('EXEC dbo.sp_Wf_SetInstanceStatus @InstanceID = ?, @Status = ?, @UserID = ?', [$instanceId, $status, $userId]);
    }

    public function insertStepInstance(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_InsertStepInstance @InstanceID = ?, @StepID = ?, @StepCode = ?, @StepType = ?, @EnteredViaTransitionID = ?, @IterationNo = ?, @UserID = ?',
            [$p['instanceId'], $p['stepId'], $p['stepCode'], $p['stepType'], $p['enteredViaTransitionId'] ?? null, $p['iterationNo'] ?? 1, $p['userId'] ?? null]
        );
    }

    public function completeStepInstance(int $stepInstanceId, string $status, ?string $actionCode, ?int $transitionId, ?int $userId): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_CompleteStepInstance @StepInstanceID = ?, @Status = ?, @OutcomeActionCode = ?, @OutcomeTransitionID = ?, @UserID = ?',
            [$stepInstanceId, $status, $actionCode, $transitionId, $userId]
        );
    }

    public function countStepIterations(int $instanceId, int $stepId): int
    {
        $row = DB::selectOne('EXEC dbo.sp_Wf_CountStepIterations @InstanceID = ?, @StepID = ?', [$instanceId, $stepId]);

        return (int) $row->Iterations;
    }

    /**
     * ساختِ آیتمِ کارتابل برای یک مرحلهٔ کاربری: یک ردیفِ Messages (نوعِ «وظیفه») +
     * یک MessageDetails به‌ازای هر assignee + UserNotifications.
     *
     * @return object  دارای MessageID و MessageNumber
     */
    public function createTaskMessage(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_CreateTaskMessage @Subject = ?, @MessageText = ?, @msgPriorityID = ?, @DueDate = ?, @SenderUserID = ?, @AssigneesJson = ?, @CreateUser = ?',
            [
                $p['subject'],
                $p['messageText'] ?? null,
                $p['priorityId'] ?? null,
                $p['dueDate'] ?? null,
                $p['senderUserId'],
                json_encode(
                    array_map(fn ($uid) => ['userId' => (int) $uid], $p['assigneeUserIds']),
                    JSON_UNESCAPED_UNICODE
                ),
                $p['createUser'] ?? $p['senderUserId'],
            ]
        );
    }

    /** اتصالِ MessageID + snapshotِ سیاست به StepInstance (پس از ساختِ Message). */
    public function attachStepMessage(int $stepInstanceId, int $messageId, string $assignPolicy, ?int $requiredApprovals, ?int $userId): void
    {
        $this->write(
            'EXEC dbo.sp_Wf_AttachStepMessage @StepInstanceID = ?, @MessageID = ?, @AssignPolicy = ?, @RequiredApprovals = ?, @UserID = ?',
            [$stepInstanceId, $messageId, $assignPolicy, $requiredApprovals, $userId]
        );
    }

    public function insertTaskAssignee(int $stepInstanceId, int $userId, string $sourceType, ?int $sourceRefId, ?int $actorUserId): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_InsertTaskAssignee @StepInstanceID = ?, @UserID = ?, @SourceType = ?, @SourceRefID = ?, @ActorUserID = ?',
            [$stepInstanceId, $userId, $sourceType, $sourceRefId, $actorUserId]
        );
    }

    public function markStepFirstOpened(int $stepInstanceId, int $userId): bool
    {
        $row = DB::selectOne('EXEC dbo.sp_Wf_MarkStepFirstOpened @StepInstanceID = ?, @UserID = ?', [$stepInstanceId, $userId]);

        return (bool) ($row->Changed ?? 0);
    }

    public function setAssigneeDecision(int $stepInstanceId, int $userId, string $decision, string $actionCode, ?string $comment): object
    {
        return $this->write(
            'EXEC dbo.sp_Wf_SetAssigneeDecision @StepInstanceID = ?, @UserID = ?, @Decision = ?, @ActionCode = ?, @Comment = ?',
            [$stepInstanceId, $userId, $decision, $actionCode, $comment]
        );
    }

    /**
     * بستنِ «تسکِ مرحله»: StepInstance → COMPLETED (CAS با RowVersion + Status) و بستنِ
     * ردیف‌های بازِ MessageDetails تا از کارتابل خارج شود. ناسازگاری → WorkflowConcurrencyException.
     */
    public function completeStepTask(
        int $stepInstanceId,
        ?string $expectedRowVersionHex,
        string $outcomeActionCode,
        ?int $outcomeTransitionId,
        int $actorUserId,
        string $disposition
    ): void {
        $row = DB::selectOne(
            'EXEC dbo.sp_Wf_CompleteStepTask @StepInstanceID = ?, @ExpectedRowVersion = ?, @OutcomeActionCode = ?, @OutcomeTransitionID = ?, @ActorUserID = ?, @Disposition = ?',
            [
                $stepInstanceId,
                $this->normalizeRowVersionHex($expectedRowVersionHex),
                $outcomeActionCode,
                $outcomeTransitionId,
                $actorUserId,
                $disposition,
            ]
        );

        if (! $row || (int) $row->Success !== 1) {
            throw new WorkflowConcurrencyException($row->Message ?? 'بستنِ مرحله ناموفق بود.');
        }
    }

    /* ---------- چرخهٔ حیاتِ Instance (Cancel / Suspend / Resume) ---------- */

    /**
     * لغوِ Instance: RUNNING|SUSPENDED → CANCELLED + بستنِ تسک‌های باز + SKIP مراحلِ فعال.
     * وضعیتِ نامناسب → WorkflowStateException (HTTP 409).
     *
     * @return array{closedMessageIds:int[], closedTaskCount:int, skippedStepCount:int}
     */
    public function cancelInstance(int $instanceId, int $actorUserId): array
    {
        [$head, $messages] = $this->multi(
            'EXEC dbo.sp_Wf_CancelInstance @InstanceID = :id, @ActorUserID = :uid',
            ['id' => $instanceId, 'uid' => $actorUserId],
            2
        );

        $row = $head[0] ?? null;
        $this->assertLifecycleOk($row);

        return [
            'closedMessageIds' => array_map(fn ($t) => (int) $t->MessageID, $messages),
            'closedTaskCount'  => (int) ($row->ClosedTaskCount ?? 0),
            'skippedStepCount' => (int) ($row->SkippedStepCount ?? 0),
        ];
    }

    /** تعلیقِ Instance: RUNNING → SUSPENDED. وضعیتِ نامناسب → WorkflowStateException. */
    public function suspendInstance(int $instanceId, int $actorUserId): void
    {
        $this->assertLifecycleOk(DB::selectOne(
            'EXEC dbo.sp_Wf_SuspendInstance @InstanceID = ?, @ActorUserID = ?',
            [$instanceId, $actorUserId]
        ));
    }

    /** ازسرگیریِ Instance: SUSPENDED → RUNNING. وضعیتِ نامناسب → WorkflowStateException. */
    public function resumeInstance(int $instanceId, int $actorUserId): void
    {
        $this->assertLifecycleOk(DB::selectOne(
            'EXEC dbo.sp_Wf_ResumeInstance @InstanceID = ?, @ActorUserID = ?',
            [$instanceId, $actorUserId]
        ));
    }

    /** خروجیِ استانداردِ SPهای چرخهٔ حیات: Success + Conflict + Message. */
    private function assertLifecycleOk(?object $row): void
    {
        if (! $row) {
            throw new WorkflowException('رویه هیچ نتیجه‌ای برنگرداند.');
        }
        if ((int) $row->Success === 1) {
            return;
        }
        if (! empty($row->Conflict)) {
            throw new WorkflowStateException($row->Message ?? 'وضعیتِ فرایند برای این عملیات مناسب نیست.');
        }
        // Success=0 و Conflict=0 → یافت‌نشد / خطای ورودی
        throw new WorkflowValidationException($row->Message ?? 'عملیات ناموفق بود.');
    }

    /* ---------- ارجاع / تفویض / باطل‌سازیِ تفویض (Step 7.2) ---------- */

    /**
     * ارجاع (FORWARD) یا تفویضِ (DELEGATE) «تسکِ مرحله» از Actor به کاربرِ دیگر.
     *
     * MessageID و StepInstanceID ثابت می‌مانند؛ رکوردِ Actor غیرفعال و یک رکوردِ
     * *تازهٔ* WorkflowTaskAssignees برای Target ساخته می‌شود؛ کارتابلِ Actor به
     * «انجام نخواهد شد» و کارتابلِ Target به «ارسال شده» می‌رود. رویدادِ تاریخچه
     * (`TASK_FORWARDED` / `TASK_DELEGATED`) داخلِ همان تراکنشِ خودِ رویه ثبت می‌شود؛
     * بنابراین این عملیات به‌تنهایی اتمیک است و به DB::transaction بیرونی نیاز ندارد.
     *
     * قراردادِ خروجی: Success + Conflict + Message  →  assertReassignOk
     *
     * @param  string       $mode  'FORWARD' | 'DELEGATE'
     * @return object  دارای StepInstanceID و InstanceID
     */
    public function reassignStepTask(
        int $messageId,
        int $actorUserId,
        int $targetUserId,
        string $mode,
        ?string $comment = null,
        ?string $expectedRowVersionHex = null
    ): object {
        $row = DB::selectOne(
            'EXEC dbo.sp_Wf_ReassignStepTask @MessageID = ?, @ActorUserID = ?, @TargetUserID = ?, @Mode = ?, @Comment = ?, @ExpectedRowVersion = ?',
            [
                $messageId,
                $actorUserId,
                $targetUserId,
                $mode,
                $comment,
                $this->normalizeRowVersionHex($expectedRowVersionHex),
            ]
        );

        $this->assertReassignOk($row);

        return $row;
    }

    /**
     * باطل‌سازیِ تفویض: نماینده غیرفعال و واگذارکننده (Delegator) دوباره فعال می‌شود.
     * رویه فقط دقیق‌ترین رکوردِ inactiveِ بی‌تصمیمِ واگذارکننده را دوباره فعال می‌کند.
     * رویدادِ `TASK_DELEGATION_REVOKED` داخلِ همان تراکنشِ خودِ رویه ثبت می‌شود.
     *
     * @return object  دارای DelegatorUserID و StepInstanceID
     */
    public function revokeDelegation(int $messageId, int $delegateUserId, int $revokedByUserId): object
    {
        $row = DB::selectOne(
            'EXEC dbo.sp_Wf_RevokeDelegation @MessageID = ?, @DelegateUserID = ?, @RevokedByUserID = ?',
            [$messageId, $delegateUserId, $revokedByUserId]
        );

        $this->assertReassignOk($row);

        return $row;
    }

    /**
     * قراردادِ خروجیِ SPهای ارجاع/تفویض/باطل‌سازی: Success + Conflict + Message.
     *
     *   Success=1              → موفق
     *   Conflict=1             → WorkflowStateException        (HTTP 409)
     *   Success=0 و Conflict=0 → WorkflowValidationException   (HTTP 422)
     */
    private function assertReassignOk(?object $row): void
    {
        if (! $row) {
            throw new WorkflowException('رویه هیچ نتیجه‌ای برنگرداند.');
        }
        if ((int) $row->Success === 1) {
            return;
        }
        if (! empty($row->Conflict)) {
            throw new WorkflowStateException($row->Message ?? 'وضعیتِ تسک برای این عملیات مناسب نیست.');
        }

        throw new WorkflowValidationException($row->Message ?? 'ارجاع/تفویض ناموفق بود.');
    }

    public function insertHistory(array $p): void
    {
        $this->call(
            'EXEC dbo.sp_Wf_InsertHistory @EntityType = ?, @EntityID = ?, @EventCode = ?, @InstanceID = ?, @StepInstanceID = ?, @MessageID = ?, @ActorUserID = ?, @ActorType = ?, @Summary = ?, @OldValueJson = ?, @NewValueJson = ?, @DetailJson = ?, @IpAddress = ?',
            [
                $p['entityType'], $p['entityId'], $p['eventCode'],
                $p['instanceId'] ?? null, $p['stepInstanceId'] ?? null, $p['messageId'] ?? null,
                $p['actorUserId'] ?? null, $p['actorType'] ?? 'USER', $p['summary'] ?? null,
                isset($p['oldValue']) ? json_encode($p['oldValue'], JSON_UNESCAPED_UNICODE) : null,
                isset($p['newValue']) ? json_encode($p['newValue'], JSON_UNESCAPED_UNICODE) : null,
                isset($p['detail']) ? json_encode($p['detail'], JSON_UNESCAPED_UNICODE) : null,
                $p['ipAddress'] ?? null,
            ]
        );
    }

    /* ---------- Queries ---------- */

    public function resolveAssignees(string $assigneeType, array $ctx = []): array
    {
        return DB::select(
            'EXEC dbo.sp_Wf_ResolveAssignees @AssigneeType = ?, @RefID = ?, @RefExpression = ?, @InitiatorUserID = ?, @EntityOwnerUserID = ?, @EntityUnitID = ?',
            [
                $assigneeType,
                $ctx['refId'] ?? null,
                $ctx['refExpression'] ?? null,
                $ctx['initiatorUserId'] ?? null,
                $ctx['entityOwnerUserId'] ?? null,
                $ctx['entityUnitId'] ?? null,
            ]
        );
    }

    /** @return array{instance:?object, steps:array, tasks:array} */
    public function getInstance(int $instanceId): array
    {
        [$inst, $steps, $tasks] = $this->multi(
            'EXEC dbo.sp_Wf_GetInstance @InstanceID = :id',
            ['id' => $instanceId],
            3
        );

        return ['instance' => $inst[0] ?? null, 'steps' => $steps, 'tasks' => $tasks];
    }

    public function getInstanceHistory(int $instanceId): array
    {
        return DB::select('EXEC dbo.sp_Wf_GetInstanceHistory @InstanceID = ?', [$instanceId]);
    }

    /** @return array{rows:array, totalCount:int} */
    public function getInstances(array $filters, int $page, int $pageSize): array
    {
        $rows = DB::select(
            'EXEC dbo.sp_Wf_GetInstances
                @DefinitionID = ?, @Status = ?, @EntityType = ?, @EntityID = ?, @StartedByUserID = ?,
                @DateFrom = ?, @DateTo = ?, @Page = ?, @PageSize = ?',
            [
                $filters['definitionId'] ?? null,
                $filters['status'] ?? null,
                $filters['entityType'] ?? null,
                $filters['entityId'] ?? null,
                $filters['startedByUserId'] ?? null,
                $filters['dateFrom'] ?? null,
                $filters['dateTo'] ?? null,
                $page,
                $pageSize,
            ]
        );

        return ['rows' => $rows, 'totalCount' => (int) ($rows[0]->TotalCount ?? 0)];
    }

    /**
     * جزئیاتِ «تسکِ مرحله» بر مبنای MessageID (پیامِ کارتابلی).
     *
     * @return array{task:?object, assignees:array, actions:array, history:array}
     */
    public function getStepTaskDetail(int $messageId): array
    {
        [$task, $assignees, $actions, $history] = $this->multi(
            'EXEC dbo.sp_Wf_GetStepTaskDetail @MessageID = :id',
            ['id' => $messageId],
            4
        );

        return [
            'task'      => $task[0] ?? null,
            'assignees' => $assignees,
            'actions'   => $actions,
            'history'   => $history,
        ];
    }

    /* ================================================================== */

    /** اجرای رویهٔ نوشتنی و بازگرداندنِ ردیفِ اول؛ Success=0 → استثنا. */
    private function write(string $sql, array $bindings): object
    {
        $row = DB::selectOne($sql, $bindings);

        if (! $row) {
            throw new WorkflowException('رویه هیچ نتیجه‌ای برنگرداند.');
        }

        if (property_exists($row, 'Success') && (int) $row->Success !== 1) {
            throw new WorkflowException($row->Message ?? 'عملیاتِ پایگاه‌داده ناموفق بود.');
        }

        return $row;
    }

    /** اجرای رویه بدونِ نیاز به نتیجه. */
    private function call(string $sql, array $bindings): void
    {
        DB::statement($sql, $bindings);
    }

    /**
     * اجرای رویهٔ چندنتیجه‌ای و بازگرداندنِ $count نتیجهٔ اول به‌صورتِ آرایه‌ای از آرایه‌ها.
     *
     * @return array<int,array<int,object>>
     */
    private function multi(string $sql, array $named, int $count): array
    {
        $pdo = DB::connection()->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($named);

        $sets = [];
        for ($i = 0; $i < $count; $i++) {
            $sets[] = $stmt->fetchAll(\PDO::FETCH_OBJ);
            if (! $stmt->nextRowset()) {
                break;
            }
        }

        return array_pad($sets, $count, []);
    }

    /** نرمال‌سازیِ RowVersion به رشتهٔ «0x» + ۱۶ رقمِ hex برای CONVERT(BINARY(8), ..., 1). */
    private function normalizeRowVersionHex(?string $hex): ?string
    {
        if ($hex === null || $hex === '') {
            return null;
        }

        $body = (str_starts_with($hex, '0x') || str_starts_with($hex, '0X')) ? substr($hex, 2) : $hex;
        $body = preg_replace('/[^0-9a-fA-F]/', '', (string) $body);

        if ($body === '') {
            return null;
        }

        return '0x' . str_pad(substr($body, -16), 16, '0', STR_PAD_LEFT);
    }
}
