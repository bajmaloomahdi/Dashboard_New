<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Entity\EntityResolverRegistry;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\Support\WorkflowStore;
use Illuminate\Support\Facades\DB;

/**
 * مدیریتِ تعریفِ فرایندها و نسخه‌ها: CRUD، ساختِ Draft، Clone، اعتبارسنجی و انتشار.
 *
 * منطقِ کسب‌وکار (اعتبارسنجیِ گراف) در PHP است؛ ذخیره/انتشارِ اتمیک در رویه.
 */
class WorkflowDefinitionService
{
    public function __construct(
        private WorkflowStore $store,
        private EntityResolverRegistry $entities,
    ) {
    }

    public function list(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getDefinitions($search, $isActive);
    }

    public function show(int $definitionId): array
    {
        return $this->store->getDefinition($definitionId);
    }

    public function save(array $input, int $userId): object
    {
        $entityType = $input['entityType'] ?? '';
        if (! $this->entities->isKnown($entityType)) {
            throw new WorkflowValidationException("نوعِ موجودیتِ «{$entityType}» شناخته‌شده نیست (config/workflow.php).");
        }

        return $this->store->saveDefinition([
            'definitionId' => $input['definitionId'] ?? null,
            'code'         => trim($input['code'] ?? ''),
            'name'         => trim($input['name'] ?? ''),
            'description'  => $input['description'] ?? null,
            'entityType'   => $entityType,
            'isActive'     => (int) ($input['isActive'] ?? 1),
            'userId'       => $userId,
        ]);
    }

    public function createDraft(int $definitionId, int $userId): object
    {
        return $this->store->createDraftVersion($definitionId, $userId);
    }

    public function cloneVersion(int $sourceVersionId, int $userId): object
    {
        return $this->store->cloneVersion($sourceVersionId, $userId);
    }

    public function getGraph(int $versionId): array
    {
        return [
            'meta'  => $this->store->getVersionMeta($versionId),
            'graph' => $this->store->getVersionGraph($versionId),
        ];
    }

    public function saveGraph(int $versionId, array $graph, int $userId): object
    {
        return $this->store->saveVersionGraph($versionId, $graph, $userId);
    }

    /**
     * اعتبارسنجیِ گرافِ نسخه. نتیجه در ValidationResultJson ذخیره می‌شود.
     *
     * @return array{ok:bool, errors:string[], warnings:string[]}
     */
    public function validate(int $versionId, int $userId, bool $persist = true): array
    {
        $meta = $this->store->getVersionMeta($versionId);
        if (! $meta) {
            throw new WorkflowValidationException('نسخهٔ فرایند یافت نشد.');
        }

        $g = $this->store->getVersionGraph($versionId);
        $errors = [];
        $warnings = [];

        $steps = $g['steps'];
        $byId = [];
        $starts = [];
        $ends = [];
        foreach ($steps as $s) {
            $byId[(int) $s->StepID] = $s;
            if ($s->StepType === 'START') {
                $starts[] = $s;
            }
            if ($s->StepType === 'END') {
                $ends[] = $s;
            }
        }

        if ($steps === []) {
            $errors[] = 'نسخه هیچ مرحله‌ای ندارد.';
        }
        if (count($starts) === 0) {
            $errors[] = 'مرحلهٔ شروع (START) وجود ندارد.';
        }
        if (count($starts) > 1) {
            $errors[] = 'بیش از یک مرحلهٔ شروع (START) تعریف شده است.';
        }
        if (count($ends) === 0) {
            $errors[] = 'حداقل یک مرحلهٔ پایان (END) لازم است.';
        }

        $outByStep = [];
        $inByStep = [];
        foreach ($g['transitions'] as $t) {
            $from = (int) $t->FromStepID;
            $to = (int) $t->ToStepID;
            if (! isset($byId[$from])) {
                $errors[] = "گذارِ «{$t->Code}» از مرحله‌ای خارج از این نسخه شروع می‌شود.";
            }
            if (! isset($byId[$to])) {
                $errors[] = "گذارِ «{$t->Code}» به مرحله‌ای خارج از این نسخه می‌رود.";
            }
            $outByStep[$from][] = $t;
            $inByStep[$to][] = $t;
        }

        $assignByStep = [];
        foreach ($g['assignments'] as $a) {
            $assignByStep[(int) $a->StepID][] = $a;
        }

        foreach ($steps as $s) {
            $id = (int) $s->StepID;
            $type = $s->StepType;

            if ($type !== 'END' && empty($outByStep[$id])) {
                $errors[] = "مرحلهٔ «{$s->Name}» هیچ گذارِ خروجی ندارد.";
            }
            if ($type !== 'START' && empty($inByStep[$id])) {
                $errors[] = "مرحلهٔ «{$s->Name}» از هیچ مسیری قابلِ دسترسی نیست.";
            }
            if ($type === 'START' && ! empty($inByStep[$id])) {
                $errors[] = 'مرحلهٔ شروع نباید گذارِ ورودی داشته باشد.';
            }
            if ($type === 'END' && ! empty($outByStep[$id])) {
                $errors[] = "مرحلهٔ پایانِ «{$s->Name}» نباید گذارِ خروجی داشته باشد.";
            }
            if (in_array($type, ['USER_TASK', 'APPROVAL'], true) && empty($assignByStep[$id])) {
                $errors[] = "مرحلهٔ «{$s->Name}» هیچ انجام‌دهنده‌ای ندارد.";
            }
            if (($s->AssignPolicy ?? 'ANY') === 'N_OF_M' && (int) ($s->RequiredApprovals ?? 0) < 1) {
                $errors[] = "مرحلهٔ «{$s->Name}» با سیاستِ N_OF_M نیازمندِ «تعداد تأییدِ لازم» است.";
            }
            if (in_array($type, ['CONDITION', 'AUTOMATIC', 'NOTIFICATION', 'PARALLEL_SPLIT', 'PARALLEL_JOIN', 'SUBPROCESS'], true)) {
                $warnings[] = "مرحلهٔ «{$s->Name}» از نوعِ «{$type}» است؛ اجرای این نوع در فاز ۱ پشتیبانی نمی‌شود.";
            }
        }

        $result = [
            'ok'         => $errors === [],
            'errors'     => $errors,
            'warnings'   => $warnings,
            'checkedAt'  => now()->toIso8601String(),
            'checkedBy'  => $userId,
        ];

        if ($persist) {
            $this->store->setValidationResult($versionId, $result, $userId);
        }

        return $result;
    }

    /**
     * انتشارِ نسخه: اعتبارسنجی → ذخیرهٔ نتیجه → فعال‌سازیِ اتمیک (بایگانیِ نسخهٔ ACTIVE قبلی).
     */
    public function publish(int $versionId, int $userId): object
    {
        return DB::transaction(function () use ($versionId, $userId) {
            $validation = $this->validate($versionId, $userId, persist: true);

            if (! $validation['ok']) {
                throw new WorkflowValidationException(
                    'نسخه به دلیلِ خطاهای اعتبارسنجی قابلِ انتشار نیست.',
                    $validation['errors']
                );
            }

            return $this->store->publishVersion($versionId, $userId);
        });
    }
}
