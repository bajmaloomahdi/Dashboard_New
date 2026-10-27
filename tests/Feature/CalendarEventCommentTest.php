<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\CreatesPermissionlessUser;
use Tests\TestCase;

/**
 * توضیحاتِ رویداد (پچِ ۱۲۹): دیدن با CALENDAR_COMMENT_VIEW و ثبت با CALENDAR_COMMENT_ADD، هر دو فقط رویِ رویدادی که کاربر به
 * جزئیاتش دسترسی دارد؛ نویسنده و زمان را سرور ثبت می‌کند.
 */
class CalendarEventCommentTest extends TestCase
{
    use CreatesPermissionlessUser, DatabaseTransactions;

    private function user(array $perms): int
    {
        $id = $this->createUserWithoutRoles();
        $this->grantPermissionsTo($id, $perms);

        return $id;
    }

    public function test_comments_need_permissions_and_event_access(): void
    {
        $owner = $this->user(['CALENDAR_VIEW', 'CALENDAR_CREATE', 'CALENDAR_CREATE_FOR_OTHERS', 'CALENDAR_COMMENT_VIEW', 'CALENDAR_COMMENT_ADD']);
        $reader = $this->user(['CALENDAR_VIEW', 'CALENDAR_COMMENT_VIEW']);           // فقط دیدن
        $noPerm = $this->user(['CALENDAR_VIEW']);                                     // بدونِ دسترسیِ توضیحات
        $stranger = $this->user(['CALENDAR_VIEW', 'CALENDAR_COMMENT_VIEW', 'CALENDAR_COMMENT_ADD']); // به رویداد ربطی ندارد
        $eventId = (int) $this->actingAs(User::find($owner))->postJson('/calendar/events', [
            'Title' => 'جلسه', 'StartDateTime' => '2099-06-01 10:00:00', 'EndDateTime' => '2099-06-01 10:30:00', 'RecurrenceType' => 'NONE',
            'AttendeeUserIDs' => [$reader, $noPerm],
        ])->assertOk()->json('eventId');

        $res = $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/comments", ['Text' => "  خط اول\nخط دوم  "])->assertOk();
        $this->assertSame('توضیح ثبت شد.', $res->json('message'));
        $c = $res->json('comments.0');
        $this->assertSame("خط اول\nخط دوم", $c['CommentText']);
        $this->assertSame($owner, (int) $c['UserID']);
        $this->assertNotEmpty($c['UserName']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $c['CreatedAt']);

        $this->assertCount(1, $this->actingAs(User::find($reader))->getJson("/calendar/events/{$eventId}/comments")->assertOk()->json('comments'));
        $this->actingAs(User::find($reader))->postJson("/calendar/events/{$eventId}/comments", ['Text' => 'x'])->assertForbidden();
        $this->actingAs(User::find($noPerm))->getJson("/calendar/events/{$eventId}/comments")->assertForbidden();
        $this->actingAs(User::find($stranger))->getJson("/calendar/events/{$eventId}/comments")->assertForbidden();
        $this->actingAs(User::find($stranger))->postJson("/calendar/events/{$eventId}/comments", ['Text' => 'x'])->assertForbidden();
        $this->actingAs(User::find($owner))->postJson("/calendar/events/{$eventId}/comments", ['Text' => '   '])->assertStatus(422);
    }
}
