import { useEffect, useState } from 'react';
import {
    Modal,
    Descriptions,
    Tag,
    Button,
    Space,
    Spin,
    Empty,
    Typography,
    Select,
    Segmented,
    Divider,
    Checkbox,
} from 'antd';
import {
    EditOutlined,
    ClockCircleOutlined,
    TeamOutlined,
    UserOutlined,
    CrownOutlined,
    RetweetOutlined,
    BellOutlined,
    LockOutlined,
    SyncOutlined,
} from '@ant-design/icons';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { gregorianToJalaliDateTimeDisplay, gregorianToJalaliDisplay } from '../../Utils/jalali';
import type { CalendarEventAttendee, CalendarPermission, ReminderOption } from '../../Types';
import { parseDT, previousResponseText, recurrenceSummary } from './calendarUtils';
import EventAttachmentList, { EventAttachment } from './EventAttachments';
import { EventDetailTabs } from './EventComments';

const { Text, Paragraph } = Typography;

interface EventDetail {
    EventID: number;
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
    RecurrenceWeekDays?: string | null;
    CreatedByUserID: number;
    OwnerUserID: number;
    CreatorName: string;
    OwnerName: string;
    Date_InsertFirst: string;
    Date_LastUpdate: string | null;
    CanManage: boolean | number;
    MyRelation: 'CREATOR' | 'OWNER' | 'ATTENDEE' | 'UNIT' | 'NONE';
    MyResponseStatus: 'PENDING' | 'ACCEPTED' | 'REJECTED' | 'TENTATIVE' | null;
}

interface EventDetailsModalProps {
    open: boolean;
    eventId: number | null;
    /** وقوعی که رویش کلیک شده (برایِ رویدادِ تکرارشونده)؛ جزئیاتِ سرور شروعِ خودِ سری است */
    occurrence?: { start: string; end: string } | null;
    currentUserId: number;
    permissions: CalendarPermission[];
    reminderOptions: ReminderOption[];
    onClose: () => void;
    onEdit: (eventId: number) => void;
    onChanged: () => void;
    /** دیگر استفاده نمی‌شود (حذف از این پنجره برداشته شد) — برایِ سازگاریِ فراخوان */
    onDeleted?: () => void;
}

const STATUS_LABELS: Record<string, { label: string; color: string }> = {
    CONFIRMED: { label: 'ثبت شده', color: 'processing' },
    TENTATIVE: { label: 'موقت', color: 'warning' },
    CANCELLED: { label: 'لغو شده', color: 'default' },
    DONE: { label: 'انجام شده', color: 'success' },
};

