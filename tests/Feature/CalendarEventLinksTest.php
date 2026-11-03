<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCrmTitle;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * پچِ ۱۳۸ — ارتباطِ رویداد با پروژه/طرف‌حساب/فردِ مرتبط، تبِ «رویدادها»ی پروژه و رویدادِ خودکارِ مهلتِ وظیفهٔ پروژه (ساعتِ ۱۳).
 * کاربران: 2 مسئولِ پروژهٔ تست (همهٔ مجوزها)، 3 عضوِ عادیِ پروژه. طرف‌حسابِ واقعیِ از‌پیش‌موجود: 766.
 */
class CalendarEventLinksTest extends TestCase
{
    use CreatesCrmTitle, CreatesPermissionlessUser, DatabaseTransactions;

    private const RESPONSIBLE = 2;
    private const MEMBER = 3;
    private const PARTY = 766;

    private function project(): int
    {
        $title = 'پروژهٔ رویداد ' . strtoupper(bin2hex(random_bytes(4)));
        $this->actingAs(User::find(self::RESPONSIBLE))->post('/projects', ['ProjectTitle' => $title, 'ProjectStatusID' => 1, 'ResponsibleUserID' => self::RESPONSIBLE])->assertRedirect();
        $id = (int) DB::selectOne('SELECT TOP 1 ProjectID FROM dbo.Projects WHERE ProjectTitle = ? ORDER BY ProjectID DESC', [$title])->ProjectID;
        $this->actingAs(User::find(self::RESPONSIBLE))->postJson("/projects/{$id}/members", ['UserID' => self::MEMBER, 'IsResponsible' => false])->assertOk();

        return $id;
    }

    private function links(int $userId, int $eventId): array
    {
        return collect($this->actingAs(User::find($userId))->getJson("/calendar/events/{$eventId}")->assertOk()->json('links'))
            ->map(fn ($l) => $l['LinkType'] . ':' . $l['EntityID'])->sort()->values()->all();
    }

    private function projectEventIds(int $userId, int $projectId)
    {
        return $this->actingAs(User::find($userId))->getJson("/projects/{$projectId}/events?from=2099-01-01&to=2099-12-31");
    }

    public function test_event_links_to_project_party_and_person_and_shows_in_project_tab(): void
    {
        $projectId = $this->project();
        $personId = (int) $this->actingAs(User::find(self::RESPONSIBLE))->postJson('/crm/persons', ['titleId' => $this->crmTitleId(), 'lastName' => 'فردِ رویداد ' . bin2hex(random_bytes(3))])
            ->assertOk()->json('personId');
        $eventId = (int) $this->actingAs(User::find(self::RESPONSIBLE))->postJson('/calendar/events', [
            'Title' => 'جلسهٔ پروژه', 'StartDateTime' => '2099-05-01 10:00:00', 'EndDateTime' => '2099-05-01 11:00:00', 'RecurrenceType' => 'NONE',
            'LinkedProjectIDs' => [$projectId], 'LinkedPartyIDs' => [self::PARTY], 'LinkedPersonIDs' => [$personId],
        ])->assertOk()->json('eventId');

        $this->assertSame(collect(["PARTY:" . self::PARTY, "PERSON:{$personId}", "PROJECT:{$projectId}"])->sort()->values()->all(), $this->links(self::RESPONSIBLE, $eventId));

        // تبِ پروژه: عضوِ پروژه (شرکت‌کنندهٔ رویداد نیست) هم می‌بیند و جزئیات را باز می‌کند؛ غیرِعضو نه
        $ids = array_map(fn ($e) => (int) $e['EventID'], $this->projectEventIds(self::MEMBER, $projectId)->assertOk()->json('events'));
        $this->assertContains($eventId, $ids);
        $this->actingAs(User::find(self::MEMBER))->getJson("/calendar/events/{$eventId}")->assertOk()->assertJsonPath('event.Title', 'جلسهٔ پروژه');
        $outsider = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($outsider, ['CALENDAR_VIEW']);
        $this->projectEventIds($outsider, $projectId)->assertForbidden();

        // ویرایش: کلیدِ نیامده = همان قبلی؛ فهرستِ خالی = حذفِ همان نوع
        $times = ['StartDateTime' => '2099-05-01 10:00:00', 'EndDateTime' => '2099-05-01 11:00:00', 'IsAllDay' => false, 'Color' => '#1677ff'];
        $this->actingAs(User::find(self::RESPONSIBLE))->putJson("/calendar/events/{$eventId}", $times)->assertOk();
        $this->assertCount(3, $this->links(self::RESPONSIBLE, $eventId));
        $this->actingAs(User::find(self::RESPONSIBLE))->putJson("/calendar/events/{$eventId}", $times + ['LinkedProjectIDs' => []])->assertOk();
        $this->assertSame(collect(["PARTY:" . self::PARTY, "PERSON:{$personId}"])->sort()->values()->all(), $this->links(self::RESPONSIBLE, $eventId));
        $this->assertNotContains($eventId, array_map(fn ($e) => (int) $e['EventID'], $this->projectEventIds(self::RESPONSIBLE, $projectId)->json('events')));
    }

