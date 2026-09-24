<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;

/** برندهایِ یک طرف‌حساب (CrmBrands) — Entityِ مستقل، بدونِ فیلدِ ثابت. */
class CrmBrandController extends CrmApiController
{
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(private CrmPartyService $parties)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['partyId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->parties->listBrands($validated['partyId'])]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'brandId' => 'nullable|integer|exists:CrmBrands,BrandID',
            'partyId' => 'required|integer|exists:CrmParties,PartyID',
            'name' => 'required|string|max:200',
            'description' => 'nullable|string',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->saveBrand($validated, $this->actorId());

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
}
