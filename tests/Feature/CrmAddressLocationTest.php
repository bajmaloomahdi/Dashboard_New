<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * موقعیتِ مکانیِ آدرس (CrmAddresses.Latitude/Longitude، DECIMAL(9,6)، WGS84): اختیاری، هر دو با هم یا هیچ‌کدام،
 * در محدودهٔ معتبر؛ آدرسِ متنی و سلسله‌مراتبِ جغرافیاییِ CRM دست نمی‌خورد. Map Keyِ نشان فقط از config/.env.
 */
class CrmAddressLocationTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    /** @return array{party:int, address:array<string,mixed>} */
    private function fixture(): array
    {
        $post = fn (string $url, array $body) => $this->as(self::USER_FULL)->postJson($url, $body)->assertOk()->json();
        $digits = (string) random_int(1, 9);
        for ($i = 1; $i < 11; $i++) {
            $digits .= (string) random_int(0, 9);
        }
        $party = $post('/crm/parties', ['partyNature' => 'LEGAL', 'officialName' => 'طرف‌حسابِ تستِ نقشه', 'identifierNumber' => $digits])['partyId'];
        $province = $post('/crm/geography/provinces', ['displayName' => 'استانِ نقشه'])['provinceId'];
        $county = $post('/crm/geography/counties', ['provinceId' => $province, 'displayName' => 'شهرستانِ نقشه'])['countyId'];
        $city = $post('/crm/geography/cities', ['countyId' => $county, 'displayName' => 'شهرِ نقشه'])['cityId'];
        $title = $post('/crm/directory/address-titles', ['displayName' => 'عنوانِ نقشه'])['addressTitleId'];

        return ['party' => $party, 'address' => [
            'partyId' => $party, 'addressTitleId' => $title, 'provinceId' => $province, 'countyId' => $county, 'cityId' => $city,
            'addressText' => 'خیابانِ تست، پلاکِ ۱', 'postalCode' => '1234567890',
        ]];
    }

    private function list(int $party): array
    {
        return $this->as(self::USER_FULL)->getJson("/crm/addresses?partyId={$party}")->assertOk()->json('items');
    }

    public function test_address_can_be_saved_with_coordinates_and_they_are_returned(): void
    {
        $f = $this->fixture();
        $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'] + ['latitude' => 35.6997123, 'longitude' => '51.3380004'])
            ->assertOk()->assertJson(['success' => true]);

        $row = $this->list($f['party'])[0];
        $this->assertSame(35.699712, (float) $row['Latitude']);   // ۶ رقمِ اعشار
        $this->assertSame(51.338, (float) $row['Longitude']);
        // آدرسِ متنی و جغرافیایِ CRM دست‌نخورده
        $this->assertSame('خیابانِ تست، پلاکِ ۱', $row['AddressText']);
        $this->assertSame('شهرِ نقشه', $row['CityName']);
        $this->assertSame('1234567890', $row['PostalCode']);
    }

    public function test_address_without_coordinates_still_works(): void
    {
        $f = $this->fixture();
        $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'])->assertOk();

        $row = $this->list($f['party'])[0];
        $this->assertNull($row['Latitude']);
        $this->assertNull($row['Longitude']);
    }

    public function test_coordinates_must_come_in_pairs(): void
    {
        $f = $this->fixture();
        $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'] + ['latitude' => 35.7])
            ->assertStatus(422)->assertJson(['success' => false]);
        $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'] + ['longitude' => 51.3])
            ->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_coordinates_must_be_in_range_and_numeric(): void
    {
        $f = $this->fixture();
        foreach ([[90.1, 51], [-90.1, 51], [35, 180.1], [35, -180.1], ['abc', 51]] as [$lat, $lng]) {
            $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'] + ['latitude' => $lat, 'longitude' => $lng])
                ->assertStatus(422)->assertJson(['success' => false]);
        }
        $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'] + ['latitude' => -90, 'longitude' => 180])->assertOk();
    }

    public function test_editing_keeps_moves_and_clears_the_location(): void
    {
        $f = $this->fixture();
        $id = $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'] + ['latitude' => 35.7, 'longitude' => 51.4])->json('addressId');

        // ویرایشِ فیلدهایِ دیگر با همان مختصات (همان کاری که فرم می‌کند) → مختصات حفظ می‌شود
        $this->as(self::USER_FULL)->postJson('/crm/addresses', array_merge($f['address'], ['addressId' => $id, 'addressText' => 'متنِ جدید', 'latitude' => 35.7, 'longitude' => 51.4]))->assertOk();
        $row = $this->list($f['party'])[0];
        $this->assertSame('متنِ جدید', $row['AddressText']);
        $this->assertSame(35.7, (float) $row['Latitude']);

        // جابه‌جاییِ Marker
        $this->as(self::USER_FULL)->postJson('/crm/addresses', ['addressId' => $id] + $f['address'] + ['latitude' => 36.1, 'longitude' => 59.6])->assertOk();
        $this->assertSame(59.6, (float) $this->list($f['party'])[0]['Longitude']);

        // «حذفِ موقعیت»
        $this->as(self::USER_FULL)->postJson('/crm/addresses', ['addressId' => $id] + $f['address'] + ['latitude' => null, 'longitude' => null])->assertOk();
        $row = $this->list($f['party'])[0];
        $this->assertNull($row['Latitude']);
        $this->assertNull($row['Longitude']);
    }

    public function test_database_constraints_guard_coordinates_even_without_the_sp(): void
    {
        $f = $this->fixture();
        $id = $this->as(self::USER_FULL)->postJson('/crm/addresses', $f['address'])->json('addressId');

        foreach (['UPDATE CrmAddresses SET Latitude = 35 WHERE AddressID = ?', 'UPDATE CrmAddresses SET Latitude = 95, Longitude = 50 WHERE AddressID = ?'] as $sql) {
            try {
                DB::update($sql, [$id]);
                $this->fail('CHECK constraint should have rejected: ' . $sql);
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertMatchesRegularExpression('/CK_CrmAddresses_Geo(Pair|Range)/', $e->getMessage());
            }
        }
    }

    /* ---------- جست‌وجویِ محل (proxyِ «تبدیل آدرس به نقطه» نشان — Geocoding v1) ---------- */

    /** قالبِ واقعیِ پاسخِ geocoding/v1 (همان که از داخلِ dashboard_app دیده شد): تا ۵ نامزدِ اغلب چند متری از هم */
    private function geocodingResponse(): array
    {
        return ['items' => [
            ['location' => ['latitude' => 35.8069123, 'longitude' => 51.4285456], 'province' => 'تهران', 'city' => 'تهران', 'neighbourhood' => 'تجریش', 'unMatchedTerm' => ''],
            ['location' => ['latitude' => 35.8074, 'longitude' => 51.4292], 'province' => 'تهران', 'city' => 'تهران', 'neighbourhood' => 'تجریش', 'unMatchedTerm' => ''],   // ~۹۰ متر → تکراری
            ['location' => ['latitude' => 35.8068, 'longitude' => 51.4288], 'province' => 'تهران', 'city' => 'تهران', 'neighbourhood' => 'تجریش', 'unMatchedTerm' => ''],   // ~۳۰ متر → تکراری
            ['location' => ['latitude' => 35.7641, 'longitude' => 51.3994], 'province' => 'تهران', 'city' => 'تهران', 'neighbourhood' => 'ونک', 'unMatchedTerm' => 'پلاک ۱۲'],
            ['location' => ['latitude' => 35.6891, 'longitude' => 51.0234], 'province' => 'تهران', 'city' => 'nan', 'unMatchedTerm' => ''],
            ['location' => []],                                                                                                                               // بدونِ مختصات → حذف
        ]];
    }

    public function test_location_search_uses_geocoding_v1_with_city_and_without_map_center(): void
    {
        config(['services.neshan.service_key' => 'service-key-from-config', 'services.neshan.geocoding_plus' => false]);
        Http::fake(['api.neshan.org/*' => Http::response($this->geocodingResponse(), 200)]);

        $this->as(self::USER_FULL)->getJson('/crm/addresses/location-search?' . http_build_query(['term' => 'میدان تجریش', 'city' => 'تهران']))
            ->assertOk()->assertJson(['success' => true]);

        Http::assertSent(function ($request) {
            $payload = json_decode($request['json'], true);

            return str_starts_with($request->url(), 'https://api.neshan.org/geocoding/v1?')
                && $request->hasHeader('Api-Key', 'service-key-from-config')
                && $payload === ['address' => 'میدان تجریش', 'city' => 'تهران'];   // بدونِ location
        });
    }

    public function test_location_search_deduplicates_and_maps_results(): void
    {
        config(['services.neshan.service_key' => 'service-key-from-config']);
        Http::fake(['api.neshan.org/*' => Http::response($this->geocodingResponse(), 200)]);

        $items = $this->as(self::USER_FULL)->getJson('/crm/addresses/location-search?term=' . urlencode('میدان تجریش'))->assertOk()->json('items');

        $this->assertCount(3, $items, '۲ نامزدِ هم‌مکان و ۱ نامزدِ بدونِ مختصات حذف می‌شوند.');
        $this->assertSame('محلهٔ تجریش', $items[0]['title']);
        $this->assertSame('تهران', $items[0]['address']);
        $this->assertSame(35.806912, $items[0]['latitude']);
        $this->assertSame(51.428546, $items[0]['longitude']);
        $this->assertSame('پلاک ۱۲', $items[1]['unmatched']);
        $this->assertNull($items[2]['neighbourhood']);
        $this->assertSame('تهران', $items[2]['region'], 'city=nan نادیده گرفته می‌شود.');

        Http::assertSent(fn ($request) => json_decode($request['json'], true) === ['address' => 'میدان تجریش']); // بدونِ شهر
    }

    public function test_location_search_switches_to_geocoding_plus_when_enabled(): void
    {
        config(['services.neshan.service_key' => 'service-key-from-config', 'services.neshan.geocoding_plus' => 'true']);
        Http::fake(['api.neshan.org/*' => Http::response($this->geocodingResponse(), 200)]);

        $this->as(self::USER_FULL)->getJson('/crm/addresses/location-search?term=x')->assertOk();
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.neshan.org/geocoding/v1/plus?'));
    }

    public function test_empty_search_term_returns_nothing_without_calling_neshan(): void
    {
        config(['services.neshan.service_key' => 'service-key-from-config']);
        Http::fake();

        $this->as(self::USER_FULL)->getJson('/crm/addresses/location-search?term=%20%20')->assertOk()->assertJson(['success' => true, 'items' => []]);
        Http::assertNothingSent();
    }

    public function test_location_search_reports_missing_key_and_neshan_errors_in_persian(): void
    {
        config(['services.neshan.service_key' => '']);
        $this->as(self::USER_FULL)->getJson('/crm/addresses/location-search?term=x')
            ->assertStatus(422)->assertJson(['success' => false])->assertJsonFragment(['message' => 'کلیدِ سرویسِ نشان (NESHAN_SERVICE_KEY) تنظیم نشده است.']);

        config(['services.neshan.service_key' => 'wrong-type']);
        Http::fake(['api.neshan.org/*' => Http::sequence()
            ->push(['status' => 'ERROR', 'code' => 483], 483)
            ->push(['status' => 'ERROR', 'code' => 485, 'message' => 'Api Key services not match.'], 485)]);
        $this->assertStringContainsString('کلیدِ Service', $this->as(self::USER_FULL)->getJson('/crm/addresses/location-search?term=x')->assertStatus(422)->json('message'));
        $this->assertStringContainsString('تبدیل آدرس به نقطه', $this->as(self::USER_FULL)->getJson('/crm/addresses/location-search?term=x')->assertStatus(422)->json('message'));
    }

    public function test_location_search_requires_manage_permission(): void
    {
        $this->actingAs(User::find(3));
        $this->getJson('/crm/addresses/location-search?term=x')->assertStatus(403);
    }

    public function test_map_key_is_given_only_to_the_party_page_from_config(): void
    {
        config(['services.neshan.map_key' => 'test-key-from-config', 'services.neshan.service_key' => 'secret-service-key']);
        $f = $this->fixture();

        $response = $this->as(self::USER_FULL)->get("/crm/parties/{$f['party']}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Parties/Show')->where('neshanMapKey', 'test-key-from-config')->where('neshanSearchEnabled', true));
        $this->assertStringNotContainsString('secret-service-key', $response->getContent(), 'کلیدِ Service هرگز به مرورگر نمی‌رود.');

        $person = $this->as(self::USER_FULL)->postJson('/crm/persons', ['firstName' => 'ن', 'lastName' => 'ن'])->json('personId');
        $this->as(self::USER_FULL)->get("/crm/persons/{$person}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Persons/Show')->missing('neshanMapKey')->missing('neshanSearchEnabled'));
    }
}
