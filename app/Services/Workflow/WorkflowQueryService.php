<?php

namespace App\Services\Workflow;

use App\Services\Workflow\Support\WorkflowStore;

/**
 * پرس‌وجوهای فقط‌خواندنیِ فرایند.
 *
 * «کارتابلِ من» دیگر اینجا نیست — تسک‌های فرایند مثلِ Taskهای عادی/پروژه از
 * مسیرِ موجودِ sp_GetMessages دیده می‌شوند (MessageController). این سرویس فقط
 * جزئیاتِ «تسکِ مرحله» و ردیابیِ Instance را می‌دهد.
 */
class WorkflowQueryService
{
    public function __construct(private WorkflowStore $store)
    {
    }

    /**
     * جزئیاتِ «تسکِ مرحله» بر مبنای MessageID (پیامِ کارتابلی).
     * اگر $openingUserId داده شود و انجام‌دهندهٔ فعال باشد، «نخستین بازکردن» ثبت می‌شود.
     */
    public function stepTaskDetail(int $messageId, ?int $openingUserId = null): array
    {
        $detail = $this->store->getStepTaskDetail($messageId);

        if ($openingUserId !== null && $detail['task']) {
            $isAssignee = collect($detail['assignees'])
                ->contains(fn ($a) => (int) $a->UserID === $openingUserId && (int) $a->IsActive === 1);

            if ($isAssignee) {
                $this->store->markStepFirstOpened((int) $detail['task']->StepInstanceID, $openingUserId);

                return $this->store->getStepTaskDetail($messageId);
            }
        }

        return $detail;
    }

    public function instance(int $instanceId): array
    {
        return [
            ...$this->store->getInstance($instanceId),
            'history' => $this->store->getInstanceHistory($instanceId),
        ];
    }

    /** @return array{rows:array, totalCount:int} */
    public function instances(array $filters, int $page, int $pageSize): array
    {
        return $this->store->getInstances($filters, $page, $pageSize);
    }
}
