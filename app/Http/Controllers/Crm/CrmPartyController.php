<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use App\Services\Crm\CrmPartyService;
use App\Services\Crm\Exceptions\CrmValidationException;
use App\Services\Crm\Support\CrmPartyImageFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            'interactionTypes' => $this->masterData->listInteractionTypes(null, true),
            'partyImages' => $this->parties->listPartyImages($partyId),
            'supplementaryInfo' => $this->parties->getSupplementaryInfo($partyId),
            // فقط نوع‌هایِ فعال برایِ انتخابِ جدید؛ نوعِ غیرفعالی که همین حالا روی طرف‌حساب ثبت است از خودِ supplementaryInfo می‌آید
            'ownershipTypes' => $this->masterData->listOwnershipTypes(null, true),
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

    /* ---------- تصاویرِ طرف‌حساب (CrmPartyImages — تبِ «ضمائم و سایر ویژگی‌ها») ---------- */

    /** GET crm/parties/{partyId}/images — فهرستِ تصاویرِ فعال (برایِ Reload بعدِ Upload/Delete). */
    public function imagesIndex(int $partyId)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        return $this->runCrm(fn () => ['items' => $this->parties->listPartyImages($partyId)]);
    }

    /** POST crm/parties/{partyId}/images — multipart: images[] + descriptions[] (هم‌اندیس، هردو اختیاری‌بودنِ توضیح). */
    public function imagesStore(Request $request, int $partyId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($request, $partyId) {
            $files = $request->file('images');
            $descriptions = $request->input('descriptions', []);
            if (! is_array($files)) {
                throw new CrmValidationException('هیچ تصویری برایِ افزودن انتخاب نشده است.');
            }
            if (! is_array($descriptions)) {
                throw new CrmValidationException('توضیحاتِ تصویر نامعتبر است.');
            }

            $count = $this->parties->addPartyImages($partyId, $files, $descriptions, $this->actorId());

            return ['message' => $count > 1 ? "{$count} تصویر ثبت شد." : 'تصویر ثبت شد.'];
        });
    }

    /** POST crm/party-images/{imageId}/delete — حذفِ منطقی (IsActive=0)؛ فایلِ فیزیکی باقی می‌ماند. */
    public function imagesDestroy(int $imageId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($imageId) {
            $res = $this->parties->deletePartyImage($imageId, $this->actorId());

            return ['message' => $res->Message ?? 'تصویر حذف شد.'];
        });
    }

    /**
     * POST crm/party-images/{imageId}/description — ویرایشِ فقط توضیحِ یک تصویر. هیچ شناسهٔ Party/تصویرِ
     * دیگری از بدنهٔ درخواست پذیرفته نمی‌شود — تصویرِ هدف همیشه دقیقاً همان imageIdِ Route است.
     */
    public function imagesUpdateDescription(Request $request, int $imageId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'description' => 'nullable|string|max:500',
        ]);

        return $this->runCrm(function () use ($validated, $imageId) {
            $res = $this->parties->updatePartyImageDescription($imageId, $validated['description'] ?? null, $this->actorId());

            return ['message' => $res->Message ?? 'توضیحاتِ تصویر به‌روزرسانی شد.'];
        });
    }

    /* ---------- اطلاعاتِ تکمیلیِ طرف‌حساب (CrmPartySupplementaryInfo — تبِ «ضمائم و سایر ویژگی‌ها») ---------- */

    /** GET crm/parties/{partyId}/supplementary-info — item: null یعنی هنوز چیزی ثبت نشده است. */
    public function supplementaryInfoShow(int $partyId)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        return $this->runCrm(fn () => ['item' => $this->parties->getSupplementaryInfo($partyId)]);
    }

    /** POST crm/parties/{partyId}/supplementary-info — Upsert؛ PartyID فقط از Route (نه از بدنهٔ درخواست). */
    public function supplementaryInfoStore(Request $request, int $partyId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'ownershipTypeId' => 'nullable|integer|exists:CrmOwnershipTypes,OwnershipTypeID',
            'areaSqm'         => 'bail|nullable|numeric|min:0|max:9999999999.99|decimal:0,2',
        ], [
            'areaSqm.numeric' => 'متراژ باید عدد باشد.',
            'areaSqm.min'     => 'متراژ نمی‌تواند منفی باشد.',
            'areaSqm.max'     => 'متراژ بیش از حدِ مجاز است.',
            'areaSqm.decimal' => 'متراژ حداکثر دو رقمِ اعشار می‌پذیرد.',
            'ownershipTypeId.exists' => 'نوعِ مالکیت یافت نشد.',
        ]);

        return $this->runCrm(function () use ($validated, $partyId) {
            $res = $this->parties->saveSupplementaryInfo($partyId, $validated, $this->actorId());

            return [
                'message' => $res->Message ?? 'اطلاعاتِ تکمیلی ذخیره شد.',
                'item' => $this->parties->getSupplementaryInfo($partyId),
            ];
        });
    }

    /**
     * GET crm/party-images/{imageId} — استریمِ تصویر از Private Disk (بدونِ Public Storage، بدونِ
     * افشایِ مسیر). دستکاریِ imageId فقط ردیفِ همان شناسهٔ فعال را برمی‌گرداند؛ ردیفِ حذف‌شده
     * (IsActive=0) یا ناموجود → 404 (نه خطایِ عمومی‌تر که وجودِ آن را لو بدهد).
     */
    public function imageShow(int $imageId)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $file = $this->parties->getPartyImageFile($imageId);
        abort_if(! $file, 404);

        return Storage::disk(CrmPartyImageFiles::DISK)->response($file['path'], null, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
