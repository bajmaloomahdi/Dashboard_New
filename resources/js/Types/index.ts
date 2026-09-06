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
    MenuKind: 'PAGE' | 'FOLDER' | 'REPORT';
    Url: string | null;
    Icon: string | null;
    Level: number;
    SortOrder: number;
    OpenInNewTab: boolean;
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
    Status: 'CONFIRMED' | 'TENTATIVE' | 'CANCELLED';
    RecurrenceType: 'NONE' | 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY';
    RecurrenceInterval: number;
    RecurrenceEndDate: string | null;
    CreatedByUserID: number;
    CreatorName: string;
    OwnerUserID: number;
    OwnerName: string;
    OrganizationalUnitID: number | null;
    CanManage: boolean | number;
    MyRelation: 'CREATOR' | 'OWNER' | 'ATTENDEE' | 'NONE';
    MyResponseStatus: 'PENDING' | 'ACCEPTED' | 'REJECTED' | 'TENTATIVE' | null;
    MyReminderOffsetMinutes: number | null;
}

export interface CalendarEventAttendee {
    CalendarEventAttendeeID: number;
    EventID: number;
    UserID: number;
    FullName: string;
    RelationType: 'ORGANIZER' | 'PARTICIPANT' | 'OPTIONAL' | 'RESOURCE';
    ResponseStatus: 'PENDING' | 'ACCEPTED' | 'REJECTED' | 'TENTATIVE';
    ResponseDate: string | null;
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
    | 'CALENDAR_VIEW_OTHERS';

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