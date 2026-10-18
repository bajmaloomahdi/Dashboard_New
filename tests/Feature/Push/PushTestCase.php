<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Services\Push\Contracts\PushTransport;
use App\Services\Push\PushDispatcher;
use App\Services\Push\PushStore;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * پایهٔ آزمون‌هایِ Web Push: Transportِ ساختگی (بدونِ شبکه)، مرزِ شروعِ تازه در همان Transaction،
 * و کمک‌تابع‌هایِ ساختِ اشتراک/پیام/یادآوری. همه‌چیز با DatabaseTransactions برمی‌گردد.
 */
abstract class PushTestCase extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    protected const USER_SENDER = 2;

    protected FakePushTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'push.enabled' => true,
            'push.max_age_minutes' => null,
            'push.vapid' => ['subject' => 'mailto:test@example.com', 'public_key' => 'test-public', 'private_key' => 'test-private'],
        ]);
        $this->fake = new FakePushTransport();
        $this->app->instance(PushTransport::class, $this->fake);
        $this->activate();
    }

    /** مرزِ شروعِ تازه (همین لحظه، یا چند دقیقه قبل برایِ یادآورهایِ سررسیدشده) */
    protected function activate(int $minutesAgo = 0): void
    {
        DB::selectOne('EXEC dbo.sp_Push_Activate @Enable = 1, @ResetBoundary = 1');
        if ($minutesAgo > 0) {
            DB::update('UPDATE dbo.PushState SET ActivatedAt = DATEADD(MINUTE, ?, ActivatedAt) WHERE PushStateID = 1', [-$minutesAgo]);
        }
    }

    protected function store(): PushStore
    {
        return app(PushStore::class);
    }

    protected function endpoint(string $host = 'fcm.googleapis.com'): string
    {
        return "https://{$host}/fcm/send/" . bin2hex(random_bytes(12));
    }

    protected function subscribe(int $userId, ?string $endpoint = null): string
    {
        $endpoint ??= $this->endpoint();
        $r = $this->store()->saveSubscription($userId, $endpoint, 'BPkey' . bin2hex(random_bytes(8)), 'auth' . bin2hex(random_bytes(4)), 'aes128gcm', 'PHPUnit');
        $this->assertSame(1, (int) $r->Success);
        // اشتراک «قبل از» رویداد ثبت شده باشد (SubscribedAt ثانیه‌ای است)
        DB::update('UPDATE dbo.PushSubscriptions SET SubscribedAt = DATEADD(SECOND, -5, SubscribedAt) WHERE EndpointHash = CAST(HASHBYTES(\'SHA2_256\', CAST(? AS NVARCHAR(2000))) AS BINARY(32))', [$endpoint]);

        return $endpoint;
    }

    /** پیامِ متنِ آزاد از USER_SENDER به گیرندگان؛ خروجی: MessageID */
    protected function sendMessage(array $recipients): int
    {
        $subject = 'Push آزمون ' . bin2hex(random_bytes(4));
        $this->actingAs(User::find(self::USER_SENDER))->post('/messages', [
            'MessageTypeID' => 1, 'msgPriorityID' => 1, 'Subject' => $subject, 'MessageText' => 'متنِ محرمانه',
            'RecipientType' => 1, 'RecipientUserIDs' => $recipients,
        ])->assertSessionHasNoErrors();

        return (int) DB::selectOne('SELECT TOP 1 MessageID FROM dbo.Messages WHERE Subject = ? ORDER BY MessageID DESC', [$subject])->MessageID;
    }

    protected function reminder(int $userId, string $remindAtSql = 'DATEADD(MINUTE, -1, SYSDATETIME())'): int
    {
        DB::insert("INSERT INTO dbo.Reminders (EntityType, EntityID, UserID, OffsetMinutes, RemindAt, Title, IsActive, CreatedByUserID)
                    VALUES (N'STANDALONE', 0, ?, 0, CAST({$remindAtSql} AS DATETIME2(0)), N'یادآوریِ محرمانه', 1, ?)", [$userId, $userId]);

        return (int) DB::selectOne('SELECT MAX(ReminderID) AS id FROM dbo.Reminders WHERE UserID = ?', [$userId])->id;
    }

    protected function dispatch(string $worker = 'phpunit'): array
    {
        return app(PushDispatcher::class)->run($worker . '-' . bin2hex(random_bytes(3)));
    }

    protected function outbox(int $userId, ?string $sourceType = null): array
    {
        return DB::select('SELECT * FROM dbo.PushOutbox WHERE UserID = ? AND (? IS NULL OR SourceType = ?) ORDER BY PushOutboxID', [$userId, $sourceType, $sourceType]);
    }

    /** موعدِ تلاشِ بعدی را به گذشته می‌برد (به‌جایِ صبرکردن) */
    protected function makeDue(): void
    {
        DB::update("UPDATE dbo.PushOutbox SET NextAttemptAt = DATEADD(SECOND, -1, SYSDATETIME()) WHERE Status = 'PENDING'");
    }
}
