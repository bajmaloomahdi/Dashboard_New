<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;

/** دسته‌بندی‌هایِ یک طرف‌حساب (CrmPartyClassifications) — دپارتمان/نوع/فعالیت، چندگانه. */
class CrmPartyClassificationController extends CrmApiController
{
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(private CrmPartyService $parties)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate(['partyId' => 'required|integer']);

        return $this->runCrm(fn () => ['items' => $this->parties->listClassifications($validated['partyId'])]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'classificationId' => 'nullable|integer|exists:CrmPartyClassifications,ClassificationID',
            'partyId' => 'required|integer|exists:CrmParties,PartyID',
            'departmentId' => 'nullable|integer|exists:CrmDepartments,DepartmentID',
            'partyTypeId' => 'nullable|integer|exists:CrmPartyTypes,PartyTypeID',
            'activityId' => 'nullable|integer|exists:CrmActivities,ActivityID',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->saveClassification($validated, $this->actorId());

            return ['message' => $res->Message ?? 'دسته‌بندی ذخیره شد.', 'classificationId' => (int) $res->ClassificationID];
        });
    }

    public function toggleActive(int $classificationId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($classificationId) {
            $res = $this->parties->toggleClassificationActive($classificationId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ دسته‌بندی تغییر کرد.'];
        });
    }
}
