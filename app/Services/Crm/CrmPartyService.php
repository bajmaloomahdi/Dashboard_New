<?php

namespace App\Services\Crm;

use App\Services\Crm\Exceptions\CrmValidationException;
use App\Services\Crm\Support\CrmBrandLogo;
use App\Services\Crm\Support\CrmStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * منطقِ کسب‌وکارِ طرف‌حساب و موجودیت‌هایِ وابسته (برند/آدرس/تماس/شخص/رابطه).
 *
 * طرف‌حساب یک نهادِ حقیقی یا حقوقی است که رابطهٔ کاری/تجاری با ما دارد —
 * ماهیت (PartyNature) همان تفکیکِ سطحِ اول است: INDIVIDUAL (فروشگاه/مغازه/
 * صاحبِ‌کسب‌وکارِحقیقی) یا LEGAL (شرکت/سازمان/مؤسسه). دپارتمان/نوع/فعالیت
 * دسته‌بندیِ Master-Dataیِ اضافی‌اند، نه جایگزینِ ماهیت. «مخاطب» (CrmPersons)
 * مفهومی کاملاً جداست: هویتِ افرادِ حقیقی هرگز در طرف‌حساب نگهداری نمی‌شود،
 * فقط از طریقِ رابطهٔ CrmPartyPersonRelations به یک یا چند طرف‌حساب وصل می‌شود.
 */
class CrmPartyService
{
    private const NATURES = ['INDIVIDUAL', 'LEGAL'];

    public function __construct(private CrmStore $store, private CrmBrandLogo $logos)
    {
    }

    /**
     * آدرس/اطلاعاتِ تماس همیشه دقیقاً به یکی از طرف‌حساب یا مخاطب متعلق‌اند
     * (نه هیچ‌کدام، نه هردو) — این قید در PHP بررسی می‌شود تا پیامِ خطا واضح
     * باشد، هرچند سطحِ داده هم با CHECK Constraint محافظت شده است.
     */
    private function assertExactlyOneOwner(array $input): void
    {
        $hasParty = ! empty($input['partyId']);
        $hasPerson = ! empty($input['personId']);
        if ($hasParty === $hasPerson) {
            throw new CrmValidationException('دقیقاً یکی از طرف‌حساب یا مخاطب باید مشخص باشد.');
        }
    }

    /* ---------- طرف‌حساب ---------- */

    public function listParties(
        ?string $search = null,
        ?bool $isActive = null,
        ?int $departmentId = null,
        ?string $partyNature = null,
    ): array {
        return $this->store->getParties($search, $isActive, $departmentId, $partyNature);
    }

    public function getParty(int $partyId): ?object
    {
        return $this->store->getParty($partyId);
    }

    public function saveParty(array $input, int $userId): object
    {
        $nature = strtoupper(trim($input['partyNature'] ?? ''));
        if (! in_array($nature, self::NATURES, true)) {
            throw new CrmValidationException('ماهیتِ طرف‌حساب نامعتبر است.');
        }

        if (trim($input['officialName'] ?? '') === '') {
            throw new CrmValidationException('نام الزامی است.');
        }

        $identifierNumber = null;
        if ($nature === 'LEGAL') {
            $identifierNumber = preg_replace('/\D/', '', (string) ($input['identifierNumber'] ?? ''));
            if (strlen($identifierNumber) !== 11) {
                throw new CrmValidationException('شناسه ملی باید دقیقاً ۱۱ رقم باشد.');
            }
        }

        // برندِ طرف‌حساب اینجا ثبت نمی‌شود؛ فقط از تبِ «برندها»ی جزئیاتِ طرف‌حساب (savePartyBrandCategory).
        return $this->store->saveParty([
            'partyId' => $input['partyId'] ?? null,
            'partyNature' => $nature,
            'officialName' => trim($input['officialName']),
            'tradeName' => trim($input['tradeName'] ?? '') ?: null,
            'registrationNumber' => trim($input['registrationNumber'] ?? '') ?: null,
            'economicCode' => trim($input['economicCode'] ?? '') ?: null,
            'identifierNumber' => $identifierNumber,
            'identifierDate' => $input['identifierDate'] ?? null,
            'description' => $input['description'] ?? null,
            'departmentId' => $input['departmentId'] ?? null,
            'partyTypeId' => $input['partyTypeId'] ?? null,
            'activityId' => $input['activityId'] ?? null,
            'userId' => $userId,
        ]);
    }

