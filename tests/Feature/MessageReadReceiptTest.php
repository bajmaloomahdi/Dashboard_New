<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * رسیدِ مشاهده و تغییرِ تازهٔ پیام (پچ 102):
 * فرستنده می‌بیند گیرنده/رونوشت پیام را دیده‌اند یا نه؛ توضیح/ضمیمهٔ تازه پیام را برایِ بقیهٔ طرف‌ها «تغییرِ تازه»
 * می‌کند و شمارندهٔ پیام‌هایِ خوانده‌نشده (تعدادِ پیام، نه ردیفِ اعلان) زیاد می‌شود.
 */
class MessageReadReceiptTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const USER_SENDER = 2;

    private function as(int $userId): self
    {
        $this->actingAs(User::find($userId));

        return $this;
    }

    /** پیامِ متنِ آزاد از فرستنده به یک گیرنده + یک رونوشت؛ خروجی: [messageId, recipientId, copyId] */
    private function send(): array
    {
        $recipient = $this->createUserWithoutRoles();
        $copy = $this->createUserWithoutRoles();
        $subject = 'رسیدِ مشاهده ' . bin2hex(random_bytes(4));

        $this->as(self::USER_SENDER)->post('/messages', [
            'MessageTypeID' => 1,
            'msgPriorityID' => 1,
            'Subject' => $subject,
            'MessageText' => 'متنِ آزمون',
            'RecipientType' => 1,
            'RecipientUserIDs' => [$recipient],
            'CopyUserIDs' => [$copy],
        ])->assertSessionHasNoErrors();

        $id = (int) DB::selectOne('SELECT TOP 1 MessageID FROM dbo.Messages WHERE Subject = ? ORDER BY MessageID DESC', [$subject])->MessageID;
        $this->assertGreaterThan(0, $id);

        return [$id, $recipient, $copy];
    }

    private function sentRow(int $messageId): object
    {
        $row = collect(DB::select('EXEC sp_GetMessages @UserID = ?, @Mode = 2', [self::USER_SENDER]))->firstWhere('MessageID', $messageId);
        $this->assertNotNull($row);

        return $row;
    }

    private function inboxRow(int $userId, int $messageId): ?object
    {
        foreach ([0, 1] as $archive) {
            $row = collect(DB::select('EXEC sp_GetMessages @UserID = ?, @Mode = 1, @IsArchive = ?', [$userId, $archive]))->firstWhere('MessageID', $messageId);
            if ($row) {
                return $row;
            }
        }

        return null;
    }

    private function unreadCount(int $userId): int
    {
        return (int) DB::selectOne('EXEC sp_GetUnreadNotificationsCount @UserID = ?', [$userId])->UnreadCount;
    }

    public function test_sender_sees_who_viewed_the_message(): void
    {
        [$id, $recipient, $copy] = $this->send();

        $row = $this->sentRow($id);
        $this->assertSame(2, (int) $row->RecipientCount);
        $this->assertSame(0, (int) $row->SeenCount);

        // گیرنده پیام را باز می‌کند
        $this->as($recipient)->get("/messages/{$id}")->assertOk();
        $this->assertSame(1, (int) $this->sentRow($id)->SeenCount);

        $detail = collect(DB::select('EXEC dbo.sp_GetMessageDetailsList @MessageID = ?', [$id]))->firstWhere('ToUserID', $recipient);
        $this->assertSame(1, (int) $detail->ToSeen);
        $this->assertNotNull($detail->ToSeenDate);

        $copyRow = collect(DB::select('EXEC dbo.sp_GetMessageCopiesList @MessageID = ?', [$id]))->firstWhere('UserID', $copy);
        $this->assertSame(0, (int) $copyRow->Seen);

        // رونوشت‌گیرنده هم باز می‌کند → همه دیده‌اند
        $this->as($copy)->get("/messages/{$id}")->assertOk();
        $this->assertSame(2, (int) $this->sentRow($id)->SeenCount);

        // فرستنده در صفحهٔ پیام رسیدها را می‌گیرد
        $this->as(self::USER_SENDER)->get("/messages/{$id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('copies.0.Seen', fn ($v) => (int) $v === 1)
            ->has('details.0.ToSeen'));
    }

    public function test_comment_marks_message_as_updated_for_others_and_raises_unread_count(): void
    {
        [$id, $recipient, $copy] = $this->send();

        // پیامِ تازه: برایِ گیرنده «جدید»
        $row = $this->inboxRow($recipient, $id);
        $this->assertSame(0, (int) $row->IsRead);
        $this->assertSame(1, (int) $row->HasUnread);
        $this->assertSame(1, $this->unreadCount($recipient));

        // گیرنده می‌خواند → دیگر تغییرِ تازه ندارد
        $this->as($recipient)->get("/messages/{$id}")->assertOk();
        $row = $this->inboxRow($recipient, $id);
        $this->assertSame(1, (int) $row->IsRead);
        $this->assertSame(0, (int) $row->HasUnread);
        $this->assertSame(0, $this->unreadCount($recipient));
        $senderBefore = $this->unreadCount(self::USER_SENDER);

        // فرستنده توضیح می‌گذارد → گیرنده و رونوشت «تغییرِ تازه» می‌گیرند، خودِ فرستنده نه
        $this->as(self::USER_SENDER)->post("/messages/{$id}/comment", ['Comment' => 'توضیحِ تازه'])->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $this->inboxRow($recipient, $id)->HasUnread);
        $this->assertSame(1, $this->unreadCount($recipient));
        $this->assertSame(1, (int) $this->inboxRow($copy, $id)->HasUnread);
        $this->assertSame($senderBefore, $this->unreadCount(self::USER_SENDER));

        // توضیحِ دوم پیش از خواندن، تعدادِ پیام‌هایِ خوانده‌نشده را دوبار نمی‌شمارد —
        // ولی خودش اعلانِ تازه دارد (پچِ ۱۳۰: دیگر به‌خاطرِ اعلانِ خوانده‌نشدهٔ قبلی جا نمی‌افتد → Push هم می‌رود)
        $rowsBefore = DB::table('UserNotifications')->where('UserID', $recipient)->where('MessageID', $id)->where('IsRead', 0)->count();
        $this->as(self::USER_SENDER)->post("/messages/{$id}/comment", ['Comment' => 'توضیحِ دوم'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->unreadCount($recipient));
        $this->assertSame($rowsBefore + 1, DB::table('UserNotifications')->where('UserID', $recipient)->where('MessageID', $id)->where('IsRead', 0)->count());

        // گیرنده توضیح می‌گذارد → فرستنده «تغییرِ تازه» می‌گیرد
        $this->as($recipient)->post("/messages/{$id}/comment", ['Comment' => 'پاسخ'])->assertSessionHasNoErrors();
        $this->assertSame(1, (int) $this->sentRow($id)->HasUnread);

        // دوباره خواندن → پاک می‌شود و زمانِ آخرین مشاهده ثبت می‌شود
        $this->as($recipient)->get("/messages/{$id}")->assertOk();
        $this->assertSame(0, (int) $this->inboxRow($recipient, $id)->HasUnread);
        $view = DB::selectOne('SELECT FirstViewDate, LastViewDate FROM dbo.MessageViews WHERE MessageID = ? AND UserID = ?', [$id, $recipient]);
        $this->assertNotNull($view);
        $this->assertGreaterThanOrEqual($view->FirstViewDate, $view->LastViewDate);
    }

    public function test_unread_count_counts_messages_not_notification_rows(): void
    {
        [$id, $recipient] = $this->send();
        DB::insert('INSERT INTO dbo.UserNotifications (UserID, MessageID, CreateDate, IsRead) VALUES (?, ?, GETDATE(), 0)', [$recipient, $id]);

        $this->assertSame(1, $this->unreadCount($recipient));
        $this->as($recipient)->get('/messages')->assertOk()->assertInertia(fn ($page) => $page->where('unreadNotificationsCount', 1));
    }
}
