<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * شخص/مخاطب (CrmPersons) — مستقل از طرف‌حساب — و رابطهٔ آن با یک طرف‌حساب
 * (CrmPartyPersonRelations + نقش‌هایِ چندگانه در CrmPartyPersonRelationRoles).
 *
 * مدلِ مفهومی: Party ↔ Relationship ↔ Person. یک شخص می‌تواند به چند طرف‌حساب
 * مرتبط باشد؛ سمت روی رابطه تک‌مقداری است، نقش چندمقداری (Multi-Select).
 */
class CrmPersonController extends CrmApiController
{
    private const PERM_VIEW = 'CRM_VIEW';
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(private CrmPartyService $parties, private CrmMasterDataService $masterData)
    {
    }

    /** GET crm/persons-page — صفحهٔ مستقلِ مدیریتِ مخاطبین (فهرست + ایجاد/ویرایش/فعال‌سازی). */
    public function page(Request $request)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $validated = $request->validate([
            'search' => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
        ]);

        return Inertia::render('Crm/Persons/Index', [
            'persons' => $this->parties->listPersons(
                $validated['search'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
            ),
            'titles' => $this->masterData->listTitles(null, true),
            'filters' => $validated,
            'canManage' => $this->userCan(self::PERM_MANAGE),
        ]);
    }

    /** GET crm/persons/{personId} — صفحهٔ جزئیاتِ مخاطب، با تب‌هایِ اطلاعاتِ تماس/آدرس (مستقل از هر طرف‌حساب). */
    public function show(int $personId)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $person = $this->parties->getPerson($personId);
        abort_if(! $person, 404, 'مخاطب یافت نشد.');

        return Inertia::render('Crm/Persons/Show', [
            'person' => $person,
            'contacts' => $this->parties->listContacts(null, $personId),
            'addresses' => $this->parties->listAddresses(null, $personId),
            'relations' => $this->parties->listRelationsForPerson($personId),
            'addressTitles' => $this->masterData->listAddressTitles(null, true),
            'provinces' => $this->masterData->listProvinces(null, true),
            'contactTypes' => $this->masterData->listContactTypes(null, true),
            'titles' => $this->masterData->listTitles(null, true),
            'canManage' => $this->userCan(self::PERM_MANAGE),
        ]);
    }

    /** GET crm/persons?search= — جست‌وجویِ سراسریِ اشخاص، برایِ انتخاب/جلوگیری از تکرار هنگامِ افزودنِ مخاطب. */
    public function index(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->parties->listPersons(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'personId' => 'nullable|integer|exists:CrmPersons,PersonID',
            'firstName' => 'required|string|max:100',
            'lastName' => 'required|string|max:100',
            'titleId' => 'nullable|integer|exists:CrmTitles,TitleID',
            'identifierNumber' => 'nullable|string|max:20',
            'identifierDate' => 'nullable|date',
            'description' => 'nullable|string',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->savePerson($validated, $this->actorId());

            return ['message' => $res->Message ?? 'شخص ذخیره شد.', 'personId' => (int) $res->PersonID];
        });
    }

    public function toggleActive(int $personId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($personId) {
            $res = $this->parties->togglePersonActive($personId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ شخص تغییر کرد.'];
        });
    }

    /* ---------- رابطهٔ طرف‌حساب ↔ شخص ---------- */

    /** GET crm/relations?partyId= */
    public function relationsIndex(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['partyId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->parties->listRelations($validated['partyId'])]);
    }

    /** GET crm/relations/{relationId}/roles — نقش‌هایِ فعلی، برایِ پیش‌پرکردنِ فرمِ ویرایش. */
    public function relationRoles(int $relationId)
    {
        $this->authorizeCrm('CRM_VIEW');

        return $this->runCrm(fn () => ['roleIds' => $this->parties->getRelationRoleIds($relationId)]);
    }

    /** POST crm/relations — ایجاد/ویرایشِ رابطه + جایگزینیِ کاملِ نقش‌ها، در یک تراکنش. */
    public function relationsStore(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'relationId' => 'nullable|integer|exists:CrmPartyPersonRelations,RelationID',
            'partyId' => 'required|integer|exists:CrmParties,PartyID',
            'personId' => 'required|integer|exists:CrmPersons,PersonID',
            'positionId' => 'nullable|integer|exists:CrmPositions,PositionID',
            'isPrimaryContact' => 'nullable|boolean',
            'roleIds' => 'nullable|array',
            'roleIds.*' => 'integer|exists:CrmContactRoles,ContactRoleID',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->saveRelationWithRoles($validated, $validated['roleIds'] ?? [], $this->actorId());

            return ['message' => $res->Message ?? 'رابطه ذخیره شد.', 'relationId' => (int) $res->RelationID];
        });
    }

    public function relationsToggle(int $relationId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($relationId) {
            $res = $this->parties->toggleRelationActive($relationId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ رابطه تغییر کرد.'];
        });
    }
}
