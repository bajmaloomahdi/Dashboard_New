<?php

namespace Tests\Feature\GlobalSearch\Fakes;

use App\Services\GlobalSearch\Providers\ProcessSearchProvider;

/** ارائه‌دهندهٔ فرایندها با رویه‌ای که وجود ندارد — آزمونِ جداسازیِ خطا. */
class BrokenProcessSearchProvider extends ProcessSearchProvider
{
    protected string $procedure = 'dbo.sp_Gs_DoesNotExist';
}
