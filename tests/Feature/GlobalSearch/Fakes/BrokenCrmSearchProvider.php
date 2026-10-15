<?php

namespace Tests\Feature\GlobalSearch\Fakes;

use App\Services\GlobalSearch\Providers\CrmSearchProvider;

/** همان ارائه‌دهندهٔ CRM با رویه‌ای که وجود ندارد — برایِ آزمونِ جداسازیِ خطا و عدمِ افشایِ جزئیات. */
class BrokenCrmSearchProvider extends CrmSearchProvider
{
    protected string $procedure = 'dbo.sp_Gs_DoesNotExist';
}
