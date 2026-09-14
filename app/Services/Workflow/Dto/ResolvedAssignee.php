<?php

namespace App\Services\Workflow\Dto;

/**
 * یک کاربرِ نهایی‌شده به‌عنوانِ انجام‌دهندهٔ یک مرحله.
 */
final class ResolvedAssignee
{
    public function __construct(
        public int $userId,
        public string $sourceType,
        public ?int $sourceRefId = null,
        public ?string $fullName = null,
    ) {
    }
}
