import { Space, Tag, Typography } from 'antd';
import { CalendarOutlined } from '@ant-design/icons';
import { gregorianToJalaliDisplay, daysUntilDate, dueDateRelativeLabel } from '../Utils/jalali';

const { Text } = Typography;

interface DueDateBadgeProps {
    /** تاریخ مهلت به فرمت میلادی YYYY-MM-DD (یا null) */
    value: string | null;
    /** وقتی مهلت نداریم چه چیزی نشان داده شود */
    emptyText?: string;
    /** نمایش برچسب وضعیت (امروز / X روز مانده / X روز گذشته) */
    showRelative?: boolean;
}

function relativeColor(value: string): string {
    const diff = daysUntilDate(value);
    if (diff === null) return 'default';
    if (diff < 0) return 'error';
    if (diff <= 3) return 'warning';
    return 'green';
}

/**
 * نمایش «مهلت اجرا»ی یک وظیفه: تاریخ شمسی + (اختیاری) برچسب فاصله‌ی زمانی.
 * فقط برای نمایش (لیست/جزئیات) — در فرم‌ها از PersianDateInput استفاده کنید.
 */
export default function DueDateBadge({ value, emptyText = '—', showRelative = true }: DueDateBadgeProps) {
    if (!value) return <Text type="secondary">{emptyText}</Text>;

    return (
        <Space size={6} wrap>
            <Space size={4}>
                <CalendarOutlined style={{ color: '#6B7280' }} />
                <span>{gregorianToJalaliDisplay(value)}</span>
            </Space>
            {showRelative && (
                <Tag color={relativeColor(value)} style={{ borderRadius: 6, marginInlineEnd: 0 }}>
                    {dueDateRelativeLabel(value)}
                </Tag>
            )}
        </Space>
    );
}
