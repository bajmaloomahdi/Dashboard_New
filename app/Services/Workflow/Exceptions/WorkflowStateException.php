<?php

namespace App\Services\Workflow\Exceptions;

/**
 * عملیاتی که در وضعیتِ فعلیِ Instance/Task/Version مجاز نیست
 * (مثلاً بستنِ تسکِ قبلاً بسته‌شده، انتشارِ نسخهٔ غیرِ DRAFT).
 */
class WorkflowStateException extends WorkflowException
{
}
