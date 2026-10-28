<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * پچِ ۱۳۲ — «بعداً یادآوری کن» رویِ هر اعلان (نامه/وظیفه، دعوت، لغوِ جلسه، فعالیتِ رویداد، اعلانِ عمومی).
 * یادآوریِ تقویم تعویقِ خودش را دارد (CalendarController::snoozeReminder). دسترسی در sp_Notification_Snooze بررسی می‌شود:
 * فقط گیرندهٔ همان اعلان. پس از پایانِ تعویق هم در برنامه دوباره نمایش داده می‌شود هم Pushِ تازه.
 */
class NotificationSnoozeController extends Controller
{
    /** از اعلانِ داخلِ برنامه (Kind + SourceID) یا از دکمهٔ اعلانِ گوشی (PushOutboxID) */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'Minutes' => 'required|integer|min:5|max:60|multiple_of:5',
            'PushOutboxID' => 'nullable|integer|min:1|required_without_all:Kind,SourceID',
            'Kind' => 'nullable|string|in:MESSAGE,INVITE,CANCEL,CALEVENT,BROADCAST|required_without:PushOutboxID',
            'SourceID' => 'nullable|integer|min:1|required_without:PushOutboxID',
        ]);
        $fromPush = ! empty($validated['PushOutboxID']);
        $res = (array) (DB::select(
            'EXEC dbo.sp_Notification_Snooze @UserID = ?, @Minutes = ?, @SourceKind = ?, @SourceID = ?, @PushOutboxID = ?',
            [
                (int) Auth::id(),
                (int) $validated['Minutes'],
                $fromPush ? null : $validated['Kind'],
                $fromPush ? null : (int) $validated['SourceID'],
                $fromPush ? (int) $validated['PushOutboxID'] : null,
            ],
        )[0] ?? []);
        $ok = ! empty($res['Success']);

        return response()->json([
            'success' => $ok,
            'message' => $res['Message'] ?? '',
            'snoozeId' => isset($res['SnoozeID']) ? (int) $res['SnoozeID'] : null,
            'kind' => $res['SourceKind'] ?? null,
            'sourceId' => isset($res['SourceID']) ? (int) $res['SourceID'] : null,
            'snoozedUntil' => $res['SnoozedUntil'] ?? null,
        ], $ok ? 200 : 422);
    }

    /** تعویق‌هایِ سررسیدشده برایِ نمایشِ دوباره در برنامه */
    public function due()
    {
        $rows = DB::select('EXEC dbo.sp_Notification_GetDueSnoozes @UserID = ?', [(int) Auth::id()]);

        return response()->json(['snoozes' => array_map(fn ($r) => [
            'SnoozeID' => (int) $r->SnoozeID,
            'Kind' => $r->SourceKind,
            'SourceID' => (int) $r->SourceID,
            'SnoozedUntil' => $r->SnoozedUntil,
            'Title' => $r->Title,
            'Subtitle' => $r->Subtitle,
            'StartDateTime' => $r->StartDateTime,
            'TargetUrl' => $r->TargetUrl,
        ], $rows)]);
    }

    /** نمایش داده شد — زبانه‌ها و دستگاه‌هایِ دیگر دوباره نشانش نمی‌دهند */
    public function shown(int $id)
    {
        $res = DB::selectOne('EXEC dbo.sp_Notification_MarkSnoozeShown @SnoozeID = ?, @UserID = ?', [$id, (int) Auth::id()]);

        return response()->json(['success' => ! empty($res->Success)]);
    }
}
