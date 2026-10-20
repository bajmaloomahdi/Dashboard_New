import type { CalendarEvent, CalendarPermission } from '../../Types';
import { normalizeForSearch } from '../../Utils/persianSearch';
import { gregorianToJalaliDateTimeDisplay, parseServerDateTime, toEnglishDigits } from '../../Utils/jalali';
import { jalaliParts } from '../../Utils/jalaliCalendar';

/**
 * ابزارهای مشترکِ صفحهٔ تقویم: تاریخ، فیلتر و جست‌وجو، هفتهٔ کاری و قواعدِ جابه‌جایی (Drag & Drop).
 */

export type ViewMode = 'day' | 'workweek' | 'week' | 'month' | 'agenda';

export const VIEW_OPTIONS: { label: string; value: ViewMode }[] = [
    { label: 'روز', value: 'day' },
    { label: 'هفته کاری', value: 'workweek' },
    { label: 'هفته', value: 'week' },
    { label: 'ماه', value: 'month' },
    { label: 'دستورکار', value: 'agenda' },
];

/** «YYYY-MM-DD HH:mm:ss[.fffffff]» سرور → Date محلی (Safari/iOS قالبِ با فاصله را نمی‌شناسد) */
export const parseDT = parseServerDateTime;

/** Date → «YYYY-MM-DD HH:mm:ss» محلی برای ارسال به سرور */
export function toServerDateTime(date: Date): string {
    const p = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())} ${p(date.getHours())}:${p(date.getMinutes())}:${p(date.getSeconds())}`;
}

export function dayKey(date: Date): string {
    const p = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`;
}

export function startOfDay(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 0, 0, 0, 0);
}

