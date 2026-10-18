<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * جمعیت (پچ 100): در شهر وارد می‌شود؛ جمعیتِ شهرستان = جمعِ شهرهایش و جمعیتِ استان = جمعِ همهٔ شهرستان‌هایش.
 */
class CrmGeographyPopulationTest extends TestCase
{
    use DatabaseTransactions;

    private const USER_FULL = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    private function province(): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/geography/provinces', ['displayName' => 'استانِ جمعیت'])->assertOk()->json('provinceId');
    }

    private function county(int $provinceId, string $name): int
    {
        return (int) $this->as(self::USER_FULL)->postJson('/crm/geography/counties', ['provinceId' => $provinceId, 'displayName' => $name])->assertOk()->json('countyId');
    }

    private function city(int $countyId, string $name, ?int $population)
    {
        return $this->as(self::USER_FULL)->postJson('/crm/geography/cities', array_filter([
            'countyId' => $countyId, 'displayName' => $name, 'population' => $population,
        ], fn ($v) => $v !== null));
    }

    private function row(string $url, string $idField, int $id): array
    {
        $row = collect($this->as(self::USER_FULL)->getJson($url)->assertOk()->json('items'))->firstWhere($idField, $id);
        $this->assertNotNull($row);

        return $row;
    }

    public function test_population_rolls_up_from_cities_to_county_and_province(): void
    {
        $province = $this->province();
        $countyA = $this->county($province, 'شهرستانِ الف');
        $countyB = $this->county($province, 'شهرستانِ ب');

        $cityA1 = (int) $this->city($countyA, 'شهرِ ۱', 120000)->assertOk()->json('cityId');
        $this->city($countyA, 'شهرِ ۲', 30000)->assertOk();
        $this->city($countyA, 'شهرِ بدونِ جمعیت', null)->assertOk();
        $this->city($countyB, 'شهرِ ۳', 50000)->assertOk();

        $this->assertSame(120000, (int) $this->row('/crm/geography/cities', 'CityID', $cityA1)['Population']);
        $this->assertSame(150000, (int) $this->row('/crm/geography/counties', 'CountyID', $countyA)['Population']);
        $this->assertSame(50000, (int) $this->row('/crm/geography/counties', 'CountyID', $countyB)['Population']);
        $this->assertSame(200000, (int) $this->row('/crm/geography/provinces', 'ProvinceID', $province)['Population']);

        // ویرایشِ جمعیتِ شهر → جمعِ شهرستان و استان همان لحظه عوض می‌شود
        $this->as(self::USER_FULL)->postJson('/crm/geography/cities', [
            'cityId' => $cityA1, 'countyId' => $countyA, 'displayName' => 'شهرِ ۱', 'population' => 100000,
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertSame(130000, (int) $this->row('/crm/geography/counties', 'CountyID', $countyA)['Population']);
        $this->assertSame(180000, (int) $this->row('/crm/geography/provinces', 'ProvinceID', $province)['Population']);
    }

    public function test_population_can_be_cleared_and_empty_county_has_no_population(): void
    {
        $province = $this->province();
        $county = $this->county($province, 'شهرستانِ خالی');
        $this->assertNull($this->row('/crm/geography/counties', 'CountyID', $county)['Population']);

        $city = (int) $this->city($county, 'شهرِ پاک‌شدنی', 5000)->assertOk()->json('cityId');
        $this->as(self::USER_FULL)->postJson('/crm/geography/cities', [
            'cityId' => $city, 'countyId' => $county, 'displayName' => 'شهرِ پاک‌شدنی', 'population' => null,
        ])->assertOk();

        $this->assertNull($this->row('/crm/geography/cities', 'CityID', $city)['Population']);
        $this->assertNull($this->row('/crm/geography/provinces', 'ProvinceID', $province)['Population']);
    }

    public function test_negative_or_non_integer_population_is_rejected(): void
    {
        $county = $this->county($this->province(), 'شهرستانِ نامعتبر');

        $res = $this->city($county, 'شهرِ منفی', -5);
        $res->assertStatus(422);
        $this->assertStringContainsString('جمعیت نمی‌تواند منفی باشد', (string) $res->json('message'));

        $this->as(self::USER_FULL)->postJson('/crm/geography/cities', ['countyId' => $county, 'displayName' => 'x', 'population' => 'زیاد'])
            ->assertStatus(422);
    }
}
