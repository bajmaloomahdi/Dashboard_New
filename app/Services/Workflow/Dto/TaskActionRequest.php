<?php

namespace App\Services\Workflow\Dto;

/**
 * ورودیِ انجامِ یک اقدام (Action) روی «تسکِ مرحله»ی فرایند.
 *
 * آیتمِ کارتابل یک ردیفِ dbo.Messages است؛ اقدام بر مبنای MessageID انجام می‌شود
 * و موتور آن را به StepInstanceِ فعال نگاشت می‌کند.
 */
final class TaskActionRequest
{
    /**
     * @param  int          $messageId           شناسهٔ پیامِ کارتابلیِ این تسک
     * @param  int          $userId              کاربرِ اقدام‌کننده (باید انجام‌دهندهٔ فعالِ مرحله باشد)
     * @param  string       $actionCode          کدِ Action از WorkflowStepActions
     * @param  string|null  $comment
     * @param  string|null  $expectedRowVersion  RowVersionِ StepInstance به‌صورتِ hex («0x…») برای کنترلِ هم‌زمانی
     * @param  string|null  $ipAddress
     */
    public function __construct(
        public int $messageId,
        public int $userId,
        public string $actionCode,
        public ?string $comment = null,
        public ?string $expectedRowVersion = null,
        public ?string $ipAddress = null,
    ) {
    }
}
