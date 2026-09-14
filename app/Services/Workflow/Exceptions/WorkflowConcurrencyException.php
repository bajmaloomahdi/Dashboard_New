<?php

namespace App\Services\Workflow\Exceptions;

/**
 * وقتی RowVersion موردِ انتظار با نسخهٔ فعلیِ رکورد نمی‌خواند
 * (تغییرِ هم‌زمان توسط کاربرِ دیگر) پرتاب می‌شود.
 */
class WorkflowConcurrencyException extends WorkflowException
{
    public function __construct(string $message = 'این مورد هم‌زمان توسط کاربرِ دیگری تغییر کرده است. صفحه را تازه کنید و دوباره تلاش کنید.')
    {
        parent::__construct($message);
    }
}
