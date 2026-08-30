import { DateObject } from 'react-multi-date-picker';
import persian from 'react-date-object/calendars/persian';
import persian_fa from 'react-date-object/locales/persian_fa';

/**
 * ابزارهای مشترک تبدیل تاریخ شمسی/میلادی
 * (بر پایه همان منطقی که در صفحه گزارش‌گیری استفاده شده)
 */

const persianDigits = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
const arabicDigits = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];

/** تبدیل اعداد فارسی/عربی به انگلیسی */
export const toEnglishDigits = (str: string): string => {
    let result = String(str);
    for (let i = 0; i < 10; i++) {
        result = result.replace(new RegExp(persianDigits[i], 'g'), String(i));
        result = result.replace(new RegExp(arabicDigits[i], 'g'), String(i));
    }
    return result;
};

/** تبدیل تاریخ میلادی (Date یا رشته YYYY-MM-DD) به رشته شمسی YYYY/MM/DD برای نمایش */
export const gregorianToJalaliDisplay = (value: Date | string | null): string => {
    if (!value) return '';
    const date = value instanceof Date ? value : new Date(value);
    if (isNaN(date.getTime())) return '';
    const d = new DateObject({ date, calendar: persian, locale: persian_fa });
    return toEnglishDigits(d.format('YYYY/MM/DD'));
};

/** تبدیل تاریخ‌زمان میلادی به رشته شمسی همراه با ساعت: YYYY/MM/DD HH:mm */
export const gregorianToJalaliDateTimeDisplay = (value: Date | string | null): string => {
    if (!value) return '';
    const date = value instanceof Date ? value : new Date(value);
    if (isNaN(date.getTime())) return '';
    const d = new DateObject({ date, calendar: persian, locale: persian_fa });
    return toEnglishDigits(d.format('YYYY/MM/DD HH:mm'));
};

/** تبدیل Date جاوااسکریپت (خروجی تقویم شمسی) به رشته میلادی YYYY-MM-DD برای ارسال به سرور */
export const dateToGregorianISO = (date: Date | null): string | null => {
    if (!date) return null;
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
};

/** تبدیل رشته شمسی (به‌صورت رقم پیوسته یا با اسلش) به Date جاوااسکریپت */
export const parseJalaliString = (str: string): Date | null => {
    const digits = toEnglishDigits(str).replace(/\D/g, '');
    if (digits.length !== 8) return null;

    const year = parseInt(digits.substring(0, 4));
    const month = parseInt(digits.substring(4, 6));
    const day = parseInt(digits.substring(6, 8));

    if (year < 1300 || year > 1500) return null;
    if (month < 1 || month > 12) return null;
    if (day < 1 || day > 31) return null;

    try {
        const d = new DateObject({ year, month, day, calendar: persian, locale: persian_fa });
        return d.toDate();
    } catch {
        return null;
    }
};