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