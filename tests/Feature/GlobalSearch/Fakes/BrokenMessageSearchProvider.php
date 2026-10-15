<?php

namespace Tests\Feature\GlobalSearch\Fakes;

use App\Services\GlobalSearch\Providers\MessageSearchProvider;

/** همان ارائه‌دهندهٔ نامه‌ها با رویه‌ای که وجود ندارد — آزمونِ جداسازیِ خطا و عدمِ افشایِ جزئیات. */
class BrokenMessageSearchProvider extends MessageSearchProvider
{
    protected string $procedure = 'dbo.sp_Gs_DoesNotExist';
}
