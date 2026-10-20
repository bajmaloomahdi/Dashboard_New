<?php

namespace App\Services\Calendar;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * موضوع و متنِ یک یادآوری برایِ خودِ صاحبِ یادآوری (اعلانِ داخلِ برنامه و Push).
 * متنِ رویداد فقط از sp_GetCalendarEvent با شناسهٔ همان کاربر خوانده می‌شود — همان قاعدهٔ دسترسیِ «جزئیاتِ رویداد»:
 * رویدادِ خصوصی فقط برایِ سازنده/مالک/شرکت‌کننده؛ بینندگانِ READ/FREEBUSY اصلاً یادآوری ندارند.
 */
final class ReminderContent
{
    public const TITLE_MAX = 60;
    public const BODY_MAX = 150;

    /**
     * @param  object  $reminder  ردیفِ یادآوری (EntityType, EntityID, Title, RemindAt, OffsetMinutes)
     * @return array{title: string, description: ?string, isPrivate: bool, startsAt: ?string}
     */
    public static function for(object $reminder, int $userId): array
    {
        $title = trim((string) ($reminder->Title ?? ''));
        $description = null;
        $isPrivate = false;
        $startsAt = null;

        if (($reminder->EntityType ?? '') === 'CALENDAR_EVENT' && !empty($reminder->EntityID ?? $reminder->EventID ?? null)) {
            $eventId = (int) ($reminder->EntityID ?? $reminder->EventID);
            $event = DB::select('EXEC sp_GetCalendarEvent @EventID = ?, @UserID = ?', [$eventId, $userId])[0] ?? null;
            if ($event) {
                $title = $title !== '' ? $title : trim((string) $event->Title);
                $description = self::clean($event->Description ?? null);
                $isPrivate = (int) ($event->IsPrivate ?? 0) === 1;
            } else {
                $title = ''; // رویداد دیگر برایِ این کاربر قابلِ‌مشاهده نیست → هیچ جزئیاتی
            }
            // شروعِ همین وقوع = زمانِ یادآوری + فاصله (برایِ تکرارشونده هم درست است)
            $startsAt = Carbon::parse($reminder->RemindAt)->addMinutes((int) ($reminder->OffsetMinutes ?? 0))->toDateTimeString();
        }

        return ['title' => $title, 'description' => $description, 'isPrivate' => $isPrivate, 'startsAt' => $startsAt];
    }

    /** فاصله‌ها و خط‌هایِ اضافه یکی؛ خالی → null */
    public static function clean(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' ? null : $text;
    }

    /** کوتاه‌سازیِ امن برایِ فارسی (بر اساسِ نویسه، نه بایت) با «…» */
    public static function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)) . '…' : $text;
    }

    /** «1405/07/28 ساعت 10:30» — همان قالبِ نمایشِ تاریخ در برنامه */
    public static function jalaliDateTime(string $dateTime): string
    {
        $d = Carbon::parse($dateTime);
        [$jy, $jm, $jd] = Jalali::fromGregorian($d->year, $d->month, $d->day);

        return sprintf('%04d/%02d/%02d ساعت %s', $jy, $jm, $jd, $d->format('H:i'));
    }
}
