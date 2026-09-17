<?php

namespace App\Http\Controllers;

use App\Services\Message\MessageComposer;
use App\Services\Workflow\Exceptions\WorkflowValidationException;
use App\Services\Workflow\TemplateRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Message-from-Template — Phase 3, Phase C.
 *
 * Endpointِ کاملاً جدا از MessageController::store() (طبقِ تصمیمِ صریح): این
 * Controller منطقِ Template را در خود نگه می‌دارد، نه MessageController. از
 * همان MessageComposer (بدونِ تغییر در sp_InsertMessage) و همان TemplateRenderer
 * (Read-Only) استفاده می‌کند.
 *
 * دو مرحله، دو Transactionِ کاملاً مستقل:
 *   ۱) TemplateRenderer::render() — فقط محاسبه، هیچ نوشتنی در DB.
 *   ۲) MessageComposer::insert() — همان EXEC sp_InsertMessage، بدونِ تغییر.
 * اگر مرحلهٔ ۱ شکست بخورد، هیچ Messageای ساخته نمی‌شود. Startِ Workflow (مرحلهٔ
 * بعدی، از سمتِ کلاینت) کاملاً جدا و از طریقِ همان POST /workflow/instancesِ
 * موجودِ P0 انجام می‌شود — بدونِ Transactionِ مشترک.
 */
class MessageTemplateController extends Controller
{
    public function __construct(
        private TemplateRenderer $renderer,
        private MessageComposer $composer,
    ) {
    }

    /** POST messages/from-template */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'letterTemplateId'   => 'required|integer|exists:LetterTemplates,LetterTemplateID',
            'formValues'         => 'nullable|array',
            'MessageTypeID'      => 'required|integer',
            'msgPriorityID'      => 'required|integer',
            'RecipientType'      => 'required|in:1,2,3',
            'RecipientUserIDs'   => 'nullable|array',
            'RecipientUserIDs.*' => 'integer',
            'CopyUserIDs'        => 'nullable|array',
            'CopyUserIDs.*'      => 'integer',
            'CopyDescription'    => 'nullable|string|max:1000',
            'DueDate'            => 'nullable|date',
        ]);

        try {
            // مرحلهٔ ۱ — Read-Only. متنِ Renderشده همیشه همین‌جا و تازه محاسبه می‌شود؛
            // هیچ متنِ از‌پیش‌رندرشده‌ای از کلاینت مستقیماً ذخیره نمی‌شود.
            $rendered = $this->renderer->render(
                $validated['letterTemplateId'],
                $validated['formValues'] ?? [],
                (int) Auth::id(),
            );
        } catch (WorkflowValidationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }

        // مرحلهٔ ۲ — همان مسیرِ نوشتنیِ MessageController::store()، بدونِ تغییرِ sp_InsertMessage.
        $result = $this->composer->insert([
            'MessageTypeID'    => $validated['MessageTypeID'],
            'msgPriorityID'    => $validated['msgPriorityID'],
            'Subject'          => $rendered['subject'],
            'MessageText'      => $rendered['body'],
            'RecipientType'    => (int) $validated['RecipientType'],
            'RecipientUserIDs' => $validated['RecipientUserIDs'] ?? null,
            'CopyUserIDs'      => $validated['CopyUserIDs'] ?? null,
            'CopyDescription'  => $validated['CopyDescription'] ?? null,
            'SenderUserID'     => Auth::id(),
            'CreateUserID'     => Auth::id(),
            'DueDate'          => $validated['DueDate'] ?? null,
        ]);

        if (! $result['success']) {
            return response()->json(['success' => false, 'message' => $result['message'] ?? 'خطا در ارسال پیام'], 422);
        }

        return response()->json([
            'success'   => true,
            'message'   => $result['message'],
            'messageId' => $result['messageId'],
        ]);
    }
}
