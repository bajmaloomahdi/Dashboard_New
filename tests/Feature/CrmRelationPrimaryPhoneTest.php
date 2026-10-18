<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * تبِ «مخاطبین»ِ طرف‌حساب شمارهٔ تماسِ اصلیِ هر مخاطب را هم نشان می‌دهد (پچ 095):
 * موبایل/تلفنِ فعالِ خودِ شخص یا تماس‌هایِ همین طرف‌حساب که به او مرتبط شده‌اند؛
 * اولویت با «تماسِ اصلی»، بعد شمارهٔ خودِ شخص، بعد موبایل.
 */
class CrmRelationPrimaryPhoneTest extends TestCase
{
    use CreatesCrmTitle, DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function typeId(string $name): int
    {
        return (int) DB::table('CrmContactTypes')->whereRaw('LTRIM(RTRIM(DisplayName)) = ?', [$name])->value('ContactTypeID');
    }

    private function freshMobile(): string
    {
        do {
            $n = '0936' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
            $exists = DB::selectOne('SELECT COUNT(*) AS c FROM dbo.CrmContacts WHERE dbo.fn_Crm_NormalizeMobile(ContactValue) = ?', [$n])->c;
        } while ((int) $exists > 0);

        return $n;
    }

    private function party(): int
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }

        return (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'شرکتِ تماسِ اصلی' . ' ' . bin2hex(random_bytes(3)), 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');
    }

    private function personRelatedTo(int $partyId): int
    {
        $personId = (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'lastName' => 'تماس‌دار' . ' ' . bin2hex(random_bytes(3))])
            ->assertOk()->json('personId');
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId])
            ->assertOk()->assertJson(['success' => true]);

        return $personId;
    }

    private function contact(array $owner, string $type, string $value, bool $primary = false, ?string $extension = null): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/contacts', array_merge($owner, [
            'contactTypeId' => $this->typeId($type), 'contactValue' => $value, 'isPrimary' => $primary, 'extension' => $extension,
        ]))->assertOk()->assertJson(['success' => true])->json('contactId');
    }

    private function relationRow(int $partyId, int $personId): array
    {
        $row = collect($this->as(self::USER_FULL)->getJson("/crm/relations?partyId={$partyId}")->assertOk()->json('items'))
            ->firstWhere('PersonID', $personId);
        $this->assertNotNull($row);

        return $row;
    }

    public function test_person_without_numbers_shows_nothing(): void
    {
        $party = $this->party();
        $person = $this->personRelatedTo($party);
        $this->contact(['personId' => $person], 'ایمیل', 'a@example.com', true);

        $this->assertNull($this->relationRow($party, $person)['PrimaryPhone']);
    }

    public function test_primary_contact_of_the_person_wins_over_mobile(): void
    {
        $party = $this->party();
        $person = $this->personRelatedTo($party);
        $this->contact(['personId' => $person], 'موبایل', $this->freshMobile());
        $this->contact(['personId' => $person], 'تلفن', '02155955160', true, '204');

        $row = $this->relationRow($party, $person);
        $this->assertSame('02155955160', $row['PrimaryPhone']);
        $this->assertSame('204', $row['PrimaryPhoneExtension']);
        $this->assertSame('تلفن', $row['PrimaryPhoneType']);
    }

    public function test_without_primary_mobile_is_preferred_and_inactive_is_ignored(): void
    {
        $party = $this->party();
        $person = $this->personRelatedTo($party);
        $this->contact(['personId' => $person], 'تلفن', '02155955160');
        $mobile = $this->freshMobile();
        $this->contact(['personId' => $person], 'موبایل', $mobile);

        $this->assertSame($mobile, $this->relationRow($party, $person)['PrimaryPhone']);

        $inactive = (int) DB::table('CrmContacts')->where('PersonID', $person)->where('ContactValue', $mobile)->value('ContactID');
        $this->as(self::USER_FULL)->postJson("/crm/contacts/{$inactive}/toggle")->assertOk()->assertJson(['success' => true]);

        $this->assertSame('02155955160', $this->relationRow($party, $person)['PrimaryPhone']);
    }

    public function test_party_contact_linked_to_the_person_is_used_only_for_that_party(): void
    {
        $partyA = $this->party();
        $partyB = $this->party();
        $person = $this->personRelatedTo($partyA);
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyB, 'personId' => $person])->assertOk();

        $this->contact(['partyId' => $partyA, 'relatedPersonId' => $person], 'تلفن', '02188776655', false, '12');

        $this->assertSame('02188776655', $this->relationRow($partyA, $person)['PrimaryPhone']);
        $this->assertNull($this->relationRow($partyB, $person)['PrimaryPhone']);
    }

    public function test_persons_own_number_is_preferred_over_party_linked_number(): void
    {
        $party = $this->party();
        $person = $this->personRelatedTo($party);
        $this->contact(['partyId' => $party, 'relatedPersonId' => $person], 'تلفن', '02188776655');
        $mobile = $this->freshMobile();
        $this->contact(['personId' => $person], 'موبایل', $mobile);

        $this->assertSame($mobile, $this->relationRow($party, $person)['PrimaryPhone']);
    }
}
