<?php

namespace App\Services\Crm;

use App\Services\Crm\Exceptions\CrmValidationException;
use App\Services\Crm\Support\CrmStore;
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

    public function __construct(private CrmStore $store)
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

    /* ---------- برند ---------- */

    public function listBrands(int $partyId): array
    {
        return $this->store->getBrands($partyId);
    }

    public function saveBrand(array $input, int $userId): object
    {
        return $this->store->saveBrand([
            'brandId' => $input['brandId'] ?? null,
            'partyId' => $input['partyId'],
            'name' => trim($input['name'] ?? ''),
            'description' => $input['description'] ?? null,
            'userId' => $userId,
        ]);
    }

    public function toggleBrandActive(int $brandId, int $userId): object
    {
        return $this->store->toggleBrandActive($brandId, $userId);
    }

    /* ---------- آدرس ---------- */

    public function listAddresses(?int $partyId = null, ?int $personId = null): array
    {
        return $this->store->getAddresses($partyId, $personId);
    }

    public function saveAddress(array $input, int $userId): object
    {
        $this->assertExactlyOneOwner($input);

        return $this->store->saveAddress([
            'addressId' => $input['addressId'] ?? null,
            'partyId' => $input['partyId'] ?? null,
            'personId' => $input['personId'] ?? null,
            'addressTitleId' => $input['addressTitleId'],
            'provinceId' => $input['provinceId'],
            'cityId' => $input['cityId'],
            'countyId' => $input['countyId'],
            'municipalZoneId' => $input['municipalZoneId'] ?? null,
            'postalCode' => $input['postalCode'] ?? null,
            'addressText' => trim($input['addressText'] ?? ''),
            'plateNumber' => $input['plateNumber'] ?? null,
            'unit' => $input['unit'] ?? null,
            'userId' => $userId,
        ]);
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
