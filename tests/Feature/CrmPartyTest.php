<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * CRM Phase 2 — طرف‌حساب (CrmParties، همیشه یک نهادِ تجاری — فروشگاه/شرکت/
 * کارخانه، از طریقِ Master Dataیِ «نوع» تفکیک می‌شود) و موجودیت‌هایِ وابسته:
 * برند، آدرس، اطلاعاتِ تماس، شخص/مخاطب (CrmPersons، با عنوان/کدِ ملی/تاریخِ
 * تولدِ اختیاری)، و رابطهٔ Party↔Person (با سمت/نقش‌هایِ چندگانه/مخاطبِ اصلی/
 * وضعیتِ رابطه).
 *
 * کاربران: 2 → RoleID=1 (مدیرِ سیستم)، شاملِ CRM_VIEW و CRM_MANAGE_PARTIES
 *          3 → بدونِ این دو Permission
 */
class CrmPartyTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;
    private const USER_NOPERM = 3;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function uniqueDigits(int $length): string
    {
        $s = (string) random_int(1, 9);
        for ($i = 1; $i < $length; $i++) {
            $s .= (string) random_int(0, 9);
        }

        return $s;
    }

    private function createParty(array $overrides = []): array
    {
        return $this->as(self::USER_FULL)->postJson('/crm/parties', array_merge([
            'partyNature' => 'LEGAL',
            'officialName' => 'شرکتِ تستی',
            'identifierNumber' => $this->uniqueDigits(11),
        ], $overrides))->assertOk()->json();
    }

    /* ==================================================================== */
    /*  Permission                                                           */
    /* ==================================================================== */

    public function test_manage_parties_permission_is_granted_to_admin_role(): void
    {
        $res = $this->createParty();
        $this->assertTrue($res['success']);
    }

    public function test_store_without_permission_is_403(): void
    {
        $this->as(self::USER_NOPERM)->postJson('/crm/parties', [
            'officialName' => 'ش', 'identifierNumber' => $this->uniqueDigits(11),
        ])->assertStatus(403);
    }

    public function test_page_requires_authentication(): void
    {
        $this->getJson('/crm/parties-list')->assertStatus(401);
    }

    /* ==================================================================== */
    /*  طرف‌حساب — نهادِ تجاری                                               */
    /* ==================================================================== */

    public function test_create_party_requires_official_name(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'identifierNumber' => $this->uniqueDigits(11),
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_create_party_requires_valid_nature(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'officialName' => 'ش', 'identifierNumber' => $this->uniqueDigits(11),
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_legal_identifier_must_be_exactly_11_digits(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'ش', 'identifierNumber' => '12345',
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);
        $this->assertStringContainsString('شناسه ملی', $res->json('message'));
    }

    public function test_individual_party_does_not_require_identifier_number(): void
    {
        $res = $this->createParty(['partyNature' => 'INDIVIDUAL', 'officialName' => 'فروشگاهِ رضایی', 'identifierNumber' => null]);
        $this->assertTrue($res['success']);

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/parties-list?partyNature=INDIVIDUAL')->json('items'));
        $found = $list->firstWhere('PartyID', $res['partyId']);
        $this->assertNotNull($found);
        $this->assertNull($found['IdentifierNumber']);
    }

    public function test_individual_identifier_number_is_ignored_even_if_sent(): void
    {
        $res = $this->createParty([
            'partyNature' => 'INDIVIDUAL', 'officialName' => 'فروشگاهِ دوم', 'identifierNumber' => $this->uniqueDigits(10),
        ]);
        $this->assertTrue($res['success']);

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/parties-list?partyNature=INDIVIDUAL')->json('items'));
        $found = $list->firstWhere('PartyID', $res['partyId']);
        $this->assertNull($found['IdentifierNumber']);
    }

    public function test_multiple_individual_parties_without_identifier_do_not_collide(): void
    {
        $a = $this->createParty(['partyNature' => 'INDIVIDUAL', 'officialName' => 'فروشگاهِ سوم', 'identifierNumber' => null]);
        $b = $this->createParty(['partyNature' => 'INDIVIDUAL', 'officialName' => 'فروشگاهِ چهارم', 'identifierNumber' => null]);
        $this->assertTrue($a['success']);
        $this->assertTrue($b['success']);
    }

    public function test_duplicate_identifier_is_rejected(): void
    {
        $code = $this->uniqueDigits(11);
        $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'اول', 'identifierNumber' => $code,
        ])->assertOk();

        $res = $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyNature' => 'LEGAL', 'officialName' => 'دوم', 'identifierNumber' => $code,
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_party_can_be_created_with_department_type_and_activity(): void
    {
        $department = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', [
            'displayName' => 'دپارتمانِ تست',
        ])->assertOk()->json();

        $partyType = $this->as(self::USER_FULL)->postJson('/crm/classification/party-types', [
            'displayName' => 'فروشگاهی',
        ])->assertOk()->json();

        $activity = $this->as(self::USER_FULL)->postJson('/crm/classification/activities', [
            'partyTypeId' => $partyType['partyTypeId'], 'displayName' => 'خرده‌فروشی',
        ])->assertOk()->json();

        $party = $this->createParty([
            'departmentId' => $department['departmentId'],
            'partyTypeId' => $partyType['partyTypeId'],
            'activityId' => $activity['activityId'],
        ]);
        $this->assertTrue($party['success']);

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/parties-list')->json('items'));
        $found = $list->firstWhere('PartyID', $party['partyId']);
        $this->assertSame('دپارتمانِ تست', $found['DepartmentName']);
        $this->assertSame('فروشگاهی', $found['PartyTypeName']);
        $this->assertSame('خرده‌فروشی', $found['ActivityName']);
    }

    /* ==================================================================== */
    /*  دسته‌بندیِ چندگانهٔ طرف‌حساب (CrmPartyClassifications)               */
    /* ==================================================================== */

    public function test_party_can_have_multiple_classifications(): void
    {
        $party = $this->createParty();

        $deptA = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', ['displayName' => 'دپارتمانِ الف'])->assertOk()->json();
        $deptB = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', ['displayName' => 'دپارتمانِ ب'])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson('/crm/classifications', [
            'partyId' => $party['partyId'], 'departmentId' => $deptA['departmentId'],
        ])->assertOk()->assertJson(['success' => true]);

        $this->as(self::USER_FULL)->postJson('/crm/classifications', [
            'partyId' => $party['partyId'], 'departmentId' => $deptB['departmentId'],
        ])->assertOk()->assertJson(['success' => true]);

        $items = $this->as(self::USER_FULL)->getJson("/crm/classifications?partyId={$party['partyId']}")->assertOk()->json('items');
        $this->assertCount(2, $items);

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/parties-list')->json('items'));
        $found = $list->firstWhere('PartyID', $party['partyId']);
        $this->assertSame(2, (int) $found['ClassificationCount']);
    }

    public function test_classification_requires_at_least_one_dimension(): void
    {
        $party = $this->createParty();

        $res = $this->as(self::USER_FULL)->postJson('/crm/classifications', ['partyId' => $party['partyId']]);
        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_toggle_classification_active_excludes_it_from_active_count(): void
    {
        $party = $this->createParty();
        $department = $this->as(self::USER_FULL)->postJson('/crm/classification/departments', ['displayName' => 'دپارتمانِ تاگل'])->assertOk()->json();

        $created = $this->as(self::USER_FULL)->postJson('/crm/classifications', [
            'partyId' => $party['partyId'], 'departmentId' => $department['departmentId'],
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/crm/classifications/{$created['classificationId']}/toggle")
            ->assertOk()->assertJson(['success' => true]);

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/parties-list')->json('items'));
        $found = $list->firstWhere('PartyID', $party['partyId']);
        $this->assertSame(0, (int) $found['ClassificationCount']);
    }

    public function test_edit_party_updates_fields(): void
    {
        $created = $this->createParty();

        $res = $this->as(self::USER_FULL)->postJson('/crm/parties', [
            'partyId' => $created['partyId'],
            'partyNature' => 'LEGAL',
            'officialName' => 'نامِ‌جدید',
            'identifierNumber' => $this->uniqueDigits(11),
        ])->assertOk()->json();

        $this->assertTrue($res['success']);

        $shown = $this->as(self::USER_FULL)->getJson('/crm/parties-list')->json('items');
        $found = collect($shown)->firstWhere('PartyID', $created['partyId']);
        $this->assertSame('نامِ‌جدید', $found['OfficialName']);
    }

    public function test_toggle_party_active_flips_state(): void
    {
        $created = $this->createParty();

        $this->as(self::USER_FULL)->postJson("/crm/parties/{$created['partyId']}/toggle")
            ->assertOk()->assertJson(['success' => true]);

        $shown = $this->as(self::USER_FULL)->getJson('/crm/parties-list?isActive=0')->json('items');
        $this->assertTrue(collect($shown)->contains('PartyID', $created['partyId']));
    }

    public function test_party_show_page_requires_view_permission(): void
    {
        $created = $this->createParty();

        $this->as(self::USER_NOPERM)->get("/crm/parties/{$created['partyId']}")->assertStatus(403);
        $this->as(self::USER_FULL)->get("/crm/parties/{$created['partyId']}")->assertOk();
    }

    /* برند و ارتباطِ طرف‌حساب ↔ برند: tests/Feature/CrmBrandTest.php */

    /* ==================================================================== */
    /*  آدرس — سلسله‌مراتبِ استان→شهرستان→شهر(→محلهٔ اختیاری)                */
    /* ==================================================================== */

    /** زنجیرهٔ کاملِ جغرافیایی + عنوانِ آدرس؛ neighborhoodId جدا برمی‌گردد چون در آدرس اختیاری است. */
    private function geoChain(): array
    {
        $province = $this->as(self::USER_FULL)->postJson('/crm/geography/provinces', [
            'displayName' => 'استانِ تست',
        ])->assertOk()->json();

        $county = $this->as(self::USER_FULL)->postJson('/crm/geography/counties', [
            'provinceId' => $province['provinceId'], 'displayName' => 'شهرستانِ تست',
        ])->assertOk()->json();

        $city = $this->as(self::USER_FULL)->postJson('/crm/geography/cities', [
            'countyId' => $county['countyId'], 'displayName' => 'شهرِ تست',
        ])->assertOk()->json();

        $neighborhood = $this->as(self::USER_FULL)->postJson('/crm/geography/neighborhoods', [
            'cityId' => $city['cityId'], 'displayName' => 'محلهٔ تست',
        ])->assertOk()->json();

        $addressTitle = $this->as(self::USER_FULL)->postJson('/crm/directory/address-titles', [
            'displayName' => 'عنوانِ تست',
        ])->assertOk()->json();

        return [
            'address' => [
                'provinceId' => $province['provinceId'], 'countyId' => $county['countyId'], 'cityId' => $city['cityId'],
                'addressTitleId' => $addressTitle['addressTitleId'],
            ],
            'neighborhoodId' => $neighborhood['neighborhoodId'],
        ];
    }

    public function test_create_multiple_addresses_with_full_geography_hierarchy(): void
    {
        $party = $this->createParty();
        $geo = $this->geoChain();

        $res = $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo['address'], [
            'partyId' => $party['partyId'], 'neighborhoodId' => $geo['neighborhoodId'], 'addressText' => 'خیابانِ اصلی، پلاکِ ۱',
        ]));
        $res->assertOk()->assertJson(['success' => true]);

        // آدرسِ دوم بدونِ محله — محله اختیاری است
        $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo['address'], [
            'partyId' => $party['partyId'], 'addressText' => 'خیابانِ فرعی',
        ]))->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson("/crm/addresses?partyId={$party['partyId']}")->json('items');
        $this->assertCount(2, $list);
        $this->assertSame('استانِ تست', $list[0]['ProvinceName']);
        $this->assertSame('شهرستانِ تست', $list[0]['CountyName']);
        $this->assertSame('شهرِ تست', $list[0]['CityName']);
        $this->assertSame('محلهٔ تست', $list[0]['NeighborhoodName']);
        $this->assertNull($list[1]['NeighborhoodID']);
    }

    public function test_address_county_must_belong_to_selected_province(): void
    {
        $party = $this->createParty();
        $geo = $this->geoChain();

        $otherProvince = $this->as(self::USER_FULL)->postJson('/crm/geography/provinces', [
            'displayName' => 'استانِ دیگر',
        ])->assertOk()->json();

        $res = $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo['address'], [
            'partyId' => $party['partyId'],
            'provinceId' => $otherProvince['provinceId'], // مغایر با CountyID
            'addressText' => 'آدرس',
        ]));

        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_address_city_must_belong_to_selected_county(): void
    {
        $party = $this->createParty();
        $geo = $this->geoChain();

        $otherCounty = $this->as(self::USER_FULL)->postJson('/crm/geography/counties', [
            'provinceId' => $geo['address']['provinceId'], 'displayName' => 'شهرستانِ دیگر',
        ])->assertOk()->json();

        $res = $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo['address'], [
            'partyId' => $party['partyId'],
            'countyId' => $otherCounty['countyId'], // هم‌استان، اما شهر زیرمجموعه‌اش نیست
            'addressText' => 'آدرس',
        ]));

        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_address_neighborhood_must_belong_to_selected_city(): void
    {
        $party = $this->createParty();
        $geo = $this->geoChain();

        $otherCity = $this->as(self::USER_FULL)->postJson('/crm/geography/cities', [
            'countyId' => $geo['address']['countyId'], 'displayName' => 'شهرِ دیگر',
        ])->assertOk()->json();

        $res = $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo['address'], [
            'partyId' => $party['partyId'],
            'cityId' => $otherCity['cityId'],
            'neighborhoodId' => $geo['neighborhoodId'], // محلهٔ شهرِ دیگر
            'addressText' => 'آدرس',
        ]));

        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_address_geography_lookups_follow_hierarchy(): void
    {
        $geo = $this->geoChain();

        $counties = $this->as(self::USER_FULL)->getJson("/crm/addresses/counties?provinceId={$geo['address']['provinceId']}")->assertOk()->json('items');
        $this->assertSame([$geo['address']['countyId']], collect($counties)->pluck('CountyID')->map(fn ($v) => (int) $v)->all());

        $cities = $this->as(self::USER_FULL)->getJson("/crm/addresses/cities?countyId={$geo['address']['countyId']}")->assertOk()->json('items');
        $this->assertSame([$geo['address']['cityId']], collect($cities)->pluck('CityID')->map(fn ($v) => (int) $v)->all());

        $neighborhoods = $this->as(self::USER_FULL)->getJson("/crm/addresses/neighborhoods?cityId={$geo['address']['cityId']}")->assertOk()->json('items');
        $this->assertSame([$geo['neighborhoodId']], collect($neighborhoods)->pluck('NeighborhoodID')->map(fn ($v) => (int) $v)->all());

        $this->as(self::USER_FULL)->getJson('/crm/addresses/municipal-zones')->assertOk()->assertJson(['success' => true]);
    }

    public function test_address_geography_lookups_require_view_permission(): void
    {
        $this->as(self::USER_NOPERM)->getJson('/crm/addresses/counties?provinceId=1')->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson('/crm/addresses/cities?countyId=1')->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson('/crm/addresses/neighborhoods?cityId=1')->assertStatus(403);
        $this->as(self::USER_NOPERM)->getJson('/crm/addresses/municipal-zones')->assertStatus(403);
    }

    public function test_toggle_address_active(): void
    {
        $party = $this->createParty();
        $geo = $this->geoChain();
        $address = $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo['address'], [
            'partyId' => $party['partyId'], 'addressText' => 'آدرس',
        ]))->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/crm/addresses/{$address['addressId']}/toggle")->assertOk()->assertJson(['success' => true]);
    }

    /* ==================================================================== */
    /*  اطلاعاتِ تماس                                                        */
    /* ==================================================================== */

    private function contactTypeId(): int
    {
        $row = collect($this->as(self::USER_FULL)->getJson('/crm/directory/contact-types?isActive=1')->json('items'))->first();

        return (int) $row['ContactTypeID'];
    }

    public function test_create_multiple_contacts_and_only_one_stays_primary(): void
    {
        $party = $this->createParty();
        $typeId = $this->contactTypeId();

        $first = $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'partyId' => $party['partyId'], 'contactTypeId' => $typeId, 'contactValue' => '09120000001', 'isPrimary' => true,
        ])->assertOk()->json();

        $second = $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'partyId' => $party['partyId'], 'contactTypeId' => $typeId, 'contactValue' => '09120000002', 'isPrimary' => true,
        ])->assertOk()->json();

        $list = collect($this->as(self::USER_FULL)->getJson("/crm/contacts?partyId={$party['partyId']}")->json('items'));
        $this->assertFalse((bool) $list->firstWhere('ContactID', $first['contactId'])['IsPrimary']);
        $this->assertTrue((bool) $list->firstWhere('ContactID', $second['contactId'])['IsPrimary']);
    }

    public function test_party_contact_can_be_linked_to_a_related_person(): void
    {
        $party = $this->createParty();
        $typeId = $this->contactTypeId();
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'سارا', 'lastName' => 'احمدی'])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $party['partyId'], 'personId' => $person['personId'],
        ])->assertOk();

        $contact = $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'partyId' => $party['partyId'], 'contactTypeId' => $typeId, 'contactValue' => '09120000003',
            'relatedPersonId' => $person['personId'],
        ])->assertOk()->json();

        $list = collect($this->as(self::USER_FULL)->getJson("/crm/contacts?partyId={$party['partyId']}")->json('items'));
        $found = $list->firstWhere('ContactID', $contact['contactId']);
        $this->assertSame($person['personId'], (int) $found['RelatedPersonID']);
        $this->assertSame('سارا احمدی', $found['RelatedPersonName']);
    }

    public function test_toggle_contact_active(): void
    {
        $party = $this->createParty();
        $contact = $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'partyId' => $party['partyId'], 'contactTypeId' => $this->contactTypeId(), 'contactValue' => '021123456',
        ])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/crm/contacts/{$contact['contactId']}/toggle")->assertOk()->assertJson(['success' => true]);
    }

    /* ==================================================================== */
    /*  شخص/مخاطب + رابطه (Party ↔ Relationship ↔ Person)                   */
    /* ==================================================================== */

    public function test_create_person_independent_of_party(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'سارا', 'lastName' => 'احمدی'])->assertOk()->json();
        $this->assertTrue($res['success']);

        $list = $this->as(self::USER_FULL)->getJson('/crm/persons?search=سارا')->json('items');
        $this->assertGreaterThanOrEqual(1, count($list));
    }

    public function test_person_can_be_created_with_title_identifier_and_birth_date(): void
    {
        $title = $this->as(self::USER_FULL)->postJson('/crm/directory/titles', ['displayName' => 'آقای'])->assertOk()->json();

        $res = $this->as(self::USER_FULL)->postJson('/crm/persons', [
            'firstName' => 'کاوه', 'lastName' => 'محمدی',
            'titleId' => $title['titleId'], 'identifierNumber' => $this->uniqueDigits(10), 'identifierDate' => '1370-01-01',
        ])->assertOk()->json();
        $this->assertTrue($res['success']);

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/persons?search=کاوه')->json('items'));
        $found = $list->firstWhere('PersonID', $res['personId']);
        $this->assertSame('آقای', $found['TitleName']);
        $this->assertNotEmpty($found['IdentifierNumber']);
    }

    public function test_person_identifier_number_must_be_exactly_10_digits_when_provided(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/crm/persons', [
            'firstName' => 'ن', 'lastName' => 'ن', 'identifierNumber' => '12345',
        ]);
        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_person_description_is_saved(): void
    {
        $res = $this->as(self::USER_FULL)->postJson('/crm/persons', [
            'firstName' => 'یاسمن', 'lastName' => 'صادقی', 'description' => 'یادداشتِ تستی',
        ])->assertOk()->json();

        $list = collect($this->as(self::USER_FULL)->getJson('/crm/persons?search=یاسمن')->json('items'));
        $found = $list->firstWhere('PersonID', $res['personId']);
        $this->assertSame('یادداشتِ تستی', $found['Description']);
    }

    public function test_person_show_page_requires_view_permission(): void
    {
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ح', 'lastName' => 'ح'])->assertOk()->json();

        $this->as(self::USER_NOPERM)->get("/crm/persons/{$person['personId']}")->assertStatus(403);
        $this->as(self::USER_FULL)->get("/crm/persons/{$person['personId']}")->assertOk();
    }

    /* ==================================================================== */
    /*  اطلاعاتِ تماس/آدرسِ مستقیمِ مخاطب (بدونِ طرف‌حساب)                   */
    /* ==================================================================== */

    public function test_contact_can_be_created_directly_for_a_person_without_a_party(): void
    {
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ص', 'lastName' => 'ص'])->assertOk()->json();

        $res = $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'personId' => $person['personId'], 'contactTypeId' => $this->contactTypeId(), 'contactValue' => '09121234567',
        ]);
        $res->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson("/crm/contacts?personId={$person['personId']}")->json('items');
        $this->assertCount(1, $list);
        $this->assertNull($list[0]['PartyID']);
    }

    public function test_address_can_be_created_directly_for_a_person_without_a_party(): void
    {
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ق', 'lastName' => 'ق'])->assertOk()->json();
        $geo = $this->geoChain();

        $res = $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($geo['address'], [
            'personId' => $person['personId'], 'neighborhoodId' => $geo['neighborhoodId'], 'addressText' => 'آدرسِ مخاطب',
        ]));
        $res->assertOk()->assertJson(['success' => true]);

        $list = $this->as(self::USER_FULL)->getJson("/crm/addresses?personId={$person['personId']}")->json('items');
        $this->assertCount(1, $list);
        $this->assertNull($list[0]['PartyID']);
        $this->assertSame('شهرستانِ تست', $list[0]['CountyName']);
        $this->assertSame('محلهٔ تست', $list[0]['NeighborhoodName']);
    }

    public function test_contact_requires_exactly_one_of_party_or_person(): void
    {
        $party = $this->createParty();
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ط', 'lastName' => 'ط'])->assertOk()->json();

        // نه طرف‌حساب نه مخاطب
        $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'contactTypeId' => $this->contactTypeId(), 'contactValue' => '09120000000',
        ])->assertStatus(422)->assertJson(['success' => false]);

        // هر دو هم‌زمان
        $this->as(self::USER_FULL)->postJson('/crm/contacts', [
            'partyId' => $party['partyId'], 'personId' => $person['personId'],
            'contactTypeId' => $this->contactTypeId(), 'contactValue' => '09120000000',
        ])->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_same_person_can_relate_to_multiple_parties(): void
    {
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'رضا', 'lastName' => 'کریمی'])->assertOk()->json();
        $partyA = $this->createParty();
        $partyB = $this->createParty();

        $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $partyA['partyId'], 'personId' => $person['personId'],
        ])->assertOk()->assertJson(['success' => true]);

        $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $partyB['partyId'], 'personId' => $person['personId'],
        ])->assertOk()->assertJson(['success' => true]);
    }

    public function test_person_show_page_lists_related_parties_with_role_position_and_primary_flag(): void
    {
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'نیما', 'lastName' => 'رستمی'])->assertOk()->json();
        $party = $this->createParty(['officialName' => 'شرکتِ رابطهٔ‌معکوس']);

        $position = $this->as(self::USER_FULL)->postJson('/crm/directory/positions', ['displayName' => 'مدیرِفروش'])->assertOk()->json();
        $role = $this->as(self::USER_FULL)->postJson('/crm/directory/contact-roles', ['displayName' => 'رابطِ‌خرید'])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $party['partyId'],
            'personId' => $person['personId'],
            'positionId' => $position['positionId'],
            'roleIds' => [$role['contactRoleId']],
            'isPrimaryContact' => true,
        ])->assertOk()->assertJson(['success' => true]);

        $response = $this->as(self::USER_FULL)->get("/crm/persons/{$person['personId']}")->assertOk();
        $relations = $response->viewData('page')['props']['relations'];

        $this->assertCount(1, $relations);
        $found = $relations[0];
        $this->assertSame($party['partyId'], (int) $found->PartyID);
        $this->assertSame('شرکتِ رابطهٔ‌معکوس', $found->PartyDisplayName);
        $this->assertSame('LEGAL', $found->PartyNature);
        $this->assertSame('مدیرِفروش', $found->PositionName);
        $this->assertSame('رابطِ‌خرید', $found->RoleNames);
        $this->assertTrue((bool) $found->IsPrimaryContact);
        $this->assertTrue((bool) $found->IsActive);
    }

    public function test_relation_requires_position_role_primary_and_status(): void
    {
        $party = $this->createParty();
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'م', 'lastName' => 'م'])->assertOk()->json();

        $position = $this->as(self::USER_FULL)->postJson('/crm/directory/positions', [
            'displayName' => 'مدیرِ فروش',
        ])->assertOk()->json();

        $role1 = $this->as(self::USER_FULL)->postJson('/crm/directory/contact-roles', [
            'displayName' => 'تصمیم‌گیرنده',
        ])->assertOk()->json();
        $role2 = $this->as(self::USER_FULL)->postJson('/crm/directory/contact-roles', [
            'displayName' => 'خریدار',
        ])->assertOk()->json();

        $relation = $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $party['partyId'],
            'personId' => $person['personId'],
            'positionId' => $position['positionId'],
            'isPrimaryContact' => true,
            'roleIds' => [$role1['contactRoleId'], $role2['contactRoleId']],
        ])->assertOk()->json();

        $this->assertTrue($relation['success']);

        $list = collect($this->as(self::USER_FULL)->getJson("/crm/relations?partyId={$party['partyId']}")->json('items'));
        $row = $list->firstWhere('RelationID', $relation['relationId']);
        $this->assertSame('مدیرِ فروش', $row['PositionName']);
        $this->assertTrue((bool) $row['IsPrimaryContact']);
        $this->assertStringContainsString('تصمیم‌گیرنده', $row['RoleNames']);
        $this->assertStringContainsString('خریدار', $row['RoleNames']);

        $roleIds = $this->as(self::USER_FULL)->getJson("/crm/relations/{$relation['relationId']}/roles")->json('roleIds');
        $this->assertContains((int) $role1['contactRoleId'], $roleIds);
        $this->assertContains((int) $role2['contactRoleId'], $roleIds);
    }

    public function test_same_person_cannot_be_added_twice_to_same_party(): void
    {
        $party = $this->createParty();
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ت', 'lastName' => 'ت'])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $party['partyId'], 'personId' => $person['personId']])->assertOk();

        $res = $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $party['partyId'], 'personId' => $person['personId']]);
        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_only_one_primary_contact_per_party(): void
    {
        $party = $this->createParty();
        $personA = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ا', 'lastName' => 'ا'])->assertOk()->json();
        $personB = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ب', 'lastName' => 'ب'])->assertOk()->json();

        $relA = $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $party['partyId'], 'personId' => $personA['personId'], 'isPrimaryContact' => true,
        ])->assertOk()->json();

        $relB = $this->as(self::USER_FULL)->postJson('/crm/relations', [
            'partyId' => $party['partyId'], 'personId' => $personB['personId'], 'isPrimaryContact' => true,
        ])->assertOk()->json();

        $list = collect($this->as(self::USER_FULL)->getJson("/crm/relations?partyId={$party['partyId']}")->json('items'));
        $this->assertFalse((bool) $list->firstWhere('RelationID', $relA['relationId'])['IsPrimaryContact']);
        $this->assertTrue((bool) $list->firstWhere('RelationID', $relB['relationId'])['IsPrimaryContact']);
    }

    public function test_toggle_relation_active_changes_relation_status(): void
    {
        $party = $this->createParty();
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'و', 'lastName' => 'و'])->assertOk()->json();
        $relation = $this->as(self::USER_FULL)->postJson('/crm/relations', ['partyId' => $party['partyId'], 'personId' => $person['personId']])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/crm/relations/{$relation['relationId']}/toggle")->assertOk()->assertJson(['success' => true]);

        $list = collect($this->as(self::USER_FULL)->getJson("/crm/relations?partyId={$party['partyId']}")->json('items'));
        $this->assertFalse((bool) $list->firstWhere('RelationID', $relation['relationId'])['IsActive']);
    }

    public function test_toggle_person_active(): void
    {
        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ژ', 'lastName' => 'ژ'])->assertOk()->json();

        $this->as(self::USER_FULL)->postJson("/crm/persons/{$person['personId']}/toggle")->assertOk()->assertJson(['success' => true]);
    }
}
