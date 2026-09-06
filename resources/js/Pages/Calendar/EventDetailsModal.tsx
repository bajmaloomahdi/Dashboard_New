import { useEffect, useState } from 'react';
import {
    Modal,
    Descriptions,
    Tag,
    Button,
    Space,
    Popconfirm,
    Spin,
    Empty,
    Typography,
    Select,
    Segmented,
    Divider,
} from 'antd';
import {
    EditOutlined,
    DeleteOutlined,
    ClockCircleOutlined,
    TeamOutlined,
    UserOutlined,
    CrownOutlined,
    RetweetOutlined,
    BellOutlined,
} from '@ant-design/icons';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { gregorianToJalaliDateTimeDisplay, gregorianToJalaliDisplay } from '../../Utils/jalali';
import type { CalendarEventAttendee, CalendarPermission, ReminderOption } from '../../Types';

const { Text, Paragraph } = Typography;

interface EventDetail {
    EventID: number;
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
    OwnerUserID: number;
    CreatorName: string;
    OwnerName: string;
    Date_InsertFirst: string;
    Date_LastUpdate: string | null;
    CanManage: boolean | number;
    MyRelation: 'CREATOR' | 'OWNER' | 'ATTENDEE' | 'NONE';
    MyResponseStatus: 'PENDING' | 'ACCEPTED' | 'REJECTED' | 'TENTATIVE' | null;
}

interface EventDetailsModalProps {
    open: boolean;
    eventId: number | null;
    currentUserId: number;
    permissions: CalendarPermission[];
    reminderOptions: ReminderOption[];
    onClose: () => void;
    onEdit: (eventId: number) => void;
    onChanged: () => void;
    onDeleted: () => void;
}

const STATUS_LABELS: Record<string, { label: string; color: string }> = {
    CONFIRMED: { label: 'تایید شده', color: 'success' },
    TENTATIVE: { label: 'موقت', color: 'warning' },
    CANCELLED: { label: 'لغو شده', color: 'default' },
};

const RECURRENCE_LABELS: Record<string, string> = {
    NONE: 'بدون تکرار',
    DAILY: 'روزانه',
    WEEKLY: 'هفتگی',
    MONTHLY: 'ماهانه',
    YEARLY: 'سالانه',
};

const RESPONSE_LABELS: Record<string, { label: string; color: string }> = {
    PENDING: { label: 'در انتظار پاسخ', color: 'default' },
    ACCEPTED: { label: 'پذیرفته', color: 'success' },
    REJECTED: { label: 'رد شده', color: 'error' },
    TENTATIVE: { label: 'شاید', color: 'warning' },
};

const NO_REMINDER = '__none__';

function getXsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function api(url: string, method: string, body?: any) {
    const res = await fetch(url, {
        method,
        headers: {
            'X-XSRF-TOKEN': getXsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    return { success: !!data.success, message: data.message || (res.ok ? 'انجام شد.' : 'خطا در ارتباط با سرور') };
}

export default function EventDetailsModal({
    open,
    eventId,
    currentUserId,
    permissions,
    reminderOptions,
    onClose,
    onEdit,
    onChanged,
    onDeleted,
}: EventDetailsModalProps) {
    const [loading, setLoading] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [event, setEvent] = useState<EventDetail | null>(null);
    const [attendees, setAttendees] = useState<CalendarEventAttendee[]>([]);
    const [myReminder, setMyReminder] = useState<number | string>(NO_REMINDER);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const reload = () => {
        if (!eventId) return;
        setLoading(true);
        fetch(`/calendar/events/${eventId}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setEvent(data.event || null);
                setAttendees(data.attendees || []);
                setMyReminder(data.reminderOffsetMinutes ?? NO_REMINDER);
            })
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (open && eventId) reload();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, eventId]);

    const handleDelete = async () => {
        if (!eventId) return;
        setDeleting(true);
        const result = await api(`/calendar/events/${eventId}`, 'DELETE');
        setDeleting(false);
        if (result.success) {
            notify('success', result.message);
            setTimeout(onDeleted, 400);
        } else {
            notify('error', result.message);
        }
    };

    const handleResponse = async (status: string) => {
        if (!eventId) return;
        const result = await api(`/calendar/events/${eventId}/respond`, 'POST', { ResponseStatus: status });
        if (result.success) {
            notify('success', result.message);
            reload();
            onChanged();
        } else {
            notify('error', result.message);
        }
    };

    const handleReminderChange = async (val: number | string) => {
        if (!eventId) return;
        setMyReminder(val);
        const offset = val === NO_REMINDER ? null : Number(val);
        const result = await api(`/calendar/events/${eventId}/reminder`, 'POST', { ReminderOffsetMinutes: offset });
        if (result.success) {
            notify('success', result.message);
            onChanged();
        } else {
            notify('error', result.message);
            reload();
        }
    };

    const canManage = event ? Number(event.CanManage) === 1 || event.CanManage === true : false;
    const canEdit = canManage && permissions.includes('CALENDAR_EDIT');
    const canDelete = canManage && permissions.includes('CALENDAR_DELETE');
    const isAttendee = event?.MyRelation === 'ATTENDEE';
    const relatedToEvent = event && event.MyRelation !== 'NONE';

    const footer: React.ReactNode[] = [];
    if (event && (canEdit || canDelete)) {
        if (canDelete) {
            footer.push(
                <Popconfirm
                    key="delete"
                    title="حذف رویداد"
                    description="آیا از حذف این رویداد مطمئن هستید؟"
                    okText="بله، حذف شود"
                    cancelText="انصراف"
                    okButtonProps={{ danger: true, loading: deleting }}
                    onConfirm={handleDelete}
                >
                    <Button danger icon={<DeleteOutlined />}>
                        حذف
                    </Button>
                </Popconfirm>,
            );
        }
        if (canEdit) {
            footer.push(
                <Button key="edit" type="primary" icon={<EditOutlined />} onClick={() => eventId && onEdit(eventId)}>
                    ویرایش
                </Button>,
            );
        }
    } else {
        footer.push(
            <Button key="close" onClick={onClose}>
                بستن
            </Button>,
        );
    }

    return (
        <>
            <Modal open={open} onCancel={onClose} title={event?.Title || 'جزئیات رویداد'} width={580} footer={footer}>
                <Spin spinning={loading}>
                    {!event && !loading ? (
                        <Empty description="رویداد یافت نشد" />
                    ) : event ? (
                        <div>
                            <Space wrap style={{ marginBottom: 12 }}>
                                <Tag color={event.Color} style={{ borderRadius: 6 }}>
                                    <span style={{ color: '#fff' }}>●</span>
                                </Tag>
                                <Tag color={STATUS_LABELS[event.Status]?.color} style={{ borderRadius: 6 }}>
                                    {STATUS_LABELS[event.Status]?.label || event.Status}
                                </Tag>
                                {event.RecurrenceType !== 'NONE' && (
                                    <Tag icon={<RetweetOutlined />} color="purple" style={{ borderRadius: 6 }}>
                                        {RECURRENCE_LABELS[event.RecurrenceType]}
                                        {event.RecurrenceEndDate ? ` تا ${gregorianToJalaliDisplay(event.RecurrenceEndDate)}` : ''}
                                    </Tag>
                                )}
                            </Space>

                            {event.Description && (
                                <Paragraph style={{ whiteSpace: 'pre-wrap', marginBottom: 16 }}>{event.Description}</Paragraph>
                            )}

                            <Descriptions column={1} size="small" bordered>
                                <Descriptions.Item label={<><ClockCircleOutlined /> شروع</>}>
                                    {Number(event.IsAllDay) === 1
                                        ? gregorianToJalaliDisplay(event.StartDateTime) + ' (تمام‌روز)'
                                        : gregorianToJalaliDateTimeDisplay(event.StartDateTime)}
                                </Descriptions.Item>
                                <Descriptions.Item label={<><ClockCircleOutlined /> پایان</>}>
                                    {Number(event.IsAllDay) === 1
                                        ? gregorianToJalaliDisplay(event.EndDateTime)
                                        : gregorianToJalaliDateTimeDisplay(event.EndDateTime)}
                                </Descriptions.Item>
                                <Descriptions.Item label={<><UserOutlined /> ایجادکننده</>}>{event.CreatorName}</Descriptions.Item>
                                <Descriptions.Item label={<><CrownOutlined /> مالک</>}>
                                    {event.OwnerName}
                                    {event.OwnerUserID === currentUserId && <Tag color="blue" style={{ marginRight: 6, borderRadius: 6 }}>شما</Tag>}
                                </Descriptions.Item>
                                <Descriptions.Item label={<><TeamOutlined /> کاربران مرتبط</>}>
                                    {attendees.length > 0 ? (
                                        <Space direction="vertical" size={4} style={{ width: '100%' }}>
                                            {attendees.map((a) => (
                                                <Space key={a.UserID} size={6}>
                                                    <Text>{a.FullName}</Text>
                                                    {a.RelationType === 'ORGANIZER' && (
                                                        <Tag color="gold" style={{ borderRadius: 6 }}>برگزارکننده</Tag>
                                                    )}
                                                    <Tag color={RESPONSE_LABELS[a.ResponseStatus]?.color} style={{ borderRadius: 6 }}>
                                                        {RESPONSE_LABELS[a.ResponseStatus]?.label}
                                                    </Tag>
                                                </Space>
                                            ))}
                                        </Space>
                                    ) : (
                                        <Text type="secondary">—</Text>
                                    )}
                                </Descriptions.Item>
                            </Descriptions>

                            {relatedToEvent && (
                                <>
                                    <Divider orientation="right" plain>
                                        تنظیمات شخصی من
                                    </Divider>

                                    {isAttendee && (
                                        <div style={{ marginBottom: 12 }}>
                                            <Text style={{ marginLeft: 8 }}>پاسخ من به این دعوت:</Text>
                                            <Segmented
                                                value={event.MyResponseStatus || 'PENDING'}
                                                onChange={(v) => handleResponse(v as string)}
                                                options={[
                                                    { label: 'پذیرش', value: 'ACCEPTED' },
                                                    { label: 'شاید', value: 'TENTATIVE' },
                                                    { label: 'رد', value: 'REJECTED' },
                                                ]}
                                            />
                                        </div>
                                    )}

                                    <div>
                                        <Text style={{ marginLeft: 8 }}>
                                            <BellOutlined /> یادآوری من:
                                        </Text>
                                        <Select
                                            style={{ minWidth: 200 }}
                                            value={myReminder}
                                            onChange={handleReminderChange}
                                            options={reminderOptions.map((o) => ({
                                                value: o.value === null ? NO_REMINDER : o.value,
                                                label: o.label,
                                            }))}
                                        />
                                    </div>
                                </>
                            )}

                            {!canManage && !relatedToEvent && (
                                <Text type="secondary" style={{ display: 'block', marginTop: 12, fontSize: 12 }}>
                                    نمای فقط‌خواندنی.
                                </Text>
                            )}
                        </div>
                    ) : null}
                </Spin>
            </Modal>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((p) => ({ ...p, open: false }))}
            />
        </>
    );
}