/** وضعیت‌هایی که از همین پنجره قابلِ انتخاب‌اند (پچِ ۱۱۶) */
const STATUS_CHOICES = ['CONFIRMED', 'CANCELLED', 'DONE'] as const;

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
    occurrence = null,
    currentUserId,
    permissions,
    reminderOptions,
    onClose,
    onEdit,
    onChanged,
}: EventDetailsModalProps) {
    const [loading, setLoading] = useState(false);
    const [changingStatus, setChangingStatus] = useState(false);
    // پچِ ۱۲۷: وضعیتِ همین وقوع (رویدادِ تکرارشونده) و گزینهٔ «اعمال به همهٔ تکرارها»
    const [occurrenceStatus, setOccurrenceStatus] = useState<string | null>(null);
    const [allOccurrences, setAllOccurrences] = useState(false);
    // تبِ فعال (جزئیات / توضیحات) — برایِ دکمه‌هایِ پایینِ پنجره
    const [activeTab, setActiveTab] = useState('details');
    const [event, setEvent] = useState<EventDetail | null>(null);
    const [attendees, setAttendees] = useState<CalendarEventAttendee[]>([]);
    const [attachments, setAttachments] = useState<EventAttachment[]>([]);
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
        fetch(`/calendar/events/${eventId}${occurrence?.start ? `?occurrence=${encodeURIComponent(occurrence.start.slice(0, 19))}` : ''}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                setEvent(data.event || null);
                setOccurrenceStatus(data.occurrenceStatus ?? null);
                setAttendees(data.attendees || []);
                setAttachments(data.attachments || []);
                setMyReminder(data.reminderOffsetMinutes ?? NO_REMINDER);
            })
            .finally(() => setLoading(false));
    };

    useEffect(() => {
        if (open && eventId) reload();
        setAllOccurrences(false);
        setActiveTab('details');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, eventId, occurrence?.start]);

    // تغییرِ وضعیت همین‌جا با یک کلیک (ثبت شده / لغو شده / انجام شده) — بقیهٔ اطلاعات دست نمی‌خورد
    const handleStatusChange = async (status: string) => {
        if (!eventId || !event || status === shownStatus) return;
        setChangingStatus(true);
        // تکرارشونده: فقط همین وقوع، مگر «همهٔ تکرارها» تیک خورده باشد
        const result = await api(`/calendar/events/${eventId}/status`, 'POST', isRecurringEvent
            ? { Status: status, OccurrenceStart: (occurrence?.start ?? String(event.StartDateTime)).slice(0, 19), AllOccurrences: allOccurrences }
            : { Status: status });
        setChangingStatus(false);
        if (result.success) {
            notify('success', result.message);
            reload();
            onChanged();
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

    const isRecurringEvent = !!event && event.RecurrenceType !== 'NONE';
    // وضعیتِ نمایشی: برایِ رویدادِ تکرارشونده وضعیتِ همین وقوع
    const shownStatus = (isRecurringEvent && occurrenceStatus) || event?.Status || 'CONFIRMED';
    const canManage = event ? Number(event.CanManage) === 1 || event.CanManage === true : false;
    const canEdit = canManage && permissions.includes('CALENDAR_EDIT');
    const isAttendee = event?.MyRelation === 'ATTENDEE';
    // عضوِ واحد (UNIT) که شرکت‌کننده نیست یادآوریِ شخصی ندارد
    const relatedToEvent = event && event.MyRelation !== 'NONE' && event.MyRelation !== 'UNIT';

    const footer: React.ReactNode[] = [];
    // حذف از این پنجره برداشته شد؛ ویرایش فقط برایِ تاریخ/ساعت و وضعیت است.
    // تبِ «توضیحات»: دکمهٔ ویرایش نمایش داده نمی‌شود — دکمهٔ «ثبتِ توضیح» پایینِ همان تب است
    if (activeTab === 'comments') {
        // بدونِ دکمهٔ پایینِ پنجره
    } else if (event && canEdit) {
        footer.push(
            <Button key="edit" type="primary" icon={<EditOutlined />} onClick={() => eventId && onEdit(eventId)}>
                ویرایشِ تاریخ، ساعت و رنگ
            </Button>,
        );
    } else {
        footer.push(
            <Button key="close" onClick={onClose}>
                بستن
            </Button>,
        );
    }

    return (
        <>
            <Modal open={open} onCancel={onClose} title={event?.Title || 'جزئیات رویداد'} width={580} footer={footer.length ? footer : null}>
                <Spin spinning={loading}>
                    {!event && !loading ? (
                        <Empty description="رویداد یافت نشد" />
                    ) : event ? (
                        // پچِ ۱۲۹: «توضیحات» (با نویسنده و زمان) در تبِ جدا — فقط با دسترسیِ دیدن/ثبت
                        <EventDetailTabs
                            onTabChange={setActiveTab}
                            eventId={Number(event.EventID)}
                            canView={permissions.includes('CALENDAR_COMMENT_VIEW')}
                            canAdd={permissions.includes('CALENDAR_COMMENT_ADD')}
                        >
                        <div>
                            <Space wrap style={{ marginBottom: 12 }}>
                                <Tag color={event.Color} style={{ borderRadius: 6 }}>
                                    <span style={{ color: '#fff' }}>●</span>
                                </Tag>
                                {canEdit ? (
                                    // کلیک رویِ وضعیت → همان‌جا تغییر می‌کند
                                    <Segmented
                                        size="small"
                                        value={STATUS_CHOICES.includes(shownStatus as (typeof STATUS_CHOICES)[number]) ? shownStatus : 'CONFIRMED'}
                                        disabled={changingStatus}
                                        onChange={(v) => handleStatusChange(String(v))}
                                        options={STATUS_CHOICES.map((s) => ({ value: s, label: STATUS_LABELS[s].label }))}
                                    />
                                ) : (
                                    <Tag color={STATUS_LABELS[shownStatus]?.color} style={{ borderRadius: 6 }}>
                                        {STATUS_LABELS[shownStatus]?.label || shownStatus}
                                    </Tag>
                                )}
                                {canEdit && isRecurringEvent && (
                                    <Checkbox checked={allOccurrences} disabled={changingStatus} onChange={(e) => setAllOccurrences(e.target.checked)} style={{ fontSize: 12 }}>
                                        اعمال به همهٔ تکرارها
                                    </Checkbox>
                                )}
                                {Number(event.IsPrivate) === 1 && (
                                    <Tag icon={<LockOutlined />} style={{ borderRadius: 6 }}>
                                        خصوصی
                                    </Tag>
                                )}
                                {event.RecurrenceType !== 'NONE' && (
                                    <Tag icon={<RetweetOutlined />} color="purple" style={{ borderRadius: 6 }}>
                                        {recurrenceSummary(event.RecurrenceType, event.RecurrenceInterval, parseDT(event.StartDateTime), event.RecurrenceWeekDays) || RECURRENCE_LABELS[event.RecurrenceType]}
                                        {event.RecurrenceEndDate ? ` تا ${gregorianToJalaliDisplay(event.RecurrenceEndDate)}` : ''}
                                    </Tag>
                                )}
                            </Space>

                            {event.Description && (
                                <Paragraph style={{ whiteSpace: 'pre-wrap', marginBottom: 16 }}>{event.Description}</Paragraph>
                            )}

                            <Descriptions column={1} size="small" bordered>
                                {event.RecurrenceType !== 'NONE' && occurrence && occurrence.start.slice(0, 19) !== String(event.StartDateTime).slice(0, 19) && (
                                    <Descriptions.Item label={<><ClockCircleOutlined /> این وقوع</>}>
                                        {Number(event.IsAllDay) === 1
                                            ? gregorianToJalaliDisplay(occurrence.start) + ' (تمام‌روز)'
                                            : `${gregorianToJalaliDateTimeDisplay(occurrence.start)} تا ${gregorianToJalaliDateTimeDisplay(occurrence.end)}`}
                                    </Descriptions.Item>
                                )}
                                <Descriptions.Item label={<><ClockCircleOutlined /> {event.RecurrenceType !== 'NONE' ? 'شروعِ سری' : 'شروع'}</>}>
                                    {Number(event.IsAllDay) === 1
                                        ? gregorianToJalaliDisplay(event.StartDateTime) + ' (تمام‌روز)'
                                        : gregorianToJalaliDateTimeDisplay(event.StartDateTime)}
                                </Descriptions.Item>
                                <Descriptions.Item label={<><ClockCircleOutlined /> {event.RecurrenceType !== 'NONE' ? 'پایانِ نخستین وقوع' : 'پایان'}</>}>
                                    {Number(event.IsAllDay) === 1
                                        ? gregorianToJalaliDisplay(event.EndDateTime)
                                        : gregorianToJalaliDateTimeDisplay(event.EndDateTime)}
                                </Descriptions.Item>
                                {attachments.length > 0 && (
                                    <Descriptions.Item label="ضمیمه‌ها">
                                        <EventAttachmentList items={attachments} />
                                    </Descriptions.Item>
                                )}
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
                                                    {Number(a.InviteIsUpdate) === 1 && a.ResponseStatus === 'PENDING' && (
                                                        <Tag icon={<SyncOutlined />} color="orange" style={{ borderRadius: 6 }}>تغییرِ جلسه</Tag>
                                                    )}
                                                    {a.PreviousResponseStatus && (
                                                        <Text type="secondary" style={{ fontSize: 12 }}>{previousResponseText(a.PreviousResponseStatus, a.PreviousResponseDate)}</Text>
                                                    )}
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
                        </EventDetailTabs>
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
