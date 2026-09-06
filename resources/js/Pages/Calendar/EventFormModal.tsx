import { useEffect, useState } from 'react';
import { Modal, Form, Input, Select, Switch, InputNumber, Row, Col, TimePicker, Divider, Spin } from 'antd';
import dayjs, { Dayjs } from 'dayjs';
import PersianDateInput from '../../Components/PersianDateInput';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import type { CalendarEvent, CalendarPermission, ReminderOption } from '../../Types';
import { toApiDateTime } from '../../Utils/jalaliCalendar';

const { TextArea } = Input;

interface UserOption {
    UserID: number;
    FullName: string;
}

interface EventFormModalProps {
    open: boolean;
    onClose: (changed: boolean) => void;
    users: UserOption[];
    reminderOptions: ReminderOption[];
    permissions: CalendarPermission[];
    currentUserId: number;
    eventId: number | null; // null = ایجاد رویداد جدید
    defaultDate: Date | null;
}

const COLOR_PRESETS = ['#1677ff', '#722ed1', '#13a8a8', '#52c41a', '#fa8c16', '#eb2f96', '#f5222d', '#595959'];
const NO_REMINDER = '__none__';

const STATUS_OPTIONS = [
    { value: 'CONFIRMED', label: 'تایید شده' },
    { value: 'TENTATIVE', label: 'موقت' },
    { value: 'CANCELLED', label: 'لغو شده' },
];

const RECURRENCE_OPTIONS = [
    { value: 'NONE', label: 'بدون تکرار' },
    { value: 'DAILY', label: 'روزانه' },
    { value: 'WEEKLY', label: 'هفتگی' },
    { value: 'MONTHLY', label: 'ماهانه' },
    { value: 'YEARLY', label: 'سالانه' },
];

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function api(url: string, method: string, body?: any): Promise<{ success: boolean; message: string; [k: string]: any }> {
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
    return { success: !!data.success, message: data.message || (res.ok ? 'عملیات انجام شد.' : 'خطا در ارتباط با سرور'), ...data };
}

interface FormValues {
    Title: string;
    Description?: string;
    StartDate: string | null;
    StartTime: Dayjs | null;
    EndDate: string | null;
    EndTime: Dayjs | null;
    IsAllDay: boolean;
    Color: string;
    Status: string;
    RecurrenceType: string;
    RecurrenceInterval: number;
    RecurrenceEndDate: string | null;
    OwnerUserID: number;
    AttendeeUserIDs: number[];
    ReminderOffsetMinutes: number | null;
    AttendeeReminders: Record<number, number | string>;
}

