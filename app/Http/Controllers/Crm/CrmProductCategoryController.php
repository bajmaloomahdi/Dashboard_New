<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * دسته‌بندیِ محصولات (CrmProductCategories) — درختِ چندسطحیِ نامحدود؛ هر دسته یک والدِ مستقیم دارد
 * (بدونِ والد = ریشه). مشاهده با CRM_VIEW، مدیریت با CRM_MANAGE_MASTER_DATA (الگویِ Master Data).
 */
class CrmProductCategoryController extends CrmApiController
{
    private const PERM_VIEW = 'CRM_VIEW';
    private const PERM_MANAGE = 'CRM_MANAGE_MASTER_DATA';

    public function __construct(private CrmMasterDataService $masterData)
    {
    }

    /** GET crm/product-categories — صفحهٔ درختیِ دسته‌بندی‌ها. */
    public function page()
    {
        $this->authorizeCrm(self::PERM_VIEW);

        return Inertia::render('Crm/ProductCategories/Index', [
            'categories' => $this->masterData->listProductCategories(),
            'canManage' => $this->userCan(self::PERM_MANAGE),
        ]);
    }

    /** GET crm/product-categories-list?search=&isActive= — فهرستِ تخت (با Path/Level) برایِ Reload و TreeSelect. */
    public function index(Request $request)
    {
        $this->authorizeCrm(self::PERM_VIEW);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listProductCategories(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);
        $validated = $request->validate([
            'productCategoryId' => 'nullable|integer|exists:CrmProductCategories,ProductCategoryID',
            'parentCategoryId' => 'nullable|integer|exists:CrmProductCategories,ProductCategoryID',
            'displayName' => 'nullable|string|max:200',
            'sortOrder' => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveProductCategory($validated, $this->actorId());

            return ['message' => $res->Message ?? 'دسته‌بندی ذخیره شد.', 'productCategoryId' => (int) $res->ProductCategoryID];
        });
    }

    public function toggleActive(int $productCategoryId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($productCategoryId) {
            $res = $this->masterData->toggleProductCategoryActive($productCategoryId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ دسته‌بندی تغییر کرد.'];
        });
    }
}
