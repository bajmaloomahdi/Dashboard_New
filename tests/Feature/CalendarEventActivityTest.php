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
 * پچِ ۱۳۰ — اطلاعِ فعالیت رویِ رویداد (توضیح، ضمیمه، وضعیت، پاسخ به دعوت) برایِ طرف‌هایِ رویداد به‌جز انجام‌دهنده؛
 * اعلانِ داخلِ برنامه از /calendar/activity (همین ردیف‌ها Push هم می‌شوند).
 */
class CalendarEventActivityTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private function user(array $perms): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    private function kinds(int $userId): array
    {
        return array_column($this->actingAs(User::find($userId))->getJson('/calendar/activity')->assertOk()->json('notices'), 'Kind');
    }

    public function test_activity_notices_reach_the_other_participants(): void
    {
        Storage::fake('local');
        $owner = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_EDIT', 'CALENDAR_CREATE_FOR_OTHERS', 'CALENDAR_COMMENT_VIEW', 'CALENDAR_COMMENT_ADD']);
        $guest = $this->user(['CALENDAR_VIEW', 'CALENDAR_COMMENT_VIEW']);
        $noComments = $this->user(['CALENDAR_VIEW']); // اجازهٔ دیدنِ توضیحات ندارد
        $eventId = (int) $this->actingAs(User::find($owner))->postJson('/calendar/events', [
            'Title' => 'جلسهٔ هماهنگی', 'StartDateTime' => '2099-08-01 10:00:00', 'EndDateTime' => '2099-08-01 10:30:00', 'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => [$guest, $noComments],
        ])->assertOk()->json('eventId');

        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/comments", ['Text' => 'توضیح'])->assertOk();
        $this->actingAs(User::find($owner))->post("/calendar/events/{$eventId}/attachments", ['files' => [UploadedFile::fake()->create('a.pdf', 5)]], ['Accept' => 'application/json'])->assertOk();
        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/status", ['Status' => 'DONE'])->assertOk();

        $this->assertSame(['STATUS', 'ATTACHMENT', 'COMMENT'], $this->kinds($guest));
        $this->assertSame(['STATUS', 'ATTACHMENT'], $this->kinds($noComments), 'توضیح فقط برایِ کسی که اجازهٔ دیدنش را دارد');
        $this->assertSame([], $this->kinds($owner), 'انجام‌دهنده اطلاعِ کارِ خودش را نمی‌گیرد');

        $notice = $this->actingAs(User::find($guest))->getJson('/calendar/activity')->json('notices.0');
        $this->assertSame('جلسهٔ هماهنگی', $notice['Title']);
        $this->assertSame('انجام شده', $notice['Detail']);
        $this->assertNotEmpty($notice['ActorName']);

        // پاسخ به دعوت → فقط سازنده/مالک
        $this->actingAs(User::find($guest))->postJson("/calendar/events/{$eventId}/respond", ['ResponseStatus' => 'ACCEPTED'])->assertOk();
        $this->assertSame(['RESPONSE'], $this->kinds($owner));
        $this->assertSame('پذیرفت', DB::table('CalendarEventNotices')->where('UserID', $owner)->value('Detail'));
        $this->assertNotContains('RESPONSE', $this->kinds($noComments));
    }
}
