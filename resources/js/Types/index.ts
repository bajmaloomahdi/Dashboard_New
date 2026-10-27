// اطلاعات کاربر
export interface User {
    UserID: number;
    UserName: string;
    FirstName: string;
    LastName: string;
    FullName: string;
    Email: string | null;
}

// منو (به همون شکلی که از دیتابیس میاد)
export interface Menu {
    MenuID: number;
    ParentID: number | null;
    MenuCode: string;
    MenuTitle: string;
    // REPORT_ITEM: ردیف‌هایِ گزارشِ sp_GetUserMenus (از جدولِ Reports)؛ TAB: تبِ صفحهٔ اصلی
    MenuKind: 'PAGE' | 'FOLDER' | 'REPORT' | 'REPORT_ITEM' | 'TAB';
    Url: string | null;
    Icon: string | null;
    Level: number;
    SortOrder: number;
    OpenInNewTab: boolean;
    // BIT از SQL Server ممکن است به‌صورتِ رشتهٔ "0"/"1" برسد — با toBool خوانده شود
    IsReport?: boolean | number | string;
}

// منو با ساختار درختی (شامل children)
export interface MenuTree extends Menu {
    children: MenuTree[];
}

// رویداد تقویم (شامل رخدادهای بسط‌یافته‌ی تکرارشونده)
export interface CalendarEvent {
    EventID: number;
    OccurrenceKey: string;
    IsRecurringInstance: boolean;
    Title: string;
    Description: string | null;
    StartDateTime: string;
    EndDateTime: string;
    IsAllDay: boolean | number;
    Color: string;
    Status: 'CONFIRMED' | 'TENTATIVE' | 'CANCELLED' | 'DONE';
    RecurrenceType: 'NONE' | 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY';
    RecurrenceInterval: number;
    RecurrenceEndDate: string | null;
    /** روزهایِ هفتهٔ تکرارِ هفتگی «1,3» (۰=شنبه … ۶=جمعه) — پچِ ۱۲۷ */
    RecurrenceWeekDays?: string | null;
    CreatedByUserID: number;
    CreatorName: string;
    OwnerUserID: number;
    OwnerName: string;
    OrganizationalUnitID: number | null;
    CanManage: boolean | number;
    MyRelation: 'CREATOR' | 'OWNER' | 'ATTENDEE' | 'UNIT' | 'NONE';
    /** فقط وضعیتِ «مشغول» (اشتراکِ FREEBUSY) — بدونِ جزئیات، غیرقابلِ کلیک */
    IsBusyOnly?: boolean;
    /** رویدادِ خصوصی (پچِ ۱۱۱): غیرِ شرکت‌کنندگان فقط «مشغول» می‌بینند */
    IsPrivate?: boolean | number;
    MyResponseStatus: 'PENDING' | 'ACCEPTED' | 'REJECTED' | 'TENTATIVE' | null;
    MyReminderOffsetMinutes: number | null;
    /** تقویمِ هم‌زمانی که رویداد از آن آمده (اشتراکی/واحد)؛ خالی = تقویمِ اصلیِ صفحه */
    SourceKey?: string;
    SourceLabel?: string;
}

export interface CalendarEventAttendee {
    CalendarEventAttendeeID: number;
    EventID: number;
    UserID: number;
    FullName: string;
    RelationType: 'ORGANIZER' | 'PARTICIPANT' | 'OPTIONAL' | 'RESOURCE';
    ResponseStatus: 'PENDING' | 'ACCEPTED' | 'REJECTED' | 'TENTATIVE';
    ResponseDate: string | null;
    /** پاسخ و زمانِ پاسخِ پیش از تغییرِ زمان/تکرارِ جلسه (پچِ ۱۱۲)؛ با پاسخِ تازه پاک می‌شود */
    PreviousResponseStatus?: 'ACCEPTED' | 'REJECTED' | 'TENTATIVE' | null;
    PreviousResponseDate?: string | null;
    /** نسخهٔ فعلیِ دعوت به‌خاطرِ تغییرِ جلسه است */
    InviteIsUpdate?: boolean | number;
    ReminderOffsetMinutes: number | null;
}

export interface ReminderOption {
    value: number | null;
    label: string;
}

export interface UserReminder {
    ReminderID: number;
    EntityType: string;
    EntityID: number;
    OffsetMinutes: number;
    RemindAt: string;
    IsSent: boolean | number;
    Title: string | null;
    EventStartDateTime: string | null;
    CreatedByUserID: number | null;
    CreatorName: string | null;
}

export type CalendarPermission =
    | 'CALENDAR_VIEW'
    | 'CALENDAR_CREATE'
    | 'CALENDAR_EDIT'
    | 'CALENDAR_DELETE'
    | 'CALENDAR_CREATE_FOR_OTHERS'
    | 'CALENDAR_VIEW_OTHERS'
    // توضیحاتِ رویداد (پچِ ۱۲۹)
    | 'CALENDAR_COMMENT_VIEW'
    | 'CALENDAR_COMMENT_ADD';

// Props مشترک تمام صفحات
export interface PageProps {
    auth: {
        user: User | null;
    };
    menus: Menu[];
    errors: Record<string, string>;
    flash: {
        success: string | null;
        error: string | null;
    };
    [key: string]: any;
}