<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Dto\EngineResult;
use App\Services\Workflow\Dto\StartWorkflowRequest;
use App\Services\Workflow\Dto\TaskActionRequest;
use App\Services\Workflow\Entity\EntityResolverRegistry;
use App\Services\Workflow\Exceptions\WorkflowStateException;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;
use Illuminate\Support\Facades\DB;

/**
 * موتورِ اجرای فرایند — فاز ۱.
 *
 * قابلیت‌ها: شروعِ Instance، ورود/خروجِ مراحلِ START/USER_TASK/APPROVAL/END،
 * حلِ گذار (بدونِ شرط)، حلِ انتساب، ساخت/بستنِ تسک، تاریخچه/حسابرسی،
 * نگهبانِ حلقه (TransitionCount)، قفلِ نسخه (Instance به VersionID خودش گره خورده)،
 * کنترلِ هم‌زمانی (RowVersion در بستنِ تسک)، و ایمنیِ تکرار (Idempotency).
 *
 * مرزِ تراکنش اینجاست؛ رویه‌ها و سرویس‌های پایین‌دستی تراکنشِ داخلی ندارند.
 */
class WorkflowEngine
{
    /** سقفِ محلیِ پیمایشِ زنجیرهٔ مراحلِ خودکار در یک فراخوان. */
    private const LOCAL_STEP_GUARD = 50;

    public function __construct(
        private WorkflowStore $store,
        private AssignmentResolver $assignments,
        private TransitionResolver $transitions,
        private TaskService $tasks,
        private WorkflowHistoryRecorder $history,
        private EntityResolverRegistry $entities,
        private ConditionContextBuilder $contextBuilder,
        private ConditionEvaluator $conditionEvaluator,
    ) {
    }

    /* ============================ شروع ============================ */

    public function start(StartWorkflowRequest $req): EngineResult
    {
        $definition = $this->locateActiveDefinition($req);
        $version = $definition->versionMeta;

        // نگهبانِ تکرار: نمونهٔ فعالِ دیگری برای همین (تعریف، موجودیت) نباشد
        $active = $this->store->hasActiveInstance((int) $definition->DefinitionID, $req->entityType, $req->entityId);
        if ($active) {
            throw new WorkflowStateException(
                "برای این مورد از قبل یک فرایندِ فعال وجود دارد ({$active->InstanceNumber})."
            );
        }

        $resolver = $this->entities->resolverFor($req->entityType);
        if (! $resolver->exists($req->entityType, $req->entityId)) {
            throw new WorkflowValidationException('موجودیتِ مقصد برای شروعِ فرایند معتبر نیست.');
        }

        $ctx = (object) [
            'entityType'        => $req->entityType,
            'entityId'          => $req->entityId,
            'definitionId'      => (int) $definition->DefinitionID,
            'versionId'         => (int) $version->VersionID,
            'initiatorUserId'   => $req->startedByUserId,
            'entityOwnerUserId' => $req->entityOwnerUserId ?? $resolver->ownerUserId($req->entityType, $req->entityId),
            'entityUnitId'      => $req->entityUnitId ?? $resolver->unitId($req->entityType, $req->entityId),
            'entityTitle'       => $resolver->title($req->entityType, $req->entityId),
            'preCreatedMessageId' => $req->preCreatedMessageId,
        ];

        // Validate/Cast قبل از Transaction — Contextِ نامعتبر نباید هرگز Instanceِ
        // ناقص بسازد (طبقِ Final Design، بخشِ D). با versionId (نه definitionId) صدا زده
        // می‌شود چون فیلدهایِ مرتبط اکنون از رویِ RuleJsonِ همین Version استخراج می‌شوند
        // (Global Registry)، نه از رویِ یک فهرستِ Definition-level.
        $context = $this->contextBuilder->build($ctx->versionId, $req->context);
        $contextJson = $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE);