    public function test_crm_links_need_crm_view_and_projects_must_be_visible(): void
    {
        $projectId = $this->project();
        $user = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($user, ['CALENDAR_VIEW', 'CALENDAR_CREATE']);
        $eventId = (int) $this->actingAs(User::find($user))->postJson('/calendar/events', [
            'Title' => 'بدونِ CRM', 'StartDateTime' => '2099-05-02 10:00:00', 'EndDateTime' => '2099-05-02 11:00:00', 'RecurrenceType' => 'NONE',
            'LinkedPartyIDs' => [self::PARTY], 'LinkedProjectIDs' => [$projectId], // عضوِ این پروژه نیست
        ])->assertOk()->json('eventId');
        $this->assertSame(0, DB::table('CalendarEventLinks')->where('EventID', $eventId)->count(), 'طرف‌حساب بدونِ CRM_VIEW و پروژهٔ نادیدنی ثبت نمی‌شود');
    }

    public function test_project_task_with_due_date_creates_a_13_oclock_event_for_the_assignee(): void
    {
        $projectId = $this->project();
        $res = $this->actingAs(User::find(self::RESPONSIBLE))->postJson("/projects/{$projectId}/tasks", [
            'ToUserID' => self::MEMBER, 'msgPriorityID' => 1, 'MessageText' => "بررسیِ نقشه‌ها\nجزئیات", 'DueDate' => '2099-07-01',
        ])->assertOk()->assertJson(['success' => true]);
        $this->assertStringContainsString('رویدادِ مهلت', $res->json('message'));

        $messageId = (int) DB::table('ProjectMessages')->where('ProjectID', $projectId)->max('MessageID');
        $eventId = (int) DB::table('CalendarEventLinks')->where('LinkType', 'MESSAGE')->where('EntityID', $messageId)->value('EventID');
        $this->assertGreaterThan(0, $eventId);
        $ev = DB::table('CalendarEvents')->where('EventID', $eventId)->first(['Title', 'StartDateTime', 'EndDateTime', 'OwnerUserID', 'CreatedByUserID']);
        $this->assertStringStartsWith('2099-07-01 13:00:00', (string) $ev->StartDateTime);
        $this->assertStringStartsWith('2099-07-01 13:30:00', (string) $ev->EndDateTime);
        $this->assertSame([self::MEMBER, self::RESPONSIBLE], [(int) $ev->OwnerUserID, (int) $ev->CreatedByUserID]);
        $this->assertStringContainsString('بررسیِ نقشه‌ها', $ev->Title);
        $this->assertSame('ACCEPTED', DB::table('CalendarEventAttendees')->where('EventID', $eventId)->where('UserID', self::MEMBER)->value('ResponseStatus'), 'بدونِ دعوتِ در انتظار');
        $this->assertContains($eventId, array_map(fn ($e) => (int) $e['EventID'], $this->projectEventIds(self::RESPONSIBLE, $projectId)->json('events')));

        // بدونِ مهلت رویدادی ساخته نمی‌شود
        $before = DB::table('CalendarEventLinks')->where('LinkType', 'MESSAGE')->count();
        $this->actingAs(User::find(self::RESPONSIBLE))->postJson("/projects/{$projectId}/tasks", ['ToUserID' => self::MEMBER, 'msgPriorityID' => 1, 'MessageText' => 'بی‌مهلت'])->assertOk();
        $this->assertSame($before, DB::table('CalendarEventLinks')->where('LinkType', 'MESSAGE')->count());
    }
}
