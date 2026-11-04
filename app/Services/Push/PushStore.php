<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\DB;

/**
 * تنها لایهٔ دسترسی به دیتابیسِ Web Push — هر متد یک Stored Procedureِ sp_Push_* (پچ 103).
 */
class PushStore
{
    public function activate(bool $enable, ?int $userId = null, bool $resetBoundary = false): object
    {
        return DB::selectOne('EXEC dbo.sp_Push_Activate @Enable = ?, @UserID = ?, @ResetBoundary = ?', [$enable ? 1 : 0, $userId, $resetBoundary ? 1 : 0]);
    }

    public function state(): object
    {
        return DB::selectOne('EXEC dbo.sp_Push_GetState');
    }

    public function heartbeat(string $worker): object
    {
        return DB::selectOne('EXEC dbo.sp_Push_Heartbeat @Worker = ?', [$worker]);
    }

    public function saveSubscription(int $userId, string $endpoint, string $p256dh, string $auth, string $contentEncoding, ?string $userAgent): object
    {
        return DB::selectOne(
            'EXEC dbo.sp_Push_SaveSubscription @UserID = ?, @Endpoint = ?, @P256dh = ?, @AuthSecret = ?, @ContentEncoding = ?, @UserAgent = ?',
            [$userId, $endpoint, $p256dh, $auth, $contentEncoding, $userAgent]
        );
    }

    public function deactivateSubscription(int $userId, ?int $subscriptionId, ?string $endpoint, string $reason = 'USER'): object
    {
        return DB::selectOne(
            'EXEC dbo.sp_Push_DeactivateSubscription @UserID = ?, @PushSubscriptionID = ?, @Endpoint = ?, @Reason = ?',
            [$userId, $subscriptionId, $endpoint, $reason]
        );
    }

    public function userSubscriptions(int $userId, ?string $endpoint = null): array
    {
        return DB::select('EXEC dbo.sp_Push_GetUserSubscriptions @UserID = ?, @Endpoint = ?', [$userId, $endpoint]);
    }

    public function enqueue(?int $maxAgeMinutes): object
    {
        return DB::selectOne('EXEC dbo.sp_Push_Enqueue @MaxAgeMinutes = ?', [$maxAgeMinutes]);
    }

    public function enqueueTest(int $userId): object
    {
        return DB::selectOne('EXEC dbo.sp_Push_EnqueueTest @UserID = ?', [$userId]);
    }

    public function claimBatch(string $worker, int $batchSize, int $leaseSeconds): array
    {
        return DB::select('EXEC dbo.sp_Push_ClaimBatch @Worker = ?, @BatchSize = ?, @LeaseSeconds = ?', [$worker, $batchSize, $leaseSeconds]);
    }

    /** @return object{Validity: string, ExpiresInSeconds: ?int} — ثانیه‌هایِ باقی‌مانده تا پایانِ جلسه با ساعتِ SQL (پچِ ۱۱۳) */
    public function checkOutbox(int $outboxId, string $worker): object
    {
        $row = DB::selectOne('EXEC dbo.sp_Push_CheckOutbox @PushOutboxID = ?, @Worker = ?', [$outboxId, $worker]);

        return (object) [
            'Validity' => (string) ($row->Validity ?? 'LEASE_LOST'),
            'ExpiresInSeconds' => isset($row->ExpiresInSeconds) ? (int) $row->ExpiresInSeconds : null,
        ];
    }

    public function targets(int $outboxId, string $worker): array
    {
        return DB::select('EXEC dbo.sp_Push_GetTargets @PushOutboxID = ?, @Worker = ?', [$outboxId, $worker]);
    }

    public function beginDelivery(int $outboxId, int $subscriptionId, string $worker): bool
    {
        $r = DB::selectOne('EXEC dbo.sp_Push_BeginDelivery @PushOutboxID = ?, @PushSubscriptionID = ?, @Worker = ?', [$outboxId, $subscriptionId, $worker]);

        return (int) ($r->Success ?? 0) === 1;
    }

    public function recordDelivery(int $outboxId, int $subscriptionId, string $worker, string $status, ?int $httpStatus, ?string $error): bool
    {
        $r = DB::selectOne(
            'EXEC dbo.sp_Push_RecordDelivery @PushOutboxID = ?, @PushSubscriptionID = ?, @Worker = ?, @Status = ?, @HttpStatus = ?, @Error = ?',
            [$outboxId, $subscriptionId, $worker, $status, $httpStatus, $error]
        );

        return (int) ($r->Success ?? 0) === 1;
    }

    public function disableSubscription(int $subscriptionId, string $reason): void
    {
        DB::selectOne('EXEC dbo.sp_Push_DisableSubscription @PushSubscriptionID = ?, @Reason = ?', [$subscriptionId, $reason]);
    }

    public function completeOutbox(int $outboxId, string $worker, string $outcome, ?int $retryAfterSeconds = null, ?string $error = null): bool
    {
        $r = DB::selectOne(
            'EXEC dbo.sp_Push_CompleteOutbox @PushOutboxID = ?, @Worker = ?, @Outcome = ?, @RetryAfterSeconds = ?, @Error = ?',
            [$outboxId, $worker, $outcome, $retryAfterSeconds, $error]
        );

        return (int) ($r->Success ?? 0) === 1;
    }

    public function cleanup(int $retentionDays): object
    {
        return DB::selectOne('EXEC dbo.sp_Push_Cleanup @RetentionDays = ?', [$retentionDays]);
    }

