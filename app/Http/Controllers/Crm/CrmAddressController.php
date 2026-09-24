<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;

/**
 * آدرس‌هایِ یک طرف‌حساب (CrmAddresses). سلسله‌مراتبِ استان→شهر→شهرستان اجباری،
 * منطقهٔ شهرداری اختیاری — طبقِ طراحیِ تأییدشده.
 */
class CrmAddressController extends CrmApiController
{
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(private CrmPartyService $parties, private CrmMasterDataService $masterData)
    {
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

    /** GET crm/addresses/cities?provinceId= — برایِ Selectِ آبشاری در فرمِ آدرس. */
    public function citiesByProvince(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['provinceId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listCities($validated['provinceId'], null, true)]);
    }

    /** GET crm/addresses/counties?cityId= — برایِ Selectِ آبشاری در فرمِ آدرس. */
    public function countiesByCity(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['cityId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listCounties($validated['cityId'], null, true)]);
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
            'cityId' => 'required|integer|exists:CrmCities,CityID',
            'countyId' => 'required|integer|exists:CrmCounties,CountyID',
            'municipalZoneId' => 'nullable|integer|exists:CrmMunicipalZones,MunicipalZoneID',
            'postalCode' => 'nullable|string|max:10',
            'addressText' => 'required|string|max:500',
            'plateNumber' => 'nullable|string|max:20',
            'unit' => 'nullable|string|max:20',
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
