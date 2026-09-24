<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmMasterDataService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * فهرست‌هایِ کمکیِ CRM: عنوانِ فرد، سمت، نقش، نوعِ تماس، عنوانِ آدرس —
 * همگی مستقل، بدونِ سلسله‌مراتب.
 */
class CrmDirectoryController extends CrmApiController
{
    private const PERM = 'CRM_MANAGE_MASTER_DATA';

    public function __construct(private CrmMasterDataService $masterData)
    {
    }

    /** GET crm/directory — صفحهٔ Inertia با هر پنج فهرست. */
    public function page()
    {
        $this->authorizeCrm(self::PERM);

        return Inertia::render('Crm/MasterData/Directory', [
            'titles'         => $this->masterData->listTitles(),
            'positions'      => $this->masterData->listPositions(),
            'contactRoles'   => $this->masterData->listContactRoles(),
            'contactTypes'   => $this->masterData->listContactTypes(),
            'addressTitles'  => $this->masterData->listAddressTitles(),
            'canManage'      => true,
        ]);
    }

    /* ---------- عنوانِ فرد ---------- */

    public function titlesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listTitles(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function titlesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'titleId'     => 'nullable|integer|exists:CrmTitles,TitleID',
            'displayName' => 'required|string|max:200',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveTitle($validated, $this->actorId());

            return ['message' => $res->Message ?? 'عنوان ذخیره شد.', 'titleId' => (int) $res->TitleID];
        });
    }

    public function titlesToggle(int $titleId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($titleId) {
            $res = $this->masterData->toggleTitleActive($titleId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ عنوان تغییر کرد.'];
        });
    }

    /* ---------- سمت ---------- */

    public function positionsIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listPositions(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function positionsStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'positionId'  => 'nullable|integer|exists:CrmPositions,PositionID',
            'displayName' => 'required|string|max:200',
            'sortOrder'   => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->savePosition($validated, $this->actorId());

            return ['message' => $res->Message ?? 'سمت ذخیره شد.', 'positionId' => (int) $res->PositionID];
        });
    }

    public function positionsToggle(int $positionId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($positionId) {
            $res = $this->masterData->togglePositionActive($positionId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ سمت تغییر کرد.'];
        });
    }

    /* ---------- نقش ---------- */

    public function contactRolesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listContactRoles(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function contactRolesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'contactRoleId' => 'nullable|integer|exists:CrmContactRoles,ContactRoleID',
            'displayName'   => 'required|string|max:200',
            'sortOrder'     => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveContactRole($validated, $this->actorId());

            return ['message' => $res->Message ?? 'نقش ذخیره شد.', 'contactRoleId' => (int) $res->ContactRoleID];
        });
    }

    public function contactRolesToggle(int $contactRoleId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($contactRoleId) {
            $res = $this->masterData->toggleContactRoleActive($contactRoleId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ نقش تغییر کرد.'];
        });
    }

    /* ---------- نوعِ تماس ---------- */

    public function contactTypesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listContactTypes(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function contactTypesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'contactTypeId' => 'nullable|integer|exists:CrmContactTypes,ContactTypeID',
            'displayName'   => 'required|string|max:200',
            'sortOrder'     => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveContactType($validated, $this->actorId());

            return ['message' => $res->Message ?? 'نوعِ تماس ذخیره شد.', 'contactTypeId' => (int) $res->ContactTypeID];
        });
    }

    public function contactTypesToggle(int $contactTypeId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($contactTypeId) {
            $res = $this->masterData->toggleContactTypeActive($contactTypeId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ نوعِ تماس تغییر کرد.'];
        });
    }

    /* ---------- عنوانِ آدرس ---------- */

    public function addressTitlesIndex(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate(['search' => 'nullable|string|max:200', 'isActive' => 'nullable|boolean']);

        return $this->runCrm(fn () => ['items' => $this->masterData->listAddressTitles(
            $validated['search'] ?? null,
            array_key_exists('isActive', $validated) ? (bool) $validated['isActive'] : null,
        )]);
    }

    public function addressTitlesStore(Request $request)
    {
        $this->authorizeCrm(self::PERM);
        $validated = $request->validate([
            'addressTitleId' => 'nullable|integer|exists:CrmAddressTitles,AddressTitleID',
            'displayName'    => 'required|string|max:200',
            'sortOrder'      => 'nullable|integer',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->masterData->saveAddressTitle($validated, $this->actorId());

            return ['message' => $res->Message ?? 'عنوانِ آدرس ذخیره شد.', 'addressTitleId' => (int) $res->AddressTitleID];
        });
    }

    public function addressTitlesToggle(int $addressTitleId)
    {
        $this->authorizeCrm(self::PERM);

        return $this->runCrm(function () use ($addressTitleId) {
            $res = $this->masterData->toggleAddressTitleActive($addressTitleId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ عنوانِ آدرس تغییر کرد.'];
        });
    }
}
