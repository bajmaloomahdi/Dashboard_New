<?php

namespace App\Services\Workflow\Exceptions;

/**
 * خطای اعتبارسنجیِ تعریف/نسخهٔ فرایند یا ورودیِ نامعتبرِ عملیات.
 */
class WorkflowValidationException extends WorkflowException
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
