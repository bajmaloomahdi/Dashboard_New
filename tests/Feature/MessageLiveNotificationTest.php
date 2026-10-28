<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * اعلانِ زندهٔ داخلِ برنامه برایِ نامه/وظیفه (GET /messages/notifications/recent):
 * هر تغییرِ تازه (نامهٔ تازه، توضیح، …) شناسهٔ اعلانِ تازه دارد؛ شمارنده همان قاعدهٔ زنگوله است؛
 * با دیدنِ نامه خوانده می‌شود؛ هر کاربر فقط اعلان‌هایِ خودش را می‌بیند.
 */
class MessageLiveNotificationTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const USER_SENDER = 2;

    private function recent(int $userId): array
    {
        return $this->actingAs(User::find($userId))->getJson('/messages/notifications/recent')->assertOk()->json();
    }

    private function bell(int $userId): int
    {
        return (int) DB::table('UserNotifications')->where('UserID', $userId)->where('IsRead', 0)->distinct()->count('MessageID');
    }

    public function test_new_message_and_each_change_appear_live_and_clear_when_viewed(): void
    {
        $recipient = $this->createUserWithoutRoles();
        $outsider = $this->createUserWithoutRoles();
        $subject = 'اعلانِ زنده ' . bin2hex(random_bytes(4));

        $this->actingAs(User::find(self::USER_SENDER))->post('/messages', [
            'MessageTypeID' => 1,
            'msgPriorityID' => 1,
            'Subject' => $subject,
            'MessageText' => 'متنِ آزمون',
            'RecipientType' => 1,
            'RecipientUserIDs' => [$recipient],
        ])->assertSessionHasNoErrors();
        $id = (int) DB::selectOne('SELECT TOP 1 MessageID FROM dbo.Messages WHERE Subject = ? ORDER BY MessageID DESC', [$subject])->MessageID;

        $first = $this->recent($recipient);
        $this->assertSame(1, $first['unreadCount']);
        $this->assertSame($this->bell($recipient), $first['unreadCount'], 'همان شمارهٔ زنگوله');
        $this->assertSame($id, $first['notifications'][0]['MessageID']);
        $this->assertSame($subject, $first['notifications'][0]['Subject']);
        $firstNoticeId = $first['notifications'][0]['UserNotificationID'];

        // توضیحِ تازه از فرستنده → اعلانِ تازه (شناسهٔ تازه) برایِ همان نامه، شمارنده همچنان ۱ نامه
        $this->actingAs(User::find(self::USER_SENDER))->post("/messages/{$id}/comment", ['Comment' => 'توضیحِ تازه'])->assertSessionHasNoErrors();
        $second = $this->recent($recipient);
        $this->assertSame(1, $second['unreadCount']);
        $this->assertCount(1, $second['notifications'], 'یک ردیف به‌ازایِ هر نامه');
        $this->assertGreaterThan($firstNoticeId, $second['notifications'][0]['UserNotificationID']);

        // کاربرِ بی‌ربط چیزی از این نامه نمی‌بیند
        $this->assertNotContains($id, array_column($this->recent($outsider)['notifications'], 'MessageID'));

        // دیدنِ نامه → خوانده
        $this->actingAs(User::find($recipient))->get("/messages/{$id}")->assertOk();
        $after = $this->recent($recipient);
        $this->assertSame(0, $after['unreadCount']);
        $this->assertSame([], $after['notifications']);
    }

    public function test_requires_login(): void
    {
        $this->getJson('/messages/notifications/recent')->assertUnauthorized();
    }
}