    /** وضعیتِ یک اشتراک از رویِ Endpoint (بدونِ برگرداندنِ Endpoint/کلیدها) */
    public function subscriptionByEndpoint(string $endpoint): ?object
    {
        return DB::selectOne(
            'SELECT PushSubscriptionID, UserID, IsActive, DeactivationReason, LastSuccessAt, LastFailureAt FROM dbo.PushSubscriptions '
            . 'WHERE EndpointHash = CAST(HASHBYTES(\'SHA2_256\', CAST(? AS NVARCHAR(2000))) AS BINARY(32))',
            [$endpoint]
        ) ?: null;
    }

    /** کاربرانِ فعالِ هدف (همه یا انتخاب‌شده) با وضعیتِ اشتراک */
    public function broadcastAudience(string $mode, array $userIds = []): array
    {
        return DB::select('EXEC dbo.sp_Push_GetBroadcastAudience @TargetMode = ?, @UserIDs = ?', [$mode, self::idList($userIds)]);
    }

    public function createBroadcast(int $userId, string $title, string $body, string $mode, array $userIds, string $idempotencyKey): object
    {
        return DB::selectOne(
            'EXEC dbo.sp_Push_CreateBroadcast @CreatedByUserID = ?, @Title = ?, @Body = ?, @TargetMode = ?, @UserIDs = ?, @IdempotencyKey = ?',
            [$userId, $title, $body, $mode, self::idList($userIds), $idempotencyKey]
        );
    }

    public function broadcasts(int $top = 50, ?int $broadcastId = null): array
    {
        return DB::select('EXEC dbo.sp_Push_GetBroadcasts @Top = ?, @PushBroadcastID = ?', [$top, $broadcastId]);
    }

    /** عنوان/متنِ اعلانِ عمومی از رویِ شناسهٔ گیرنده (SourceIDِ Outbox) */
    public function broadcastContent(int $recipientId): ?object
    {
        return DB::selectOne('EXEC dbo.sp_Push_GetBroadcastContent @PushBroadcastRecipientID = ?', [$recipientId]) ?: null;
    }

    /** یادآوریِ خودِ گیرنده (برایِ موضوع/متنِ Push) — sp_GetReminderById مالکیت و فعال بودن را کنترل می‌کند */
    public function reminder(int $reminderId, int $userId): ?object
    {
        return DB::selectOne('EXEC sp_GetReminderById @ReminderID = ?, @UserID = ?', [$reminderId, $userId]) ?: null;
    }

    /** شمارهٔ نامه/وظیفه برایِ عنوانِ Push — فقط اگر همین کاربر اعلانی از این نامه دارد (طرفِ نامه است) */
    public function messageNumber(int $messageId, int $userId): ?string
    {
        $row = DB::selectOne(
            'SELECT M.MessageNumber FROM dbo.Messages M
             WHERE M.MessageID = ? AND EXISTS (SELECT 1 FROM dbo.UserNotifications UN WHERE UN.MessageID = M.MessageID AND UN.UserID = ?)',
            [$messageId, $userId],
        );
        $number = trim((string) ($row->MessageNumber ?? ''));

        return $number !== '' ? $number : null;
    }

    /**
     * موضوعِ رویداد برایِ اعلانِ دعوت/لغو/فعالیتِ رویداد (و تعویقِ همان‌ها) — فقط اگر همین کاربر طرفِ همان دعوت/اطلاع است؛
     * رویدادِ خصوصی null (رویِ صفحهٔ قفل نمی‌آید، مثلِ یادآوری).
     */
    public function eventTitle(object $row): ?string
    {
        $type = (string) $row->SourceType;
        $sourceId = (int) $row->SourceID;
        $userId = (int) $row->UserID;
        if ($type === 'SNOOZE') {
            $z = DB::selectOne('SELECT SourceKind, SourceID FROM dbo.NotificationSnoozes WHERE SnoozeID = ? AND UserID = ?', [$sourceId, $userId]);
            if (! $z || ! in_array($z->SourceKind, ['INVITE', 'CANCEL', 'CALEVENT'], true)) {
                return null;
            }
            $event = $z->SourceKind === 'CALEVENT'
                ? DB::selectOne('SELECT E.Title, E.IsPrivate FROM dbo.CalendarEvents E WHERE E.EventID = ?', [(int) $z->SourceID])
                : DB::selectOne('SELECT E.Title, E.IsPrivate FROM dbo.CalendarEventAttendees A INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID WHERE A.CalendarEventAttendeeID = ? AND A.UserID = ?', [(int) $z->SourceID, $userId]);
        } elseif (in_array($type, ['INVITE', 'CANCEL'], true)) {
            $event = DB::selectOne('SELECT E.Title, E.IsPrivate FROM dbo.CalendarEventAttendees A INNER JOIN dbo.CalendarEvents E ON E.EventID = A.EventID WHERE A.CalendarEventAttendeeID = ? AND A.UserID = ?', [$sourceId, $userId]);
        } elseif ($type === 'CALEVENT') {
            $event = DB::selectOne('SELECT E.Title, E.IsPrivate FROM dbo.CalendarEventNotices N INNER JOIN dbo.CalendarEvents E ON E.EventID = N.EventID WHERE N.NoticeID = ? AND N.UserID = ?', [$sourceId, $userId]);
        } else {
            return null;
        }
        if (! $event || (int) ($event->IsPrivate ?? 0) === 1) {
            return null;
        }
        $title = trim((string) ($event->Title ?? ''));

        return $title !== '' ? $title : null;
    }

    /** @param  array<int|string>  $ids */
    private static function idList(array $ids): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($i) => $i > 0)));

        return $ids ? implode(',', $ids) : null;
    }
}