        return DB::transaction(function () use ($ctx, $req, $contextJson) {
            $instanceNumber = $this->store->nextNumber('WF_INSTANCE', 'WFI');

            $ins = $this->store->startInstance([
                'definitionId'    => $ctx->definitionId,
                'versionId'       => $ctx->versionId,
                'entityType'      => $ctx->entityType,
                'entityId'        => $ctx->entityId,
                'startedByUserId' => $req->startedByUserId,
                'instanceNumber'  => $instanceNumber,
                'contextJson'     => $contextJson,
            ]);
            $ctx->instanceId = (int) $ins->InstanceID;

            $this->history->instanceStarted($ctx, $req->startedByUserId);

            $graph = $this->store->getVersionGraph($ctx->versionId);
            $startStep = $this->firstOfType($graph['steps'], 'START');
            if (! $startStep) {
                throw new WorkflowStateException('نسخهٔ فعالِ این فرایند مرحلهٔ شروع ندارد.');
            }

            // مرحلهٔ START: ورود، بستنِ فوری، دنبال‌کردنِ گذار
            $startSi = $this->store->insertStepInstance([
                'instanceId' => $ctx->instanceId,
                'stepId'     => (int) $startStep->StepID,
                'stepCode'   => $startStep->Code,
                'stepType'   => 'START',
                'iterationNo' => 1,
                'userId'     => $req->startedByUserId,
            ]);
            $transition = $this->transitions->resolve($graph['transitions'], $graph['actions'], (int) $startStep->StepID, null);
            $this->store->completeStepInstance((int) $startSi->StepInstanceID, 'COMPLETED', null, (int) $transition->TransitionID, $req->startedByUserId);

            $result = $this->advance($ctx, $graph, $transition, $req->startedByUserId);

            return $result;
        });
    }

    /**
     * Pre-create + Adopt — مسیرِ جایگزینِ «Message عادی → Start Workflow» برایِ
     * EntityType=MESSAGE، فقط وقتی مسیرِ START→اولین USER_TASK/APPROVAL مستقیم است
     * (بدونِ CONDITIONِ میانی). به‌جایِ ساختِ دو Message (یکی برایِ خودِ «نامه»، یکی
     * برایِ تسکِ Workflow)، یک Message واحد (با sp_Wf_CreateTaskMessage، از ابتدا
     * MessageTypeID=«وظیفه» و گیرندهٔ واقعی) ساخته و همان به اولین Task متصل می‌شود.
     *
     * توالی (همه در یک Transaction، بجز پیش‌نمایش که Read-Only است):
     *   ۱) previewAssignment() — تشخیصِ مسیرِ مستقیم + Assigneeهایِ واقعی (Read-Only)
     *   ۲) sp_Wf_CreateTaskMessage — ساختِ تنها Message
     *   ۳) start() با entityId=همان MessageID و preCreatedMessageId=همان MessageID
     *      → advance() در رسیدنِ به اولین Task، به‌جایِ createStepTask()، adoptStepTask()
     *        را صدا می‌زند (Assigneeها دوباره از همان AssignmentResolver حل می‌شوند —
     *        نه از پیش‌نمایشِ گامِ ۱؛ چون entityId اکنون همین Message است و
     *        MessageEntityResolver::ownerUserId/unitId دقیقاً همان actorUserId/UnitId را
     *        برمی‌گردانند، این دو حل، در یک درخواست و بدونِ تغییرِ داده‌ای در فاصلهٔ آن‌ها،
     *        همیشه یکسان‌اند).
     *
     * @throws WorkflowValidationException  اگر مسیرِ مستقیم برقرار نباشد (Conditionِ میانی،
     *         بدونِ نسخهٔ فعال، بدونِ Assigneeِ قابلِ‌حل و ...) — هیچ Message ای ساخته نمی‌شود.
     */
    /**
     * @param  array<string,mixed>  $formValues  مقادیرِ خامِ فرمِ نامه — فقط برایِ ساختِ Contextِ
     *         CONDITION استفاده می‌شود (فیلترشده تا فقط فیلدهایِ واقعاً مرتبط با این Version).
     *         اگر مسیرِ مستقیم باشد (بدونِ CONDITION) اصلاً مصرف نمی‌شود.
     */
    public function startWithNewTaskMessage(
        int $definitionId,
        int $startedByUserId,
        string $subject,
        ?string $messageText = null,
        ?int $priorityId = null,
        ?string $dueDate = null,
        ?array $copyUserIds = null,
        ?string $copyDescription = null,
        array $formValues = [],
    ): EngineResult {
        $preview = $this->previewAssignment($definitionId, $startedByUserId);

        // پیش‌شرط‌هایِ مختصِ «نامهٔ فرایندی» (N_OF_M، نبودِ گیرنده، ...) — فقط در همین مسیر
        // اعمال می‌شود؛ start() و رفتارِ عمومیِ موتور دست‌نخورده است. وجودِ CONDITION دیگر
        // خطا نیست ($rules['deferred']=true) — مسیرِ دوم پایین‌تر همان را مدیریت می‌کند.
        $rules = LetterStartRules::evaluate($preview);
        if (! $rules['ok']) {
            throw new WorkflowValidationException(
                $rules['message'] ?? 'این فرایند برایِ مسیرِ نامهٔ فرایندی مناسب نیست.'
            );
        }

        if ($rules['deferred']) {
            return $this->startDeferredTaskMessage(
                $definitionId, $startedByUserId, $subject, $messageText, $priorityId, $dueDate,
                $copyUserIds, $copyDescription, $formValues, (int) $preview['versionId']
            );
        }

        return DB::transaction(function () use ($definitionId, $startedByUserId, $subject, $messageText, $priorityId, $dueDate, $copyUserIds, $copyDescription, $preview) {
            $assigneeUserIds = array_map(fn ($u) => (int) $u['userId'], $preview['users']);

            $msg = $this->store->createTaskMessage([
                'subject'         => $subject,
                'messageText'     => $messageText,
                'priorityId'      => $priorityId,
                'dueDate'         => $dueDate,
                'senderUserId'    => $startedByUserId,
                'assigneeUserIds' => $assigneeUserIds,
                'createUser'      => $startedByUserId,
                'copyUserIds'     => $copyUserIds,
                'copyDescription' => $copyDescription,
            ]);
            $messageId = (int) $msg->MessageID;

            $result = $this->start(new StartWorkflowRequest(
                entityType: 'MESSAGE',
                entityId: $messageId,
                startedByUserId: $startedByUserId,
                definitionId: $definitionId,
                context: [],
                preCreatedMessageId: $messageId,
            ));

            // دفاعی: انجام‌دهندگانِ ثبت‌شدهٔ Task (حلِ مجددِ Engine) باید دقیقاً همان کسانی باشند که
            // Message برایشان ساخته شد؛ در غیرِ این صورت کلِ عملیات Rollback می‌شود.
            $detail = $this->store->getStepTaskDetail($messageId);
            $recorded = array_map(fn ($a) => (int) $a->UserID, $detail['assignees']);
            sort($recorded);
            $expected = $assigneeUserIds;
            sort($expected);
            if ($recorded !== $expected) {
                throw new WorkflowStateException(
                    'انجام‌دهندگانِ مرحلهٔ اول هم‌زمان با ثبتِ نامه تغییر کرد؛ لطفاً دوباره تلاش کنید.'
                );
            }

            return $result;
        });
    }

    /**
     * مسیرِ Deferred — وقتی مسیرِ START تا اولین Task از CONDITION عبور می‌کند: گیرنده پیش از
     * Submit قابلِ‌تعیین نیست، پس:
     *   ۱) Messageِ اصلی بدونِ هیچ گیرنده‌ای ساخته می‌شود (sp_Wf_CreateTaskMessage با
     *      @RequireAssignees=0 — هنوز در کارتابلِ هیچ‌کس نیست).
     *   ۲) start() با preCreatedMessageId همان Message و Contextِ واقعی (از formValues، فقط
     *      فیلدهایِ مرتبط با CONDITIONهایِ همین Version) صدا زده می‌شود؛ Engine خودش
     *      CONDITION(ها) را با همین Context طی می‌کند (کاملاً Generic، هر تعداد CONDITIONِ
     *      پشتِ‌سرِهم) و در اولین USER_TASK/APPROVAL، adoptStepTask() گیرندهٔ واقعی را متصل
     *      می‌کند — همان Message، بدونِ ساختِ Messageِ دوم.
     *   ۳) اگر آن Step هم گیرنده نداشته باشد یا هر بخشی شکست بخورد، کلِ Transaction
     *      (Message+Instance+Task) Rollback می‌شود — هیچ دادهٔ ناقصی باقی نمی‌ماند.
     */
    private function startDeferredTaskMessage(
        int $definitionId,
        int $startedByUserId,
        string $subject,
        ?string $messageText,
        ?int $priorityId,
        ?string $dueDate,
        ?array $copyUserIds,
        ?string $copyDescription,
        array $formValues,
        int $versionId,
    ): EngineResult {
        $context = $this->contextBuilder->extractRelevantContext($versionId, $formValues);

        return DB::transaction(function () use (
            $definitionId, $startedByUserId, $subject, $messageText, $priorityId, $dueDate,
            $copyUserIds, $copyDescription, $context
        ) {
            $msg = $this->store->createTaskMessage([
                'subject'          => $subject,
                'messageText'      => $messageText,
                'priorityId'       => $priorityId,
                'dueDate'          => $dueDate,
                'senderUserId'     => $startedByUserId,
                'assigneeUserIds'  => [],
                'createUser'       => $startedByUserId,
                'copyUserIds'      => $copyUserIds,
                'copyDescription'  => $copyDescription,
                'requireAssignees' => false,
            ]);
            $messageId = (int) $msg->MessageID;

            return $this->start(new StartWorkflowRequest(
                entityType: 'MESSAGE',
                entityId: $messageId,
                startedByUserId: $startedByUserId,
                definitionId: $definitionId,
                context: $context,
                preCreatedMessageId: $messageId,
            ));
        });
    }

    /**
     * پیش‌نمایشِ Read-Only گیرندهٔ اولین Task — بدونِ نوشتن، بدونِ Instanceِ واقعی.
     *
     * فقط مسیرِ پیش‌فرض (بدونِ هیچ مرحلهٔ CONDITION) را از START دنبال می‌کند؛
     * به‌محضِ رسیدن به یک CONDITION، بدونِ حدس متوقف می‌شود — چون Contextِ لازم
     * برایِ ارزیابیِ آن از Registryِ کاملاً جداگانهٔ WorkflowConditionFields
     * می‌آید (نه پارامترهایِ قالب) و اینجا در دسترس نیست.
     *
     * برایِ EntityType=MESSAGE، «مالکِ موجودیت» همیشه خودِ Actor است (فرستندهٔ
     * پیامی که هنوز ساخته نشده)، دقیقاً هم‌الگو با MessageEntityResolver —
     * پس EntityOwnerUserID/EntityUnitID بدونِ نیاز به Messageِ واقعی معلوم‌اند.
     *
     * @return array{resolved:bool, reason:?string, users:array<int,array{userId:int,fullName:?string}>}
     */
    public function previewAssignment(int $definitionId, int $actorUserId): array
    {
        $definitions = $this->store->getDefinitions(null, true);
        $match = null;
        foreach ($definitions as $d) {
            if ((int) $d->DefinitionID === $definitionId) {
                $match = $d;
                break;
            }
        }
        if (! $match || (int) ($match->ActiveVersionNo ?? 0) === 0) {
            return ['resolved' => false, 'reason' => 'NO_ACTIVE_VERSION', 'users' => []];
        }

        // کلیدهایِ افزوده (entityType، و step در حالتِ رسیدن به Task) فقط اضافه‌اند؛
        // کلیدهایِ قدیمیِ خروجی (resolved/reason/users) بدونِ تغییرند.
        return $this->previewAssignmentPath($definitionId, $actorUserId) + ['entityType' => $match->EntityType ?? null];
    }

    /**
     * @return array{resolved:bool, reason:?string, users:array, step?:array}
     */
    private function previewAssignmentPath(int $definitionId, int $actorUserId): array
    {
        $detail = $this->store->getDefinition($definitionId);
        $activeVersion = null;
        foreach ($detail['versions'] as $v) {
            if ($v->Status === 'ACTIVE') {
                $activeVersion = $v;
                break;
            }
        }
        if (! $activeVersion) {
            return ['resolved' => false, 'reason' => 'NO_ACTIVE_VERSION', 'users' => []];
        }

        $graph = $this->store->getVersionGraph((int) $activeVersion->VersionID);
        $startStep = $this->firstOfType($graph['steps'], 'START');
        if (! $startStep) {
            return ['resolved' => false, 'reason' => 'NO_START_STEP', 'users' => []];
        }

        $ctx = (object) [
            'initiatorUserId'   => $actorUserId,
            'entityOwnerUserId' => $actorUserId,
            'entityUnitId'      => $this->store->getUserCurrentUnitId($actorUserId),
        ];

        $currentStepId = (int) $startStep->StepID;
        $guard = 0;

        while ($guard++ < self::LOCAL_STEP_GUARD) {
            try {
                $transition = $this->transitions->resolve($graph['transitions'], $graph['actions'], $currentStepId, null);
            } catch (WorkflowStateException) {
                return ['resolved' => false, 'reason' => 'DEAD_END', 'users' => []];
            }

            $target = $this->stepById($graph['steps'], (int) $transition->ToStepID);
            if (! $target) {
                return ['resolved' => false, 'reason' => 'DEAD_END', 'users' => []];
            }

            if ($target->StepType === 'CONDITION') {
                // versionId اینجا اضافه شده تا فراخوان (مسیرِ Deferredِ نامهٔ فرایندی) بتواند
                // Contextِ CONDITION را از رویِ همین Version بسازد — کلیدِ افزوده، بدونِ اثر
                // رویِ سایرِ مصرف‌کننده‌ها.
                return ['resolved' => false, 'reason' => 'CONDITION', 'users' => [], 'versionId' => (int) $activeVersion->VersionID];
            }

            if ($target->StepType === 'END') {
                return ['resolved' => true, 'reason' => 'NO_TASK', 'users' => []];
            }

            if (in_array($target->StepType, ['USER_TASK', 'APPROVAL'], true)) {
                $assignmentRows = array_values(array_filter(
                    $graph['assignments'],
                    fn ($a) => (int) $a->StepID === (int) $target->StepID && empty($a->IsBackup)
                ));
                $assignees = $this->assignments->resolve($assignmentRows, $ctx);

                return [
                    'resolved' => true,
                    'reason'   => $assignees === [] ? 'NO_ASSIGNEE_FOUND' : null,
                    'users'    => array_map(fn ($a) => ['userId' => $a->userId, 'fullName' => $a->fullName], $assignees),
                    'step'     => [
                        'stepId'            => (int) $target->StepID,
                        'code'              => $target->Code,
                        'name'              => $target->Name,
                        'type'              => $target->StepType,
                        'assignPolicy'      => $target->AssignPolicy ?? 'ANY',
                        'requiredApprovals' => $target->RequiredApprovals !== null ? (int) $target->RequiredApprovals : null,
                    ],
                ];
            }

            if ($target->StepType === 'START') {
                $currentStepId = (int) $target->StepID;
                continue;
            }

            return ['resolved' => false, 'reason' => 'UNSUPPORTED_STEP', 'users' => []];
        }

        return ['resolved' => false, 'reason' => 'LOOP_GUARD', 'users' => []];
    }

    /* ======================= اقدام روی تسک ======================= */

    public function performAction(TaskActionRequest $req): EngineResult
    {
        $detail = $this->store->getStepTaskDetail($req->messageId);
        $task = $detail['task'];

        if (! $task) {
            throw new WorkflowValidationException('تسک یافت نشد.');
        }
        if (! $this->isActiveAssignee($detail['assignees'], $req->userId)) {
            throw new WorkflowStateException('شما انجام‌دهندهٔ فعالِ این تسک نیستید.');
        }
        if ($task->StepStatus !== 'ACTIVE') {
            throw new WorkflowStateException('این تسک بسته شده است.');
        }
        if ($task->InstanceStatus !== 'RUNNING') {
            throw new WorkflowStateException('فرایندِ این تسک دیگر در جریان نیست.');
        }

        $ctx = (object) [
            'entityType'        => $task->EntityType,
            'entityId'          => (int) $task->EntityID,
            'definitionId'      => (int) $task->DefinitionID,
            'versionId'         => (int) $task->VersionID,
            'instanceId'        => (int) $task->InstanceID,
            'initiatorUserId'   => $task->StartedByUserID !== null ? (int) $task->StartedByUserID : null,
            'entityOwnerUserId' => null,
            'entityUnitId'      => null,
            'entityTitle'       => null,
            // preCreatedMessageId فقط برای اولین Taskِ بلافاصله‌بعدِ START (در start()) معنا دارد؛
            // performAction() همیشه رویِ Instanceِ از‌قبل‌موجود کار می‌کند، پس همیشه null است.
            'preCreatedMessageId' => null,
        ];

        $graph = $this->store->getVersionGraph($ctx->versionId);
        $stepActions = array_values(array_filter($graph['actions'], fn ($a) => (int) $a->StepID === (int) $task->StepID));

        // پیش‌بینیِ نتیجه بدونِ نوشتن
        $preview = $this->tasks->preview($task, $detail['assignees'], $stepActions, $req->userId, $req->actionCode, $req->comment);

        return DB::transaction(function () use ($req, $task, $ctx, $graph, $preview) {
            if (! $preview->willResolve) {
                $this->tasks->recordDecision($task, $preview->action, $preview->decision, $req->userId, $req->comment);

                return new EngineResult(
                    instanceId: $ctx->instanceId,
                    instanceStatus: 'RUNNING',
                    message: 'تصمیمِ شما ثبت شد؛ منتظرِ سایرِ انجام‌دهندگان.',
                );
            }

            $transition = $this->transitions->resolve(
                $graph['transitions'],
                $graph['actions'],
                (int) $task->StepID,
                $preview->outcomeActionCode
            );

            // نخستین نوشته: بستنِ StepInstance با CAS (RowVersion + Status=ACTIVE) و
            // بستنِ کارتابلِ Messages
            $this->store->completeStepTask(
                (int) $task->StepInstanceID,
                $req->expectedRowVersion,
                $preview->outcomeActionCode,
                (int) $transition->TransitionID,
                $req->userId,
                $preview->disposition
            );

            // ثبتِ تصمیمِ کاربر برای حسابرسی
            $this->tasks->recordDecision($task, $preview->action, $preview->decision, $req->userId, $req->comment);

            $this->history->record([
                'entityType'  => $ctx->entityType,
                'entityId'    => $ctx->entityId,
                'eventCode'   => WorkflowHistoryRecorder::TASK_COMPLETED,
                'instanceId'  => $ctx->instanceId,
                'stepInstanceId' => (int) $task->StepInstanceID,
                'messageId'   => (int) $task->MessageID,
                'actorUserId' => $req->userId,
                'summary'     => "تسک بسته شد ({$preview->disposition})",
                'ipAddress'   => $req->ipAddress,
            ]);

            return $this->advance($ctx, $graph, $transition, $req->userId);
        });
    }

    /* ==================== چرخهٔ حیاتِ Instance ==================== */

    /**
     * لغوِ فرایند — RUNNING|SUSPENDED → CANCELLED.
     * تسک‌های باز بسته و مراحلِ فعال SKIP می‌شوند؛ پس از آن هیچ Action/Advance ممکن نیست
     * (چون performAction روی InstanceStatus != RUNNING رد می‌کند).
     */
    public function cancel(int $instanceId, int $actorUserId, ?string $reason = null): EngineResult
    {
        $ctx = $this->instanceContext($instanceId);

        return DB::transaction(function () use ($ctx, $actorUserId, $reason) {
            $res = $this->store->cancelInstance($ctx->instanceId, $actorUserId);

            $this->history->record([
                'entityType'  => $ctx->entityType,
                'entityId'    => $ctx->entityId,
                'eventCode'   => WorkflowHistoryRecorder::INSTANCE_CANCELLED,
                'instanceId'  => $ctx->instanceId,
                'actorUserId' => $actorUserId,
                'actorType'   => 'USER',
                'summary'     => $this->reasonSummary('فرایند لغو شد', $reason),
                'detail'      => [
                    'reason'               => $reason,
                    'closedMessageIds'     => $res['closedMessageIds'],
                    'closedTaskCount'      => $res['closedTaskCount'],
                    'skippedStepInstances' => $res['skippedStepCount'],
                ],
            ]);

            foreach ($res['closedMessageIds'] as $messageId) {
                $this->history->record([
                    'entityType'  => $ctx->entityType,
                    'entityId'    => $ctx->entityId,
                    'eventCode'   => WorkflowHistoryRecorder::TASK_CANCELLED,
                    'instanceId'  => $ctx->instanceId,
                    'messageId'   => $messageId,
                    'actorUserId' => $actorUserId,
                    'actorType'   => 'USER',
                    'summary'     => 'تسک به دلیلِ لغوِ فرایند بسته شد',
                ]);
            }

            return new EngineResult($ctx->instanceId, 'CANCELLED', [], null, 'فرایند لغو شد.');
        });
    }

    /**
     * تعلیقِ فرایند — RUNNING → SUSPENDED.
     * تسک‌ها و انجام‌دهندگان دست‌نخورده می‌مانند؛ اما هر Action روی تسک‌ها رد می‌شود.
     */
    public function suspend(int $instanceId, int $actorUserId, ?string $reason = null): EngineResult
    {
        $ctx = $this->instanceContext($instanceId);

        return DB::transaction(function () use ($ctx, $actorUserId, $reason) {
            $this->store->suspendInstance($ctx->instanceId, $actorUserId);

            $this->history->record([
                'entityType'  => $ctx->entityType,
                'entityId'    => $ctx->entityId,
                'eventCode'   => WorkflowHistoryRecorder::INSTANCE_SUSPENDED,
                'instanceId'  => $ctx->instanceId,
                'actorUserId' => $actorUserId,
                'actorType'   => 'USER',
                'summary'     => $this->reasonSummary('فرایند تعلیق شد', $reason),
                'detail'      => ['reason' => $reason],
            ]);

            return new EngineResult($ctx->instanceId, 'SUSPENDED', [], null, 'فرایند تعلیق شد.');
        });
    }

    /**
     * ازسرگیریِ فرایند — SUSPENDED → RUNNING.
     * تسک‌های قبلی همان‌طور که بودند قابلِ ادامه می‌شوند.
     */
    public function resume(int $instanceId, int $actorUserId): EngineResult
    {
        $ctx = $this->instanceContext($instanceId);

        return DB::transaction(function () use ($ctx, $actorUserId) {
            $this->store->resumeInstance($ctx->instanceId, $actorUserId);

            $this->history->record([
                'entityType'  => $ctx->entityType,
                'entityId'    => $ctx->entityId,
                'eventCode'   => WorkflowHistoryRecorder::INSTANCE_RESUMED,
                'instanceId'  => $ctx->instanceId,
                'actorUserId' => $actorUserId,
                'actorType'   => 'USER',
                'summary'     => 'فرایند ازسر گرفته شد',
            ]);

            return new EngineResult($ctx->instanceId, 'RUNNING', [], null, 'فرایند ازسر گرفته شد.');
        });
    }

    /* ==================== ارجاع و تفویضِ تسک (Step 7.2) ==================== */

    /**
     * ارجاعِ دائمیِ «تسکِ مرحله» از Actor به کاربرِ دیگر (A → B).
     *
     * MessageID و StepInstanceID ثابت می‌مانند؛ تسکِ جدیدی ساخته نمی‌شود؛ این مسیر
     * از performAction عبور نمی‌کند و sp_ForwardMessage را استفاده نمی‌کند. رکوردِ B
     * همیشه یک رکوردِ *تازهٔ* WorkflowTaskAssignees با SourceType='FORWARD' است
     * (رکوردِ inactive قبلی هرگز reuse نمی‌شود).
     */
    public function forward(int $messageId, int $actorUserId, int $targetUserId, ?string $comment = null): EngineResult
    {
        return $this->reassign($messageId, $actorUserId, $targetUserId, 'FORWARD', $comment);
    }

    /**
     * تفویضِ موقتِ «تسکِ مرحله» از Actor به کاربرِ دیگر (A → B).
     *
     * مثلِ forward، ولی SourceType='DELEGATION' و قابلِ بازپس‌گیری (revokeDelegation).
     * تا زمانِ باطل‌سازی، Actor نمی‌تواند روی این تسک اقدام کند.
     */
    public function delegate(int $messageId, int $actorUserId, int $targetUserId, ?string $comment = null): EngineResult
    {
        return $this->reassign($messageId, $actorUserId, $targetUserId, 'DELEGATE', $comment);
    }

    /**
     * باطل‌سازیِ تفویض — نماینده (delegate) غیرفعال و واگذارکننده دوباره فعال می‌شود.
     * فقط زمانی مجاز است که نماینده هنوز تصمیمی نگرفته باشد.
     */
    public function revokeDelegation(int $messageId, int $delegateUserId, int $revokedByUserId): EngineResult
    {
        $task = $this->requireActiveStepTask($messageId, 'باطل‌سازیِ تفویض');

        // نماینده باید انجام‌دهندهٔ فعالِ بی‌تصمیم و از نوعِ DELEGATION باشد
        // (رویه هم این را قطعی بررسی می‌کند و «قبلاً تصمیم گرفته» را از «یافت نشد» جدا می‌کند).
        if (! $this->isRevocableDelegate($task['assignees'], $delegateUserId)) {
            throw new WorkflowStateException(
                'تفویضِ فعالی برای این کاربر روی این تسک یافت نشد یا نماینده قبلاً تصمیم گرفته است.'
            );
        }

        // رویه تراکنش و تاریخچهٔ خود را دارد؛ اینجا DB::transaction بیرونی لازم نیست.
        $this->store->revokeDelegation($messageId, $delegateUserId, $revokedByUserId);

        return new EngineResult(
            (int) $task['task']->InstanceID,
            'RUNNING',
            [],
            $task['task']->StepCode ?? null,
            'تفویض باطل شد.',
        );
    }

    private function reassign(int $messageId, int $actorUserId, int $targetUserId, string $mode, ?string $comment): EngineResult
    {
        $label = $mode === 'FORWARD' ? 'ارجاع' : 'تفویض';
        $task = $this->requireActiveStepTask($messageId, $label);

        // Actor باید انجام‌دهندهٔ فعالِ un-decided باشد (رویه هم با CAS این را قطعی می‌کند).
        if (! $this->isUndecidedActiveAssignee($task['assignees'], $actorUserId)) {
            throw new WorkflowStateException('شما انجام‌دهندهٔ فعالِ این تسک نیستید یا قبلاً تصمیم گرفته‌اید.');
        }

        // رویهٔ sp_Wf_ReassignStepTask خودش BEGIN TRAN/COMMIT و ثبتِ تاریخچه را دارد
        // (برخلافِ SPهای چرخهٔ حیات که تراکنش‌شان از Engine می‌آید).
        $this->store->reassignStepTask($messageId, $actorUserId, $targetUserId, $mode, $comment);

        return new EngineResult(
            (int) $task['task']->InstanceID,
            'RUNNING',
            [],
            $task['task']->StepCode ?? null,
            $mode === 'FORWARD' ? 'تسک ارجاع شد.' : 'تسک تفویض شد.',
        );
    }

    /**
     * گاردهای مشترکِ ارجاع/تفویض/باطل‌سازی:
     *   • پیام باید به یک StepInstanceِ فرایند گره خورده باشد.
     *   • InstanceStatus = RUNNING  (پس SUSPENDED / CANCELLED / … رد می‌شود).
     *   • StepStatus = ACTIVE.
     *
     * @return array{task:object, assignees:array}
     */
    private function requireActiveStepTask(int $messageId, string $opLabel): array
    {
        $detail = $this->store->getStepTaskDetail($messageId);
        $task = $detail['task'];

        if (! $task) {
            throw new WorkflowValidationException('تسکِ فرایند یافت نشد.');
        }
        if ($task->InstanceStatus !== 'RUNNING') {
            throw new WorkflowStateException("{$opLabel} تنها زمانی ممکن است که فرایند در جریان (RUNNING) باشد.");
        }
        if ($task->StepStatus !== 'ACTIVE') {
            throw new WorkflowStateException('این تسک بسته شده است.');
        }

        return ['task' => $task, 'assignees' => $detail['assignees']];
    }

    private function isUndecidedActiveAssignee(array $assignees, int $userId): bool
    {
        foreach ($assignees as $a) {
            if ((int) $a->UserID === $userId && (int) $a->IsActive === 1 && ($a->Decision ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    private function isRevocableDelegate(array $assignees, int $delegateUserId): bool
    {
        foreach ($assignees as $a) {
            if ((int) $a->UserID === $delegateUserId
                && (int) $a->IsActive === 1
                && ($a->Decision ?? null) === null
                && ($a->SourceType ?? null) === 'DELEGATION') {
                return true;
            }
        }

        return false;
    }

    private function instanceContext(int $instanceId): object
    {
        $data = $this->store->getInstance($instanceId);

        if (! $data['instance']) {
            throw new WorkflowValidationException('نمونهٔ فرایند یافت نشد.');
        }

        return (object) [
            'instanceId' => (int) $data['instance']->InstanceID,
            'entityType' => $data['instance']->EntityType,
            'entityId'   => (int) $data['instance']->EntityID,
        ];
    }

    private function reasonSummary(string $base, ?string $reason): string
    {
        return ($reason !== null && trim($reason) !== '') ? "{$base} — " . trim($reason) : $base;
    }

    /* ========================= پیشرویِ داخلی ========================= */

    private function advance(object $ctx, array $graph, object $transition, ?int $actorUserId): EngineResult
    {
        $createdMessageIds = [];
        $guard = 0;
        $currentTransition = $transition;

        // Contextِ Snapshotشدهٔ همین Instance — یک‌بار خوانده می‌شود (در طولِ advance
        // تغییر نمی‌کند)؛ فقط برایِ Stepهایِ CONDITION لازم است.
        $rawContextJson = $this->store->getInstanceContext($ctx->instanceId);
        $context = $rawContextJson !== null ? (json_decode($rawContextJson, true) ?: []) : [];

        while ($guard++ < self::LOCAL_STEP_GUARD) {
            $count = $this->store->bumpTransitionCount($ctx->instanceId);
            $max = (int) config('workflow.max_transitions_per_instance', 500);

            if ($count > $max) {
                // نگهبانِ حلقه: Instance را FAILED می‌کنیم و نتیجه را برمی‌گردانیم
                // (نه throw) تا این وضعیت و تاریخچه‌اش با تراکنشِ عملیات Commit شود،
                // نه اینکه با یک استثنا Rollback شود.
                $this->store->setInstanceStatus($ctx->instanceId, 'FAILED', $actorUserId);
                $this->history->record([
                    'entityType' => $ctx->entityType, 'entityId' => $ctx->entityId,
                    'eventCode'  => WorkflowHistoryRecorder::INSTANCE_FAILED,
                    'instanceId' => $ctx->instanceId, 'actorUserId' => $actorUserId, 'actorType' => 'SYSTEM',
                    'summary'    => "سقفِ گذارها ({$max}) رد شد؛ فرایند متوقف شد (حلقهٔ احتمالی).",
                    'detail'     => ['transitionCount' => $count, 'maxTransitions' => $max],
                ]);

                return new EngineResult(
                    $ctx->instanceId,
                    'FAILED',
                    $createdMessageIds,
                    null,
                    "فرایند به دلیلِ عبور از سقفِ مجازِ گذارها ({$max}) متوقف شد (حلقهٔ احتمالی)."
                );
            }

            $target = $this->stepById($graph['steps'], (int) $currentTransition->ToStepID);
            if (! $target) {
                throw new WorkflowStateException('گذار به مرحله‌ای نامعتبر اشاره می‌کند.');
            }

            $transitionDetail = [
                'transitionId'   => (int) $currentTransition->TransitionID,
                'transitionCode' => $currentTransition->Code,
                'fromStepId'     => (int) $currentTransition->FromStepID,
                'toStepId'       => (int) $currentTransition->ToStepID,
            ];

            // اگر مبدأِ این گذار یک Stepِ CONDITION بود، نتیجهٔ ارزیابی را هم برایِ
            // Auditِ بعدی ثبت می‌کنیم (طبقِ Final Design، بخشِ B) — بدونِ تکرارِ
            // Contextِ کامل، فقط ruleMatched/ruleSummary.
            $fromStep = $this->stepById($graph['steps'], (int) $currentTransition->FromStepID);
            if ($fromStep && $fromStep->StepType === 'CONDITION') {
                $transitionDetail['ruleMatched'] = ! empty($currentTransition->RuleJson);
                if (! empty($currentTransition->RuleJson)) {
                    $decodedRule = json_decode((string) $currentTransition->RuleJson, true);
                    $transitionDetail['ruleSummary'] = is_array($decodedRule)
                        ? mb_substr($this->conditionEvaluator->summarize($decodedRule), 0, 300)
                        : null;
                }
            }

            $this->history->record([
                'entityType' => $ctx->entityType, 'entityId' => $ctx->entityId,
                'eventCode'  => WorkflowHistoryRecorder::TRANSITION_TAKEN,
                'instanceId' => $ctx->instanceId,
                'actorUserId' => $actorUserId,
                'actorType'  => $actorUserId ? 'USER' : 'SYSTEM',
                'summary'    => "گذارِ «{$currentTransition->Code}» طی شد",
                'detail'     => $transitionDetail,
            ]);

            $iteration = $this->store->countStepIterations($ctx->instanceId, (int) $target->StepID) + 1;

            $si = $this->store->insertStepInstance([
                'instanceId'            => $ctx->instanceId,
                'stepId'                => (int) $target->StepID,
                'stepCode'              => $target->Code,
                'stepType'              => $target->StepType,
                'enteredViaTransitionId' => (int) $currentTransition->TransitionID,
                'iterationNo'           => $iteration,
                'userId'                => $actorUserId,
            ]);
            $stepInstanceId = (int) $si->StepInstanceID;

            $this->history->record([
                'entityType' => $ctx->entityType, 'entityId' => $ctx->entityId,
                'eventCode'  => WorkflowHistoryRecorder::STEP_ENTERED,
                'instanceId' => $ctx->instanceId, 'stepInstanceId' => $stepInstanceId,
                'actorUserId' => $actorUserId,
                'summary'    => "ورود به مرحلهٔ «{$target->Name}»",
                'detail'     => ['transition' => $currentTransition->Code, 'iteration' => $iteration],
            ]);

            switch ($target->StepType) {
                case 'END':
                    $this->store->completeStepInstance($stepInstanceId, 'COMPLETED', null, null, $actorUserId);
                    $this->store->setInstanceStatus($ctx->instanceId, 'COMPLETED', $actorUserId);
                    $this->history->record([
                        'entityType' => $ctx->entityType, 'entityId' => $ctx->entityId,
                        'eventCode'  => WorkflowHistoryRecorder::INSTANCE_COMPLETED,
                        'instanceId' => $ctx->instanceId, 'actorUserId' => $actorUserId,
                        'summary'    => 'فرایند به پایان رسید',
                    ]);

                    return new EngineResult($ctx->instanceId, 'COMPLETED', $createdMessageIds, $target->Code, 'فرایند تکمیل شد.');

                case 'USER_TASK':
                case 'APPROVAL':
                    // IsBackup=1 یعنی «جانشین» (Designer Phase 2) — فعلاً هیچ منطقِ Runtimeای
                    // برایِ فعال‌سازیِ جانشین پیاده نشده، پس این ردیف‌ها از Resolve کنار گذاشته
                    // می‌شوند تا رفتارِ Workflowهایِ موجود (همه IsBackup=0) تغییر نکند.
                    $assignmentRows = array_values(array_filter(
                        $graph['assignments'],
                        fn ($a) => (int) $a->StepID === (int) $target->StepID && empty($a->IsBackup)
                    ));
                    $assignees = $this->assignments->resolve($assignmentRows, $ctx);

                    if (! empty($ctx->preCreatedMessageId)) {
                        // محدودیتِ قدیمی («فقط اولین Task بلافاصله‌بعدِ START») حذف شد: با معماریِ
                        // یک‌Instance/یک‌Message دیگر لازم نیست Messageِ Pre-create دقیقاً به اولین
                        // گذار وصل شود — می‌تواند بعدِ هر تعداد CONDITION/بازگشتِ خودکار هم Adopt شود.
                        // تنها محافظتِ لازم همان چکِ AlreadyAttached است که adoptStepTask() خودش
                        // (از رویِ getAdoptableTaskMessage) به‌طورِ مستقل تضمین می‌کند — اگر این
                        // Message قبلاً به یک StepInstanceِ دیگر متصل شده باشد، همان‌جا خطا می‌دهد.
                        $messageId = $this->tasks->adoptStepTask($ctx, $target, $stepInstanceId, $assignees, $actorUserId, (int) $ctx->preCreatedMessageId);
                    } else {
                        // قانونِ «یک Instance = یک Message»: اگر این Instance از قبل Messageِ اصلی
                        // دارد (از هر Stepِ قبلی)، همان ادامه می‌یابد؛ Messageِ تازه فقط برایِ اولین
                        // Taskِ کلِ Instance ساخته می‌شود. کاملاً Generic — بدونِ وابستگی به
                        // EntityType/Workflow خاص؛ فقط بر اساسِ وجود/عدمِ MessageIDِ قبلی.
                        $existingMessageId = $this->store->getInstanceMainMessageId($ctx->instanceId);

                        $messageId = $existingMessageId !== null
                            ? $this->tasks->continueStepTask($ctx, $target, $stepInstanceId, $assignees, $actorUserId, $existingMessageId)
                            : $this->tasks->createStepTask($ctx, $target, $stepInstanceId, $assignees, $actorUserId);
                    }
                    $createdMessageIds[] = $messageId;

                    return new EngineResult(
                        $ctx->instanceId,
                        'RUNNING',
                        $createdMessageIds,
                        $target->Code,
                        'فرایند در انتظارِ اقدامِ کاربر است.'
                    );

                case 'START':
                    // گذارِ برگشتی به START — رفتار: عبور و دنبال‌کردنِ گذارِ پیش‌فرض
                    $next = $this->transitions->resolve($graph['transitions'], $graph['actions'], (int) $target->StepID, null);
                    $this->store->completeStepInstance($stepInstanceId, 'COMPLETED', null, (int) $next->TransitionID, $actorUserId);
                    $currentTransition = $next;
                    break;

                case 'CONDITION':
                    // Gateway: اولین Ruleِ TRUE (طبقِ Priority) برنده است؛ در نبودِ آن، IsDefault.
                    $winner = $this->transitions->resolveConditional($graph['transitions'], (int) $target->StepID, $context);

                    if ($winner === null) {
                        // Controlled Failure — نه Exception؛ وضعیت/تاریخچه با همین تراکنش Commit می‌شود
                        // (هم‌الگو با نگهبانِ حلقهٔ بالا).
                        $this->store->completeStepInstance($stepInstanceId, 'FAILED', null, null, $actorUserId);
                        $this->store->setInstanceStatus($ctx->instanceId, 'FAILED', $actorUserId);
                        $this->history->record([
                            'entityType' => $ctx->entityType, 'entityId' => $ctx->entityId,
                            'eventCode'  => WorkflowHistoryRecorder::INSTANCE_FAILED,
                            'instanceId' => $ctx->instanceId, 'stepInstanceId' => $stepInstanceId,
                            'actorUserId' => $actorUserId, 'actorType' => 'SYSTEM',
                            'summary'    => "هیچ‌یک از قوانینِ مرحلهٔ «{$target->Name}» برقرار نبود و مسیرِ پیش‌فرضی تعریف نشده است.",
                            'detail'     => ['stepId' => (int) $target->StepID, 'reason' => 'NO_RULE_MATCHED_NO_DEFAULT'],
                        ]);

                        return new EngineResult(
                            $ctx->instanceId,
                            'FAILED',
                            $createdMessageIds,
                            $target->Code,
                            "هیچ‌یک از قوانینِ مرحلهٔ «{$target->Name}» برقرار نبود و مسیرِ پیش‌فرضی تعریف نشده است."
                        );
                    }

                    $this->store->completeStepInstance($stepInstanceId, 'COMPLETED', null, (int) $winner->TransitionID, $actorUserId);
                    $currentTransition = $winner;
                    break;

                default:
                    throw new WorkflowValidationException(
                        "اجرای مرحلهٔ نوعِ «{$target->StepType}» در فاز ۱ پشتیبانی نمی‌شود (مرحلهٔ «{$target->Name}»)."
                    );
            }

        }

        throw new WorkflowStateException('زنجیرهٔ مراحلِ خودکار بیش از حد طولانی شد.');
    }

    /* ============================ کمکی‌ها ============================ */

    private function locateActiveDefinition(StartWorkflowRequest $req): object
    {
        if ($req->definitionId === null && $req->definitionCode === null) {
            throw new WorkflowValidationException('برای شروعِ فرایند، کد یا شناسهٔ تعریف لازم است.');
        }

        $rows = $this->store->getDefinitions(null, true);
        $match = null;
        foreach ($rows as $d) {
            if (($req->definitionId !== null && (int) $d->DefinitionID === $req->definitionId)
                || ($req->definitionCode !== null && $d->Code === $req->definitionCode)) {
                $match = $d;
                break;
            }
        }

        if (! $match) {
            throw new WorkflowValidationException('فرایندِ فعالی با این مشخصات یافت نشد.');
        }
        if ((string) $match->EntityType !== $req->entityType) {
            throw new WorkflowValidationException('نوعِ موجودیتِ درخواست با نوعِ موجودیتِ این فرایند یکسان نیست.');
        }
        if ((int) ($match->ActiveVersionNo ?? 0) === 0) {
            throw new WorkflowStateException('این فرایند نسخهٔ فعالی ندارد؛ ابتدا یک نسخه را منتشر کنید.');
        }

        $detail = $this->store->getDefinition((int) $match->DefinitionID);
        $activeVersion = null;
        foreach ($detail['versions'] as $v) {
            if ($v->Status === 'ACTIVE') {
                $activeVersion = $v;
                break;
            }
        }
        if (! $activeVersion) {
            throw new WorkflowStateException('نسخهٔ فعالِ این فرایند یافت نشد.');
        }

        $match->versionMeta = $activeVersion;

        return $match;
    }

    private function isActiveAssignee(array $assignees, int $userId): bool
    {
        foreach ($assignees as $a) {
            if ((int) $a->UserID === $userId && (int) $a->IsActive === 1) {
                return true;
            }
        }

        return false;
    }

    private function firstOfType(array $steps, string $type): ?object
    {
        foreach ($steps as $s) {
            if ($s->StepType === $type) {
                return $s;
            }
        }

        return null;
    }

    private function stepById(array $steps, int $stepId): ?object
    {
        foreach ($steps as $s) {
            if ((int) $s->StepID === $stepId) {
                return $s;
            }
        }

        return null;
    }
}
