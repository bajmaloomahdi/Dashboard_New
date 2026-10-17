<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * عنوانِ CRMِ تازه (آقای/خانم/…) برایِ ساختِ مخاطب — عنوان از پچ 093 برایِ مخاطب الزامی است.
 * داخلِ همان Transactionِ آزمون (DatabaseTransactions) ساخته و با Rollback حذف می‌شود تا آزمون
 * به عنوان‌هایِ موجود در دادهٔ واقعی وابسته نباشد.
 */
trait CreatesCrmTitle
{
    private ?int $crmTitleId = null;

    protected function crmTitleId(): int
    {
        if ($this->crmTitleId === null) {
            $res = DB::selectOne(
                'EXEC dbo.sp_Crm_SaveTitle @TitleID = NULL, @DisplayName = ?, @SortOrder = 0, @UserID = 2',
                ['عنوانِ آزمون ' . bin2hex(random_bytes(3))]
            );
            $this->crmTitleId = (int) $res->TitleID;
        }

        return $this->crmTitleId;
    }
}
