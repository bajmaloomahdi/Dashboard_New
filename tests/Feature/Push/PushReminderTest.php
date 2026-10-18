<?php

namespace Tests\Feature\Push;

use Illuminate\Support\Facades\DB;

/**
 * یادآورها: وضعیتِ Push مستقل از Reminders.IsSent (که همچنان یعنی «کاربر تأیید کرد») مدیریت می‌شود.
 */
class PushReminderTest extends PushTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activate(10); // مرزِ شروع ۱۰ دقیقه قبل تا یادآورِ «یک دقیقه پیش» بعد از آن باشد
    }

    public function test_due_reminder_is_pushed_once_and_is_sent_flag_is_untouched(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $id = $this->reminder($user);

        $this->dispatch();
        $this->dispatch();

        $sent = $this->fake->sentTo($endpoint);
        $this->assertCount(1, $sent);
        $this->assertSame('REMINDER', $sent[0]['payload']['kind']);
        $this->assertSame('/calendar', $sent[0]['payload']['url']);
        $this->assertSame('high', $sent[0]['options']['urgency']);
        $this->assertStringNotContainsString('محرمانه', $sent[0]['rawPayload']); // عنوانِ یادآور در اعلان نیست
        $this->assertSame(0, (int) DB::selectOne('SELECT IsSent FROM dbo.Reminders WHERE ReminderID = ?', [$id])->IsSent);
    }

    public function test_recurring_reminder_gets_a_separate_push_for_the_next_occurrence(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $id = $this->reminder($user, 'DATEADD(MINUTE, -5, SYSDATETIME())');
        $this->dispatch();

        // کاربر تأیید کرد و نوبتِ بعدیِ رویدادِ تکرارشونده سررسید شد (RemindAt جابه‌جا شد)
        DB::update('UPDATE dbo.Reminders SET RemindAt = CAST(DATEADD(MINUTE, -1, SYSDATETIME()) AS DATETIME2(0)) WHERE ReminderID = ?', [$id]);
        $this->dispatch();

        $sent = $this->fake->sentTo($endpoint);
        $this->assertCount(2, $sent);
        $this->assertNotSame($sent[0]['payload']['tag'], $sent[1]['payload']['tag']);
        $this->assertCount(2, $this->outbox($user, 'REMINDER'));
    }

    public function test_reminder_acknowledged_before_push_is_not_sent(): void
    {
        $user = $this->createUserWithoutRoles();
        $endpoint = $this->subscribe($user);
        $id = $this->reminder($user);
        $this->store()->enqueue(null);
        $this->assertSame('PENDING', $this->outbox($user, 'REMINDER')[0]->Status);

        DB::update('UPDATE dbo.Reminders SET IsSent = 1, SentDate = SYSDATETIME() WHERE ReminderID = ?', [$id]);
        $this->dispatch();

        $this->assertSame('SKIPPED_ACKNOWLEDGED', $this->outbox($user, 'REMINDER')[0]->Status);
        $this->assertCount(0, $this->fake->sentTo($endpoint));
    }

    public function test_reminder_due_before_activation_and_future_reminders_are_not_pushed(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->subscribe($user);
        $this->reminder($user, 'DATEADD(HOUR, -2, SYSDATETIME())');   // قبل از مرزِ شروع
        $this->reminder($user, 'DATEADD(HOUR, 2, SYSDATETIME())');    // هنوز سررسید نشده

        $this->dispatch();
        $this->assertCount(0, $this->outbox($user, 'REMINDER'));
        $this->assertCount(0, $this->fake->sent);
    }
}
