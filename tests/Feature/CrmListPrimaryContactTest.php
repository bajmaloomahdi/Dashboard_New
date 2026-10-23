<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\TestCase;

/**
 * کارت‌هایِ فهرستِ طرف‌حساب‌ها و مخاطبین تماسِ اصلی را نشان می‌دهند (پچ 097):
 * «تماسِ اصلی»ِ فعال، وگرنه موبایل، تلفن، ایمیل و بعد بقیه؛ برایِ مخاطب، تماسِ خودِ او بر تماسِ مرتبط از طرف‌حساب مقدم است.
 */
class CrmListPrimaryContactTest extends TestCase
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
            $n = '0937' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
            $exists = DB::selectOne('SELECT COUNT(*) AS c FROM dbo.CrmContacts WHERE dbo.fn_Crm_NormalizeMobile(ContactValue) = ?', [$n])->c;
        } while ((int) $exists > 0);

        return $n;
    }

    private function party(): array
    {
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= random_int(0, 9);
        }
        $name = 'شرکتِ فهرست ' . $digits;
        $id = (int) $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => $name, 'identifierNumber' => $digits,
        ])->assertOk()->json('partyId');

        return [$id, $name];
    }

    private function person(): array
    {
        $last = 'فهرستی ' . bin2hex(random_bytes(3));
        $id = (int) $this->as(self::USER_FULL)->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'lastName' => $last])
            ->assertOk()->json('personId');

        return [$id, $last];
    }

    private function contact(array $owner, string $type, string $value, bool $primary = false): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/contacts', array_merge($owner, [
            'contactTypeId' => $this->typeId($type), 'contactValue' => $value, 'isPrimary' => $primary,
        ]))->assertOk()->assertJson(['success' => true])->json('contactId');
    }

    private function partyRow(int $id, string $name): array
    {
        $row = collect(DB::select('EXEC dbo.sp_Crm_GetParties @SearchText = ?', [$name]))->firstWhere('PartyID', $id);
        $this->assertNotNull($row);

        return (array) $row;
    }

    private function personRow(int $id, string $search): array
    {
        $row = collect($this->as(self::USER_FULL)->getJson('/crm/persons?search=' . urlencode($search))->assertOk()->json('items'))
            ->firstWhere('PersonID', $id);
        $this->assertNotNull($row);

        return $row;
    }

    public function test_party_without_contacts_has_no_primary_contact(): void
    {
        [$id, $name] = $this->party();
        $this->assertNull($this->partyRow($id, $name)['PrimaryContactValue']);
    }

    public function test_party_primary_flag_wins_then_mobile_then_phone(): void
    {
        [$id, $name] = $this->party();
        $this->contact(['partyId' => $id], 'ایمیل', 'info@example.com');
        $this->contact(['partyId' => $id], 'تلفن', '02155955160');
        $this->assertSame('02155955160', $this->partyRow($id, $name)['PrimaryContactValue']);

        $mobile = $this->freshMobile();
        $this->contact(['partyId' => $id], 'موبایل', $mobile);
        $this->assertSame($mobile, $this->partyRow($id, $name)['PrimaryContactValue']);

        $this->contact(['partyId' => $id], 'ایمیل', 'sales@example.com', true);
        $row = $this->partyRow($id, $name);
        $this->assertSame('sales@example.com', $row['PrimaryContactValue']);
        $this->assertSame('ایمیل', $row['PrimaryContactType']);
    }

    public function test_inactive_contact_is_ignored(): void
    {
        [$id, $name] = $this->party();
        $c = $this->contact(['partyId' => $id], 'تلفن', '02155955160', true);
        $this->as(self::USER_FULL)->postJson("/crm/contacts/{$c}/toggle")->assertOk()->assertJson(['success' => true]);

        $this->assertNull($this->partyRow($id, $name)['PrimaryContactValue']);
    }

    public function test_person_list_shows_own_contact_before_party_linked_contact(): void
    {
        [$personId, $last] = $this->person();
        [$partyId] = $this->party();
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId])->assertOk();

        $this->contact(['partyId' => $partyId, 'relatedPersonId' => $personId], 'تلفن', '02188776655');
        $this->assertSame('02188776655', $this->personRow($personId, $last)['PrimaryContactValue']);

        $mobile = $this->freshMobile();
        $this->contact(['personId' => $personId], 'موبایل', $mobile);
        $row = $this->personRow($personId, $last);
        $this->assertSame($mobile, $row['PrimaryContactValue']);
        $this->assertSame('موبایل', $row['PrimaryContactType']);
    }

    public function test_party_card_shows_person_of_the_primary_contact(): void
    {
        [$partyId, $name] = $this->party();
        [$personId, $last] = $this->person();
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId])->assertOk();

        // بدونِ تماس و بدونِ مخاطبِ اصلی → نامی نیست
        $this->assertNull($this->partyRow($partyId, $name)['PrimaryContactPersonName']);

        // تماسِ اصلیِ مرتبط با مخاطب → نامِ همان مخاطب (با عنوان)
        $this->contact(['partyId' => $partyId, 'relatedPersonId' => $personId], 'تلفن', '02155955160', true);
        $row = $this->partyRow($partyId, $name);
        $this->assertSame('02155955160', $row['PrimaryContactValue']);
        $this->assertStringEndsWith($last, (string) $row['PrimaryContactPersonName']);
        $this->assertStringStartsWith('عنوانِ آزمون', (string) $row['PrimaryContactPersonName']);
    }

    public function test_party_card_falls_back_to_the_primary_relation_person(): void
    {
        [$partyId, $name] = $this->party();
        [$personId, $last] = $this->person();
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyId, 'personId' => $personId, 'isPrimaryContact' => true])->assertOk();

        // تماسِ بدونِ مخاطب → «مخاطبِ اصلی»ِ طرف‌حساب
        $this->contact(['partyId' => $partyId], 'تلفن', '02155955160');
        $this->assertStringEndsWith($last, (string) $this->partyRow($partyId, $name)['PrimaryContactPersonName']);
    }

    /** زنجیرهٔ جغرافیا + عنوانِ آدرس (داخلِ Transactionِ آزمون) — پچ 099 */
    private function geo(): array
    {
        $province = $this->as(self::USER_FULL)->postJson('/crm/geography/provinces', ['displayName' => 'استانِ جدول'])->assertOk()->json('provinceId');
        $county = $this->as(self::USER_FULL)->postJson('/crm/geography/counties', ['provinceId' => $province, 'displayName' => 'شهرستانِ جدول'])->assertOk()->json('countyId');
        $city = $this->as(self::USER_FULL)->postJson('/crm/geography/cities', ['countyId' => $county, 'displayName' => 'شهرِ جدول'])->assertOk()->json('cityId');
        $title = $this->as(self::USER_FULL)->postJson('/crm/directory/address-titles', ['displayName' => 'دفترِ جدول'])->assertOk()->json('addressTitleId');

        return ['provinceId' => $province, 'countyId' => $county, 'cityId' => $city, 'addressTitleId' => $title];
    }

    private function address(array $owner, array $geo, string $text): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo, $owner, ['addressText' => $text]))
            ->assertOk()->assertJson(['success' => true])->json('addressId');
    }

    public function test_grid_rows_have_first_active_address(): void
    {
        [$partyId, $name] = $this->party();
        $this->assertNull($this->partyRow($partyId, $name)['PrimaryAddressCity']);

        $geo = $this->geo();
        $first = $this->address(['partyId' => $partyId], $geo, 'خیابانِ اول');
        $this->address(['partyId' => $partyId], $geo, 'خیابانِ دوم');
        $row = $this->partyRow($partyId, $name);
        $this->assertSame('استانِ جدول', $row['PrimaryAddressProvince']);
        $this->assertSame('شهرستانِ جدول', $row['PrimaryAddressCounty']); // پچِ ۱۱۸
        $this->assertSame('شهرِ جدول', $row['PrimaryAddressCity']);
        $this->assertSame('خیابانِ اول', $row['PrimaryAddressText']);
        $this->assertSame('دفترِ جدول', $row['PrimaryAddressTitle']);

        // آدرسِ غیرفعال کنار می‌رود
        $this->as(self::USER_FULL)->postJson("/crm/addresses/{$first}/toggle")->assertOk()->assertJson(['success' => true]);
        $this->assertSame('خیابانِ دوم', $this->partyRow($partyId, $name)['PrimaryAddressText']);

        [$personId, $last] = $this->person();
        $this->address(['personId' => $personId], $geo, 'کوچهٔ مخاطب');
        $this->assertSame('کوچهٔ مخاطب', $this->personRow($personId, $last)['PrimaryAddressText']);
    }

    public function test_person_grid_lists_active_related_parties(): void
    {
        [$personId, $last] = $this->person();
        $this->assertNull($this->personRow($personId, $last)['RelatedPartyNames']);

        [$partyA, $nameA] = $this->party();
        [$partyB, $nameB] = $this->party();
        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyA, 'personId' => $personId])->assertOk();
        $relB = (int) $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $partyB, 'personId' => $personId])->assertOk()->json('relationId');

        $names = (string) $this->personRow($personId, $last)['RelatedPartyNames'];
        $this->assertStringContainsString($nameA, $names);
        $this->assertStringContainsString($nameB, $names);

        $this->as(self::USER_FULL)->postJson("/crm/relations/{$relB}/toggle")->assertOk()->assertJson(['success' => true]);
        $this->assertSame($nameA, $this->personRow($personId, $last)['RelatedPartyNames']);
    }

    public function test_list_pages_receive_primary_contact(): void
    {
        [$personId] = $this->person();
        $mobile = $this->freshMobile();
        $this->contact(['personId' => $personId], 'موبایل', $mobile);

        $this->as(self::USER_FULL)->get('/crm/persons-page')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Crm/Persons/Index')
            ->where('persons', fn ($persons) => collect($persons)->firstWhere('PersonID', $personId)['PrimaryContactValue'] === $mobile));
    }
}
