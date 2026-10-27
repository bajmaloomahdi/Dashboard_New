import { useEffect, useState } from 'react';
import { Modal, Form, Input, Select, Switch, Row, Col, Divider, Spin, Upload, Button, Checkbox } from 'antd';
import { PaperClipOutlined, UploadOutlined } from '@ant-design/icons';
import dayjs, { Dayjs } from 'dayjs';
import PersianDateInput from '../../Components/PersianDateInput';
import TimeInput from '../../Components/TimeInput';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import type { CalendarEvent, CalendarPermission, ReminderOption } from '../../Types';
import { WEEKDAY_LABELS_FA, toApiDateTime } from '../../Utils/jalaliCalendar';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import { jalaliWeekIndex, parseWeekDays, recurrenceSummary } from './calendarUtils';
import EventAttachmentList, { EventAttachment, uploadEventAttachments } from './EventAttachments';

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
    /** واحدهایی که کاربر عضوِ مستقیمِ آن‌هاست (CanManageEvents = اجازهٔ رویدادِ واحد) */
    units?: { UnitID: number; UnitName: string; CanManageEvents: boolean | number }[];
}

/** «تقویمِ شخصی» در فیلدِ تقویم (۰ = بدونِ واحد) */
const PERSONAL_CALENDAR = 0;

const COLOR_PRESETS = ['#1677ff', '#722ed1', '#13a8a8', '#52c41a', '#fa8c16', '#eb2f96', '#f5222d', '#595959'];
const NO_REMINDER = '__none__';

const STATUS_OPTIONS = [
    { value: 'CONFIRMED', label: 'ثبت شده' },
    { value: 'CANCELLED', label: 'لغو شده' },
    { value: 'DONE', label: 'انجام شده' },
];

