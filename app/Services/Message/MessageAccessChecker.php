<?php

namespace App\Services\Message;

use Illuminate\Support\Facades\DB;

/**
 * منطقِ اشتراکیِ «آیا کاربر در این پیام نقشی دارد؟» (فرستنده/گیرنده/رونوشت).
 *
 * استخراج‌شده از MessageController::isMessageParticipant() بدونِ تغییرِ Semantics —
 * تا هم دانلودِ ضمیمه‌ها (رفتارِ قبلی، بدونِ تغییر) و هم مسیرِ جدیدِ
 * Start Workflow روی یک Message از همین قرارداد استفاده کنند.
 */
class MessageAccessChecker
{
    public function isParticipant(int $messageId, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $exists = DB::select(
            'SELECT 1 FROM dbo.Messages WHERE MessageID = ? AND SenderUserID = ?
             UNION ALL
             SELECT 1 FROM dbo.MessageDetails WHERE MessageID = ? AND ToUserID = ?
             UNION ALL
             SELECT 1 FROM dbo.MessageCopies WHERE MessageID = ? AND UserID = ?',
            [$messageId, $userId, $messageId, $userId, $messageId, $userId]
        );

        return !empty($exists);
    }
}
