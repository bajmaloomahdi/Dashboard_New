<?php

namespace App\Services\Crm\Support;

use App\Services\Crm\Exceptions\CrmException;
use Illuminate\Support\Facades\DB;

/**
 * تنها نقطهٔ تماسِ ماژولِ CRM با پایگاه‌داده.
 *
 * همهٔ فراخوانی‌ها از طریقِ رویه‌های dbo.sp_Crm_* انجام می‌شود (طبقِ معماریِ
 * SP-Driven پروژه، هم‌الگو با WorkflowStore). قراردادِ خروجیِ رویه‌های نوشتنی
 * «SELECT ... AS Success, ... AS Message» است؛ این کلاس آن را به آرایه/استثنا
 * ترجمه می‌کند.
 */
class CrmStore
{
    /* ---------- دپارتمان (CrmDepartments) ---------- */

    public function getDepartments(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetDepartments @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveDepartment(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveDepartment @DepartmentID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['departmentId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleDepartmentActive(int $departmentId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleDepartmentActive @DepartmentID = ?, @UserID = ?', [$departmentId, $userId]);
    }

    /* ---------- نوع (CrmPartyTypes) ---------- */

    public function getPartyTypes(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetPartyTypes @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function savePartyType(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SavePartyType @PartyTypeID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['partyTypeId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function togglePartyTypeActive(int $partyTypeId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_TogglePartyTypeActive @PartyTypeID = ?, @UserID = ?', [$partyTypeId, $userId]);
    }

    /* ---------- فعالیت (CrmActivities) — زیرمجموعهٔ نوع ---------- */

    public function getActivities(?int $partyTypeId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return DB::select(
            'EXEC dbo.sp_Crm_GetActivities @PartyTypeID = ?, @SearchText = ?, @IsActive = ?',
            [$partyTypeId, $search, $isActive]
        );
    }

    public function saveActivity(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveActivity @ActivityID = ?, @PartyTypeID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['activityId'] ?? null, $p['partyTypeId'], $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleActivityActive(int $activityId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleActivityActive @ActivityID = ?, @UserID = ?', [$activityId, $userId]);
    }

    /* ---------- عنوانِ فرد (CrmTitles) ---------- */

    public function getTitles(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetTitles @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveTitle(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveTitle @TitleID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['titleId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleTitleActive(int $titleId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleTitleActive @TitleID = ?, @UserID = ?', [$titleId, $userId]);
    }

    /* ---------- سمت (CrmPositions) ---------- */

    public function getPositions(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetPositions @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function savePosition(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SavePosition @PositionID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['positionId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function togglePositionActive(int $positionId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_TogglePositionActive @PositionID = ?, @UserID = ?', [$positionId, $userId]);
    }

    /* ---------- نقش (CrmContactRoles) ---------- */

    public function getContactRoles(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetContactRoles @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveContactRole(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveContactRole @ContactRoleID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['contactRoleId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleContactRoleActive(int $contactRoleId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleContactRoleActive @ContactRoleID = ?, @UserID = ?', [$contactRoleId, $userId]);
    }

    /* ---------- نوعِ تماس (CrmContactTypes) ---------- */

    public function getContactTypes(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetContactTypes @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveContactType(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveContactType @ContactTypeID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['contactTypeId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleContactTypeActive(int $contactTypeId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleContactTypeActive @ContactTypeID = ?, @UserID = ?', [$contactTypeId, $userId]);
    }

    /* ---------- عنوانِ آدرس (CrmAddressTitles) ---------- */

    public function getAddressTitles(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetAddressTitles @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveAddressTitle(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveAddressTitle @AddressTitleID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['addressTitleId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleAddressTitleActive(int $addressTitleId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleAddressTitleActive @AddressTitleID = ?, @UserID = ?', [$addressTitleId, $userId]);
    }

    /* ---------- استان (CrmProvinces) ---------- */

    public function getProvinces(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetProvinces @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveProvince(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveProvince @ProvinceID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['provinceId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleProvinceActive(int $provinceId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleProvinceActive @ProvinceID = ?, @UserID = ?', [$provinceId, $userId]);
    }

    /* ---------- شهرستان (CrmCounties) — زیرمجموعهٔ استان ---------- */

    public function getCounties(?int $provinceId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return DB::select(
            'EXEC dbo.sp_Crm_GetCounties @ProvinceID = ?, @SearchText = ?, @IsActive = ?',
            [$provinceId, $search, $isActive]
        );
    }

    public function saveCounty(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveCounty @CountyID = ?, @ProvinceID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['countyId'] ?? null, $p['provinceId'], $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleCountyActive(int $countyId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleCountyActive @CountyID = ?, @UserID = ?', [$countyId, $userId]);
    }

    /* ---------- شهر (CrmCities) — زیرمجموعهٔ شهرستان ---------- */

    public function getCities(?int $countyId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return DB::select(
            'EXEC dbo.sp_Crm_GetCities @CountyID = ?, @SearchText = ?, @IsActive = ?',
            [$countyId, $search, $isActive]
        );
    }

    public function saveCity(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveCity @CityID = ?, @CountyID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['cityId'] ?? null, $p['countyId'], $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleCityActive(int $cityId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleCityActive @CityID = ?, @UserID = ?', [$cityId, $userId]);
    }

    /* ---------- محله (CrmNeighborhoods) — زیرمجموعهٔ شهر ---------- */

    public function getNeighborhoods(?int $cityId = null, ?string $search = null, ?bool $isActive = null): array
    {
        return DB::select(
            'EXEC dbo.sp_Crm_GetNeighborhoods @CityID = ?, @SearchText = ?, @IsActive = ?',
            [$cityId, $search, $isActive]
        );
    }

    public function saveNeighborhood(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveNeighborhood @NeighborhoodID = ?, @CityID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['neighborhoodId'] ?? null, $p['cityId'], $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleNeighborhoodActive(int $neighborhoodId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleNeighborhoodActive @NeighborhoodID = ?, @UserID = ?', [$neighborhoodId, $userId]);
    }

    /* ---------- منطقهٔ شهرداری (CrmMunicipalZones) ---------- */

    public function getMunicipalZones(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetMunicipalZones @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveMunicipalZone(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveMunicipalZone @MunicipalZoneID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['municipalZoneId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleMunicipalZoneActive(int $municipalZoneId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleMunicipalZoneActive @MunicipalZoneID = ?, @UserID = ?', [$municipalZoneId, $userId]);
    }

    /* ---------- طرف‌حساب (CrmParties) ---------- */

    public function getParties(
        ?string $search = null,
        ?bool $isActive = null,
        ?int $departmentId = null,
        ?string $partyNature = null,
    ): array {
        return DB::select(
            'EXEC dbo.sp_Crm_GetParties @SearchText = ?, @IsActive = ?, @DepartmentID = ?, @PartyNature = ?',
            [$search, $isActive, $departmentId, $partyNature]
        );
    }

    public function getParty(int $partyId): ?object
    {
        return DB::selectOne('EXEC dbo.sp_Crm_GetParty @PartyID = ?', [$partyId]);
    }

    public function saveParty(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveParty @PartyID = ?, @PartyNature = ?, @OfficialName = ?, @TradeName = ?, @RegistrationNumber = ?, '
            . '@EconomicCode = ?, @IdentifierNumber = ?, @IdentifierDate = ?, @Description = ?, '
            . '@DepartmentID = ?, @PartyTypeID = ?, @ActivityID = ?, @UserID = ?',
            [
                $p['partyId'] ?? null, $p['partyNature'], $p['officialName'], $p['tradeName'] ?? null, $p['registrationNumber'] ?? null,
                $p['economicCode'] ?? null, $p['identifierNumber'] ?? null, $p['identifierDate'] ?? null, $p['description'] ?? null,
                $p['departmentId'] ?? null, $p['partyTypeId'] ?? null, $p['activityId'] ?? null, $p['userId'],
            ]
        );
    }

    public function togglePartyActive(int $partyId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_TogglePartyActive @PartyID = ?, @UserID = ?', [$partyId, $userId]);
    }

    /* ---------- دسته‌بندیِ طرف‌حساب (CrmPartyClassifications) — چندگانه ---------- */

    public function getPartyClassifications(int $partyId): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetPartyClassifications @PartyID = ?', [$partyId]);
    }

    public function savePartyClassification(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SavePartyClassification @ClassificationID = ?, @PartyID = ?, @DepartmentID = ?, @PartyTypeID = ?, @ActivityID = ?, @UserID = ?',
            [$p['classificationId'] ?? null, $p['partyId'], $p['departmentId'] ?? null, $p['partyTypeId'] ?? null, $p['activityId'] ?? null, $p['userId']]
        );
    }

    public function togglePartyClassificationActive(int $classificationId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_TogglePartyClassificationActive @ClassificationID = ?, @UserID = ?', [$classificationId, $userId]);
    }

    /* ---------- تعاملات (CrmInteractions) ---------- */

    public function getInteractions(?int $partyId = null, ?int $personId = null, ?string $type = null, ?string $status = null): array
    {
        return DB::select(
            'EXEC dbo.sp_Crm_GetInteractions @PartyID = ?, @PersonID = ?, @Type = ?, @Status = ?',
            [$partyId, $personId, $type, $status]
        );
    }

    public function saveInteraction(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveInteraction @InteractionID = ?, @InteractionType = ?, @PartyID = ?, @PersonID = ?, @Subject = ?, '
            . '@Description = ?, @Outcome = ?, @InteractionDate = ?, @Status = ?, @FollowUpOfID = ?, @OwnerUserID = ?, @UserID = ?',
            [
                $p['interactionId'] ?? null, $p['interactionType'], $p['partyId'], $p['personId'] ?? null, $p['subject'],
                $p['description'] ?? null, $p['outcome'] ?? null, $p['interactionDate'], $p['status'] ?? null,
                $p['followUpOfId'] ?? null, $p['ownerUserId'] ?? null, $p['userId'],
            ]
        );
    }

    public function setInteractionStatus(int $interactionId, string $status, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_SetInteractionStatus @InteractionID = ?, @Status = ?, @UserID = ?', [$interactionId, $status, $userId]);
    }

    public function toggleInteractionActive(int $interactionId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleInteractionActive @InteractionID = ?, @UserID = ?', [$interactionId, $userId]);
    }

    /* ---------- برند (CrmBrands) — موجودیتِ مستقل ---------- */

    public function getBrands(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetBrands @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function getBrand(int $brandId): ?object
    {
        return DB::selectOne('EXEC dbo.sp_Crm_GetBrand @BrandID = ?', [$brandId]);
    }

    /** setLogo=true → logoPath/logoMimeType جایگزین می‌شوند (null = حذفِ لوگو)؛ OldLogoPath در پاسخ برمی‌گردد. */
    public function saveBrand(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveBrand @BrandID = ?, @Name = ?, @Description = ?, @UserID = ?, @SetLogo = ?, @LogoPath = ?, @LogoMimeType = ?',
            [
                $p['brandId'] ?? null, $p['name'], $p['description'] ?? null, $p['userId'],
                ! empty($p['setLogo']) ? 1 : 0, $p['logoPath'] ?? null, $p['logoMimeType'] ?? null,
            ]
        );
    }

    public function toggleBrandActive(int $brandId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleBrandActive @BrandID = ?, @UserID = ?', [$brandId, $userId]);
    }

    /* ---------- طرف‌حساب‌هایِ یک برند (بر اساسِ ردیف‌هایِ فعالِ CrmPartyBrandCategories) ---------- */

    public function getBrandParties(int $brandId): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetBrandParties @BrandID = ?', [$brandId]);
    }

    /* ---------- دسته‌بندیِ محصولات (CrmProductCategories) — درختِ نامحدود ---------- */

    public function getProductCategories(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetProductCategories @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function saveProductCategory(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveProductCategory @ProductCategoryID = ?, @ParentCategoryID = ?, @DisplayName = ?, @SortOrder = ?, @UserID = ?',
            [$p['productCategoryId'] ?? null, $p['parentCategoryId'] ?? null, $p['displayName'], $p['sortOrder'] ?? 0, $p['userId']]
        );
    }

    public function toggleProductCategoryActive(int $productCategoryId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleProductCategoryActive @ProductCategoryID = ?, @UserID = ?', [$productCategoryId, $userId]);
    }

    /* ---------- دسته/تاریخ/درصدِ ارتباطِ Party↔Brand (CrmPartyBrandCategories) ---------- */

    public function getPartyBrandCategories(?int $partyId = null, ?int $brandId = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetPartyBrandCategories @PartyID = ?, @BrandID = ?', [$partyId, $brandId]);
    }

    public function savePartyBrandCategory(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SavePartyBrandCategory @PartyBrandCategoryID = ?, @PartyID = ?, @BrandID = ?, @ProductCategoryID = ?, '
            . '@EntryDate = ?, @ExitDate = ?, @SharePercent = ?, @UserID = ?',
            [
                $p['partyBrandCategoryId'] ?? null, $p['partyId'] ?? null, $p['brandId'] ?? null, $p['productCategoryId'] ?? null,
                $p['entryDate'] ?? null, $p['exitDate'] ?? null, $p['sharePercent'] ?? 0, $p['userId'],
            ]
        );
    }

    public function togglePartyBrandCategoryActive(int $partyBrandCategoryId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_TogglePartyBrandCategoryActive @PartyBrandCategoryID = ?, @UserID = ?', [$partyBrandCategoryId, $userId]);
    }

    /* ---------- آدرس (CrmAddresses) ---------- */

    public function getAddresses(?int $partyId = null, ?int $personId = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetAddresses @PartyID = ?, @PersonID = ?', [$partyId, $personId]);
    }

    public function saveAddress(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveAddress @AddressID = ?, @PartyID = ?, @PersonID = ?, @AddressTitleID = ?, @ProvinceID = ?, @CountyID = ?, '
            . '@CityID = ?, @NeighborhoodID = ?, @MunicipalZoneID = ?, @PostalCode = ?, @AddressText = ?, @PlateNumber = ?, @Unit = ?, @UserID = ?, '
            . '@Latitude = ?, @Longitude = ?',
            [
                $p['addressId'] ?? null, $p['partyId'] ?? null, $p['personId'] ?? null, $p['addressTitleId'], $p['provinceId'], $p['countyId'],
                $p['cityId'], $p['neighborhoodId'] ?? null, $p['municipalZoneId'] ?? null, $p['postalCode'] ?? null, $p['addressText'],
                $p['plateNumber'] ?? null, $p['unit'] ?? null, $p['userId'],
                $p['latitude'] ?? null, $p['longitude'] ?? null,
            ]
        );
    }

    public function toggleAddressActive(int $addressId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleAddressActive @AddressID = ?, @UserID = ?', [$addressId, $userId]);
    }

    /* ---------- اطلاعاتِ تماس (CrmContacts) ---------- */

    public function getContacts(?int $partyId = null, ?int $personId = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetContacts @PartyID = ?, @PersonID = ?', [$partyId, $personId]);
    }

    public function saveContact(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveContact @ContactID = ?, @PartyID = ?, @PersonID = ?, @ContactTypeID = ?, @ContactValue = ?, '
            . '@Extension = ?, @Description = ?, @IsPrimary = ?, @RelatedPersonID = ?, @UserID = ?',
            [
                $p['contactId'] ?? null, $p['partyId'] ?? null, $p['personId'] ?? null, $p['contactTypeId'], $p['contactValue'],
                $p['extension'] ?? null, $p['description'] ?? null, $p['isPrimary'] ?? 0, $p['relatedPersonId'] ?? null, $p['userId'],
            ]
        );
    }

    public function toggleContactActive(int $contactId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleContactActive @ContactID = ?, @UserID = ?', [$contactId, $userId]);
    }

    /* ---------- شخص (CrmPersons) ---------- */

    public function getPersons(?string $search = null, ?bool $isActive = null): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetPersons @SearchText = ?, @IsActive = ?', [$search, $isActive]);
    }

    public function getPerson(int $personId): ?object
    {
        return DB::selectOne('EXEC dbo.sp_Crm_GetPerson @PersonID = ?', [$personId]);
    }

    public function savePerson(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SavePerson @PersonID = ?, @FirstName = ?, @LastName = ?, @TitleID = ?, '
            . '@IdentifierNumber = ?, @IdentifierDate = ?, @Description = ?, @UserID = ?',
            [
                $p['personId'] ?? null, $p['firstName'], $p['lastName'], $p['titleId'] ?? null,
                $p['identifierNumber'] ?? null, $p['identifierDate'] ?? null, $p['description'] ?? null, $p['userId'],
            ]
        );
    }

    public function togglePersonActive(int $personId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_TogglePersonActive @PersonID = ?, @UserID = ?', [$personId, $userId]);
    }

    /* ---------- رابطهٔ طرف‌حساب↔شخص (CrmPartyPersonRelations) ---------- */

    public function getPartyRelations(int $partyId): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetPartyRelations @PartyID = ?', [$partyId]);
    }

    /** رابطه‌هایِ یک شخص، از سمتِ مخاطب — نمایشِ دوطرفهٔ Party ↔ Person. */
    public function getPersonRelations(int $personId): array
    {
        return DB::select('EXEC dbo.sp_Crm_GetPersonRelations @PersonID = ?', [$personId]);
    }

    /** @return int[] */
    public function getRelationRoleIds(int $relationId): array
    {
        return collect(DB::select('EXEC dbo.sp_Crm_GetRelationRoleIds @RelationID = ?', [$relationId]))
            ->pluck('RoleID')->map(fn ($v) => (int) $v)->all();
    }

    public function saveRelation(array $p): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveRelation @RelationID = ?, @PartyID = ?, @PersonID = ?, @PositionID = ?, @IsPrimaryContact = ?, @UserID = ?',
            [$p['relationId'] ?? null, $p['partyId'], $p['personId'], $p['positionId'] ?? null, $p['isPrimaryContact'] ?? 0, $p['userId']]
        );
    }

