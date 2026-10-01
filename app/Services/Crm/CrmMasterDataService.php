<?php

namespace App\Services\Crm;

use App\Services\Crm\Exceptions\CrmValidationException;
use App\Services\Crm\Support\CrmStore;

/**
 * منطقِ کسب‌وکارِ Master Dataهایِ CRM (۱۲ Registry): دپارتمان، نوع، فعالیت،
 * عنوانِ فرد، سمت، نقش، نوعِ تماس، عنوانِ آدرس، استان، شهر، شهرستان، منطقهٔ
 * شهرداری. هر Registry سه عملیاتِ list/save/toggle دارد و مستقیماً به
 * CrmStore واگذار می‌شود — هم‌الگو با WorkflowDefinitionService::listCategories/
 * saveCategory/toggleCategoryActive.
 */
class CrmMasterDataService
{
    public function __construct(private CrmStore $store)
    {
    }

    /* ---------- دپارتمان ---------- */

    public function listDepartments(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getDepartments($search, $isActive);
    }

    public function saveDepartment(array $input, int $userId): object
    {
        return $this->store->saveDepartment([
            'departmentId' => $input['departmentId'] ?? null,
            'displayName'  => trim($input['displayName'] ?? ''),
            'sortOrder'    => $input['sortOrder'] ?? 0,
            'userId'       => $userId,
        ]);
    }

    public function toggleDepartmentActive(int $departmentId, int $userId): object
    {
        return $this->store->toggleDepartmentActive($departmentId, $userId);
    }

    /* ---------- نوع ---------- */

    public function listPartyTypes(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getPartyTypes($search, $isActive);
    }

