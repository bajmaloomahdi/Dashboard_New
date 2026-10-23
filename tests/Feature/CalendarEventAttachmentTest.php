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
 * ضمیمهٔ فایل برایِ رویداد (پچِ ۱۱۷): افزودن هنگامِ ثبت و ویرایش (فقط سازنده/مالک)، نمایش در جزئیات و دانلود فقط با دسترسیِ
 * جزئیاتِ همان رویداد (خصوصی فقط شرکت‌کنندگان، FREEBUSY نه). فایل‌ها رویِ دیسکِ ساختگی؛ ردیف‌ها با DatabaseTransactions برمی‌گردند.
 */
class CalendarEventAttachmentTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private const CAL = ['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_DELETE', 'CALENDAR_CREATE_FOR_OTHERS'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(array $perms = self::CAL): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    private function event(int $as, array $extra = []): int
    {
        return (int) $this->actingAs(User::find($as))->postJson('/calendar/events', $extra + [
            'Title' => 'جلسه با ضمیمه', 'StartDateTime' => '2099-09-01 10:00:00', 'EndDateTime' => '2099-09-01 10:30:00', 'RecurrenceType' => 'NONE',
        ])->assertOk()->json('eventId');
    }

    private function upload(int $as, int $eventId, array $files)
    {
        return $this->actingAs(User::find($as))->post("/calendar/events/{$eventId}/attachments", ['files' => $files], ['Accept' => 'application/json']);
    }

    public function test_owner_attaches_files_on_create_and_edit_and_participants_download(): void
    {
        $owner = $this->user();
        $guest = $this->user(['CALENDAR_VIEW']);
        $eventId = $this->event($owner, ['AttendeeUserIDs' => [$guest]]);

        $this->upload($owner, $eventId, [UploadedFile::fake()->create('دستور جلسه.pdf', 120, 'application/pdf')])
            ->assertOk()->assertJson(['success' => true]);
        // ویرایشِ تاریخ/ساعت و بعد افزودنِ فایلِ دیگر
        $this->actingAs(User::find($owner))->putJson("/calendar/events/{$eventId}", ['StartDateTime' => '2099-09-02 10:00:00', 'EndDateTime' => '2099-09-02 10:30:00'])->assertOk();
        $this->upload($owner, $eventId, [UploadedFile::fake()->create('گزارش.xlsx', 40), UploadedFile::fake()->image('عکس.png')])->assertOk();

        $items = $this->actingAs(User::find($guest))->getJson("/calendar/events/{$eventId}")->assertOk()->json('attachments');
        $this->assertSame(['دستور جلسه.pdf', 'گزارش.xlsx', 'عکس.png'], array_column($items, 'FileName'));
        $this->assertArrayNotHasKey('FilePath', $items[0], 'مسیرِ فیزیکی به مرورگر نمی‌رود');

        $res = $this->actingAs(User::find($guest))->get($items[0]['DownloadUrl'])->assertOk();
        $this->assertStringContainsString('attachment', (string) $res->headers->get('content-disposition'));
        $path = DB::table('CalendarEventAttachments')->where('CalendarEventAttachmentID', $items[0]['CalendarEventAttachmentID'])->value('FilePath');
        Storage::disk('local')->assertExists($path);
        $this->assertStringStartsWith('calendar/event-attachments/' . $eventId . '/', $path);
    }

    public function test_only_creator_or_owner_can_attach(): void
    {
        $owner = $this->user();
        $guest = $this->user(['CALENDAR_VIEW', 'CALENDAR_EDIT']);
        $eventId = $this->event($owner, ['AttendeeUserIDs' => [$guest]]);

        $this->upload($guest, $eventId, [UploadedFile::fake()->create('x.pdf', 10)])->assertStatus(403);
        $this->upload($this->user(['CALENDAR_VIEW']), $eventId, [UploadedFile::fake()->create('x.pdf', 10)])->assertStatus(403);
        $this->assertSame(0, DB::table('CalendarEventAttachments')->where('EventID', $eventId)->count());
    }

    public function test_blocked_types_and_too_many_files_are_rejected_without_partial_saves(): void
    {
        $owner = $this->user();
        $eventId = $this->event($owner);

        $this->upload($owner, $eventId, [UploadedFile::fake()->create('ok.pdf', 10), UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);
        $this->upload($owner, $eventId, array_map(fn ($i) => UploadedFile::fake()->create("f{$i}.pdf", 1), range(1, 11)))->assertStatus(422);
        $this->upload($owner, $eventId, [])->assertStatus(422);
        $this->assertSame(0, DB::table('CalendarEventAttachments')->where('EventID', $eventId)->count(), 'همه یا هیچ');
    }

    public function test_download_follows_event_detail_access(): void
    {
        $owner = $this->user([...self::CAL, 'CALENDAR_SHARE']);
        $reader = $this->user(['CALENDAR_VIEW']);
        $busy = $this->user(['CALENDAR_VIEW']);
        $stranger = $this->user(['CALENDAR_VIEW']);
        DB::insert("INSERT INTO dbo.CalendarShares (OwnerUserID, SharedWithUserID, AccessLevel) VALUES (?, ?, N'READ'), (?, ?, N'FREEBUSY')", [$owner, $reader, $owner, $busy]);
        $public = $this->event($owner);
        $private = $this->event($owner, ['IsPrivate' => true, 'StartDateTime' => '2099-09-03 10:00:00', 'EndDateTime' => '2099-09-03 10:30:00']);
        $this->upload($owner, $public, [UploadedFile::fake()->create('عمومی.pdf', 10)])->assertOk();
        $this->upload($owner, $private, [UploadedFile::fake()->create('محرمانه.pdf', 10)])->assertOk();
        $url = fn (int $eventId) => $this->actingAs(User::find($owner))->getJson("/calendar/events/{$eventId}")->json('attachments.0.DownloadUrl');
        [$publicUrl, $privateUrl] = [$url($public), $url($private)];

        $this->actingAs(User::find($reader))->get($publicUrl)->assertOk();     // اشتراکِ READ: رویدادِ عمومی
        $this->actingAs(User::find($reader))->get($privateUrl)->assertNotFound(); // خصوصی: نه
        $this->actingAs(User::find($busy))->get($publicUrl)->assertNotFound();    // FREEBUSY: نه
        $this->actingAs(User::find($stranger))->get($publicUrl)->assertNotFound();
    }
}
