<?php

namespace App\Services\Calendar;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * پچِ ۱۱۳ — یادآوریِ رویدادِ تکرارشونده‌ای که وقوعش تمام شده (و کاربر «دیدم» نزده) خودکار به وقوعِ بعدی (تقویمِ شمسی) می‌رود؛
 * اگر سری تمام شده باشد بسته می‌شود. هم زمان‌بندِ Push (همهٔ کاربران) و هم دریافتِ یادآوری‌هایِ داخلِ برنامه (همان کاربر) صدا می‌زنند.
 */
final class CalendarReminderRoller
{
    /** @return int تعدادِ یادآوری‌هایِ جلو برده/بسته‌شده */
    public function roll(?int $userId = null): int
    {
        $rows = DB::select('EXEC dbo.sp_Calendar_GetRemindersToRoll @UserID = ?', [$userId]);
        if (! $rows) {
            return 0;
        }
        $now = Carbon::parse(DB::selectOne('SELECT CONVERT(varchar(19), SYSDATETIME(), 120) AS n')->n);
        $rolled = 0;

        foreach ($rows as $r) {
            $next = JalaliRecurrence::next(
                Carbon::parse($r->StartDateTime),
                (string) $r->RecurrenceType,
                (int) ($r->RecurrenceInterval ?: 1),
                $now,
                $r->RecurrenceEndDate ? Carbon::parse($r->RecurrenceEndDate)->endOfDay() : null,
                false,
                // پچِ ۱۲۷: روزهایِ هفتهٔ تکرار، و وقوع‌هایِ «انجام/لغو شده» رد می‌شوند
                JalaliRecurrence::parseWeekDays($r->RecurrenceWeekDays ?? null),
                isset($r->EventID) ? CalendarOccurrenceStatuses::closedStarts((int) $r->EventID) : []
            );
            // compare-and-set: اگر زمان‌بند و درخواستِ داخلِ برنامه هم‌زمان برسند، یا کاربر در این فاصله «دیدم» زده/رویداد ویرایش شده،
            // فقط یک به‌روزرسانی (یا هیچ) انجام می‌شود — جابه‌جاییِ دوباره یا از دست رفتنِ یادآوری نداریم
            $rolled += (int) DB::selectOne('EXEC dbo.sp_Calendar_RollReminder @ReminderID = ?, @ExpectedRemindAt = ?, @NewRemindAt = ?', [
                $r->ReminderID,
                Carbon::parse($r->RemindAt)->format('Y-m-d H:i:s'),
                $next?->copy()->subMinutes((int) $r->OffsetMinutes)->format('Y-m-d H:i:s'), // null = سری تمام شده → بسته شود
            ])->Rolled;
        }

        return $rolled;
    }
}