    public function savePartyType(array $input, int $userId): object
    {
        return $this->store->savePartyType([
            'partyTypeId' => $input['partyTypeId'] ?? null,
            'displayName' => trim($input['displayName'] ?? ''),
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function togglePartyTypeActive(int $partyTypeId, int $userId): object
    {
        return $this->store->togglePartyTypeActive($partyTypeId, $userId);
    }

    /* ---------- فعالیت (زیرمجموعهٔ نوع) ---------- */

    public function listActivities(?int $partyTypeId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getActivities($partyTypeId, $search, $isActive);
    }

    public function saveActivity(array $input, int $userId): object
    {
        return $this->store->saveActivity([
            'activityId'  => $input['activityId'] ?? null,
            'partyTypeId' => $input['partyTypeId'] ?? null,
            'displayName' => trim($input['displayName'] ?? ''),
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function toggleActivityActive(int $activityId, int $userId): object
    {
        return $this->store->toggleActivityActive($activityId, $userId);
    }

    /* ---------- عنوانِ فرد ---------- */

    public function listTitles(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getTitles($search, $isActive);
    }

    public function saveTitle(array $input, int $userId): object
    {
        return $this->store->saveTitle([
            'titleId'     => $input['titleId'] ?? null,
            'displayName' => trim($input['displayName'] ?? ''),
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function toggleTitleActive(int $titleId, int $userId): object
    {
        return $this->store->toggleTitleActive($titleId, $userId);
    }

    /* ---------- سمت ---------- */

    public function listPositions(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getPositions($search, $isActive);
    }

    public function savePosition(array $input, int $userId): object
    {
        return $this->store->savePosition([
            'positionId'  => $input['positionId'] ?? null,
            'displayName' => trim($input['displayName'] ?? ''),
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function togglePositionActive(int $positionId, int $userId): object
    {
        return $this->store->togglePositionActive($positionId, $userId);
    }

    /* ---------- نقش ---------- */

    public function listContactRoles(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getContactRoles($search, $isActive);
    }

    public function saveContactRole(array $input, int $userId): object
    {
        return $this->store->saveContactRole([
            'contactRoleId' => $input['contactRoleId'] ?? null,
            'displayName'   => trim($input['displayName'] ?? ''),
            'sortOrder'     => $input['sortOrder'] ?? 0,
            'userId'        => $userId,
        ]);
    }

    public function toggleContactRoleActive(int $contactRoleId, int $userId): object
    {
        return $this->store->toggleContactRoleActive($contactRoleId, $userId);
    }

    /* ---------- نوعِ تماس ---------- */

    public function listContactTypes(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getContactTypes($search, $isActive);
    }

    public function saveContactType(array $input, int $userId): object
    {
        return $this->store->saveContactType([
            'contactTypeId' => $input['contactTypeId'] ?? null,
            'displayName'   => trim($input['displayName'] ?? ''),
            'sortOrder'     => $input['sortOrder'] ?? 0,
            'userId'        => $userId,
        ]);
    }

    public function toggleContactTypeActive(int $contactTypeId, int $userId): object
    {
        return $this->store->toggleContactTypeActive($contactTypeId, $userId);
    }

    /* ---------- نوعِ تعامل ---------- */

    public function listInteractionTypes(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getInteractionTypes($search, $isActive);
    }

    public function saveInteractionType(array $input, int $userId): object
    {
        return $this->store->saveInteractionType([
            'interactionTypeId' => $input['interactionTypeId'] ?? null,
            'displayName'       => trim($input['displayName'] ?? ''),
            'sortOrder'         => $input['sortOrder'] ?? 0,
            'userId'            => $userId,
        ]);
    }

    public function toggleInteractionTypeActive(int $interactionTypeId, int $userId): object
    {
        return $this->store->toggleInteractionTypeActive($interactionTypeId, $userId);
    }

    /* ---------- عنوانِ آدرس ---------- */

    public function listAddressTitles(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getAddressTitles($search, $isActive);
    }

    public function saveAddressTitle(array $input, int $userId): object
    {
        return $this->store->saveAddressTitle([
            'addressTitleId' => $input['addressTitleId'] ?? null,
            'displayName'    => trim($input['displayName'] ?? ''),
            'sortOrder'      => $input['sortOrder'] ?? 0,
            'userId'         => $userId,
        ]);
    }

    public function toggleAddressTitleActive(int $addressTitleId, int $userId): object
    {
        return $this->store->toggleAddressTitleActive($addressTitleId, $userId);
    }

    /* ---------- استان ---------- */

    public function listProvinces(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getProvinces($search, $isActive);
    }

    public function saveProvince(array $input, int $userId): object
    {
        return $this->store->saveProvince([
            'provinceId'  => $input['provinceId'] ?? null,
            'displayName' => trim($input['displayName'] ?? ''),
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function toggleProvinceActive(int $provinceId, int $userId): object
    {
        return $this->store->toggleProvinceActive($provinceId, $userId);
    }

    /* ---------- شهرستان (زیرمجموعهٔ استان) ---------- */

    public function listCounties(?int $provinceId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getCounties($provinceId, $search, $isActive);
    }

    public function saveCounty(array $input, int $userId): object
    {
        return $this->store->saveCounty([
            'countyId'    => $input['countyId'] ?? null,
            'provinceId'  => $input['provinceId'] ?? null,
            'displayName' => trim($input['displayName'] ?? ''),
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function toggleCountyActive(int $countyId, int $userId): object
    {
        return $this->store->toggleCountyActive($countyId, $userId);
    }

    /* ---------- شهر (زیرمجموعهٔ شهرستان) ---------- */

    public function listCities(?int $countyId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getCities($countyId, $search, $isActive);
    }

    public function saveCity(array $input, int $userId): object
    {
        return $this->store->saveCity([
            'cityId'      => $input['cityId'] ?? null,
            'countyId'    => $input['countyId'] ?? null,
            'displayName' => trim($input['displayName'] ?? ''),
            'sortOrder'   => $input['sortOrder'] ?? 0,
            'userId'      => $userId,
        ]);
    }

    public function toggleCityActive(int $cityId, int $userId): object
    {
        return $this->store->toggleCityActive($cityId, $userId);
    }

    /* ---------- محله (زیرمجموعهٔ شهر) ---------- */

    public function listNeighborhoods(?int $cityId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getNeighborhoods($cityId, $search, $isActive);
    }

    public function saveNeighborhood(array $input, int $userId): object
    {
        return $this->store->saveNeighborhood([
            'neighborhoodId' => $input['neighborhoodId'] ?? null,
            'cityId'         => $input['cityId'] ?? null,
            'displayName'    => trim($input['displayName'] ?? ''),
            'sortOrder'      => $input['sortOrder'] ?? 0,
            'userId'         => $userId,
        ]);
    }

    public function toggleNeighborhoodActive(int $neighborhoodId, int $userId): object
    {
        return $this->store->toggleNeighborhoodActive($neighborhoodId, $userId);
    }

    /* ---------- منطقهٔ شهرداری ---------- */

    public function listMunicipalZones(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getMunicipalZones($search, $isActive);
    }

    public function saveMunicipalZone(array $input, int $userId): object
    {
        return $this->store->saveMunicipalZone([
            'municipalZoneId' => $input['municipalZoneId'] ?? null,
            'displayName'     => trim($input['displayName'] ?? ''),
            'sortOrder'       => $input['sortOrder'] ?? 0,
            'userId'          => $userId,
        ]);
    }

    public function toggleMunicipalZoneActive(int $municipalZoneId, int $userId): object
    {
        return $this->store->toggleMunicipalZoneActive($municipalZoneId, $userId);
    }

    /* ---------- دسته‌بندیِ محصولات (درختِ نامحدود؛ جلوگیری از حلقه در SP) ---------- */

    public function listProductCategories(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getProductCategories($search, $isActive);
    }

    public function saveProductCategory(array $input, int $userId): object
    {
        $displayName = trim($input['displayName'] ?? '');
        if ($displayName === '') {
            throw new CrmValidationException('نامِ دسته‌بندی الزامی است.');
        }

        return $this->store->saveProductCategory([
            'productCategoryId' => $input['productCategoryId'] ?? null,
            'parentCategoryId'  => $input['parentCategoryId'] ?? null,
            'displayName'       => $displayName,
            'sortOrder'         => $input['sortOrder'] ?? 0,
            'userId'            => $userId,
        ]);
    }

    public function toggleProductCategoryActive(int $productCategoryId, int $userId): object
    {
        return $this->store->toggleProductCategoryActive($productCategoryId, $userId);
    }
}
