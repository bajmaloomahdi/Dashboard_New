<?php

namespace App\Services\GlobalSearch\Support;

/** اتصالِ جست‌وجو تراکنشِ باز دارد؛ جست‌وجو اجرا نمی‌شود (فقط نامِ کلاس لاگ می‌شود، پیامی به کاربر نمی‌رسد). */
final class SearchConnectionBusy extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Global search connection has an open transaction.');
    }
}
