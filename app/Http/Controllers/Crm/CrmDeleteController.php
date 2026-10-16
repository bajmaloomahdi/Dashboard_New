<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\Support\CrmBrandLogo;
use App\Services\Crm\Support\CrmStore;
use Illuminate\Support\Facades\DB;

/**
 * حذفِ اطلاعاتِ CRM با یک دسترسیِ واحد (CRM_DELETE، در «دسترسی‌های عملیاتی»ِ نقش‌ها).
 * قاعدهٔ وابستگی در sp_Crm_DeleteRecord است: رکوردی که جایی استفاده شده حذف نمی‌شود و پیامِ
 * فهرستِ بخش‌هایِ وابسته برمی‌گردد.
 */
class CrmDeleteController extends CrmApiController
{
    public const PERM_DELETE = 'CRM_DELETE';

    /** slugِ مسیر → EntityCodeِ SP */
    private const ENTITIES = [
        'party' => 'PARTY',
        'person' => 'PERSON',
        'brand' => 'BRAND',
        'party-brand-category' => 'PARTY_BRAND_CATEGORY',
        'address' => 'ADDRESS',
        'contact' => 'CONTACT',
        'relation' => 'RELATION',
        'classification' => 'CLASSIFICATION',
        'interaction' => 'INTERACTION',
        'product-category' => 'PRODUCT_CATEGORY',
        'department' => 'DEPARTMENT',
        'party-type' => 'PARTY_TYPE',
        'activity' => 'ACTIVITY',
        'title' => 'TITLE',
        'position' => 'POSITION',
        'contact-role' => 'CONTACT_ROLE',
        'contact-type' => 'CONTACT_TYPE',
        'address-title' => 'ADDRESS_TITLE',
        'interaction-type' => 'INTERACTION_TYPE',
        'ownership-type' => 'OWNERSHIP_TYPE',
        'province' => 'PROVINCE',
        'county' => 'COUNTY',
        'city' => 'CITY',
        'neighborhood' => 'NEIGHBORHOOD',
        'municipal-zone' => 'MUNICIPAL_ZONE',
    ];

    public function __construct(private CrmStore $store, private CrmBrandLogo $logos)
    {
    }

    /** POST crm/delete/{entity}/{id} */
    public function destroy(string $entity, int $id)
    {
        $this->authorizeCrm(self::PERM_DELETE);
        abort_unless(isset(self::ENTITIES[$entity]), 404);

        return $this->runCrm(function () use ($entity, $id) {
            // فایلِ لوگویِ برند بعد از حذفِ موفقِ ردیف پاک می‌شود
            $logoPath = $entity === 'brand' ? DB::table('CrmBrands')->where('BrandID', $id)->value('LogoPath') : null;

            $res = $this->store->deleteRecord(self::ENTITIES[$entity], $id, $this->actorId());

            if ($logoPath) {
                $this->logos->delete($logoPath);
            }

            return ['message' => $res->Message ?? 'حذف شد.'];
        });
    }
}
