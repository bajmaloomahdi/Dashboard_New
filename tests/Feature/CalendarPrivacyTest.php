<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * حریمِ خصوصیِ رویدادها (پچِ ۱۱۱): رویدادِ خصوصی برایِ غیرِ شرکت‌کنندگان (حتی CALENDAR_VIEW_OTHERS و اشتراکِ READ)
 * فقط «مشغول» است؛ رویدادِ واحد خصوصی نمی‌شود؛ شرکت‌کننده در تقویمِ اشتراکی (همهٔ سطوح) رویدادِ واقعی را می‌گیرد
 * تا UI نسخهٔ تکراریِ «مشغول» را حذف کند. همه با DatabaseTransactions برمی‌گردد.
 */
class CalendarPrivacyTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE'];
    private const SECRET_TITLE = 'جلسهٔ محرمانهٔ ادغام';
    private const SECRET_DESC = 'جزئیاتِ بسیار حساس';

    private function user(array $perms = self::CAL): int
    {
        $id = $this->createUserWithoutRoles();
        if ($perms) {
            $this->grantPermissionsTo($id, $perms);
        }

        return $id;
    }

    private function unit(): int
    {
        $code = 'TU' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert('INSERT INTO dbo.OrganizationalUnits (UnitCode, UnitName, ParentUnitID, IsActive) VALUES (?, ?, NULL, 1)', [$code, 'واحدِ آزمون ' . $code]);

        return (int) DB::table('OrganizationalUnits')->where('UnitCode', $code)->value('UnitID');
    }

    private function member(int $userId, int $unitId): void
    {
        $code = 'TP' . strtoupper(bin2hex(random_bytes(4)));
        DB::insert('INSERT INTO dbo.Positions (PositionCode, PositionName, UnitID, IsActive) VALUES (?, ?, ?, 1)', [$code, 'سمتِ آزمون', $unitId]);
        $positionId = (int) DB::table('Positions')->where('PositionCode', $code)->value('PositionID');
        DB::insert('INSERT INTO dbo.UserPositions (UserID, UnitID, PositionID, StartDate, EndDate, IsActive) VALUES (?, ?, ?, SYSDATETIME(), NULL, 1)', [$userId, $unitId, $positionId]);
    }

    private function payload(array $data = []): array
    {
        return $data + [
            'Title' => self::SECRET_TITLE, 'Description' => self::SECRET_DESC,
            'StartDateTime' => '2099-05-10 10:00:00', 'EndDateTime' => '2099-05-10 11:00:00', 'RecurrenceType' => 'NONE',
        ];
    }

    private function event(int $as, array $data = []): int
    {
        return (int) $this->actingAs(User::find($as))->postJson('/calendar/events', $this->payload($data))->assertOk()->json('eventId');
    }

    private function isPrivate(int $eventId): int
    {
        return (int) DB::table('CalendarEvents')->where('EventID', $eventId)->value('IsPrivate');
    }

    private function share(int $owner, int $with, string $level): void
    {
        DB::insert('INSERT INTO dbo.CalendarShares (OwnerUserID, SharedWithUserID, AccessLevel) VALUES (?, ?, ?)', [$owner, $with, $level]);
    }

    private function feed(int $viewer, int $owner)
    {
        return $this->actingAs(User::find($viewer))->getJson("/calendar/events?from=2099-05-01&to=2099-05-31&userId={$owner}")->assertOk();
    }

    /** پاسخِ خامِ سرور هیچ اثری از رویدادِ خصوصی ندارد */
    private function assertNoLeak($response, int $eventId): void
    {
        $raw = $response->getContent();
        foreach ([self::SECRET_TITLE, self::SECRET_DESC] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        foreach ($response->json('events') as $ev) {
            $this->assertNotSame($eventId, (int) $ev['EventID']);
            $this->assertStringNotContainsString((string) $eventId, (string) $ev['OccurrenceKey']);
        }
    }

    public function test_new_event_is_public_by_default(): void
    {
        $owner = $this->user();
        $this->assertSame(0, $this->isPrivate($this->event($owner)));
        $this->assertSame(1, $this->isPrivate($this->event($owner, ['IsPrivate' => true])));
    }

    public function test_private_event_is_busy_only_for_read_share_and_admin(): void
    {
        $owner = $this->user();
        $reader = $this->user(['CALENDAR_VIEW']);
        $admin = $this->user(['CALENDAR_VIEW', 'CALENDAR_VIEW_OTHERS', 'CALENDAR_EDIT']);
        $private = $this->event($owner, ['IsPrivate' => true]);
        $public = $this->event($owner, ['Title' => 'جلسهٔ عمومی', 'StartDateTime' => '2099-05-12 10:00:00', 'EndDateTime' => '2099-05-12 11:00:00']);
        $this->share($owner, $reader, 'READ');

        foreach ([$reader, $admin] as $viewer) {
            $res = $this->feed($viewer, $owner);
            $events = collect($res->json('events'));
            $this->assertCount(2, $events);
            $busy = $events->firstWhere('IsBusyOnly', true);
            $this->assertSame(['مشغول', '2099-05-10 10:00:00'], [$busy['Title'], substr($busy['StartDateTime'], 0, 19)]);
            $this->assertSame('جلسهٔ عمومی', $events->firstWhere('EventID', $public)['Title']); // رویدادِ عمومی مثلِ قبل
            $this->assertNoLeak($res, $private);

            // هیچ مسیرِ دیگری: جزئیات، یادآوری، پاسخ، جابه‌جایی
            $this->actingAs(User::find($viewer))->getJson("/calendar/events/{$private}")->assertNotFound();
            $this->actingAs(User::find($viewer))->postJson("/calendar/events/{$private}/reminder", ['ReminderOffsetMinutes' => 10])->assertStatus(403);
            // بدونِ CALENDAR_EDIT → ۴۰۳؛ با آن (مدیرِ سیستم) → ۴۰۴ چون SP ردیفی نمی‌دهد
            $status = $this->actingAs(User::find($viewer))->postJson("/calendar/events/{$private}/move", ['StartDateTime' => '2099-05-11 10:00:00', 'EndDateTime' => '2099-05-11 11:00:00'])->status();
            $this->assertSame($viewer === $admin ? 404 : 403, $status);
        }
        $this->assertSame('2099-05-10 10:00:00', substr((string) DB::table('CalendarEvents')->where('EventID', $private)->value('StartDateTime'), 0, 19));
        $this->assertSame(0, DB::table('Reminders')->where('EntityID', $private)->whereIn('UserID', [$reader, $admin])->count());
        // SP هم به‌تنهایی (لایهٔ دوم) جزئیات نمی‌دهد
        $row = collect(DB::select('EXEC dbo.sp_GetCalendarEvents @UserID = ?, @FromDate = ?, @ToDate = ?, @ViewerID = ?', [$owner, '2099-05-01', '2099-05-31', $admin]))->firstWhere('EventID', $private);
        $this->assertSame(['مشغول', null, null, null], [$row->Title, $row->Description, $row->CreatorName, $row->CreatedByUserID]);
        $this->assertSame([], DB::select('EXEC dbo.sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$private, $admin]));
    }

    public function test_private_event_hidden_from_admin_calendar_page(): void
    {
        $owner = $this->user();
        $admin = $this->user(['CALENDAR_VIEW', 'CALENDAR_VIEW_OTHERS']);
        $start = now()->addHour()->startOfHour();
        $this->event($owner, ['IsPrivate' => true, 'StartDateTime' => $start->toDateTimeString(), 'EndDateTime' => $start->copy()->addHour()->toDateTimeString()]);

        $this->actingAs(User::find($admin))->get("/calendar?userId={$owner}")->assertOk()
            ->assertDontSee(self::SECRET_TITLE)->assertDontSee(self::SECRET_DESC);
    }

    public function test_participants_see_full_private_event(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_CREATE_FOR_OTHERS']);
        $attendee = $this->user(['CALENDAR_VIEW']);
        $private = $this->event($owner, ['IsPrivate' => true, 'AttendeeUserIDs' => [$attendee]]);

        foreach ([$owner, $attendee] as $viewer) {
            $events = $this->feed($viewer, $viewer)->json('events');
            $this->assertSame([self::SECRET_TITLE, self::SECRET_DESC, 1], [$events[0]['Title'], $events[0]['Description'], (int) $events[0]['IsPrivate']]);
            $detail = $this->actingAs(User::find($viewer))->getJson("/calendar/events/{$private}")->assertOk();
            $this->assertSame(self::SECRET_TITLE, $detail->json('event.Title'));
        }

        // شرکت‌کننده‌ای که حذف شد دسترسی را از دست می‌دهد
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$private}", $this->payload(['AttendeeUserIDs' => []]))->assertOk();
        $this->actingAs(User::find($attendee))->getJson("/calendar/events/{$private}")->assertNotFound();
    }

    public function test_participant_gets_real_event_in_shared_calendar_for_dedupe(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_CREATE_FOR_OTHERS']);
        $busyViewer = $this->user(['CALENDAR_VIEW']);
        $readViewer = $this->user(['CALENDAR_VIEW']);
        $this->share($owner, $busyViewer, 'FREEBUSY');
        $this->share($owner, $readViewer, 'READ');
        $withMe = $this->event($owner, ['Title' => 'جلسهٔ مشترک', 'AttendeeUserIDs' => [$busyViewer, $readViewer]]);
        $privateWithMe = $this->event($owner, ['IsPrivate' => true, 'AttendeeUserIDs' => [$busyViewer, $readViewer], 'StartDateTime' => '2099-05-11 10:00:00', 'EndDateTime' => '2099-05-11 11:00:00']);
        $notMine = $this->event($owner, ['Title' => 'جلسهٔ بی‌ربط', 'StartDateTime' => '2099-05-12 10:00:00', 'EndDateTime' => '2099-05-12 11:00:00']);
        $privateNotMine = $this->event($owner, ['IsPrivate' => true, 'StartDateTime' => '2099-05-13 10:00:00', 'EndDateTime' => '2099-05-13 11:00:00']);

        // FREEBUSY: رویدادهایی که بیننده خودش در آن‌هاست (عادی و خصوصی) با شناسهٔ واقعی؛ بقیه فقط «مشغول» بدونِ شناسه
        $events = collect($this->feed($busyViewer, $owner)->json('events'));
        $this->assertSame([$withMe, $privateWithMe], $events->where('IsBusyOnly', '!==', true)->pluck('EventID')->map(fn ($v) => (int) $v)->sort()->values()->all());
        $this->assertSame(2, $events->where('IsBusyOnly', true)->count());
        $this->assertSame([0, 'NONE'], [(int) $events->firstWhere('EventID', $withMe)['CanManage'], $events->firstWhere('EventID', $withMe)['MyRelation']]);
        $raw = json_encode($events->where('IsBusyOnly', true)->values(), JSON_UNESCAPED_UNICODE);
        foreach (['جلسهٔ بی‌ربط', self::SECRET_TITLE, (string) $notMine, (string) $privateNotMine] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }

        // READ: رویدادِ خصوصیِ مربوط به بیننده با جزئیات (همان نسخهٔ خودش)، خصوصیِ بی‌ربط «مشغول»
        $events = collect($this->feed($readViewer, $owner)->json('events'));
        $this->assertSame(self::SECRET_TITLE, $events->firstWhere('EventID', $privateWithMe)['Title']);
        $this->assertNull($events->firstWhere('EventID', $privateNotMine));
        $this->assertSame(1, $events->where('IsBusyOnly', true)->count());
    }

    public function test_privacy_is_preserved_by_update_without_field_and_by_move(): void
    {
        $owner = $this->user();
        $private = $this->event($owner, ['IsPrivate' => true]);

        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$private}", $this->payload(['Title' => 'عنوانِ تازه']))->assertOk();
        $this->assertSame(1, $this->isPrivate($private));
        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$private}/move", ['StartDateTime' => '2099-05-11 10:00:00', 'EndDateTime' => '2099-05-11 11:00:00'])->assertOk();
        $this->assertSame(1, $this->isPrivate($private));
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$private}", $this->payload(['IsPrivate' => false]))->assertOk();
        $this->assertSame(0, $this->isPrivate($private));
    }

    public function test_unit_event_cannot_be_private(): void
    {
        $unit = $this->unit();
        $manager = $this->user([...self::CAL, 'CALENDAR_UNIT_MANAGE']);
        $this->member($manager, $unit);

        // اعتبارسنجیِ سرور
        $this->actingAs(User::find($manager))->postJson('/calendar/events', $this->payload(['OrganizationalUnitID' => $unit, 'IsPrivate' => true]))
            ->assertStatus(422)->assertJson(['message' => 'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.']);
        $private = $this->event($manager, ['IsPrivate' => true]);
        $this->actingAs(User::find($manager))->putJson("/calendar/events/{$private}", $this->payload(['OrganizationalUnitID' => $unit]))->assertStatus(422);
        $unitEvent = $this->event($manager, ['OrganizationalUnitID' => $unit]);
        $this->actingAs(User::find($manager))->putJson("/calendar/events/{$unitEvent}", $this->payload(['IsPrivate' => true]))->assertStatus(422);
        $this->assertSame([1, 0], [$this->isPrivate($private), $this->isPrivate($unitEvent)]);
        $this->assertSame($unit, (int) DB::table('CalendarEvents')->where('EventID', $unitEvent)->value('OrganizationalUnitID'));

        // SP
        $r = DB::selectOne("EXEC dbo.sp_InsertCalendarEvent @Title = N'x', @StartDateTime = '2099-05-10 10:00:00', @EndDateTime = '2099-05-10 11:00:00', @OwnerUserID = ?, @OrganizationalUnitID = ?, @CreateUser = ?, @IsPrivate = 1", [$manager, $unit, $manager]);
        $this->assertSame([0, 'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.'], [(int) $r->Success, $r->Message]);

        // قیدِ دیتابیس
        $this->expectException(QueryException::class);
        DB::update('UPDATE dbo.CalendarEvents SET IsPrivate = 1 WHERE EventID = ?', [$unitEvent]);
    }
}
