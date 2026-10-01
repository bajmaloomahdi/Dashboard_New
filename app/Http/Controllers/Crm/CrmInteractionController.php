<?php

namespace App\Http\Controllers\Crm;

use App\Services\Crm\CrmPartyService;
use App\Services\Crm\Exceptions\CrmValidationException;
use App\Services\Crm\Support\CrmInteractionAttachmentFiles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * تعاملاتِ یک طرف‌حساب (CrmInteractions): تماس/جلسه/یادداشت/پیگیری + پیوست‌هایِ هر تعامل
 * (CrmInteractionAttachments؛ مشاهده/دانلود با CRM_VIEW، افزودن/حذف با CRM_MANAGE_PARTIES).
 */
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
            'interactionTypeId' => 'nullable|integer',
            'status' => 'nullable|string|max:20',
            'projectId' => 'nullable|integer',
        ]);

        return $this->runCrm(fn () => ['items' => $this->parties->listInteractions(
            $validated['partyId'] ?? null,
            $validated['personId'] ?? null,
            $validated['interactionTypeId'] ?? null,
            $validated['status'] ?? null,
            $validated['projectId'] ?? null,
        )]);
    }

    public function store(Request $request)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        $validated = $request->validate([
            'interactionId' => 'nullable|integer|exists:CrmInteractions,InteractionID',
            'interactionTypeId' => 'required|integer|exists:CrmInteractionTypes,InteractionTypeID',
            'partyId' => 'required|integer|exists:CrmParties,PartyID',
            'personId' => 'nullable|integer|exists:CrmPersons,PersonID',
            'projectId' => 'nullable|integer|exists:Projects,ProjectID',
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

    /**
     * GET crm/parties/{partyId}/projects — پروژه‌هایی که این Party پیمانکارِ فعالِ آن‌هاست؛
     * برایِ Selectorِ «مرتبط با پروژه» در فرمِ ثبتِ تعامل (نه فهرستِ کاملِ Projectهایِ سیستم).
     */
    public function projectsForParty(int $partyId)
    {
        $this->authorizeCrm('CRM_VIEW');

        return $this->runCrm(fn () => ['items' => $this->parties->listProjectsForParty($partyId)]);
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

    /**
     * POST crm/interactions/{interactionId}/attachments — multipart: attachments[] + descriptions[] (هم‌اندیس).
     * بررسیِ کامل (تعداد/حجم/نوع) با پیامِ فارسی در سرویس انجام می‌شود.
     */
    public function storeAttachments(Request $request, int $interactionId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($request, $interactionId) {
            $files = $request->file('attachments');
            $descriptions = $request->input('descriptions', []);
            if (! is_array($files)) {
                throw new CrmValidationException('هیچ فایلی برایِ پیوست انتخاب نشده است.');
            }
            if (! is_array($descriptions)) {
                throw new CrmValidationException('توضیحاتِ پیوست نامعتبر است.');
            }

            $count = $this->parties->addInteractionAttachments($interactionId, $files, $descriptions, $this->actorId());

            return ['message' => $count > 1 ? "{$count} پیوست ثبت شد." : 'پیوست ثبت شد.'];
        });
    }

    /** POST crm/interaction-attachments/{attachmentId}/delete — حذفِ قطعیِ ردیف و فایل. */
    public function destroyAttachment(int $attachmentId)
    {
        $this->authorizeCrm(self::PERM_MANAGE);

        return $this->runCrm(function () use ($attachmentId) {
            $res = $this->parties->deleteInteractionAttachment($attachmentId, $this->actorId());

            return ['message' => $res->Message ?? 'پیوست حذف شد.'];
        });
    }

    /** GET crm/interaction-attachments/{attachmentId}/download — استریم از Private Disk با نامِ اصلیِ فایل (همیشه attachment). */
    public function downloadAttachment(int $attachmentId)
    {
        $this->authorizeCrm('CRM_VIEW');

        $file = $this->parties->getInteractionAttachmentFile($attachmentId);
        abort_if(! $file, 404, 'پیوست یافت نشد.');

        return Storage::disk(CrmInteractionAttachmentFiles::DISK)->download($file['path'], $file['name'], [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
