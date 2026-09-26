<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;

/** اطلاعاتِ تماسِ یک طرف‌حساب (CrmContacts) — «داخلی» فیلدی کنارِ مقدار است، نه نوعِ تماسِ جدا. */
class CrmContactController extends CrmApiController
{
    private const PERM_MANAGE = 'CRM_MANAGE_PARTIES';

    public function __construct(private CrmPartyService $parties)
    {
    }

    public function index(Request $request)
    {
        $this->authorizeCrm('CRM_VIEW');
        $validated = $request->validate([
            'partyId' => 'nullable|integer',
            'personId' => 'nullable|integer',
        ]);

        return $this->runCrm(fn () => ['items' => $this->parties->listContacts(
            $validated['partyId'] ?? null,
            $validated['personId'] ?? null,
        )]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'contactId' => 'nullable|integer|exists:CrmContacts,ContactID',
            'partyId' => 'nullable|integer|exists:CrmParties,PartyID',
            'personId' => 'nullable|integer|exists:CrmPersons,PersonID',
            'contactTypeId' => 'required|integer|exists:CrmContactTypes,ContactTypeID',
            'contactValue' => 'required|string|max:200',
            'extension' => 'nullable|string|max:20',
            'description' => 'nullable|string|max:200',
            'isPrimary' => 'nullable|boolean',
            'relatedPersonId' => 'nullable|integer|exists:CrmPersons,PersonID',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->saveContact($validated, $this->actorId());

            return ['message' => $res->Message ?? 'اطلاعاتِ تماس ذخیره شد.', 'contactId' => (int) $res->ContactID];
        });
    }

    public function toggleActive(int $contactId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($contactId) {
            $res = $this->parties->toggleContactActive($contactId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ اطلاعاتِ تماس تغییر کرد.'];
        });
    }
}
