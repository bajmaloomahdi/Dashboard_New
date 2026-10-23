<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * «قطعِ همکاری» با تاریخ و ساعت برایِ طرف‌حساب و مخاطب (پچِ ۱۱۹): CooperationEndedAt (NULL = همکاری برقرار).
 * ارسال‌نشده = بدونِ تغییر؛ true بدونِ تاریخ رد می‌شود؛ false قطعِ همکاری را برمی‌دارد؛ در فهرست و جزئیات برمی‌گردد.
 */
class CrmCooperationEndedTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function digits(): string
    {
        $d = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $d .= random_int(0, 9);
        }

        return $d;
    }

    private function endedAt(string $table, string $key, int $id): ?string
    {
        $v = DB::table($table)->where($key, $id)->value('CooperationEndedAt');

        return $v === null ? null : substr((string) $v, 0, 16);
    }

    public function test_party_cooperation_end_is_saved_kept_and_cleared(): void
    {
        $name = 'طرف‌حسابِ قطعِ همکاری ' . bin2hex(random_bytes(3));
        $base = ['partyNature' => 'LEGAL', 'officialName' => $name, 'identifierNumber' => $this->digits()];

        $id = (int) $this->as(self::USER_FULL)->postJson('/crm/parties', $base + ['cooperationEnded' => true, 'cooperationEndedAt' => '2026-10-20 14:30:00'])
            ->assertOk()->assertJson(['success' => true])->json('partyId');
        $this->assertSame('2026-10-20 14:30', $this->endedAt('CrmParties', 'PartyID', $id));

        // در فهرستِ طرف‌حساب‌ها هم برمی‌گردد
        $row = collect($this->as(self::USER_FULL)->getJson('/crm/parties-list?search=' . urlencode($name))->assertOk()->json('items'))->firstWhere('PartyID', $id);
        $this->assertNotNull($row);
        $this->assertStringStartsWith('2026-10-20 14:30', (string) $row['CooperationEndedAt']);

        // ویرایش بدونِ این فیلدها → دست نمی‌خورد
        $this->as(self::USER_FULL)->postJson('/crm/parties', $base + ['partyId' => $id, 'description' => 'x'])->assertOk();
        $this->assertSame('2026-10-20 14:30', $this->endedAt('CrmParties', 'PartyID', $id));

        // قطعِ همکاری بدونِ تاریخ رد می‌شود
        $this->as(self::USER_FULL)->postJson('/crm/parties', $base + ['partyId' => $id, 'cooperationEnded' => true])->assertStatus(422);
        $this->assertSame('2026-10-20 14:30', $this->endedAt('CrmParties', 'PartyID', $id));

        // برداشتنِ تیک → برقراریِ دوبارهٔ همکاری
        $this->as(self::USER_FULL)->postJson('/crm/parties', $base + ['partyId' => $id, 'cooperationEnded' => false])->assertOk();
        $this->assertNull($this->endedAt('CrmParties', 'PartyID', $id));
    }

    public function test_quick_cooperation_endpoint_changes_only_that_field(): void
    {
        $name = 'طرف‌حسابِ قطعِ سریع ' . bin2hex(random_bytes(3));
        $partyId = (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => $name, 'identifierNumber' => $this->digits(), 'description' => 'توضیحِ ثابت',
        ])->assertOk()->json('partyId');

        $this->as(self::USER_FULL)->postJson("/crm/parties/{$partyId}/cooperation", ['cooperationEnded' => true, 'cooperationEndedAt' => '2026-10-22 16:45:00'])
            ->assertOk()->assertJson(['success' => true, 'message' => 'قطعِ همکاری ثبت شد.']);
        $this->assertSame('2026-10-22 16:45', $this->endedAt('CrmParties', 'PartyID', $partyId));
        $row = DB::table('CrmParties')->where('PartyID', $partyId)->first();
        $this->assertSame($name, $row->OfficialName);
        $this->assertSame('توضیحِ ثابت', $row->Description, 'بقیهٔ فیلدها دست نمی‌خورند');

        $this->as(self::USER_FULL)->postJson("/crm/parties/{$partyId}/cooperation", ['cooperationEnded' => true])->assertStatus(422);
        $this->as(self::USER_FULL)->postJson("/crm/parties/{$partyId}/cooperation", ['cooperationEnded' => false])->assertOk();
        $this->assertNull($this->endedAt('CrmParties', 'PartyID', $partyId));

        $personId = (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'lastName' => 'قطعِ سریع ' . bin2hex(random_bytes(3))])->json('personId');
        $this->as(self::USER_FULL)->postJson("/crm/persons/{$personId}/cooperation", ['cooperationEnded' => true, 'cooperationEndedAt' => '2026-10-22 08:00:00'])->assertOk();
        $this->assertSame('2026-10-22 08:00', $this->endedAt('CrmPersons', 'PersonID', $personId));

        // بدونِ مجوزِ مدیریتِ CRM
        $noPerm = (int) DB::table('Users')->whereNotIn('UserID', [1, 2])->where('IsActive', 1)->value('UserID');
        if ($noPerm) {
            $perms = collect(DB::select('EXEC sp_GetUserPermissions @UserID = ?', [$noPerm]))->pluck('PermissionCode')->all();
            if (! in_array('CRM_MANAGE_PARTIES', $perms, true)) {
                $this->as($noPerm)->postJson("/crm/persons/{$personId}/cooperation", ['cooperationEnded' => false])->assertStatus(403);
            }
        }
    }

    public function test_person_cooperation_end_is_saved_and_cleared(): void
    {
        $base = ['titleId' => $this->crmTitleId(), 'firstName' => 'مخاطب', 'lastName' => 'قطعِ همکاری ' . bin2hex(random_bytes(3))];

        $id = (int) $this->as(self::USER_FULL)->postJson('/crm/persons', $base)->assertOk()->json('personId');
        $this->assertNull($this->endedAt('CrmPersons', 'PersonID', $id));

        $this->as(self::USER_FULL)->postJson('/crm/persons', $base + ['personId' => $id, 'cooperationEnded' => true, 'cooperationEndedAt' => '2026-10-21 09:15:00'])->assertOk();
        $this->assertSame('2026-10-21 09:15', $this->endedAt('CrmPersons', 'PersonID', $id));

        $this->as(self::USER_FULL)->postJson('/crm/persons', $base + ['personId' => $id, 'cooperationEnded' => false])->assertOk();
        $this->assertNull($this->endedAt('CrmPersons', 'PersonID', $id));
    }
}
