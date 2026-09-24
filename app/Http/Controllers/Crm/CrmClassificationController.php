<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * طبقه‌بندیِ طرف‌حساب: دپارتمان (مستقل)، نوع (مستقل)، فعالیت (زیرمجموعهٔ نوع).
 *
 * فعلاً یک Permissionِ واحد (CRM_MANAGE_MASTER_DATA) هم دیدن هم مدیریت را می‌پوشاند —
 * هم‌الگو با WORKFLOW_MANAGE_ENTITY_TYPES.
 */
class CrmClassificationController extends CrmApiController
{
    private const PERM = 'CRM_MANAGE_MASTER_DATA';

    public function __construct(private CrmMasterDataService $masterData)
    {
    }

    /** GET crm/classification — صفحهٔ Inertia با هر سه فهرست. */
    public function page()
    {
        $this->authorizeCrm(self::PERM);

        return Inertia::render('Crm/MasterData/Classification', [
            'departments' => $this->masterData->listDepartments(),
            'partyTypes'  => $this->masterData->listPartyTypes(),
            'activities'  => $this->masterData->listActivities(),
            'canManage'   => true,
        ]);
    }

    /* ---------- دپارتمان ---------- */

    public function departmentsIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listDepartments(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function departmentsStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'departmentId' => 'nullable|integer|exists:CrmDepartments,DepartmentID',
            'displayName'  => 'required|string|max:200',
            'sortOrder'    => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveDepartment($validated, $this->actorId());

            return ['message' => $res->Message ?? 'دپارتمان ذخیره شد.', 'departmentId' => (int) $res->DepartmentID];
        });
    }

    public function departmentsToggle(int $departmentId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($departmentId) {
            $res = $this->masterData->toggleDepartmentActive($departmentId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ دپارتمان تغییر کرد.'];
        });
    }

    /* ---------- نوع ---------- */

    public function partyTypesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listPartyTypes(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function partyTypesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'partyTypeId' => 'nullable|integer|exists:CrmPartyTypes,PartyTypeID',
            'displayName' => 'required|string|max:200',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->savePartyType($validated, $this->actorId());

            return ['message' => $res->Message ?? 'نوع ذخیره شد.', 'partyTypeId' => (int) $res->PartyTypeID];
        });
    }

    public function partyTypesToggle(int $partyTypeId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($partyTypeId) {
            $res = $this->masterData->togglePartyTypeActive($partyTypeId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ نوع تغییر کرد.'];
        });
    }

    /* ---------- فعالیت (زیرمجموعهٔ نوع) ---------- */

    public function activitiesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'partyTypeId' => 'nullable|integer',
            'search'      => 'nullable|string|max:200',
            'isActive'    => 'nullable|boolean',
        ]);

        return $this->runCrm(fn () => ['items' => $this->masterData->listActivities(
            $validated['partyTypeId'] ?? null,
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function activitiesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'activityId'  => 'nullable|integer|exists:CrmActivities,ActivityID',
            'partyTypeId' => 'required|integer|exists:CrmPartyTypes,PartyTypeID',
            'displayName' => 'required|string|max:200',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveActivity($validated, $this->actorId());

            return ['message' => $res->Message ?? 'فعالیت ذخیره شد.', 'activityId' => (int) $res->ActivityID];
        });
    }

    public function activitiesToggle(int $activityId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($activityId) {
            $res = $this->masterData->toggleActivityActive($activityId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ فعالیت تغییر کرد.'];
        });
    }
}
