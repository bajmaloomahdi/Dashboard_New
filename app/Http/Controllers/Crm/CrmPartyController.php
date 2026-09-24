<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * طرف‌حساب (CrmParties): فهرست (کارت)، ایجاد/ویرایش، فعال/غیرفعال‌سازی.
 */
class CrmPartyController extends CrmApiController
{
    private const PERM_VIEW = 'CRM_VIEW';
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(private CrmPartyService $parties, private CrmMasterDataService $masterData)
    {
    }

    /** GET crm/parties — صفحهٔ Inertia (کارت‌گرید، هم‌الگو با Projects/WorkflowDefinitions). */
    public function page(Request $request)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $validated = $request->validate([
            'search' => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
            'partyNature' => 'nullable|string|in:INDIVIDUAL,LEGAL',
        ]);

        return Inertia::render('Crm/Parties/Index', [
            'parties' => $this->parties->listParties(
                $validated['search'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
                null,
                $validated['partyNature'] ?? null,
            ),
            'filters' => $validated,
            'canManage' => $this->userCan(self::PERM_MANAGE),
        ]);
    }

    /** GET crm/parties/{partyId} — صفحهٔ جزئیات با تب‌هایِ برند/آدرس/تماس/مخاطبین. */
    public function show(int $partyId)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $party = $this->parties->getParty($partyId);
        abort_if(! $party, 404, 'طرف‌حساب یافت نشد.');

        return Inertia::render('Crm/Parties/Show', [
            'party' => $party,
            'brands' => $this->parties->listBrands($partyId),
            'addresses' => $this->parties->listAddresses($partyId),
            'contacts' => $this->parties->listContacts($partyId),
            'relations' => $this->parties->listRelations($partyId),
            'addressTitles' => $this->masterData->listAddressTitles(null, true),
            'provinces' => $this->masterData->listProvinces(null, true),
            'contactTypes' => $this->masterData->listContactTypes(null, true),
            'positions' => $this->masterData->listPositions(null, true),
            'contactRoles' => $this->masterData->listContactRoles(null, true),
            'titles' => $this->masterData->listTitles(null, true),
            'departments' => $this->masterData->listDepartments(null, true),
            'partyTypes' => $this->masterData->listPartyTypes(null, true),
            'activities' => $this->masterData->listActivities(null, null, true),
            'canManage' => $this->userCan(self::PERM_MANAGE),
        ]);
    }

    /** GET crm/parties (JSON) — برایِ Reload سمتِ کلاینت بعد از عملیات. */
    public function index(Request $request)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $validated = $request->validate([
            'search' => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
            'partyNature' => 'nullable|string|in:INDIVIDUAL,LEGAL',
        ]);

        return $this->runCrm(fn () => ['items' => $this->parties->listParties(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
            null,
            $validated['partyNature'] ?? null,
        )]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'partyId' => 'nullable|integer|exists:CrmParties,PartyID',
            'partyNature' => 'nullable|string|max:20',
            'officialName' => 'nullable|string|max:200',
            'tradeName' => 'nullable|string|max:200',
            'registrationNumber' => 'nullable|string|max:50',
            'economicCode' => 'nullable|string|max:50',
            'identifierNumber' => 'nullable|string|max:20',
            'identifierDate' => 'nullable|date',
            'description' => 'nullable|string',
            'departmentId' => 'nullable|integer|exists:CrmDepartments,DepartmentID',
            'partyTypeId' => 'nullable|integer|exists:CrmPartyTypes,PartyTypeID',
            'activityId' => 'nullable|integer|exists:CrmActivities,ActivityID',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->saveParty($validated, $this->actorId());

            return ['message' => $res->Message ?? 'طرف‌حساب ذخیره شد.', 'partyId' => (int) $res->PartyID];
        });
    }

    public function toggleActive(int $partyId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($partyId) {
            $res = $this->parties->togglePartyActive($partyId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ طرف‌حساب تغییر کرد.'];
        });
    }
}
