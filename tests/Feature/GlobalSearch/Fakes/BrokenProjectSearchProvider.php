<?php

namespace Tests\Feature\GlobalSearch\Fakes;

use App\Services\GlobalSearch\Providers\ProjectSearchProvider;

/** ارائه‌دهندهٔ پروژه‌ها با رویه‌ای که وجود ندارد — آزمونِ جداسازیِ خطا. */
class BrokenProjectSearchProvider extends ProjectSearchProvider
{
    protected string $procedure = 'dbo.sp_Gs_DoesNotExist';
}