/** همهٔ روزهایِ هفته (۰=شنبه … ۶=جمعه) — پیش‌فرضِ روزانه/ماهانه/سالانه */
const ALL_WEEK_DAYS = [0, 1, 2, 3, 4, 5, 6];

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
    /** روزهایِ هفتهٔ تکرارِ هفتگی (۰=شنبه … ۶=جمعه) — پچِ ۱۲۷ */
    RecurrenceWeekDays: number[];
    OwnerUserID: number;
    AttendeeUserIDs: number[];
    ReminderOffsetMinutes: number | null;
    AttendeeReminders: Record<number, number | string>;
    OrganizationalUnitID: number;
    IsPrivate: boolean;
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
    units = [],
}: EventFormModalProps) {
    const canForOthers = permissions.includes('CALENDAR_CREATE_FOR_OTHERS');
    // ویرایشِ رویدادِ موجود بدونِ «برای دیگران»: مالک و شرکت‌کنندگانِ فعلی دست‌نخورده فرستاده شوند (حذفِ بی‌صدا نشوند)
    const [original, setOriginal] = useState<{ owner: number; attendees: number[]; unitId: number | null; unitName: string | null } | null>(null);
    const manageableUnits = units.filter((u) => Number(u.CanManageEvents) === 1 || u.CanManageEvents === true);
    const [form] = Form.useForm<FormValues>();
    const [saving, setSaving] = useState(false);
    const [loadingDetail, setLoadingDetail] = useState(false);
    // ضمیمه‌ها (پچِ ۱۱۷): فایل‌هایِ قبلیِ رویداد + فایل‌هایِ تازه که پس از ذخیره بارگذاری می‌شوند
    const [existingFiles, setExistingFiles] = useState<EventAttachment[]>([]);
    const [newFiles, setNewFiles] = useState<File[]>([]);
    const isAllDay = Form.useWatch('IsAllDay', form);
    const selectedUnit = Form.useWatch('OrganizationalUnitID', form);
    const isUnitEvent = !!selectedUnit && Number(selectedUnit) !== PERSONAL_CALENDAR;
    useEffect(() => {
        if (isUnitEvent && form.getFieldValue('IsPrivate')) form.setFieldValue('IsPrivate', false);
    }, [isUnitEvent, form]);
    const recurrenceType = Form.useWatch('RecurrenceType', form);
    const recurrenceInterval = Form.useWatch('RecurrenceInterval', form);
    const recurrenceWeekDays: number[] = Form.useWatch('RecurrenceWeekDays', form) || [];
    const startDateValue = Form.useWatch('StartDate', form);
    const seriesStart = startDateValue ? new Date(`${startDateValue}T12:00:00`) : null;
    const attendeeIds: number[] = Form.useWatch('AttendeeUserIDs', form) || [];

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const showNotification = (type: NotificationType, message: string) => setNotification({ open: true, type, message });
    // پس از ثبت فقط تاریخ، ساعت و وضعیت قابلِ ویرایش است (متن و موضوع ثابت است)
    const isEdit = !!eventId;
    // تاریخِ پایان فقط برایِ «تمام‌روز» نمایش داده می‌شود؛ رویدادِ ساعت‌دار همان روز تمام می‌شود (مگر رویدادِ چندروزهٔ قبلی در ویرایش)
    const [multiDayTimed, setMultiDayTimed] = useState(false);
    const showEndDate = !!isAllDay || multiDayTimed;

    /** با تغییرِ تاریخ یا ساعتِ شروع: پایان = همان لحظه + ۳۰ دقیقه (تمام‌روز: همان روز) */
    const syncEndWithStart = (changed: Partial<FormValues>) => {
        // تکرارِ هفتگی: پیش‌فرض همان روزِ هفتهٔ تاریخِ شروع تیک می‌خورد (پچِ ۱۲۷)
        // روزهایِ هفته (پچِ ۱۲۷/۱۲۸): هفتگی → روزِ تاریخِ شروع؛ روزانه/ماهانه/سالانه → همهٔ روزها (برداشتنِ تیک = حذفِ آن روز)
        if ('RecurrenceType' in changed && changed.RecurrenceType && changed.RecurrenceType !== 'NONE') {
            const sd = form.getFieldValue('StartDate');
            form.setFieldsValue({
                RecurrenceWeekDays: changed.RecurrenceType === 'WEEKLY'
                    ? (sd ? [jalaliWeekIndex(new Date(`${sd}T12:00:00`))] : [])
                    : ALL_WEEK_DAYS,
            });
        }
        if (!('StartDate' in changed) && !('StartTime' in changed)) return;
        const v = form.getFieldsValue(['StartDate', 'StartTime', 'IsAllDay']) as Partial<FormValues>;
        if (!v.StartDate) return;
        if (v.IsAllDay || !v.StartTime) {
            form.setFieldsValue({ EndDate: v.StartDate });
            return;
        }
        const end = dayjs(`${v.StartDate}T${v.StartTime.format('HH:mm')}:00`).add(30, 'minute');
        form.setFieldsValue({ EndDate: end.format('YYYY-MM-DD'), EndTime: end });
    };

    useEffect(() => {
        if (!open) return;

        if (!eventId) {
            const base = defaultDate ? new Date(defaultDate) : new Date();
            let startTime = dayjs(base).second(0).millisecond(0);
            if (!defaultDate && startTime.minute() % 15 !== 0) startTime = startTime.add(15 - (startTime.minute() % 15), 'minute');
            const endTime = startTime.add(30, 'minute');
            form.setFieldsValue({
                Title: '',
                Description: '',
                StartDate: startTime.format('YYYY-MM-DD'),
                StartTime: startTime,
                EndDate: endTime.format('YYYY-MM-DD'),
                EndTime: endTime,
                IsAllDay: false,
                Color: COLOR_PRESETS[0],
                Status: 'CONFIRMED',
                RecurrenceType: 'NONE',
                RecurrenceInterval: 1,
                RecurrenceEndDate: null,
                RecurrenceWeekDays: [],
                OwnerUserID: currentUserId,
                AttendeeUserIDs: [],
                ReminderOffsetMinutes: NO_REMINDER as any,
                AttendeeReminders: {},
                OrganizationalUnitID: PERSONAL_CALENDAR,
                IsPrivate: false,
            });
            setOriginal(null);
            setMultiDayTimed(false);
            setExistingFiles([]);
            setNewFiles([]);
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
                setMultiDayTimed(Number(ev.IsAllDay) !== 1 && toDateOnly(start) !== toDateOnly(end));
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
                    RecurrenceWeekDays: parseWeekDays(ev.RecurrenceWeekDays).length || ev.RecurrenceType === 'WEEKLY' ? parseWeekDays(ev.RecurrenceWeekDays) : ALL_WEEK_DAYS,
                    OwnerUserID: ev.OwnerUserID || currentUserId,
                    AttendeeUserIDs: (data.attendees || [])
                        .filter((a: any) => a.RelationType !== 'ORGANIZER')
                        .map((a: any) => a.UserID),
                    ReminderOffsetMinutes: (data.reminderOffsetMinutes ?? NO_REMINDER) as any,
                    OrganizationalUnitID: ev.OrganizationalUnitID ? Number(ev.OrganizationalUnitID) : PERSONAL_CALENDAR,
                    IsPrivate: Number(ev.IsPrivate) === 1,
                    AttendeeReminders: Object.fromEntries(
                        (data.attendees || [])
                            .filter((a: any) => a.ReminderOffsetMinutes !== null && a.ReminderOffsetMinutes !== undefined)
                            .map((a: any) => [a.UserID, a.ReminderOffsetMinutes]),
                    ),
                });
                setExistingFiles(data.attachments || []);
                setNewFiles([]);
                setOriginal({
                    owner: Number(ev.OwnerUserID) || currentUserId,
                    attendees: (data.attendees || []).filter((a: any) => a.RelationType !== 'ORGANIZER').map((a: any) => Number(a.UserID)),
                    unitId: ev.OrganizationalUnitID ? Number(ev.OrganizationalUnitID) : null,
                    unitName: null,
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
            let endDate = combine(showEndDate ? values.EndDate || values.StartDate : values.StartDate, values.IsAllDay ? null : values.EndTime);
            if (values.IsAllDay) endDate.setHours(23, 59, 0, 0);
            // بدونِ تاریخِ پایان: ساعتِ پایانِ کمتر از شروع یعنی فردا (مثلاً ۲۳:۳۰ تا ۰۰:۳۰)
            if (!showEndDate && endDate <= startDate) endDate = new Date(endDate.getTime() + 86400000);

            if (endDate < startDate) {
                showNotification('error', 'تاریخ/ساعت پایان نمی‌تواند قبل از شروع باشد.');
                setSaving(false);
                return;
            }
            // شروعِ گذشته (مثلاً اشتباه در ساعت): یادآوری برایش ارسال نمی‌شود — اول تأیید
            const startsInPast = values.IsAllDay ? endDate < new Date() : startDate < new Date();
            if (startsInPast && (values.RecurrenceType ?? 'NONE') === 'NONE') {
                const proceed = await new Promise<boolean>((resolve) =>
                    Modal.confirm({
                        title: 'زمانِ رویداد گذشته است',
                        centered: true,
                        content: <div dir="rtl">زمانِ شروع ({gregorianToJalaliDateTimeDisplay(startDate)}) قبل از الان است و برایش یادآوری ارسال نمی‌شود. با همین زمان ذخیره شود؟</div>,
                        okText: 'بله، ذخیره شود',
                        cancelText: 'اصلاحِ زمان',
                        onOk: () => resolve(true),
                        onCancel: () => resolve(false),
                    }),
                );
                if (!proceed) {
                    setSaving(false);
                    return;
                }
            }
            if (values.RecurrenceType !== 'NONE' && values.RecurrenceEndDate && values.StartDate && values.RecurrenceEndDate < values.StartDate) {
                showNotification('error', 'پایانِ تکرار نمی‌تواند پیش از تاریخِ شروعِ رویداد باشد.');
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

            // ویرایش: فقط تاریخ/ساعت، تمام‌روز، وضعیت و رنگ (بقیه در سرور همان مقدارِ فعلی می‌ماند)
            const payload = isEdit
                ? {
                      StartDateTime: toApiDateTime(startDate),
                      EndDateTime: toApiDateTime(endDate),
                      IsAllDay: !!values.IsAllDay,
                      Color: values.Color,
                      // رویدادِ تکرارشونده: وضعیتِ هر وقوع جداگانه از پنجرهٔ جزئیات تغییر می‌کند (پچِ ۱۲۷)، نه برایِ کلِ سری
                      ...(values.RecurrenceType && values.RecurrenceType !== 'NONE' ? {} : { Status: values.Status }),
                  }
                : {
                Title: values.Title,
                Description: values.Description || null,
                StartDateTime: toApiDateTime(startDate),
                EndDateTime: toApiDateTime(endDate),
                IsAllDay: !!values.IsAllDay,
                Color: values.Color,
                Status: values.Status,
                RecurrenceType: values.RecurrenceType,
                RecurrenceInterval: 1,
                RecurrenceEndDate: values.RecurrenceType !== 'NONE' ? values.RecurrenceEndDate : null,
                RecurrenceWeekDays: values.RecurrenceType !== 'NONE' ? parseWeekDays(values.RecurrenceWeekDays) : [],
                OwnerUserID: canForOthers ? values.OwnerUserID || currentUserId : original ? original.owner : currentUserId,
                AttendeeUserIDs: canForOthers ? attendees : original ? original.attendees : [],
                // همیشه فرستاده می‌شود: ویرایش از فرم رویدادِ واحد را به تقویمِ شخصی منتقل نکند
                OrganizationalUnitID: values.OrganizationalUnitID ? Number(values.OrganizationalUnitID) : null,
                // رویدادِ واحد خصوصی نمی‌شود (سرور هم رد می‌کند)
                IsPrivate: values.OrganizationalUnitID && Number(values.OrganizationalUnitID) !== PERSONAL_CALENDAR ? false : !!values.IsPrivate,
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
                // فایل‌هایِ ضمیمه پس از ثبت/ویرایشِ رویداد (همان رویداد)
                const targetId = eventId || Number(result.eventId);
                if (newFiles.length && targetId) {
                    const up = await uploadEventAttachments(targetId, newFiles);
                    if (!up.success) {
                        showNotification('warning', `رویداد ذخیره شد، ولی ضمیمهٔ فایل ناموفق بود: ${up.message}`);
                        setTimeout(() => onClose(true), 2500);
                        return;
                    }
                }
                showNotification('success', newFiles.length ? `${result.message} ${newFiles.length} فایل ضمیمه شد.` : result.message);
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
                title={eventId ? 'ویرایشِ تاریخ، ساعت و وضعیت' : 'رویداد جدید'}
                onCancel={() => onClose(false)}
                onOk={handleSubmit}
                confirmLoading={saving}
                okText={eventId ? 'ذخیره تغییرات' : 'ایجاد رویداد'}
                cancelText="انصراف"
                width={640}
                destroyOnClose
                maskClosable={false}
            >
                <Spin spinning={loadingDetail}>
                    <Form form={form} layout="vertical" requiredMark={false} onValuesChange={syncEndWithStart}>
                        <Form.Item name="Title" label="عنوان رویداد" rules={[{ required: true, message: 'عنوان الزامی است' }]} extra={isEdit ? 'موضوع و متن پس از ثبت قابلِ تغییر نیست.' : undefined}>
                            <Input disabled={isEdit} placeholder="مثلا: جلسه هماهنگی پروژه" maxLength={250} size="large" />
                        </Form.Item>

                        <Form.Item name="Description" label="توضیحات">
                            <TextArea disabled={isEdit} rows={3} placeholder="توضیحات تکمیلی (اختیاری)" maxLength={2000} />
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
                                        <TimeInput />
                                    </Form.Item>
                                </Col>
                            )}
                            {/* تاریخِ پایان فقط برایِ تمام‌روز؛ مقدارش در فرم می‌ماند (پنهان) */}
                            <Col xs={24} sm={isAllDay ? 12 : 8} style={showEndDate ? undefined : { display: 'none' }}>
                                <Form.Item name="EndDate" label="تاریخ پایان" rules={[{ required: true, message: 'الزامی است' }]}>
                                    <PersianDateInput value={null as any} onChange={() => {}} />
                                </Form.Item>
                            </Col>
                            {!isAllDay && (
                                <Col xs={24} sm={8}>
                                    <Form.Item name="EndTime" label="ساعت پایان" rules={[{ required: true, message: 'الزامی است' }]}>
                                        <TimeInput />
                                    </Form.Item>
                                </Col>
                            )}
                        </Row>

                        <Row gutter={12}>
                            {isEdit && recurrenceType && recurrenceType !== 'NONE' ? (
                                <Col xs={24}>
                                    <div style={{ fontSize: 12, color: '#6B7280', marginBottom: 12 }}>
                                        وضعیتِ هر وقوعِ این رویدادِ تکرارشونده جداگانه از پنجرهٔ جزئیاتِ همان وقوع تغییر می‌کند.
                                    </div>
                                </Col>
                            ) : (
                            <Col xs={24} sm={12}>
                                <Form.Item name="Status" label="وضعیت">
                                    <Select options={STATUS_OPTIONS} size="large" />
                                </Form.Item>
                            </Col>
                            )}
                            {/* رنگ در ثبت و ویرایش — ویرایش با همان دسترسیِ ویرایشِ رویداد (CALENDAR_EDIT + سازنده/مالک/مدیرِ رویدادِ واحد؛ سرور کنترل می‌کند) */}
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

                        {/* ضمیمه‌ها — هم در ثبت، هم در ویرایش */}
                        <Form.Item label={<><PaperClipOutlined /> فایل‌هایِ ضمیمه</>} extra="حداکثر ۱۰ فایل در هر بار، هر فایل تا ۱۰ مگابایت؛ پس از ذخیره ثبت می‌شوند.">
                            {existingFiles.length > 0 && <EventAttachmentList items={existingFiles} />}
                            <Upload
                                multiple
                                beforeUpload={(file) => {
                                    setNewFiles((list) => (list.length >= 10 ? list : [...list, file as unknown as File]));
                                    return false; // بارگذاری پس از ذخیرهٔ رویداد
                                }}
                                onRemove={(file) => setNewFiles((list) => list.filter((f) => (f as any).uid !== file.uid))}
                                fileList={newFiles.map((f) => ({ uid: (f as any).uid, name: f.name, status: 'done' as const, size: f.size }))}
                            >
                                <Button icon={<UploadOutlined />}>انتخابِ فایل</Button>
                            </Upload>
                        </Form.Item>

                        {!isEdit && (
                        <>
                        {(manageableUnits.length > 0 || !!original?.unitId) && (
                            <Form.Item name="OrganizationalUnitID" label="تقویم" extra="رویدادِ تقویمِ واحد برایِ همهٔ اعضایِ مستقیمِ آن واحد دیده می‌شود.">
                                <Select
                                    size="large"
                                    options={[
                                        { value: PERSONAL_CALENDAR, label: 'تقویمِ شخصی' },
                                        ...manageableUnits.map((u) => ({ value: Number(u.UnitID), label: `تقویمِ واحد: ${u.UnitName}` })),
                                        // واحدِ فعلیِ رویداد اگر کاربر مدیرِ آن نیست (ویرایش همان را نگه می‌دارد)
                                        ...(original?.unitId && !manageableUnits.some((u) => Number(u.UnitID) === original.unitId)
                                            ? [{ value: original.unitId, label: 'تقویمِ واحدِ فعلیِ رویداد' }]
                                            : []),
                                    ]}
                                />
                            </Form.Item>
                        )}

                        <Form.Item
                            name="IsPrivate"
                            label="خصوصی"
                            valuePropName="checked"
                            extra={isUnitEvent ? 'رویدادِ تقویمِ واحد نمی‌تواند خصوصی باشد.' : 'دیگران (حتی مدیرِ سیستم) فقط بازهٔ «مشغول» را می‌بینند؛ جزئیات فقط برایِ مالک و شرکت‌کنندگان.'}
                        >
                            <Switch disabled={isUnitEvent} />
                        </Form.Item>

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
                            <Col xs={24} sm={recurrenceType && recurrenceType !== 'NONE' ? 12 : 24}>
                                <Form.Item name="RecurrenceType" label="نوع تکرار">
                                    <Select options={RECURRENCE_OPTIONS} size="large" />
                                </Form.Item>
                            </Col>
                            {recurrenceType && recurrenceType !== 'NONE' && (
                                <>
                                    {/* «هر چند روز/هفته/ماه یک‌بار» برداشته شد — تکرارِ تازه همیشه هر بار (فاصلهٔ ۱) است */}
                                    <Col xs={24} sm={12}>
                                        <Form.Item name="RecurrenceEndDate" label="پایان تکرار">
                                            <PersianDateInput value={null as any} onChange={() => {}} />
                                        </Form.Item>
                                    </Col>
                                    {recurrenceType !== 'NONE' && (
                                        <Col xs={24}>
                                            <Form.Item
                                                name="RecurrenceWeekDays"
                                                label={recurrenceType === 'WEEKLY' ? 'روزهایِ هفته' : 'فقط در این روزهایِ هفته'}
                                                extra={recurrenceType === 'WEEKLY' ? undefined : 'وقوعی که رویِ روزِ بدونِ تیک بیفتد ثبت نمی‌شود (مثلاً جمعه‌ها).'}
                                                rules={[{ validator: (_, v) => (Array.isArray(v) && v.length ? Promise.resolve() : Promise.reject(new Error('حداقل یک روز را انتخاب کنید'))) }]}
                                            >
                                                <Checkbox.Group options={WEEKDAY_LABELS_FA.map((label, i) => ({ label, value: i }))} />
                                            </Form.Item>
                                        </Col>
                                    )}
                                    <Col xs={24}>
                                        <div style={{ fontSize: 12, color: '#6B7280', marginTop: -8 }}>
                                            {recurrenceSummary(recurrenceType, recurrenceInterval, seriesStart, recurrenceWeekDays)} — بر اساسِ تقویمِ شمسی.
                                            {eventId ? ' ویرایش روی همهٔ وقوع‌هایِ این سری اعمال می‌شود.' : ''}
                                        </div>
                                    </Col>
                                </>
                            )}
                        </Row>
                        </>
                        )}
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
