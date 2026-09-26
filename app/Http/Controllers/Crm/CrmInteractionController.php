<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmPartyService;
use Illuminate\Http\Request;

/** تعاملاتِ یک طرف‌حساب (CrmInteractions): تماس/جلسه/یادداشت/پیگیری. */
class CrmInteractionController extends CrmApiController
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
            'type' => 'nullable|string|max:20',
            'status' => 'nullable|string|max:20',
        ]);

        return $this->runCrm(fn () => ['items' => $this->parties->listInteractions(
            $validated['partyId'] ?? null,
            $validated['personId'] ?? null,
            $validated['type'] ?? null,
            $validated['status'] ?? null,
        )]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'interactionId' => 'nullable|integer|exists:CrmInteractions,InteractionID',
            'interactionType' => 'nullable|string|max:20',
            'partyId' => 'required|integer|exists:CrmParties,PartyID',
            'personId' => 'nullable|integer|exists:CrmPersons,PersonID',
            'subject' => 'nullable|string|max:200',
            'description' => 'nullable|string',
            'outcome' => 'nullable|string',
            'interactionDate' => 'nullable|date',
            'status' => 'nullable|string|max:20',
            'followUpOfId' => 'nullable|integer|exists:CrmInteractions,InteractionID',
            'ownerUserId' => 'nullable|integer|exists:Users,UserID',
        ]);

        return $this->runCrm(function () use ($validated) {
            $res = $this->parties->saveInteraction($validated, $this->actorId());

            return ['message' => $res->Message ?? 'تعامل ذخیره شد.', 'interactionId' => (int) $res->InteractionID];
        });
    }

    public function setStatus(Request $request, int $interactionId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);
        $validated = $request->validate(['status' => 'nullable|string|max:20']);

        return $this->runCrm(function () use ($interactionId, $validated) {
            $res = $this->parties->setInteractionStatus($interactionId, $validated['status'] ?? '', $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ تعامل تغییر کرد.'];
        });
    }

    public function toggleActive(int $interactionId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($interactionId) {
            $res = $this->parties->toggleInteractionActive($interactionId, $this->actorId());

            return ['message' => $res->Message ?? 'وضعیتِ تعامل تغییر کرد.'];
        });
    }
}
