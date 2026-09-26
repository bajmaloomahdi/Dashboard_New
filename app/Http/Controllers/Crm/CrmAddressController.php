<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use App\Services\Crm\CrmPartyService;
use App\Services\Crm\NeshanLocationSearch;
use Illuminate\Http\Request;

/**
 * آدرس‌هایِ یک طرف‌حساب (CrmAddresses). سلسله‌مراتبِ استان→شهرستان→شهر اجباری، محله
 * اختیاری، منطقهٔ شهرداری اختیاری و مستقل — طبقِ طراحیِ تأییدشده.
 */
class CrmAddressController extends CrmApiController
{
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(
        private CrmPartyService $parties,
        private CrmMasterDataService $masterData,
        private NeshanLocationSearch $locationSearch,
    ) {
    }

    /**
     * GET crm/addresses/location-search?term=&city= — proxyِ «تبدیل آدرس به نقطه» نشان برایِ پیدا کردنِ اولیهٔ محل
     * در فرمِ آدرسِ طرف‌حساب (کلیدِ Service فقط سمتِ سرور). city = نامِ شهرِ انتخاب‌شده در فرمِ CRM (اختیاری).
     */
    public function locationSearch(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);
        $validated = $request->validate([
            'term' => 'nullable|string|max:200',
            'city' => 'nullable|string|max:100',
        ]);

        return $this->runCrm(function () use ($validated) {
            $term = trim((string) ($validated['term'] ?? ''));
            if ($term === '') {
                return ['items' => []];
            }

            return ['items' => $this->locationSearch->search($term, $validated['city'] ?? null)];
        });
    }

    public function index(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate([
            'partyId' => 'nullable|integer',
            'personId' => 'nullable|integer',
        ]);

        return $this->runCrm(fn () => ['items' => $this->parties->listAddresses(
            $validated['partyId'] ?? null,
            $validated['personId'] ?? null,
        )]);
    }

    /** GET crm/addresses/counties?provinceId= — برایِ Selectِ آبشاری در فرمِ آدرس. */
    public function countiesByProvince(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['provinceId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listCounties($validated['provinceId'], null, true)]);
    }

    /** GET crm/addresses/cities?countyId= — برایِ Selectِ آبشاری در فرمِ آدرس. */
    public function citiesByCounty(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['countyId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listCities($validated['countyId'], null, true)]);
    }

    /** GET crm/addresses/neighborhoods?cityId= — برایِ Selectِ آبشاری در فرمِ آدرس. */
    public function neighborhoodsByCity(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['cityId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listNeighborhoods($validated['cityId'], null, true)]);
    }

    /** GET crm/addresses/municipal-zones — مناطقِ شهرداریِ فعال (مستقل از زنجیرهٔ جغرافیایی). */
    public function municipalZones()
    {
        $this->authorizeCrm('CRM_VIEW');

        return $this->runCrm(fn () => ['items' => $this->masterData->listMunicipalZones(null, true)]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'addressId' => 'nullable|integer|exists:CrmAddresses,AddressID',
            'partyId' => 'nullable|integer|exists:CrmParties,PartyID',
            'personId' => 'nullable|integer|exists:CrmPersons,PersonID',
            'addressTitleId' => 'required|integer|exists:CrmAddressTitles,AddressTitleID',
            'provinceId' => 'required|integer|exists:CrmProvinces,ProvinceID',
            'countyId' => 'required|integer|exists:CrmCounties,CountyID',
            'cityId' => 'required|integer|exists:CrmCities,CityID',
            'neighborhoodId' => 'nullable|integer|exists:CrmNeighborhoods,NeighborhoodID',
            'municipalZoneId' => 'nullable|integer|exists:CrmMunicipalZones,MunicipalZoneID',
            'postalCode' => 'nullable|string|max:10',
            'addressText' => 'required|string|max:500',
            'plateNumber' => 'nullable|string|max:20',
            'unit' => 'nullable|string|max:20',
            'latitude' => 'nullable',
            'longitude' => 'nullable',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->saveAddress($validated, $this->actorId());

            return ['message' => $res->Message ?? 'آدرس ذخیره شد.', 'addressId' => (int) $res->AddressID];
        });
    }

    public function toggleActive(int $addressId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($addressId) {
            $res = $this->parties->toggleAddressActive($addressId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ آدرس تغییر کرد.'];
        });
    }
}