    public function toggleRelationActive(int $relationId, int $userId): object
    {
        return $this->write('EXEC dbo.sp_Crm_ToggleRelationActive @RelationID = ?, @UserID = ?', [$relationId, $userId]);
    }

    /**
     * جایگزینیِ کاملِ مجموعهٔ نقش‌هایِ یک رابطه (Sync، نه Add/Remove جداگانه) —
     * چون UI یک Multi-Select است، نه یک لیستِ Toggleپذیرِ ردیف‌به‌ردیف.
     *
     * @param  int[]  $roleIds
     */
    public function saveRelationRoles(int $relationId, array $roleIds, int $userId): object
    {
        return $this->write(
            'EXEC dbo.sp_Crm_SaveRelationRoles @RelationID = ?, @RoleIDsCsv = ?, @UserID = ?',
            [$relationId, implode(',', $roleIds), $userId]
        );
    }

    /* ================================================================== */

    /** اجرای رویهٔ نوشتنی و بازگرداندنِ ردیفِ اول؛ Success=0 → استثنا. */
    private function write(string $sql, array $bindings): object
    {
        $row = DB::selectOne($sql, $bindings);

        if (! $row) {
            throw new CrmException('رویه هیچ نتیجه‌ای برنگرداند.');
        }

        if (property_exists($row, 'Success') && (int) $row->Success !== 1) {
            throw new CrmException($row->Message ?? 'عملیاتِ پایگاه‌داده ناموفق بود.');
        }

        return $row;
    }
}
