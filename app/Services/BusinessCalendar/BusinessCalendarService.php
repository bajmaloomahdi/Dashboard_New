<?php

namespace App\Services\BusinessCalendar;

/**
 * ماژولِ مستقلِ «تقویم کاری».
 *
 * این قرارداد عمداً مستقل از ماژولِ Workflow تعریف شده تا هر ماژولِ دیگری
 * (مرخصی، تنخواه، پشتیبانی و ...) هم بتواند از آن استفاده کند.
 *
 * نکتهٔ زمان: کانتینرِ Laravel روی UTC است ولی ساعتِ سیستم‌عاملِ SQL Server
 * روی Asia/Tehran تنظیم شده. تمام ورودی/خروجی‌های این سرویس بر مبنای
 * «ساعتِ دیواریِ تقویم» (همان منطقهٔ زمانیِ رکوردِ BusinessCalendars.TimeZone)
 * تفسیر می‌شوند و \DateTimeImmutable بدونِ آفستِ اجباری برمی‌گردانند.
 *
 * تقویمِ پیش‌فرض (Code = DEFAULT) یک تقویمِ ۲۴×۷ است
 * (StartTime = 00:00:00 و EndTime = 23:59:59 برای هر هفت روز) و در این حالت
 * رفتارِ همهٔ متدها با «ساعتِ دیواری» یکسان می‌شود.
 */
interface BusinessCalendarService
{
    /**
     * @param  \DateTimeInterface  $startAt       لحظهٔ شروع
     * @param  int                 $minutes       تعداد دقیقهٔ کاری برای افزودن (>= 0)
     * @param  string              $calendarCode  کدِ تقویم
     * @return \DateTimeImmutable  لحظه‌ای که پس از گذشتِ $minutes دقیقهٔ کاری به دست می‌آید
     */
    public function addWorkingMinutes(\DateTimeInterface $startAt, int $minutes, string $calendarCode = 'DEFAULT'): \DateTimeImmutable;

    /**
     * عکسِ addWorkingMinutes — به عقب برمی‌گردد.
     */
    public function subtractWorkingMinutes(\DateTimeInterface $fromAt, int $minutes, string $calendarCode = 'DEFAULT'): \DateTimeImmutable;

    /**
     * آیا لحظهٔ داده‌شده داخلِ ساعاتِ کاریِ تقویم است؟
     */
    public function isWorkingTime(\DateTimeInterface $at, string $calendarCode = 'DEFAULT'): bool;

    /**
     * نخستین لحظهٔ کاری در $at یا پس از آن.
     * اگر $at خودش کاری باشد، همان برگردانده می‌شود.
     */
    public function nextWorkingMoment(\DateTimeInterface $at, string $calendarCode = 'DEFAULT'): \DateTimeImmutable;

    /**
     * تعداد دقیقه‌های کاریِ فاصلهٔ [$from, $to).
     * اگر $to <= $from باشد، صفر برمی‌گردد.
     */
    public function workingMinutesBetween(\DateTimeInterface $from, \DateTimeInterface $to, string $calendarCode = 'DEFAULT'): int;
}
