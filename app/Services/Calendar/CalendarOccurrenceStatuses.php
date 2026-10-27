<?php

namespace App\Services\Calendar;

use Illuminate\Support\Facades\DB;

/**
 * پچِ ۱۲۷ — وضعیتِ جداگانهٔ هر وقوعِ رویدادِ تکرارشونده (CalendarEventOccurrenceStatus). کلید: رویداد + شروعِ همان وقوع
 * («Y-m-d H:i:s»). وقوعی که ردیف ندارد همان وضعیتِ سری را دارد.
 */
final class CalendarOccurrenceStatuses
{
    /** @return array<int, array<string, string>> EventID => [OccurrenceStart => Status] */
    public static function forEvents(array $eventIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $eventIds))));
        if (! $ids) {
            return [];
        }
        $map = [];
        foreach (DB::select('EXEC dbo.sp_Calendar_GetOccurrenceStatuses @EventIDs = ?', [implode(',', $ids)]) as $row) {
            $map[(int) $row->EventID][(string) $row->OccurrenceStart] = (string) $row->Status;
        }

        return $map;
    }

    /** شروع‌هایِ وقوع‌هایِ «انجام شده/لغو شده» — یادآوری از رویِ آن‌ها رد می‌شود */
    public static function closedStarts(int $eventId): array
    {
        return array_keys(array_filter(self::forEvents([$eventId])[$eventId] ?? [], fn ($s) => in_array($s, ['DONE', 'CANCELLED'], true)));
    }
}
