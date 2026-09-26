<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmPartyService;
use App\Services\Crm\Exceptions\CrmValidationException;
use App\Services\Crm\Support\CrmBrandLogo;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

/**
 * برند (CrmBrands) — موجودیتِ مستقلِ CRM، هم‌الگو با مخاطبین. مشاهده با CRM_VIEW، مدیریت با
 * CRM_MANAGE_PARTIES. تعریفِ برند برایِ طرف‌حساب (ارتباطِ داخلیِ CrmPartyBrands + دسته/سهم/تاریخ)
 * فقط از تبِ «برندها»ی جزئیاتِ طرف‌حساب انجام می‌شود (CrmPartyController::brandCategories*)، نه از اینجا.
 */
class CrmBrandController extends CrmApiController
{
    private const PERM_VIEW = 'CRM_VIEW';
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(private CrmPartyService $parties)
    {
    }

    /** GET crm/brands-page — صفحهٔ مستقلِ مدیریتِ برندها (کارت‌گرید + ایجاد/ویرایش/فعال‌سازی). */
    public function page(Request $request)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $validated = $request->validate([
            'search' => 'nullable|string|max:200',
            'isActive' => 'nullable|boolean',
        ]);

        return Inertia::render('Crm/Brands/Index', [
            'brands' => $this->parties->listBrands(
                $validated['search'] ?? null,
                array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
            ),
            'filters' => $validated,
            'canManage' => $this->userCan(self::PERM_MANAGE),
        ]);
    }

    /** GET crm/brands/{brandId} — صفحهٔ جزئیاتِ برند با طرف‌حساب‌هایِ مرتبط. */
    public function show(int $brandId)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $brand = $this->parties->getBrand($brandId);
        abort_if(! $brand, 404, 'برند یافت نشد.');

        return Inertia::render('Crm/Brands/Show', [
            'brand' => $brand,
            'parties' => $this->parties->listBrandParties($brandId),
            'canManage' => $this->userCan(self::PERM_MANAGE),
        ]);
    }

    /** GET crm/brands?search=&isActive= — فهرستِ برندها (Reloadِ صفحه + Selectِ انتخابِ برند در طرف‌حساب). */
    public function index(Request $request)
    {
        $this->authorizeCrm(self::PERM_VIEW);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->parties->listBrands(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'brandId' => 'nullable|integer|exists:CrmBrands,BrandID',
            'name' => 'nullable|string|max:200',
            'description' => 'nullable|string',
            'removeLogo' => 'nullable|boolean',
        ]);

        // لوگو (multipart، اختیاری): اعتبارسنجیِ کامل با پیامِ فارسی در CrmBrandLogo انجام می‌شود
        return $this->runCrm(function () use ($request, $validated) {
            $logo = $request->file('logo');
            if ($request->has('logo') && ! $logo instanceof UploadedFile) {
                throw new CrmValidationException('فایلِ لوگو نامعتبر است.');
            }

            $res = $this->parties->saveBrand($validated, $this->actorId(), $logo, (bool) ($validated['removeLogo'] ?? false));

            return ['message' => $res->Message ?? 'برند ذخیره شد.', 'brandId' => (int) $res->BrandID];
        });
    }

    public function toggleActive(int $brandId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($brandId) {
            $res = $this->parties->toggleBrandActive($brandId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ برند تغییر کرد.'];
        });
    }

    /**
     * GET crm/brands/{brandId}/logo — استریمِ لوگو از Private Disk (بدونِ Public Storage و بدونِ افشایِ مسیر).
     * URL حاویِ ?v=hash است، پس Cacheِ طولانی امن است (بعد از جایگزینی URL عوض می‌شود).
     */
    public function logo(int $brandId)
    {
        $this->authorizeCrm(self::PERM_VIEW);

        $file = $this->parties->getBrandLogoFile($brandId);
        abort_if(! $file, 404);

        return Storage::disk(CrmBrandLogo::DISK)->response($file['path'], null, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
