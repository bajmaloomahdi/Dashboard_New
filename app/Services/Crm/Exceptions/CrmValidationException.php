<?php

namespace App\Services\Crm\Exceptions;

/**
 * خطای اعتبارسنجیِ ورودیِ CRM (طرف‌حساب و موجودیت‌هایِ وابسته).
 */
class CrmValidationException extends CrmException
{
    /** @var string[] */
    public array $errors;

    /**
     * @param  string[]  $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }
}
