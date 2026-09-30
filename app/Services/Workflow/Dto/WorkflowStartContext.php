<?php

namespace App\Services\Workflow\Dto;

/**
 * Workflow Start Context — Read-Model بدونِ ذخیره برایِ «نامهٔ فرایندی».
 *
 * در هر درخواست از روی (definitionId, starterUserId) محاسبه می‌شود؛ هرگز ذخیره یا
 * از کلاینت پذیرفته نمی‌شود. Preview فقط از آن برایِ «نمایش» استفاده می‌کند؛
 * Submit همیشه آن را دوباره در سرور می‌سازد و همان را ملاکِ تصمیم قرار می‌دهد.
 */
final class WorkflowStartContext
{
    /**
     * @param  array{userId:int, fullName:?string, positionName:?string, unitName:?string, unitId:?int}  $starter
     * @param  array{stepId:int, code:string, name:string, type:string, assignPolicy:string, requiredApprovals:?int}|null  $step
     * @param  array<int,array{userId:int, fullName:?string}>  $recipients
     */
    public function __construct(
        public int $definitionId,
        public array $starter,
        public ?array $step,
        public array $recipients,
        public bool $startable,
        public ?string $reason,
        public ?string $message,
        public bool $resolved,
        /** true = گیرندهٔ مرحلهٔ اول به یک CONDITION بستگی دارد و پیش از Submit قابلِ‌تعیین نیست. */
        public bool $deferred = false,
    ) {
    }

    /** @return int[] */
    public function recipientIds(): array
    {
        return array_map(fn ($r) => (int) $r['userId'], $this->recipients);
    }

    /**
     * کلیدهایِ `resolved/reason/users` عیناً همانِ خروجیِ قدیمیِ previewAssignment‌اند
     * (سازگاری با عقب)؛ بقیه افزوده شده‌اند.
     */
    public function toArray(): array
    {
        return [
            'resolved'  => $this->resolved,
            'reason'    => $this->reason,
            'users'     => $this->recipients,
            'starter'   => $this->starter,
            'step'      => $this->step,
            'startable' => $this->startable,
            'message'   => $this->message,
            'deferred'  => $this->deferred,
        ];
    }
}
