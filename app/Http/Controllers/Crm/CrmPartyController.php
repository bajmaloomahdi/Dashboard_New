<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'brandCategories' => $this->parties->listPartyBrandCategories($partyId),
            'addresses' => $this->parties->listAddresses($partyId),
            'contacts' => $this->parties->listContacts($partyId),
            'relations' => $this->parties->listRelations($partyId),
            'classifications' => $this->parties->listClassifications($partyId),
            'interactions' => $this->parties->listInteractions($partyId),
            'users' => collect(DB::select('EXEC sp_GetUsers @SearchText = NULL, @IsActive = 1'))
                ->map(fn ($u) => ['UserID' => (int) $u->UserID, 'FullName' => $u->FullName])->values()->all(),
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
            // Map Keyِ نقشهٔ وبِ نشان — فقط از .env، فقط برایِ این صفحه (فرمِ آدرسِ طرف‌حساب)
            'neshanMapKey' => config('services.neshan.map_key'),
            // فقط وجودِ کلیدِ Service (نه خودِ کلید) — تا UI بداند جست‌وجویِ محل (Geocoding) در دسترس است
            'neshanSearchEnabled' => (string) config('services.neshan.service_key') !== '',
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

    /* ---------- تعریفِ برند برایِ طرف‌حساب (CrmPartyBrandCategories + ارتباطِ داخلیِ CrmPartyBrands) — تنها مسیرِ ثبت ---------- */

    /** GET crm/party-brand-categories?partyId= */
    public function brandCategoriesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM_VIEW);
        $validated = $request->validate(['partyId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->parties->listPartyBrandCategories($validated['partyId'])]);
    }

    /** POST crm/party-brand-categories — ایجاد یا ویرایشِ کاملِ ردیف (دسته/برند/درصد/تاریخ‌ها). */
    public function brandCategoriesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'partyBrandCategoryId' => 'nullable|integer|exists:CrmPartyBrandCategories,PartyBrandCategoryID',
            'partyId' => 'nullable|integer|exists:CrmParties,PartyID',
            'brandId' => 'nullable|integer|exists:CrmBrands,BrandID',
            'productCategoryId' => 'nullable|integer|exists:CrmProductCategories,ProductCategoryID',
            'entryDate' => 'nullable|date',
            'exitDate' => 'nullable|date',
            'sharePercent' => 'nullable',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->savePartyBrandCategory($validated, $this->actorId());

            return ['message' => $res->Message ?? 'ذخیره شد.', 'partyBrandCategoryId' => (int) $res->PartyBrandCategoryID];
        });
    }

    public function brandCategoriesToggle(int $partyBrandCategoryId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($partyBrandCategoryId) {
            $res = $this->parties->togglePartyBrandCategoryActive($partyBrandCategoryId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ ردیف تغییر کرد.'];
        });
    }
}
