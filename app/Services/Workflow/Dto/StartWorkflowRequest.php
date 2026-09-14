<?php

namespace App\Services\Workflow\Dto;

/**
 * ورودیِ شروعِ یک نمونهٔ فرایند.
 */
final class StartWorkflowRequest
{
    /**
     * @param  string       $entityType        نوعِ موجودیت (کلیدِ config/workflow.php:entities)
     * @param  int           $entityId          شناسهٔ موجودیت در ماژولِ مبدأ
     * @param  int|null      $startedByUserId   کاربرِ آغازگر (برای INITIATOR)
     * @param  string|null   $definitionCode    کدِ فرایند؛ اگر null باشد $definitionId لازم است
     * @param  int|null      $definitionId
     * @param  int|null      $entityOwnerUserId مالکِ موجودیت (برای ENTITY_OWNER)
     * @param  int|null      $entityUnitId      واحدِ سازمانیِ موجودیت (برای UNIT_MANAGER)
     * @param  array<string,mixed>  $context     داده‌های زمینه‌ای (برای فازهای بعد: شرط‌ها/فرم‌ها)
     */
    public function __construct(
        public string $entityType,
        public int $entityId,
        public ?int $startedByUserId = null,
        public ?string $definitionCode = null,
        public ?int $definitionId = null,
        public ?int $entityOwnerUserId = null,
        public ?int $entityUnitId = null,
        public array $context = [],
    ) {
    }
}
