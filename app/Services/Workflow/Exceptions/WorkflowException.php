<?php

namespace App\Services\Workflow\Exceptions;

/**
 * ریشهٔ همهٔ خطاهای موتورِ فرایند. کنترلرها می‌توانند این را بگیرند و
 * پیامِ فارسیِ getMessage() را به کاربر نشان دهند.
 */
class WorkflowException extends \RuntimeException
{
}