export default function EventFormModal({
    open,
    onClose,
    users,
    reminderOptions,
    permissions,
    currentUserId,
    eventId,
    defaultDate,
}: EventFormModalProps) {
    const canForOthers = permissions.includes('CALENDAR_CREATE_FOR_OTHERS');
    const [form] = Form.useForm<FormValues>();
    const [saving, setSaving] = useState(false);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const isAllDay = Form.useWatch('IsAllDay', form);
    const recurrenceType = Form.useWatch('RecurrenceType', form);
    const attendeeIds: number[] = Form.useWatch('AttendeeUserIDs', form) || [];

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const showNotification = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    useEffect(() => {
        if (!open) return;

        if (!eventId) {
            const base = defaultDate ? new Date(defaultDate) : new Date();
            const startTime = dayjs(base);
            form.setFieldsValue({
                Title: '',
                Description: '',
                StartDate: toDateOnly(base),
                StartTime: startTime,
                EndDate: toDateOnly(base),
                EndTime: startTime.add(1, 'hour'),
                IsAllDay: false,
                Color: COLOR_PRESETS[0],
                Status: 'CONFIRMED',
                RecurrenceType: 'NONE',
                RecurrenceInterval: 1,
                RecurrenceEndDate: null,
                OwnerUserID: currentUserId,
                AttendeeUserIDs: [],
                ReminderOffsetMinutes: NO_REMINDER as any,
                AttendeeReminders: {},
            });
            return;
        }

        setLoadingDetail(true);
        fetch(`/calendar/events/${eventId}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((data) => {
                const ev = data.event as CalendarEvent;
                const start = new Date(ev.StartDateTime);
                const end = new Date(ev.EndDateTime);
                form.setFieldsValue({
                    Title: ev.Title,
                    Description: ev.Description || '',
                    StartDate: toDateOnly(start),
                    StartTime: dayjs(start),
                    EndDate: toDateOnly(end),
                    EndTime: dayjs(end),
                    IsAllDay: Number(ev.IsAllDay) === 1,
                    Color: ev.Color || COLOR_PRESETS[0],
                    Status: ev.Status || 'CONFIRMED',
                    RecurrenceType: ev.RecurrenceType || 'NONE',
                    RecurrenceInterval: ev.RecurrenceInterval || 1,
                    RecurrenceEndDate: ev.RecurrenceEndDate,
                    OwnerUserID: ev.OwnerUserID || currentUserId,
                    AttendeeUserIDs: (data.attendees || [])
                        .filter((a: any) => a.RelationType !== 'ORGANIZER')
                        .map((a: any) => a.UserID),
                    ReminderOffsetMinutes: (data.reminderOffsetMinutes ?? NO_REMINDER) as any,
                    AttendeeReminders: Object.fromEntries(
                        (data.attendees || [])
                            .filter((a: any) => a.ReminderOffsetMinutes !== null && a.ReminderOffsetMinutes !== undefined)
                            .map((a: any) => [a.UserID, a.ReminderOffsetMinutes]),
                    ),
                });
            })
            .catch(() => showNotification('error', 'خطا در دریافت اطلاعات رویداد'))
            .finally(() => setLoadingDetail(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, eventId, defaultDate]);

    function toDateOnly(d: Date): string {
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    function combine(dateStr: string | null, time: Dayjs | null): Date {
        const base = dateStr ? new Date(dateStr) : new Date();
        if (time) base.setHours(time.hour(), time.minute(), 0, 0);
        else base.setHours(0, 0, 0, 0);
        return base;
    }

    const handleSubmit = async () => {
        try {
            const values = await form.validateFields();
            setSaving(true);

            const startDate = combine(values.StartDate, values.IsAllDay ? null : values.StartTime);
            const endDate = combine(values.EndDate || values.StartDate, values.IsAllDay ? null : values.EndTime);
            if (values.IsAllDay) endDate.setHours(23, 59, 0, 0);

            if (endDate < startDate) {
                showNotification('error', 'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.');
                setSaving(false);
                return;
            }

            const attendees = values.AttendeeUserIDs || [];
            const attendeeReminders: Record<number, number | null> = {};
            if (canForOthers) {
                Object.entries(values.AttendeeReminders || {}).forEach(([uid, val]) => {
                    const n = Number(uid);
                    if (!attendees.includes(n)) return;
                    attendeeReminders[n] = (val as unknown as string) === NO_REMINDER || val == null ? null : Number(val);
                });
            }

            const payload = {
                Title: values.Title,
                Description: values.Description || null,
                StartDateTime: toApiDateTime(startDate),
                EndDateTime: toApiDateTime(endDate),
                IsAllDay: !!values.IsAllDay,
                Color: values.Color,
                Status: values.Status,
                RecurrenceType: values.RecurrenceType,
                RecurrenceInterval: values.RecurrenceInterval || 1,
                RecurrenceEndDate: values.RecurrenceType !== 'NONE' ? values.RecurrenceEndDate : null,
                OwnerUserID: canForOthers ? values.OwnerUserID || currentUserId : currentUserId,
                AttendeeUserIDs: canForOthers ? attendees : [],
                AttendeeReminders: attendeeReminders,
                ReminderOffsetMinutes:
                    (values.ReminderOffsetMinutes as unknown as string) === NO_REMINDER
                        ? null
                        : values.ReminderOffsetMinutes,
            };

            const result = eventId
                ? await api(`/calendar/events/${eventId}`, 'PUT', payload)
                : await api('/calendar/events', 'POST', payload);

            if (result.success) {
                showNotification('success', result.message);
                setTimeout(() => onClose(true), 500);
            } else {
                showNotification('error', result.message);
            }
        } catch {
            // خطای Validation فرم — نیازی به پیام جدا نیست، آنتی‌دیزاین خودش نشان می‌دهد
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <Modal
                open={open}
                title={eventId ? 'ویرایش رویداد' : 'رویداد جدید'}
                onCancel={() => onClose(false)}
                onOk={handleSubmit}
                confirmLoading={saving}
                okText={eventId ? 'ذخیره تغییرات' : 'ایجاد رویداد'}
                cancelText="انصراف"
                width={640}
                destroyOnClose
            >
                <Spin spinning={loadingDetail}>
                    <Form form={form} layout="vertical" requiredMark={false}>
                        <Form.Item name="Title" label="عنوان رویداد" rules={[{ required: true, message: 'عنوان الزامی است' }]}>
                            <Input placeholder="مثلا: جلسه هماهنگی پروژه" maxLength={250} size="large" />
                        </Form.Item>

                        <Form.Item name="Description" label="توضیحات">
                            <TextArea rows={3} placeholder="توضیحات تکمیلی (اختیاری)" maxLength={2000} />
                        </Form.Item>

                        <Form.Item name="IsAllDay" label="تمام‌روز" valuePropName="checked">
                            <Switch />
                        </Form.Item>

                        <Row gutter={12}>
                            <Col xs={24} sm={isAllDay ? 12 : 8}>
                                <Form.Item name="StartDate" label="تاریخ شروع" rules={[{ required: true, message: 'الزامی است' }]}>
                                    <PersianDateInput value={null as any} onChange={() => {}} />
                                </Form.Item>
                            </Col>
                            {!isAllDay && (
                                <Col xs={24} sm={8}>
                                    <Form.Item name="StartTime" label="ساعت شروع" rules={[{ required: true, message: 'الزامی است' }]}>
                                        <TimePicker format="HH:mm" style={{ width: '100%' }} size="large" />
                                    </Form.Item>
                                </Col>
                            )}
                            <Col xs={24} sm={isAllDay ? 12 : 8}>
                                <Form.Item name="EndDate" label="تاریخ پایان" rules={[{ required: true, message: 'الزامی است' }]}>
                                    <PersianDateInput value={null as any} onChange={() => {}} />
                                </Form.Item>
                            </Col>
                            {!isAllDay && (
                                <Col xs={24} sm={8}>
                                    <Form.Item name="EndTime" label="ساعت پایان" rules={[{ required: true, message: 'الزامی است' }]}>
                                        <TimePicker format="HH:mm" style={{ width: '100%' }} size="large" />
                                    </Form.Item>
                                </Col>
                            )}
                        </Row>

                        <Row gutter={12}>
                            <Col xs={24} sm={12}>
                                <Form.Item name="Status" label="وضعیت">
                                    <Select options={STATUS_OPTIONS} size="large" />
                                </Form.Item>
                            </Col>
                            <Col xs={24} sm={12}>
                                <Form.Item name="Color" label="رنگ رویداد">
                                    <Select
                                        size="large"
                                        options={COLOR_PRESETS.map((c) => ({
                                            value: c,
                                            label: (
                                                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8 }}>
                                                    <span style={{ width: 14, height: 14, borderRadius: 4, background: c, display: 'inline-block' }} />
                                                    {c}
                                                </span>
                                            ),
                                        }))}
                                    />
                                </Form.Item>
                            </Col>
                        </Row>

                        {canForOthers && (
                            <Form.Item name="OwnerUserID" label="این رویداد برای چه کسی است؟ (مالک)">
                                <Select
                                    size="large"
                                    showSearch
                                    optionFilterProp="label"
                                    options={[
                                        { value: currentUserId, label: 'خودم' },
                                        ...users
                                            .filter((u) => u.UserID !== currentUserId)
                                            .map((u) => ({ value: u.UserID, label: u.FullName })),
                                    ]}
                                />
                            </Form.Item>
                        )}

                        {canForOthers && (
                            <Form.Item name="AttendeeUserIDs" label="کاربران مرتبط (شرکت‌کنندگان)">
                                <Select
                                    mode="multiple"
                                    allowClear
                                    placeholder="انتخاب کاربران (اختیاری)"
                                    size="large"
                                    optionFilterProp="label"
                                    options={users
                                        .filter((u) => u.UserID !== currentUserId)
                                        .map((u) => ({ value: u.UserID, label: u.FullName }))}
                                />
                            </Form.Item>
                        )}

                        <Form.Item name="ReminderOffsetMinutes" label="یادآوری من">
                            <Select
                                size="large"
                                options={reminderOptions.map((o) => ({ value: o.value === null ? NO_REMINDER : o.value, label: o.label }))}
                            />
                        </Form.Item>

                        {canForOthers && attendeeIds.length > 0 && (
                            <div style={{ marginBottom: 16 }}>
                                <Divider orientation="right" plain>
                                    یادآوری کاربران مرتبط (اختیاری)
                                </Divider>
                                {attendeeIds.map((uid) => {
                                    const u = users.find((x) => x.UserID === uid);
                                    return (
                                        <Row key={uid} gutter={12} align="middle" style={{ marginBottom: 8 }}>
                                            <Col xs={10}>{u?.FullName ?? uid}</Col>
                                            <Col xs={14}>
                                                <Form.Item name={['AttendeeReminders', String(uid)]} noStyle>
                                                    <Select
                                                        size="middle"
                                                        style={{ width: '100%' }}
                                                        placeholder="بدون یادآوری"
                                                        allowClear
                                                        options={reminderOptions
                                                            .filter((o) => o.value !== null)
                                                            .map((o) => ({ value: o.value as number, label: o.label }))}
                                                    />
                                                </Form.Item>
                                            </Col>
                                        </Row>
                                    );
                                })}
                            </div>
                        )}

                        <Divider orientation="right" plain>
                            تکرار رویداد
                        </Divider>

                        <Row gutter={12}>
                            <Col xs={24} sm={recurrenceType && recurrenceType !== 'NONE' ? 8 : 24}>
                                <Form.Item name="RecurrenceType" label="نوع تکرار">
                                    <Select options={RECURRENCE_OPTIONS} size="large" />
                                </Form.Item>
                            </Col>
                            {recurrenceType && recurrenceType !== 'NONE' && (
                                <>
                                    <Col xs={24} sm={8}>
                                        <Form.Item name="RecurrenceInterval" label="هر چند واحد یک‌بار">
                                            <InputNumber min={1} max={365} style={{ width: '100%' }} size="large" />
                                        </Form.Item>
                                    </Col>
                                    <Col xs={24} sm={8}>
                                        <Form.Item name="RecurrenceEndDate" label="پایان تکرار">
                                            <PersianDateInput value={null as any} onChange={() => {}} />
                                        </Form.Item>
                                    </Col>
                                </>
                            )}
                        </Row>
                    </Form>
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