    public function togglePartyActive(int $partyId, int $userId): object
    {
        return $this->store->togglePartyActive($partyId, $userId);
    }

    /* ---------- دسته‌بندیِ طرف‌حساب (چندگانه: دپارتمان/نوع/فعالیت) ---------- */

    public function listClassifications(int $partyId): array
    {
        return $this->store->getPartyClassifications($partyId);
    }

    public function saveClassification(array $input, int $userId): object
    {
        return $this->store->savePartyClassification([
            'classificationId' => $input['classificationId'] ?? null,
            'partyId' => $input['partyId'],
            'departmentId' => $input['departmentId'] ?? null,
            'partyTypeId' => $input['partyTypeId'] ?? null,
            'activityId' => $input['activityId'] ?? null,
            'userId' => $userId,
        ]);
    }

    public function toggleClassificationActive(int $classificationId, int $userId): object
    {
        return $this->store->togglePartyClassificationActive($classificationId, $userId);
    }

    /* ---------- تعاملات (تماس/جلسه/یادداشت/پیگیری) ---------- */

    private const INTERACTION_TYPES = ['CALL', 'MEETING', 'NOTE', 'FOLLOWUP'];

    public function listInteractions(?int $partyId = null, ?int $personId = null, ?string $type = null, ?string $status = null): array
    {
        return $this->store->getInteractions($partyId, $personId, $type, $status);
    }

    /**
     * قواعدِ وضعیت هنگامِ ایجاد (در SP هم تکرار شده — دفاعِ دوسویه):
     * NOTE همیشه DONE، FOLLOWUP همیشه PLANNED، CALL/MEETING فقط PLANNED یا DONE.
     * وضعیتِ یک تعاملِ موجود از این مسیر تغییر نمی‌کند (فقط setInteractionStatus).
     */
    public function saveInteraction(array $input, int $userId): object
    {
        $type = strtoupper(trim($input['interactionType'] ?? ''));
        if (! in_array($type, self::INTERACTION_TYPES, true)) {
            throw new CrmValidationException('نوعِ تعامل نامعتبر است.');
        }

        $status = isset($input['status']) ? strtoupper(trim($input['status'])) : null;
        if (empty($input['interactionId'])) {
            if ($type === 'NOTE' && $status !== null && $status !== 'DONE') {
                throw new CrmValidationException('یادداشت همیشه «انجام‌شده» ثبت می‌شود.');
            }
            if ($type === 'FOLLOWUP' && $status !== null && $status !== 'PLANNED') {
                throw new CrmValidationException('پیگیری هنگامِ ثبت باید «برنامه‌ریزی‌شده» باشد.');
            }
            if (in_array($type, ['CALL', 'MEETING'], true) && $status !== null && ! in_array($status, ['PLANNED', 'DONE'], true)) {
                throw new CrmValidationException('تماس/جلسه هنگامِ ثبت فقط می‌تواند «برنامه‌ریزی‌شده» یا «انجام‌شده» باشد.');
            }
        } else {
            $status = null;
        }

        return $this->store->saveInteraction([
            'interactionId' => $input['interactionId'] ?? null,
            'interactionType' => $type,
            'partyId' => $input['partyId'],
            'personId' => $input['personId'] ?? null,
            'subject' => trim($input['subject'] ?? ''),
            'description' => $input['description'] ?? null,
            'outcome' => $input['outcome'] ?? null,
            'interactionDate' => $input['interactionDate'] ?? null,
            'status' => $status,
            'followUpOfId' => $input['followUpOfId'] ?? null,
            'ownerUserId' => $input['ownerUserId'] ?? null,
            'userId' => $userId,
        ]);
    }

    public function setInteractionStatus(int $interactionId, string $status, int $userId): object
    {
        $status = strtoupper(trim($status));
        if (! in_array($status, ['DONE', 'CANCELED'], true)) {
            throw new CrmValidationException('وضعیتِ مقصد فقط می‌تواند «انجام‌شده» یا «لغوشده» باشد.');
        }

        return $this->store->setInteractionStatus($interactionId, $status, $userId);
    }

