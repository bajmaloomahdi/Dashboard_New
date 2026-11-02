<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * پچِ ۱۳۴ — وظیفه به مدیرِ هر واحد: کاربرِ عادی هم همهٔ واحدها را در فهرستِ «وظیفه» می‌بیند و به مدیرِ هر واحدی
 * می‌فرستد (ساختارِ سازمانی فقط برایِ جریان‌هایِ کاری). گیرنده همچنان باید دقیقاً یک مدیرِ واحد باشد و نه خودِ فرستنده.
 */
class TaskRecipientAnyUnitTest extends TestCase
{
    use DatabaseTransactions;

    private const NON_MANAGER = 14;   // کارشناسِ واحدِ منابع انسانی
    private const IT_MANAGER = 2;     // مدیرِ واحدِ فناوری اطلاعات

    private function sendTask(int $from, int $to)
    {
        $subject = 'وظیفهٔ آزمون ' . bin2hex(random_bytes(4));
        $this->actingAs(User::find($from))->post('/messages', [
            'MessageTypeID' => (int) DB::table('MessageTypes')->where('MessageTypeName', 'وظیفه')->value('MessageTypeID'),
            'msgPriorityID' => 1, 'Subject' => $subject, 'MessageText' => 'متن', 'RecipientType' => 1, 'RecipientUserIDs' => [$to],
        ]);

        return DB::selectOne('SELECT TOP 1 MessageID FROM dbo.Messages WHERE Subject = ? ORDER BY MessageID DESC', [$subject]);
    }

    public function test_non_manager_sees_every_active_unit_in_task_targets(): void
    {
        $units = collect(DB::select('EXEC sp_GetSendTargets @UserID = ?, @IsTask = 1', [self::NON_MANAGER]));
        $active = (int) DB::table('OrganizationalUnits')->where('IsActive', 1)->count();
        $this->assertCount($active, $units, 'همهٔ واحدهایِ فعال، نه فقط واحدِ خودش');
        $itUnit = (int) DB::table('UserPositions')->where('UserID', self::IT_MANAGER)->where('IsActive', 1)->value('UnitID');
        $this->assertTrue($units->contains(fn ($u) => (int) $u->UnitID === $itUnit), 'واحدِ مدیرِ فناوری اطلاعات در فهرست است');
    }

    public function test_non_manager_can_send_a_task_to_the_manager_of_another_unit(): void
    {
        $msg = $this->sendTask(self::NON_MANAGER, self::IT_MANAGER);
        $this->assertNotNull($msg, 'وظیفه ثبت شد');
        $this->assertSame(self::IT_MANAGER, (int) DB::table('MessageDetails')->where('MessageID', $msg->MessageID)->value('ToUserID'));
        $this->assertTrue(DB::table('UserNotifications')->where('MessageID', $msg->MessageID)->where('UserID', self::IT_MANAGER)->exists(), 'اعلان برایِ مدیر');
    }

    public function test_recipient_must_still_be_a_unit_manager_and_not_the_sender(): void
    {
        $this->assertNull($this->sendTask(self::IT_MANAGER, self::NON_MANAGER), 'کارشناس مدیرِ واحد نیست');
        $this->assertNull($this->sendTask(self::IT_MANAGER, self::IT_MANAGER), 'وظیفه به خود');
    }
}
