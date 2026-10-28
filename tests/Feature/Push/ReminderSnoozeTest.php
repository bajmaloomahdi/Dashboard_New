<?php

namespace Tests\Feature\Push;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * پچِ ۱۳۱ — تعویقِ یادآوری (۵ تا ۶۰ دقیقه، گامِ ۵): تا پایانِ تعویق نه در برنامه نمایش داده می‌شود نه Push؛ پس از آن دوباره
 * (با نسخهٔ تازه) — RemindAt دست نمی‌خورد؛ «متوجه شدم» تعویق را پاک می‌کند.
 */
class ReminderSnoozeTest extends PushTestCase
{
    private function due(int $userId): array
    {
        return array_map(fn ($r) => (int) $r['ReminderID'], $this->actingAs(User::find($userId))->getJson('/calendar/reminders/due')->assertOk()->json('reminders'));
    }

    public function test_snooze_hides_the_reminder_until_it_ends_and_pushes_again(): void
    {
        $user = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($user, ['CALENDAR_VIEW']);
        $endpoint = $this->subscribe($user);
        $this->activate(10);
        $rid = $this->reminder($user);
        $remindAt = DB::table('Reminders')->where('ReminderID', $rid)->value('RemindAt');

        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint));
        $this->assertContains($rid, $this->due($user));

        $res = $this->actingAs(User::find($user))->postJson("/calendar/reminders/{$rid}/snooze", ['Minutes' => 10])->assertOk();
        $this->assertStringContainsString('10 دقیقهٔ دیگر', $res->json('message'));
        $this->assertNotContains($rid, $this->due($user), 'در حالِ تعویق نمایش داده نمی‌شود');
        $this->assertSame($remindAt, DB::table('Reminders')->where('ReminderID', $rid)->value('RemindAt'), 'زمانِ اصلی دست نمی‌خورد');
        $this->dispatch();
        $this->assertCount(1, $this->fake->sentTo($endpoint), 'در حالِ تعویق Push نمی‌شود');

        // پایانِ تعویق (به گذشته می‌بریم) → دوباره نمایش و Pushِ تازه
        DB::update('UPDATE dbo.Reminders SET SnoozedUntil = DATEADD(SECOND, -5, SYSDATETIME()) WHERE ReminderID = ?', [$rid]);
        $due = collect($this->actingAs(User::find($user))->getJson('/calendar/reminders/due')->json('reminders'))->firstWhere('ReminderID', (string) $rid)
            ?? collect($this->actingAs(User::find($user))->getJson('/calendar/reminders/due')->json('reminders'))->firstWhere('ReminderID', $rid);
        $this->assertNotNull($due);
        $this->assertNotNull($due['SnoozedUntil'], 'نسخهٔ تازه برایِ مرورگر');
        $this->dispatch();
        $this->assertCount(2, $this->fake->sentTo($endpoint));

        // «متوجه شدم» → بسته و تعویق پاک
        $this->actingAs(User::find($user))->postJson("/calendar/reminders/{$rid}/seen")->assertOk();
        $row = DB::table('Reminders')->where('ReminderID', $rid)->first(['IsSent', 'SnoozedUntil']);
        $this->assertSame(1, (int) $row->IsSent);
        $this->assertNull($row->SnoozedUntil);
    }

    public function test_snooze_validation_and_ownership(): void
    {
        $user = $this->createUserWithoutRoles();
        $other = $this->createUserWithoutRoles();
        $rid = $this->reminder($user);

        $this->actingAs(User::find($user))->postJson("/calendar/reminders/{$rid}/snooze", ['Minutes' => 7])->assertStatus(422);
        $this->actingAs(User::find($user))->postJson("/calendar/reminders/{$rid}/snooze", ['Minutes' => 90])->assertStatus(422);
        $this->actingAs(User::find($other))->postJson("/calendar/reminders/{$rid}/snooze", ['Minutes' => 5])->assertStatus(422); // یادآوریِ دیگری
        $this->assertNull(DB::table('Reminders')->where('ReminderID', $rid)->value('SnoozedUntil'));
        $this->actingAs(User::find($user))->postJson("/calendar/reminders/{$rid}/snooze", ['Minutes' => 15])->assertOk();
        $this->assertNotNull(DB::table('Reminders')->where('ReminderID', $rid)->value('SnoozedUntil'));
    }
}
