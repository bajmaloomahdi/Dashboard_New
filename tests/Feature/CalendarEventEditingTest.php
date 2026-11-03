<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * پچِ ۱۳۷ — توضیحِ ضمیمهٔ رویداد (با کاربر و زمانِ افزودن)، ویرایشِ موضوع/متنِ رویداد (CALENDAR_EDIT_CONTENT؛ فقط
 * سازنده/مالک/مدیرِ واحد) و ویرایشِ متنِ توضیحات (CALENDAR_COMMENT_EDIT؛ با دسترسیِ جزئیاتِ رویداد، ویرایشگر و زمان ثبت).
 */
class CalendarEventEditingTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(array $perms): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    private function event(int $owner, array $attendees = []): int
    {
        return (int) $this->actingAs(User::find($owner))->postJson('/calendar/events', [
            'Title' => 'جلسهٔ اول', 'Description' => 'متنِ اول', 'StartDateTime' => '2099-06-01 10:00:00', 'EndDateTime' => '2099-06-01 10:30:00',
            'RecurrenceType' => 'NONE', 'AttendeeUserIDs' => $attendees,
        ])->assertOk()->json('eventId');
    }

    public function test_attachment_description_with_uploader_and_time(): void
    {
        $owner = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_CREATE_FOR_OTHERS']);
        $guest = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE']);
        $eventId = $this->event($owner, [$guest]);

        $this->actingAs(User::find($owner))->post("/calendar/events/{$eventId}/attachments", [
            'files' => [UploadedFile::fake()->create('صورت.pdf', 5)], 'Description' => '  صورت‌جلسهٔ امضاشده  ',
        ], ['Accept' => 'application/json'])->assertOk();

        $att = $this->actingAs(User::find($guest))->getJson("/calendar/events/{$eventId}")->assertOk()->json('attachments.0');
        $this->assertSame('صورت‌جلسهٔ امضاشده', $att['Description']);
        $this->assertNotEmpty($att['UploadedByName']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $att['UploadedAt']);
        $this->assertArrayNotHasKey('FilePath', $att);

        $aid = (int) $att['CalendarEventAttachmentID'];
        $res = $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/attachments/{$aid}/description", ['Description' => 'نسخهٔ نهایی'])->assertOk();
        $this->assertSame('نسخهٔ نهایی', $res->json('attachments.0.Description'));
        // مهمان مدیرِ رویداد نیست
        $this->actingAs(User::find($guest))->postJson("/calendar/events/{$eventId}/attachments/{$aid}/description", ['Description' => 'x'])->assertStatus(422);
        $this->assertSame('نسخهٔ نهایی', DB::table('CalendarEventAttachments')->where('CalendarEventAttachmentID', $aid)->value('Description'));
    }

    public function test_title_and_text_edit_needs_permission_and_event_management(): void
    {
        $owner = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_CREATE_FOR_OTHERS', 'CALENDAR_EDIT_CONTENT']);
        $ownerNoPerm = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT']);
        $guest = $this->user(['CALENDAR_VIEW', 'CALENDAR_EDIT_CONTENT']);
        $eventId = $this->event($owner, [$guest]);

        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/content", ['Title' => '  جلسهٔ بازبینی ', 'Description' => 'متنِ تازه'])
            ->assertOk()->assertJson(['success' => true]);
        $row = DB::table('CalendarEvents')->where('EventID', $eventId)->first(['Title', 'Description']);
        $this->assertSame(['جلسهٔ بازبینی', 'متنِ تازه'], [$row->Title, $row->Description]);

        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/content", ['Title' => ''])->assertStatus(422);
        $this->actingAs(User::find($guest))->postJson("/calendar/events/{$eventId}/content", ['Title' => 'دست‌کاری'])->assertStatus(422); // مدیرِ رویداد نیست
        $other = $this->event($ownerNoPerm);
        $this->actingAs(User::find($ownerNoPerm))->postJson("/calendar/events/{$other}/content", ['Title' => 'بی‌مجوز'])->assertForbidden();
        $this->assertSame('جلسهٔ بازبینی', DB::table('CalendarEvents')->where('EventID', $eventId)->value('Title'));
    }

    /** همان فرمِ «ویرایش» (PUT): موضوع و متن فقط با CALENDAR_EDIT_CONTENT عوض می‌شود؛ بدونِ آن همان مقدارِ قبلی می‌ماند */
    public function test_edit_form_changes_title_and_text_only_with_permission(): void
    {
        $withPerm = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_EDIT_CONTENT']);
        $noPerm = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT']);
        $times = ['StartDateTime' => '2099-06-02 11:00:00', 'EndDateTime' => '2099-06-02 11:30:00', 'IsAllDay' => false, 'Color' => '#1677ff'];

        $a = $this->event($withPerm);
        $this->actingAs(User::find($withPerm))->putJson("/calendar/events/{$a}", $times + ['Title' => 'موضوعِ تازه', 'Description' => 'متنِ تازه'])->assertOk();
        $row = DB::table('CalendarEvents')->where('EventID', $a)->first(['Title', 'Description', 'StartDateTime']);
        $this->assertSame(['موضوعِ تازه', 'متنِ تازه'], [$row->Title, $row->Description]);
        $this->assertStringStartsWith('2099-06-02 11:00:00', (string) $row->StartDateTime, 'زمان هم در همان ذخیره');
        $this->actingAs(User::find($withPerm))->putJson("/calendar/events/{$a}", $times + ['Title' => '  '])->assertStatus(422);

        $b = $this->event($noPerm);
        $this->actingAs(User::find($noPerm))->putJson("/calendar/events/{$b}", $times + ['Title' => 'بی‌مجوز', 'Description' => 'x'])->assertOk();
        $row = DB::table('CalendarEvents')->where('EventID', $b)->first(['Title', 'Description']);
        $this->assertSame(['جلسهٔ اول', 'متنِ اول'], [$row->Title, $row->Description], 'بدونِ مجوز موضوع و متن دست نمی‌خورد');
    }

    public function test_comment_text_edit_needs_permission_and_records_editor(): void
    {
        $owner = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_CREATE_FOR_OTHERS', 'CALENDAR_COMMENT_VIEW', 'CALENDAR_COMMENT_ADD']);
        $editor = $this->user(['CALENDAR_VIEW', 'CALENDAR_COMMENT_VIEW', 'CALENDAR_COMMENT_EDIT']);
        $stranger = $this->user(['CALENDAR_VIEW', 'CALENDAR_COMMENT_VIEW', 'CALENDAR_COMMENT_EDIT']);
        $eventId = $this->event($owner, [$editor]);
        $commentId = (int) $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/comments", ['Text' => 'متنِ اشتباه'])
            ->assertOk()->json('comments.0.CommentID');

        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$eventId}/comments/{$commentId}", ['Text' => 'x'])->assertForbidden(); // بدونِ مجوزِ ویرایش
        $this->actingAs(User::find($stranger))->putJson("/calendar/events/{$eventId}/comments/{$commentId}", ['Text' => 'x'])->assertForbidden(); // بی‌دسترسی به رویداد
        $this->actingAs(User::find($editor))->putJson("/calendar/events/{$eventId}/comments/{$commentId}", ['Text' => '  '])->assertStatus(422);

        $c = $this->actingAs(User::find($editor))->putJson("/calendar/events/{$eventId}/comments/{$commentId}", ['Text' => 'متنِ درست'])
            ->assertOk()->json('comments.0');
        $this->assertSame('متنِ درست', $c['CommentText']);
        $this->assertSame($owner, (int) $c['UserID'], 'نویسندهٔ اصلی عوض نمی‌شود');
        $this->assertNotEmpty($c['EditedByName']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $c['EditedAt']);
    }
}