export function sameDay(a: Date, b: Date): boolean {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

/** اختلافِ روزِ تقویمی (b - a)، مستقل از ساعت */
export function dayDiff(a: Date, b: Date): number {
    return Math.round((startOfDay(b).getTime() - startOfDay(a).getTime()) / 86400000);
}

export function isAllDay(ev: CalendarEvent): boolean {
    return Number(ev.IsAllDay) === 1 || ev.IsAllDay === true;
}

/** دعوتی که هنوز پاسخ نداده‌ام (برایِ نمایشِ متمایز) */
export function isPendingInvite(ev: CalendarEvent): boolean {
    return ev.MyRelation === 'ATTENDEE' && (ev.MyResponseStatus ?? 'PENDING') === 'PENDING';
}

export function isRecurring(ev: CalendarEvent): boolean {
    return !!ev.RecurrenceType && ev.RecurrenceType !== 'NONE';
}

export function eventsForDay(events: CalendarEvent[], day: Date): CalendarEvent[] {
    const dayStart = startOfDay(day);
    const dayEnd = new Date(dayStart.getFullYear(), dayStart.getMonth(), dayStart.getDate(), 23, 59, 59);
    return events.filter((ev) => parseDT(ev.StartDateTime) <= dayEnd && parseDT(ev.EndDateTime) >= dayStart);
}

export function sortEvents(list: CalendarEvent[]): CalendarEvent[] {
    return [...list].sort((a, b) => {
        const ad = isAllDay(a) ? 0 : 1;
        const bd = isAllDay(b) ? 0 : 1;
        if (ad !== bd) return ad - bd;
        return parseDT(a.StartDateTime).getTime() - parseDT(b.StartDateTime).getTime();
    });
}

export function fmtTime(date: Date): string {
    return toEnglishDigits(`${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`);
}

export function timeRangeLabel(ev: CalendarEvent): string {
    if (isAllDay(ev)) return 'تمام‌روز';
    return `${fmtTime(parseDT(ev.StartDateTime))} – ${fmtTime(parseDT(ev.EndDateTime))}`;
}

// ---------------- فیلتر و جست‌وجو ----------------

export type Relation = 'CREATOR' | 'OWNER' | 'ATTENDEE';
export type EventStatus = CalendarEvent['Status'];
export type ResponseStatus = NonNullable<CalendarEvent['MyResponseStatus']>;

export interface CalendarFilterState {
    relations: Relation[];        // خالی = همه
    statuses: EventStatus[];      // خالی = همه
    responses: ResponseStatus[];  // فقط برایِ رویدادهایی که دعوت‌شده‌ام؛ خالی = همه
    onlyWithReminder: boolean;
    hideCancelled: boolean;
}

export const EMPTY_FILTERS: CalendarFilterState = {
    relations: [],
    statuses: [],
    responses: [],
    onlyWithReminder: false,
    hideCancelled: false,
};

export function activeFilterCount(f: CalendarFilterState): number {
    return (f.relations.length ? 1 : 0) + (f.statuses.length ? 1 : 0) + (f.responses.length ? 1 : 0)
        + (f.onlyWithReminder ? 1 : 0) + (f.hideCancelled ? 1 : 0);
}

export function matchesSearch(ev: CalendarEvent, query: string): boolean {
    const q = normalizeForSearch(query.trim());
    if (!q) return true;
    const haystack = normalizeForSearch([ev.Title, ev.Description, ev.CreatorName, ev.OwnerName].filter(Boolean).join(' '));
    return q.split(' ').filter(Boolean).every((token) => haystack.includes(token));
}

export function applyFilters(events: CalendarEvent[], f: CalendarFilterState, query: string): CalendarEvent[] {
    return events.filter((ev) => {
        if (f.relations.length && !f.relations.includes(ev.MyRelation as Relation)) return false;
        if (f.statuses.length && !f.statuses.includes(ev.Status)) return false;
        if (f.hideCancelled && ev.Status === 'CANCELLED') return false;
        if (f.responses.length && ev.MyRelation === 'ATTENDEE' && !f.responses.includes((ev.MyResponseStatus ?? 'PENDING') as ResponseStatus)) return false;
        if (f.onlyWithReminder && (ev.MyReminderOffsetMinutes === null || ev.MyReminderOffsetMinutes === undefined)) return false;
        return matchesSearch(ev, query);
    });
}

// ---------------- هفتهٔ کاری (تنظیمِ همین مرورگر) ----------------

/** اندیسِ روزِ هفتهٔ شمسی: شنبه = 0 … جمعه = 6 */
export function jalaliWeekIndex(date: Date): number {
    return (date.getDay() + 1) % 7;
}

export interface WorkWeekSettings {
    days: number[];     // اندیس‌هایِ شمسی (شنبه = 0)
    startHour: number;  // شروعِ ساعتِ کاری
    endHour: number;    // پایانِ ساعتِ کاری
}

export const DEFAULT_WORK_WEEK: WorkWeekSettings = { days: [0, 1, 2, 3, 4], startHour: 8, endHour: 17 };

const WORK_WEEK_KEY = 'raga360.calendar.workweek';
const VIEW_KEY = 'raga360.calendar.view';

export function loadWorkWeek(): WorkWeekSettings {
    try {
        const raw = JSON.parse(localStorage.getItem(WORK_WEEK_KEY) || 'null');
        if (raw && Array.isArray(raw.days) && raw.days.length && raw.startHour < raw.endHour) {
            return {
                days: raw.days.filter((d: number) => d >= 0 && d <= 6).sort(),
                startHour: Math.max(0, Math.min(23, raw.startHour)),
                endHour: Math.max(1, Math.min(24, raw.endHour)),
            };
        }
    } catch {
        /* پیش‌فرض */
    }
    return DEFAULT_WORK_WEEK;
}

export function saveWorkWeek(s: WorkWeekSettings): void {
    try {
        localStorage.setItem(WORK_WEEK_KEY, JSON.stringify(s));
    } catch {
        /* بی‌اهمیت */
    }
}

export function loadView(fallback: ViewMode): ViewMode {
    try {
        const v = localStorage.getItem(VIEW_KEY) as ViewMode | null;
        return v && VIEW_OPTIONS.some((o) => o.value === v) ? v : fallback;
    } catch {
        return fallback;
    }
}

export function saveView(v: ViewMode): void {
    try {
        localStorage.setItem(VIEW_KEY, v);
    } catch {
        /* بی‌اهمیت */
    }
}

// ---------------- تقویم‌هایِ هم‌زمان (اشتراکی و واحد) ----------------

export interface SharedCalendarSource {
    CalendarShareID: number;
    OwnerUserID: number;
    OwnerName: string;
    AccessLevel: 'FREEBUSY' | 'READ';
}

export interface UnitCalendarSource {
    UnitID: number;
    UnitName: string;
    CanManageEvents: boolean | number;
}

export interface CalendarSources {
    shared: SharedCalendarSource[];
    units: UnitCalendarSource[];
    canShare: boolean;
}

/** کلیدِ منبع: «shared:12» یا «unit:7» */
export const sharedKey = (ownerId: number | string) => `shared:${Number(ownerId)}`;
export const unitKey = (unitId: number | string) => `unit:${Number(unitId)}`;

/** رنگ‌هایِ ثابتِ تقویم‌هایِ هم‌زمان (به ترتیبِ فهرست) */
export const SOURCE_COLORS = ['#0ea5e9', '#f97316', '#14b8a6', '#a855f7', '#ef4444', '#84cc16', '#eab308', '#64748b'];

export function sourceColor(index: number): string {
    return SOURCE_COLORS[index % SOURCE_COLORS.length];
}

const sourcesKey = (userId: number) => `raga360.calendar.sources.${userId}`;

/** تقویم‌هایِ انتخاب‌شده (همین مرورگر)؛ null = هنوز انتخابی ذخیره نشده (پیش‌فرض: همه) */
export function loadSelectedSources(userId: number): string[] | null {
    try {
        const raw = JSON.parse(localStorage.getItem(sourcesKey(userId)) || 'null');
        return Array.isArray(raw) ? raw.filter((k) => typeof k === 'string') : null;
    } catch {
        return null;
    }
}

export function saveSelectedSources(userId: number, keys: string[]): void {
    try {
        localStorage.setItem(sourcesKey(userId), JSON.stringify(keys));
    } catch {
        /* بی‌اهمیت */
    }
}

// ---------------- جابه‌جایی (Drag & Drop) ----------------

/**
 * آیا کاربر می‌تواند زمانِ این رویداد را با کشیدن تغییر دهد؟ (همان قواعدِ سرور؛ سرور دوباره بررسی می‌کند)
 * null = مجاز؛ رشته = دلیلِ عدمِ امکان (برایِ Tooltip).
 */
export function dragBlockReason(ev: CalendarEvent, permissions: CalendarPermission[], isReadOnly: boolean): string | null {
    if (ev.IsBusyOnly) return 'فقط وضعیتِ «مشغول» دیده می‌شود.';
    if (isReadOnly) return 'تقویمِ کاربرِ دیگر فقط‌خواندنی است.';
    if (!permissions.includes('CALENDAR_EDIT')) return 'اجازهٔ ویرایشِ رویداد را ندارید.';
    if (!(ev.CanManage === true || Number(ev.CanManage) === 1)) return 'فقط ایجادکننده یا مالکِ رویداد می‌تواند زمان آن را تغییر دهد.';
    if (isRecurring(ev)) return 'زمانِ رویدادِ تکرارشونده را از فرمِ ویرایش تغییر دهید.';
    return null;
}

/** گام‌بندیِ دقیقه (پیش‌فرض ۱۵ دقیقه) */
export function snapMinutes(minutes: number, step = 15): number {
    return Math.round(minutes / step) * step;
}

// ---------- تکرارِ شمسی (هم‌خوان با App\Services\Calendar\JalaliRecurrence در سرور) ----------

const RECURRENCE_UNIT: Record<string, string> = { DAILY: 'روز', WEEKLY: 'هفته', MONTHLY: 'ماه', YEARLY: 'سال' };

/** برچسبِ فیلدِ «هر چند … یک‌بار» */
export function recurrenceIntervalLabel(type: string | null | undefined): string {
    return `هر چند ${RECURRENCE_UNIT[type ?? ''] ?? 'واحد'} یک‌بار`;
}

/**
 * توضیحِ فارسیِ قاعدهٔ تکرار بر اساسِ تاریخِ شروعِ سری، مثلاً «هر ماه، روزِ 31 ماهِ شمسی (…: آخرین روزِ ماه)».
 * ماهانه/سالانه بر پایهٔ ماهِ شمسی است؛ 30 اسفند در سالِ غیرکبیسه ← 29 اسفند.
 */
export function recurrenceSummary(type: string | null | undefined, interval: number | null | undefined, start: Date | null): string {
    if (!type || type === 'NONE') return 'بدون تکرار';
    const n = Math.max(1, Number(interval) || 1);
    const every = n === 1 ? `هر ${RECURRENCE_UNIT[type]}` : `هر ${n} ${RECURRENCE_UNIT[type]}`;
    if (!start) return every;
    const j = jalaliParts(start);
    switch (type) {
        case 'DAILY':
            return every;
        case 'WEEKLY':
            return `${every}، ${j.weekDay}`;
        case 'MONTHLY':
            return j.day >= 30
                ? `${every}، روزِ ${j.day} ماهِ شمسی (در ماه‌هایی که این روز را ندارند: آخرین روزِ ماه)`
                : `${every}، روزِ ${j.day} ماهِ شمسی`;
        case 'YEARLY':
            return j.month === 12 && j.day === 30
                ? `${every}، 30 اسفند (در سالِ غیرکبیسه: 29 اسفند)`
                : `${every}، ${j.day} ${j.monthName}`;
        default:
            return every;
    }
}

// ---------- تغییرِ جلسه و پاسخِ قبلی (پچِ ۱۱۲) ----------

const RESPONSE_FA: Record<string, string> = { ACCEPTED: 'پذیرفته', REJECTED: 'رد شده', TENTATIVE: 'شاید', PENDING: 'بدونِ پاسخ' };

/** «پاسخِ قبلی: پذیرفته (1405/07/27 10:30)» — پاسخی که پیش از تغییرِ زمان/تکرارِ جلسه داده شده بود */
export function previousResponseText(status: string | null | undefined, date: string | null | undefined): string | null {
    if (!status) return null;
    const when = date ? ` (${gregorianToJalaliDateTimeDisplay(date)})` : '';
    return `پاسخِ قبلی: ${RESPONSE_FA[status] ?? status}${when}`;
}
