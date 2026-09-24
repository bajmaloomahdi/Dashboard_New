<?php

namespace App\Services\Crm\Exceptions;

/**
 * ریشهٔ همهٔ خطاهای ماژولِ CRM. کنترلرها می‌توانند این را بگیرند و
 * پیامِ فارسیِ getMessage() را به کاربر نشان دهند.
 */
class CrmException extends \RuntimeException
{
}
