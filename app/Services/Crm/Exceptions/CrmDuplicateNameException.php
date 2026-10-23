<?php

namespace App\Services\Crm\Exceptions;

/**
 * نامِ طرف‌حساب/مخاطب تکراری است (پچِ ۱۱۴): چیزی ثبت نشده و کاربر باید تأیید کند (allowDuplicateName) یا نام را عوض کند.
 */
class CrmDuplicateNameException extends CrmException
{
    /** @param  list<array{id:int, name:string, isActive:bool}>  $duplicates  تا ۵ موردِ مشابه */
    public function __construct(string $message, public readonly array $duplicates = [], public readonly int $duplicateCount = 0)
    {
        parent::__construct($message);
    }
}
