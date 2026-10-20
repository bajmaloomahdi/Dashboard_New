<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * تقویمِ مشترک (پچِ ۱۱۰): اشتراکِ تقویمِ شخصی در سطحِ FREEBUSY/READ، تقویمِ واحدِ سازمانی (فقط عضویتِ مستقیم)،
 * مدیرِ رویدادِ واحد (CALENDAR_UNIT_MANAGE)، و محرمانگیِ «آزاد/مشغول». همه با DatabaseTransactions برمی‌گردد.
 */
class CalendarSharingTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE'];

    private function user(array $perms = self::CAL): int
    {
        $id = $this->createUserWithoutRoles();
        if ($perms) {
            $this->grantPermissionsTo($id, $perms);
        }

        return $id;
    }

    private function unit(?int $parent = null): int
    {
        $code = 'TU' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert('INSERT INTO dbo.OrganizationalUnits (UnitCode, UnitName, ParentUnitID, IsActive) VALUES (?, ?, ?, 1)', [$code, 'واحدِ آزمون ' . $code, $parent]);

        return (int) DB::table('OrganizationalUnits')->where('UnitCode', $code)->value('UnitID');
    }

    private function member(int $userId, int $unitId, bool $active = true, ?string $endDate = null): void
    {
        $code = 'TP' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert('INSERT INTO dbo.Positions (PositionCode, PositionName, UnitID, IsActive) VALUES (?, ?, ?, 1)', [$code, 'سمتِ آزمون', $unitId]);
        $positionId = (int) DB::table('Positions')->where('PositionCode', $code)->value('PositionID');
        DB::insert('INSERT INTO dbo.UserPositions (UserID, UnitID, PositionID, StartDate, EndDate, IsActive) VALUES (?, ?, ?, SYSDATETIME(), ?, ?)', [$userId, $unitId, $positionId, $endDate, $active ? 1 : 0]);
    }

    private function event(int $as, array $data = []): int
    {
        return (int) $this->actingAs(User::find($as))->postJson('/calendar/events', $data + [
            'Title' => 'جلسهٔ محرمانهٔ قرارداد', 'Description' => 'جزئیاتِ حساس',
            'StartDateTime' => '2099-05-10 10:00:00', 'EndDateTime' => '2099-05-10 11:00:00', 'RecurrenceType' => 'NONE',
        ])->assertOk()->json('eventId');
    }

    private function feed(int $viewer, int $owner)
    {
        return $this->actingAs(User::find($viewer))->getJson("/calendar/events?from=2099-05-01&to=2099-05-31&userId={$owner}");
    }

    private function access(int $viewer, int $owner): string
    {
        return DB::selectOne('EXEC dbo.sp_Calendar_GetAccess @ViewerID = ?, @OwnerUserID = ?', [$viewer, $owner])->AccessLevel;
    }

    private function share(int $owner, int $with, string $level)
    {
        return $this->actingAs(User::find($owner))->postJson('/calendar/shares', ['SharedWithUserID' => $with, 'AccessLevel' => $level]);
    }

    // ---------------- اشتراکِ تقویمِ شخصی ----------------

    public function test_access_levels_follow_shares_and_permissions(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_SHARE']);
        $reader = $this->user(['CALENDAR_VIEW']);
        $busy = $this->user(['CALENDAR_VIEW']);
        $stranger = $this->user(['CALENDAR_VIEW']);
        $admin = $this->user(['CALENDAR_VIEW', 'CALENDAR_VIEW_OTHERS']);
        $noView = $this->user([]);

        $this->share($owner, $reader, 'READ')->assertOk();
        $this->share($owner, $busy, 'FREEBUSY')->assertOk();
        DB::insert("INSERT INTO dbo.CalendarShares (OwnerUserID, SharedWithUserID, AccessLevel) VALUES (?, ?, N'READ')", [$owner, $noView]);

        $this->assertSame('OWNER', $this->access($owner, $owner));
        $this->assertSame('READ', $this->access($reader, $owner));
        $this->assertSame('FREEBUSY', $this->access($busy, $owner));
        $this->assertSame('NONE', $this->access($stranger, $owner));
        $this->assertSame('ADMIN', $this->access($admin, $owner)); // رفتارِ فعلیِ CALENDAR_VIEW_OTHERS
        $this->assertSame('NONE', $this->access($noView, $owner)); // بدونِ CALENDAR_VIEW حتی با اشتراک

        DB::update('UPDATE dbo.Users SET IsActive = 0 WHERE UserID = ?', [$owner]);
        $this->assertSame('NONE', $this->access($reader, $owner)); // صاحبِ غیرفعال
    }

    public function test_read_share_sees_details_read_only(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_SHARE']);
        $reader = $this->user(['CALENDAR_VIEW', 'CALENDAR_EDIT']);
        $eventId = $this->event($owner, ['ReminderOffsetMinutes' => 15]);
        $this->share($owner, $reader, 'READ')->assertOk();

        $events = $this->feed($reader, $owner)->assertOk()->json('events');
        $this->assertSame('جلسهٔ محرمانهٔ قرارداد', $events[0]['Title']);
        // فیلدهایِ «من» نسبت به صاحب محاسبه شده‌اند → برایِ بیننده خنثی (فقط‌خواندنی، یادآوریِ صاحب لو نمی‌رود)
        $this->assertSame([0, 'NONE', null], [(int) $events[0]['CanManage'], $events[0]['MyRelation'], $events[0]['MyReminderOffsetMinutes']]);
        $this->actingAs(User::find($reader))->getJson("/calendar/events/{$eventId}")->assertOk();
        // فقط‌خواندنی: نه ویرایش، نه جابه‌جایی
        $this->actingAs(User::find($reader))->postJson("/calendar/events/{$eventId}/move", ['StartDateTime' => '2099-05-11 10:00:00', 'EndDateTime' => '2099-05-11 11:00:00'])->assertStatus(403);
    }

    public function test_freebusy_share_reveals_only_busy_times(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_SHARE']);
        $busy = $this->user(['CALENDAR_VIEW']);
        $eventId = $this->event($owner);
        $this->event($owner, ['Title' => 'لغوشده', 'Status' => 'CANCELLED', 'StartDateTime' => '2099-05-12 10:00:00', 'EndDateTime' => '2099-05-12 11:00:00']);
        $this->share($owner, $busy, 'FREEBUSY')->assertOk();

        $res = $this->feed($busy, $owner)->assertOk();
        $events = $res->json('events');
        $this->assertCount(1, $events); // رویدادِ لغوشده «مشغول» نیست
        $this->assertSame(['2099-05-10 10:00:00', '2099-05-10 11:00:00', true], [substr($events[0]['StartDateTime'], 0, 19), substr($events[0]['EndDateTime'], 0, 19), $events[0]['IsBusyOnly']]);
        $raw = $res->getContent();
        foreach (['محرمانه', 'قرارداد', 'جزئیاتِ حساس', (string) $eventId] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, "FREEBUSY نباید «{$secret}» را لو دهد");
        }
        $this->actingAs(User::find($busy))->getJson("/calendar/events/{$eventId}")->assertStatus(403);
    }

    public function test_no_share_means_no_access(): void
    {
        $owner = $this->user();
        $stranger = $this->user(['CALENDAR_VIEW']);
        $eventId = $this->event($owner);

        $this->feed($stranger, $owner)->assertStatus(403);
        $this->actingAs(User::find($stranger))->getJson("/calendar/events/{$eventId}")->assertStatus(403);
    }

    public function test_sharing_requires_permission_and_can_be_revoked(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_SHARE']);
        $noShare = $this->user();
        $reader = $this->user(['CALENDAR_VIEW']);

        $this->share($noShare, $reader, 'READ')->assertStatus(403);
        $this->share($owner, $owner, 'READ')->assertStatus(422);         // با خودش
        $this->share($owner, $reader, 'EDIT')->assertStatus(422);        // فقط FREEBUSY/READ
        $id = (int) $this->share($owner, $reader, 'READ')->assertOk()->json('shareId');
        $this->share($owner, $reader, 'FREEBUSY')->assertOk();           // تغییرِ سطح، بدونِ ردیفِ تکراری
        $this->assertSame(1, DB::table('CalendarShares')->where('OwnerUserID', $owner)->where('SharedWithUserID', $reader)->count());

        $shares = $this->actingAs(User::find($owner))->getJson('/calendar/shares')->assertOk()->json('shares');
        $this->assertSame([[$reader, 'FREEBUSY']], array_map(fn ($s) => [(int) $s['SharedWithUserID'], $s['AccessLevel']], $shares));
        $this->assertSame([$owner], array_map(fn ($s) => (int) $s['OwnerUserID'], $this->actingAs(User::find($reader))->getJson('/calendar/sources')->assertOk()->json('shared')));

        // دیگری نمی‌تواند اشتراکِ من را لغو کند
        $this->actingAs(User::find($reader))->deleteJson("/calendar/shares/{$id}")->assertStatus(404);
        $this->actingAs(User::find($owner))->deleteJson("/calendar/shares/{$id}")->assertOk();
        $this->assertSame('NONE', $this->access($reader, $owner));
        $this->assertSame(1, DB::table('CalendarShares')->where('CalendarShareID', $id)->count()); // حذفِ نرم
    }

    // ---------------- تقویمِ واحد ----------------

    public function test_unit_calendar_visible_only_to_direct_active_members(): void
    {
        $unit = $this->unit();
        $child = $this->unit($unit);
        $manager = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $member = $this->user(['CALENDAR_VIEW']);
        $childMember = $this->user(['CALENDAR_VIEW']);
        $former = $this->user(['CALENDAR_VIEW']);
        $ended = $this->user(['CALENDAR_VIEW']);
        $this->member($manager, $unit);
        $this->member($member, $unit);
        $this->member($childMember, $child);                       // زیرواحد: دسترسیِ خودکار ندارد
        $this->member($former, $unit, false);                       // عضویتِ غیرفعال
        $this->member($ended, $unit, true, '2000-01-01 00:00:00');  // عضویتِ پایان‌یافته
        $eventId = $this->event($manager, ['OrganizationalUnitID' => $unit]);

        $url = "/calendar/units/{$unit}/events?from=2099-05-01&to=2099-05-31";
        $this->assertSame([$eventId], array_map(fn ($e) => (int) $e['EventID'], $this->actingAs(User::find($member))->getJson($url)->assertOk()->json('events')));
        foreach ([$childMember, $former, $ended] as $u) {
            $this->actingAs(User::find($u))->getJson($url)->assertStatus(403);
            $this->actingAs(User::find($u))->getJson("/calendar/events/{$eventId}")->assertStatus(403);
        }
        $this->actingAs(User::find($member))->getJson("/calendar/events/{$eventId}")->assertOk();
        $this->assertSame([$unit], array_map(fn ($u) => (int) $u['UnitID'], $this->actingAs(User::find($member))->getJson('/calendar/sources')->json('units')));
    }

    public function test_only_unit_managers_create_unit_events(): void
    {
        $unit = $this->unit();
        $other = $this->unit();
        $plainMember = $this->user(self::CAL);
        $manager = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $this->member($plainMember, $unit);
        $this->member($manager, $unit);

        $data = ['Title' => 'جلسهٔ واحد', 'StartDateTime' => '2099-05-10 10:00:00', 'EndDateTime' => '2099-05-10 11:00:00', 'RecurrenceType' => 'NONE'];
        $this->actingAs(User::find($plainMember))->postJson('/calendar/events', $data + ['OrganizationalUnitID' => $unit])->assertStatus(403);
        $this->actingAs(User::find($manager))->postJson('/calendar/events', $data + ['OrganizationalUnitID' => $other])->assertStatus(403); // عضو نیست
        $this->actingAs(User::find($manager))->postJson('/calendar/events', $data + ['OrganizationalUnitID' => $unit])->assertOk();

        // لایهٔ دوم: خودِ SP هم رد می‌کند
        $r = DB::selectOne("EXEC dbo.sp_InsertCalendarEvent @Title = N'x', @StartDateTime = '2099-05-10 10:00:00', @EndDateTime = '2099-05-10 11:00:00', @OwnerUserID = ?, @OrganizationalUnitID = ?, @CreateUser = ?", [$plainMember, $unit, $plainMember]);
        $this->assertSame(0, (int) $r->Success);
    }

    public function test_unit_manager_can_edit_move_and_delete_others_unit_events(): void
    {
        $unit = $this->unit();
        $creator = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $manager = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $plainMember = $this->user(self::CAL);
        foreach ([$creator, $manager, $plainMember] as $u) {
            $this->member($u, $unit);
        }
        $eventId = $this->event($creator, ['OrganizationalUnitID' => $unit]);

        $move = ['StartDateTime' => '2099-05-11 10:00:00', 'EndDateTime' => '2099-05-11 11:00:00'];
        $this->actingAs(User::find($plainMember))->postJson("/calendar/events/{$eventId}/move", $move)->assertStatus(403);
        $this->actingAs(User::find($manager))->postJson("/calendar/events/{$eventId}/move", $move)->assertOk();
        $this->assertSame($unit, (int) DB::table('CalendarEvents')->where('EventID', $eventId)->value('OrganizationalUnitID'));

        $this->actingAs(User::find($manager))->putJson("/calendar/events/{$eventId}", [
            'Title' => 'عنوانِ تازه', 'StartDateTime' => '2099-05-11 10:00:00', 'EndDateTime' => '2099-05-11 11:30:00', 'RecurrenceType' => 'NONE', 'OrganizationalUnitID' => $unit,
        ])->assertOk();
        $this->actingAs(User::find($plainMember))->deleteJson("/calendar/events/{$eventId}")->assertStatus(422);
        $this->actingAs(User::find($manager))->deleteJson("/calendar/events/{$eventId}")->assertOk();
    }

    public function test_former_manager_loses_unit_rights(): void
    {
        $unit = $this->unit();
        $creator = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $leaver = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $this->member($creator, $unit);
        $this->member($leaver, $unit);
        $eventId = $this->event($creator, ['OrganizationalUnitID' => $unit]);
        DB::update('UPDATE dbo.UserPositions SET IsActive = 0 WHERE UserID = ?', [$leaver]);

        $this->actingAs(User::find($leaver))->postJson("/calendar/events/{$eventId}/move", ['StartDateTime' => '2099-05-11 10:00:00', 'EndDateTime' => '2099-05-11 11:00:00'])->assertStatus(403);
        $r = DB::selectOne('EXEC dbo.sp_DeleteCalendarEvent @EventID = ?, @ModifyUser = ?', [$eventId, $leaver]);
        $this->assertSame(0, (int) $r->Success);
    }

    public function test_unit_member_cannot_set_personal_reminder_on_unit_event(): void
    {
        $unit = $this->unit();
        $manager = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $member = $this->user(['CALENDAR_VIEW']);
        $attendee = $this->user(['CALENDAR_VIEW']);
        foreach ([$manager, $member, $attendee] as $u) {
            $this->member($u, $unit);
        }
        $this->grantPermissionsTo($manager, ['CALENDAR_CREATE_FOR_OTHERS']);
        $eventId = $this->event($manager, ['OrganizationalUnitID' => $unit, 'AttendeeUserIDs' => [$attendee]]);

        $this->actingAs(User::find($member))->postJson("/calendar/events/{$eventId}/reminder", ['ReminderOffsetMinutes' => 10])->assertStatus(403);
        $this->assertSame(0, DB::table('Reminders')->where('EntityID', $eventId)->where('UserID', $member)->count());
        // شرکت‌کنندهٔ همان رویداد می‌تواند
        $this->actingAs(User::find($attendee))->postJson("/calendar/events/{$eventId}/reminder", ['ReminderOffsetMinutes' => 10])->assertOk();
    }

    public function test_personal_events_stay_private_from_unit_members(): void
    {
        $unit = $this->unit();
        $owner = $this->user();
        $member = $this->user(['CALENDAR_VIEW']);
        $this->member($owner, $unit);
        $this->member($member, $unit);
        $personal = $this->event($owner); // بدونِ واحد

        $this->assertSame([], $this->actingAs(User::find($member))->getJson("/calendar/units/{$unit}/events?from=2099-05-01&to=2099-05-31")->assertOk()->json('events'));
        $this->actingAs(User::find($member))->getJson("/calendar/events/{$personal}")->assertStatus(403);
    }

    public function test_event_details_hide_other_attendees_reminders(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_SHARE', 'CALENDAR_CREATE_FOR_OTHERS']);
        $a = $this->user(['CALENDAR_VIEW']);
        $b = $this->user(['CALENDAR_VIEW']);
        $reader = $this->user(['CALENDAR_VIEW']);
        $eventId = $this->event($owner, ['AttendeeUserIDs' => [$a, $b], 'AttendeeReminders' => [$a => 30, $b => 60]]);
        $this->share($owner, $reader, 'READ')->assertOk();

        $offsets = fn (int $viewer) => collect($this->actingAs(User::find($viewer))->getJson("/calendar/events/{$eventId}")->assertOk()->json('attendees'))
            ->mapWithKeys(fn ($r) => [(int) $r['UserID'] => $r['ReminderOffsetMinutes'] === null ? null : (int) $r['ReminderOffsetMinutes']])->all();

        // مدیرِ رویداد (برایِ فرمِ ویرایش) همه را می‌بیند
        $this->assertSame([30, 60], [$offsets($owner)[$a], $offsets($owner)[$b]]);
        // شرکت‌کننده فقط یادآوریِ خودش را
        $this->assertSame([30, null], [$offsets($a)[$a], $offsets($a)[$b]]);
        // بینندهٔ READ هیچ‌کدام را
        $this->assertSame([null, null], [$offsets($reader)[$a], $offsets($reader)[$b]]);
    }
}
