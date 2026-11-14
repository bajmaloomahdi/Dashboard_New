<?php

namespace Tests\Unit;

use App\Services\Crm\Support\CrmStore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmStoreCooperationFilterTest extends TestCase
{
    private const GET_PARTIES_SQL = 'EXEC dbo.sp_Crm_GetParties @SearchText = ?, @IsActive = ?, @DepartmentID = ?, @PartyNature = ?, @CooperationEnded = ?';

    public function test_null_cooperation_filter_requests_all_parties(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with(self::GET_PARTIES_SQL, ['نام', null, null, null, null])
            ->andReturn([]);

        $this->assertSame([], (new CrmStore())->getParties('نام'));
    }

    public function test_active_cooperation_filter_is_passed_independently_of_record_status(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with(self::GET_PARTIES_SQL, ['نام', true, null, 'LEGAL', false])
            ->andReturn([]);

        $this->assertSame([], (new CrmStore())->getParties('نام', true, null, 'LEGAL', false));
    }

    public function test_ended_cooperation_filter_is_passed_independently_of_record_status(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with(self::GET_PARTIES_SQL, ['نام', false, null, 'INDIVIDUAL', true])
            ->andReturn([]);

        $this->assertSame([], (new CrmStore())->getParties('نام', false, null, 'INDIVIDUAL', true));
    }
}
