<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * جغرافیایِ آدرس: استان → شهرستان → شهر → محله (هر سطح فقط والدِ مستقیمِ خود را می‌گیرد)
 * + منطقهٔ شهرداری (کاملاً مستقل از این زنجیره).
 */
class CrmGeographyController extends CrmApiController
{
    private const PERM = 'CRM_MANAGE_MASTER_DATA';

    public function __construct(private CrmMasterDataService $masterData)
    {
    }

    /** GET crm/geography — صفحهٔ Inertia با هر پنج فهرست. */
    public function page()
    {
        $this->authorizeCrm(self::PERM);

        return Inertia::render('Crm/MasterData/Geography', [
            'provinces'      => $this->masterData->listProvinces(),
            'counties'       => $this->masterData->listCounties(),
            'cities'         => $this->masterData->listCities(),
            'neighborhoods'  => $this->masterData->listNeighborhoods(),
            'municipalZones' => $this->masterData->listMunicipalZones(),
            'canManage'      => true,
        ]);
    }

    /* ---------- استان ---------- */

    public function provincesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listProvinces(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function provincesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'provinceId'  => 'nullable|integer|exists:CrmProvinces,ProvinceID',
            'displayName' => 'required|string|max:200',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveProvince($validated, $this->actorId());

            return ['message' => $res->Message ?? 'استان ذخیره شد.', 'provinceId' => (int) $res->ProvinceID];
        });
    }

    public function provincesToggle(int $provinceId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($provinceId) {
            $res = $this->masterData->toggleProvinceActive($provinceId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ استان تغییر کرد.'];
        });
    }

    /* ---------- شهرستان (زیرمجموعهٔ استان) ---------- */

    public function countiesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'provinceId' => 'nullable|integer',
            'search'     => 'nullable|string|max:200',
            'isActive'   => 'nullable|boolean',
        ]);

        return $this->runCrm(fn () => ['items' => $this->masterData->listCounties(
            $validated['provinceId'] ?? null,
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function countiesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'countyId'    => 'nullable|integer|exists:CrmCounties,CountyID',
            'provinceId'  => 'required|integer|exists:CrmProvinces,ProvinceID',
            'displayName' => 'required|string|max:200',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveCounty($validated, $this->actorId());

            return ['message' => $res->Message ?? 'شهرستان ذخیره شد.', 'countyId' => (int) $res->CountyID];
        });
    }

    public function countiesToggle(int $countyId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($countyId) {
            $res = $this->masterData->toggleCountyActive($countyId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ شهرستان تغییر کرد.'];
        });
    }

    /* ---------- شهر (زیرمجموعهٔ شهرستان) ---------- */

    public function citiesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'countyId' => 'nullable|integer',
            'search'   => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
        ]);

        return $this->runCrm(fn () => ['items' => $this->masterData->listCities(
            $validated['countyId'] ?? null,
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function citiesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'cityId'      => 'nullable|integer|exists:CrmCities,CityID',
            'countyId'    => 'required|integer|exists:CrmCounties,CountyID',
            'displayName' => 'required|string|max:200',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveCity($validated, $this->actorId());

            return ['message' => $res->Message ?? 'شهر ذخیره شد.', 'cityId' => (int) $res->CityID];
        });
    }

    public function citiesToggle(int $cityId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($cityId) {
            $res = $this->masterData->toggleCityActive($cityId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ شهر تغییر کرد.'];
        });
    }

    /* ---------- محله (زیرمجموعهٔ شهر) ---------- */

    public function neighborhoodsIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'cityId'   => 'nullable|integer',
            'search'   => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
        ]);

        return $this->runCrm(fn () => ['items' => $this->masterData->listNeighborhoods(
            $validated['cityId'] ?? null,
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function neighborhoodsStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'neighborhoodId' => 'nullable|integer|exists:CrmNeighborhoods,NeighborhoodID',
            'cityId'         => 'required|integer|exists:CrmCities,CityID',
            'displayName'    => 'required|string|max:200',
            'sortOrder'      => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveNeighborhood($validated, $this->actorId());

            return ['message' => $res->Message ?? 'محله ذخیره شد.', 'neighborhoodId' => (int) $res->NeighborhoodID];
        });
    }

    public function neighborhoodsToggle(int $neighborhoodId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($neighborhoodId) {
            $res = $this->masterData->toggleNeighborhoodActive($neighborhoodId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ محله تغییر کرد.'];
        });
    }

    /* ---------- منطقهٔ شهرداری (مستقل) ---------- */

    public function municipalZonesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listMunicipalZones(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function municipalZonesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'municipalZoneId' => 'nullable|integer|exists:CrmMunicipalZones,MunicipalZoneID',
            'displayName'     => 'required|string|max:200',
            'sortOrder'       => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveMunicipalZone($validated, $this->actorId());

            return ['message' => $res->Message ?? 'منطقهٔ شهرداری ذخیره شد.', 'municipalZoneId' => (int) $res->MunicipalZoneID];
        });
    }

    public function municipalZonesToggle(int $municipalZoneId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($municipalZoneId) {
            $res = $this->masterData->toggleMunicipalZoneActive($municipalZoneId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ منطقهٔ شهرداری تغییر کرد.'];
        });
    }
}
