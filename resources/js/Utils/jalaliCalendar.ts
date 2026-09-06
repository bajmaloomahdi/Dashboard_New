import { DateObject } from 'react-multi-date-picker';
import persian from 'react-date-object/calendars/persian';
import persian_fa from 'react-date-object/locales/persian_fa';
import { toEnglishDigits } from './jalali';

/**
 * ابزارهای ساخت جدول تقویم شمسی (ماه/هفته) — مخصوص صفحه‌ی Calendar.
 * مکمل Utils/jalali.ts (که برای نمایش/پارس یک تاریخ ساده استفاده می‌شود).
 */

export const WEEKDAY_LABELS_FA = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
export const WEEKDAY_SHORT_FA = ['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'];

export interface JalaliCell {
    /** نیمه‌شب محلی همان روز (میلادی) — برای مقایسه و کلید */
    date: Date;
    jalaliDay: number;
    isCurrentMonth: boolean;
    isToday: boolean;
}

function toJalali(date: Date) {
    return new DateObject({ date, calendar: persian, locale: persian_fa });
}

function sameDay(a: Date, b: Date): boolean {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

export function addDays(date: Date, delta: number): Date {
    const result = new Date(date);
    result.setDate(result.getDate() + delta);
    return result;
}

/** جابه‌جایی ماه شمسی (delta می‌تواند منفی باشد) */
export function addJalaliMonths(date: Date, delta: number): Date {
    const d = toJalali(date);
    if (delta !== 0) d.add(delta, 'month');
    return d.toDate();
}

/** شروع هفته‌ی شمسی (شنبه) حاوی تاریخ داده‌شده */
export function startOfJalaliWeek(date: Date): Date {
    const d = toJalali(date);
    return addDays(date, -d.weekDay.index);
}

/** عنوان «ماه سال» شمسی برای هدر تقویم */
export function jalaliMonthTitle(date: Date): string {
    const d = toJalali(date);
    return `${d.month.name} ${toEnglishDigits(String(d.year))}`;
}

/** عنوان کامل روز شمسی، مثلا «شنبه ۱۵ شهریور ۱۴۰۵» */
export function jalaliFullDayTitle(date: Date): string {
    const d = toJalali(date);
    return `${WEEKDAY_LABELS_FA[d.weekDay.index]} ${toEnglishDigits(String(d.day))} ${d.month.name} ${toEnglishDigits(String(d.year))}`;
}

export function jalaliDayNumber(date: Date): number {
    return toJalali(date).day;
}

/** جدول ۴۲ خانه‌ای (۶ هفته) برای نمای ماهانه */
export function buildMonthGrid(reference: Date, today: Date = new Date()): JalaliCell[] {
    const jRef = toJalali(reference);
    const firstOfMonth = new DateObject({
        year: jRef.year,
        month: jRef.month.number,
        day: 1,
        calendar: persian,
        locale: persian_fa,
    });
    const leading = firstOfMonth.weekDay.index;
    const daysInMonth = firstOfMonth.month.length;
    const gridStart = addDays(firstOfMonth.toDate(), -leading);

    return Array.from({ length: 42 }, (_, i) => {
        const cellDate = addDays(gridStart, i);
        const dayNumber = i - leading + 1;
        const isCurrentMonth = dayNumber >= 1 && dayNumber <= daysInMonth;
        return {
            date: cellDate,
            jalaliDay: isCurrentMonth ? dayNumber : jalaliDayNumber(cellDate),
            isCurrentMonth,
            isToday: sameDay(cellDate, today),
        };
    });
}

/** ۷ روز هفته‌ی شمسی حاوی تاریخ مرجع، برای نمای هفتگی */
export function buildWeekDays(reference: Date, today: Date = new Date()): JalaliCell[] {
    const start = startOfJalaliWeek(reference);
    return Array.from({ length: 7 }, (_, i) => {
        const cellDate = addDays(start, i);
        return {
            date: cellDate,
            jalaliDay: jalaliDayNumber(cellDate),
            isCurrentMonth: true,
            isToday: sameDay(cellDate, today),
        };
    });
}

/** فرمت YYYY-MM-DD HH:mm:ss محلی — برای ارسال تاریخ‌زمان به سرور */
export function toApiDateTime(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:00`;
}

/** فرمت YYYY-MM-DD محلی */
export function toApiDate(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}
