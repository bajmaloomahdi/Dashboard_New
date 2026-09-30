<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Dto\WorkflowStartContext;
use App\Services\Workflow\Support\WorkflowStore;

/**
 * سازندهٔ Workflow Start Context (Read-Only، بدونِ ذخیره).
 *
 * تنها منبعِ محاسبهٔ «آغازکننده + Stepِ اول + گیرندگانِ واقعی + قابلیتِ شروع» برایِ
 * نامهٔ فرایندی است. گیرندگان از همان WorkflowEngine::previewAssignment (=
 * AssignmentResolver روی Assignmentهایِ واقعیِ Stepِ اولِ همان Definition) می‌آیند؛
 * هیچ نوعِ Assignment/Workflow/Codeای Hard-code نشده است.
 *
 * Preview فقط برایِ نمایش از این استفاده می‌کند؛ Submit (WorkflowRuntimeController::startLetter
 * → WorkflowEngine::startWithNewTaskMessage) خودش همین محاسبه را دوباره در سرور انجام می‌دهد.
 */
class WorkflowStartContextBuilder
{
    public function __construct(
        private WorkflowEngine $engine,
        private WorkflowStore $store,
    ) {
    }

    public function build(int $definitionId, int $starterUserId): WorkflowStartContext
    {
        $preview = $this->engine->previewAssignment($definitionId, $starterUserId);
        $rules = LetterStartRules::evaluate($preview);

        return new WorkflowStartContext(
            definitionId: $definitionId,
            starter: $this->store->getStarterProfile($starterUserId),
            step: $preview['step'] ?? null,
            recipients: array_values(array_map(
                fn ($u) => ['userId' => (int) $u['userId'], 'fullName' => $u['fullName'] ?? null],
                $preview['users'] ?? []
            )),
            startable: $rules['ok'],
            reason: $rules['reason'],
            message: $rules['message'],
            resolved: (bool) ($preview['resolved'] ?? false),
            deferred: (bool) ($rules['deferred'] ?? false),
        );
    }
}