    public function toggleInteractionActive(int $interactionId, int $userId): object
    {
        return $this->store->toggleInteractionActive($interactionId, $userId);
    }

    /* ---------- برند (موجودیتِ مستقل) ---------- */

    public function listBrands(?string $search = null, ?bool $isActive = null): array
    {
        return array_map(fn (object $row) => $this->logos->present($row), $this->store->getBrands($search, $isActive));
    }

    public function getBrand(int $brandId): ?object
    {
        $brand = $this->store->getBrand($brandId);

        return $brand ? $this->logos->present($brand) : null;
    }

    /** مسیرِ داخلیِ لوگو فقط برایِ Routeِ استریم — هرگز به Frontend داده نمی‌شود. @return array{path: string, mime: string}|null */
    public function getBrandLogoFile(int $brandId): ?array
    {
        $brand = $this->store->getBrand($brandId);
        if (! $brand || empty($brand->LogoPath) || ! $this->logos->exists($brand->LogoPath)) {
            return null;
        }

        return ['path' => $brand->LogoPath, 'mime' => $brand->LogoMimeType];
    }

    /**
     * لوگو: فایلِ جدید اول رویِ دیسک ذخیره می‌شود، بعد SP؛ اگر SP خطا بدهد فایلِ جدید پاک
     * و فایلِ قبلی دست‌نخورده می‌ماند. بعد از ذخیرهٔ موفق، فایلِ قبلی (OldLogoPath) حذف می‌شود.
     */
    public function saveBrand(array $input, int $userId, ?UploadedFile $logo = null, bool $removeLogo = false): object
    {
        $name = trim($input['name'] ?? '');
        if ($name === '') {
            throw new CrmValidationException('نامِ برند الزامی است.');
        }

        $stored = $logo ? $this->logos->store($logo) : null;
        $setLogo = $stored !== null || $removeLogo;

        try {
            $result = $this->store->saveBrand([
                'brandId' => $input['brandId'] ?? null,
                'name' => $name,
                'description' => trim($input['description'] ?? '') ?: null,
                'userId' => $userId,
                'setLogo' => $setLogo,
                'logoPath' => $stored['path'] ?? null,
                'logoMimeType' => $stored['mime'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->logos->delete($stored['path'] ?? null);
            throw $e;
        }

        $oldPath = $result->OldLogoPath ?? null;
        if ($setLogo && $oldPath && $oldPath !== ($stored['path'] ?? null)) {
            $this->logos->delete($oldPath);
        }
        unset($result->OldLogoPath);

        return $result;
    }

    public function toggleBrandActive(int $brandId, int $userId): object
    {
        return $this->store->toggleBrandActive($brandId, $userId);
    }

    /** طرف‌حساب‌هایی که حداقل یک ردیفِ فعال با این برند دارند (صفحهٔ برند — فقط نمایش). */
    public function listBrandParties(int $brandId): array
    {
        return $this->store->getBrandParties($brandId);
    }

    /* ---------- تعریفِ برند برایِ طرف‌حساب (CrmPartyBrandCategories؛ ارتباطِ داخلیِ CrmPartyBrands خودکار) ---------- */

    public function listPartyBrandCategories(int $partyId): array
    {
        return $this->store->getPartyBrandCategories($partyId, null);
    }

    /**
     * برند اجباری؛ دسته و تاریخ‌ها اختیاری؛ درصد اجباری با پیش‌فرض ۰. در ویرایش هر پنج مقدار قابلِ تغییر است.
     * اعتبارسنجیِ خوانا در PHP؛ یکتاییِ فعال (Party+Brand+Category، با NULL) و سقفِ ۱۰۰٪ مرجعشان SP است
     * (با sp_getapplock در سطحِ طرف‌حساب، تا درخواست‌هایِ هم‌زمان نتوانند از قواعد عبور کنند).
     */
    public function savePartyBrandCategory(array $input, int $userId): object
    {
        $isEdit = ! empty($input['partyBrandCategoryId']);

        if (! $isEdit && empty($input['partyId'])) {
            throw new CrmValidationException('طرف‌حساب الزامی است.');
        }
        if (empty($input['brandId'])) {
            throw new CrmValidationException('برند الزامی است.');
        }

        $entryDate = ($input['entryDate'] ?? null) ?: null;
        $exitDate = ($input['exitDate'] ?? null) ?: null;
        if ($entryDate && $exitDate && strtotime($exitDate) < strtotime($entryDate)) {
            throw new CrmValidationException('تاریخِ خروج نمی‌تواند قبل از تاریخِ ورود باشد.');
        }

        $percent = $input['sharePercent'] ?? null;
        if ($percent === null || $percent === '') {
            $percent = 0;
        }
        if (! is_numeric($percent)) {
            throw new CrmValidationException('درصد باید عدد باشد.');
        }
        $percent = round((float) $percent, 2);
        if ($percent < 0 || $percent > 100) {
            throw new CrmValidationException('درصد باید بین ۰ تا ۱۰۰ باشد.');
        }

        return $this->store->savePartyBrandCategory([
            'partyBrandCategoryId' => $input['partyBrandCategoryId'] ?? null,
            'partyId' => $isEdit ? null : $input['partyId'],
            'brandId' => (int) $input['brandId'],
            'productCategoryId' => ($input['productCategoryId'] ?? null) ?: null,
            'entryDate' => $entryDate,
            'exitDate' => $exitDate,
            'sharePercent' => $percent,
            'userId' => $userId,
        ]);
    }

    public function togglePartyBrandCategoryActive(int $partyBrandCategoryId, int $userId): object
    {
        return $this->store->togglePartyBrandCategoryActive($partyBrandCategoryId, $userId);
    }

    /* ---------- آدرس ---------- */

    public function listAddresses(?int $partyId = null, ?int $personId = null): array
    {
        return $this->store->getAddresses($partyId, $personId);
    }

    public function saveAddress(array $input, int $userId): object
    {
        $this->assertExactlyOneOwner($input);
        [$latitude, $longitude] = $this->normalizeCoordinates($input['latitude'] ?? null, $input['longitude'] ?? null);

        return $this->store->saveAddress([
            'addressId' => $input['addressId'] ?? null,
            'partyId' => $input['partyId'] ?? null,
            'personId' => $input['personId'] ?? null,
            'addressTitleId' => $input['addressTitleId'],
            'provinceId' => $input['provinceId'],
            'countyId' => $input['countyId'],
            'cityId' => $input['cityId'],
            'neighborhoodId' => $input['neighborhoodId'] ?? null,
            'municipalZoneId' => $input['municipalZoneId'] ?? null,
            'postalCode' => $input['postalCode'] ?? null,
            'addressText' => trim($input['addressText'] ?? ''),
            'plateNumber' => $input['plateNumber'] ?? null,
            'unit' => $input['unit'] ?? null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'userId' => $userId,
        ]);
    }

    /**
     * مختصاتِ WGS84 آدرس: هر دو خالی یا هر دو مقدار؛ عرض -90..90 و طول -180..180؛ ۶ رقمِ اعشار (DECIMAL(9,6)).
     * همین قواعد در SP و CHECKهایِ جدول هم هست؛ اینجا فقط برایِ پیامِ خوانا.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function normalizeCoordinates($latitude, $longitude): array
    {
        $latitude = ($latitude === '' ? null : $latitude);
        $longitude = ($longitude === '' ? null : $longitude);

        if ($latitude === null && $longitude === null) {
            return [null, null];
        }
        if ($latitude === null || $longitude === null) {
            throw new CrmValidationException('عرض و طولِ جغرافیایی باید با هم ثبت یا با هم حذف شوند.');
        }
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw new CrmValidationException('مختصاتِ جغرافیایی باید عددی باشد.');
        }

        $latitude = round((float) $latitude, 6);
        $longitude = round((float) $longitude, 6);
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new CrmValidationException('مختصاتِ جغرافیایی خارج از محدودهٔ معتبر است.');
        }

        return [$latitude, $longitude];
    }

    public function toggleAddressActive(int $addressId, int $userId): object
    {
        return $this->store->toggleAddressActive($addressId, $userId);
    }

    /* ---------- اطلاعاتِ تماس ---------- */

    public function listContacts(?int $partyId = null, ?int $personId = null): array
    {
        return $this->store->getContacts($partyId, $personId);
    }

    public function saveContact(array $input, int $userId): object
    {
        $this->assertExactlyOneOwner($input);

        return $this->store->saveContact([
            'contactId' => $input['contactId'] ?? null,
            'partyId' => $input['partyId'] ?? null,
            'personId' => $input['personId'] ?? null,
            'contactTypeId' => $input['contactTypeId'],
            'contactValue' => trim($input['contactValue'] ?? ''),
            'extension' => $input['extension'] ?? null,
            'description' => $input['description'] ?? null,
            'isPrimary' => (int) ($input['isPrimary'] ?? 0),
            'relatedPersonId' => $input['relatedPersonId'] ?? null,
            'userId' => $userId,
        ]);
    }

    public function toggleContactActive(int $contactId, int $userId): object
    {
        return $this->store->toggleContactActive($contactId, $userId);
    }

    /* ---------- شخص ---------- */

    public function listPersons(?string $search = null, ?bool $isActive = null): array
    {
        return $this->store->getPersons($search, $isActive);
    }

    public function getPerson(int $personId): ?object
    {
        return $this->store->getPerson($personId);
    }

    public function savePerson(array $input, int $userId): object
    {
        $identifierNumber = trim($input['identifierNumber'] ?? '') !== ''
            ? preg_replace('/\D/', '', (string) $input['identifierNumber'])
            : null;
        if ($identifierNumber !== null && ! preg_match('/^\d{10}$/', $identifierNumber)) {
            throw new CrmValidationException('کد ملی باید دقیقاً ۱۰ رقم باشد.');
        }

        return $this->store->savePerson([
            'personId' => $input['personId'] ?? null,
            'firstName' => trim($input['firstName'] ?? ''),
            'lastName' => trim($input['lastName'] ?? ''),
            'titleId' => $input['titleId'] ?? null,
            'identifierNumber' => $identifierNumber,
            'identifierDate' => $input['identifierDate'] ?? null,
            'description' => $input['description'] ?? null,
            'userId' => $userId,
        ]);
    }

    public function togglePersonActive(int $personId, int $userId): object
    {
        return $this->store->togglePersonActive($personId, $userId);
    }

    /* ---------- رابطهٔ طرف‌حساب↔شخص ---------- */

    public function listRelations(int $partyId): array
    {
        return $this->store->getPartyRelations($partyId);
    }

    /** رابطه‌هایِ یک شخص با طرف‌حساب‌ها — نمایشِ دوطرفه از صفحهٔ جزئیاتِ مخاطب. */
    public function listRelationsForPerson(int $personId): array
    {
        return $this->store->getPersonRelations($personId);
    }

    /** @return int[] */
    public function getRelationRoleIds(int $relationId): array
    {
        return $this->store->getRelationRoleIds($relationId);
    }

    /**
     * ذخیرهٔ رابطه + جایگزینیِ کاملِ نقش‌ها در یک تراکنش — چون UI این دو را
     * یک عملیاتِ واحد می‌بیند (فرمِ «مخاطب» با چندین نقشِ هم‌زمان).
     *
     * @param  int[]  $roleIds
     */
    public function saveRelationWithRoles(array $input, array $roleIds, int $userId): object
    {
        return DB::transaction(function () use ($input, $roleIds, $userId) {
            $res = $this->store->saveRelation([
                'relationId' => $input['relationId'] ?? null,
                'partyId' => $input['partyId'],
                'personId' => $input['personId'],
                'positionId' => $input['positionId'] ?? null,
                'isPrimaryContact' => (int) ($input['isPrimaryContact'] ?? 0),
                'userId' => $userId,
            ]);

            $this->store->saveRelationRoles((int) $res->RelationID, $roleIds, $userId);

            return $res;
        });
    }

    public function toggleRelationActive(int $relationId, int $userId): object
    {
        return $this->store->toggleRelationActive($relationId, $userId);
    }
}
