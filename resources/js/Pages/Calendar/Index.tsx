import { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, Button, Segmented, Space, Spin, Select, Alert } from 'antd';
import {
    CalendarOutlined,
    PlusOutlined,
    LeftOutlined,
    RightOutlined,
    BellOutlined,
    EyeOutlined,
} from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import CalendarGrid from './CalendarGrid';
import EventFormModal from './EventFormModal';
import EventDetailsModal from './EventDetailsModal';
import RemindersDrawer from './RemindersDrawer';
import type { CalendarEvent, CalendarPermission, ReminderOption } from '../../Types';
import {
    addDays,
    addJalaliMonths,
    buildMonthGrid,
    buildWeekDays,
    jalaliFullDayTitle,
    jalaliMonthTitle,
    toApiDate,
} from '../../Utils/jalaliCalendar';
import { toEnglishDigits } from '../../Utils/jalali';
import { STYLES } from '../../theme';

type ViewMode = 'month' | 'week' | 'day';

interface UserOption {
    UserID: number;
    FullName: string;
}

function computeRange(view: ViewMode, referenceDate: Date): { from: Date; to: Date } {
    if (view === 'month') {
        const cells = buildMonthGrid(referenceDate);
        return { from: cells[0].date, to: cells[cells.length - 1].date };
    }
    if (view === 'week') {
        const cells = buildWeekDays(referenceDate);
        return { from: cells[0].date, to: cells[cells.length - 1].date };
    }
    return { from: referenceDate, to: referenceDate };
}

export default function CalendarIndex() {
    const props = usePage().props as unknown as {
        initialEvents: CalendarEvent[];
        users: UserOption[];
        reminderOptions: ReminderOption[];
        permissions: CalendarPermission[];
        viewingUserId: number;
        viewingUserName: string | null;
        isReadOnly: boolean;
        viewableUsers: UserOption[];
        currentUserId: number;
    };
    const { initialEvents, users, reminderOptions, permissions, viewableUsers, currentUserId } = props;

    const can = (p: CalendarPermission) => permissions.includes(p);

    const [view, setView] = useState<ViewMode>('month');
    const [referenceDate, setReferenceDate] = useState<Date>(new Date());
    const [events, setEvents] = useState<CalendarEvent[]>(initialEvents || []);
    const [loading, setLoading] = useState(false);

    const [viewingUserId, setViewingUserId] = useState<number>(props.viewingUserId || currentUserId);
    const isReadOnly = viewingUserId !== currentUserId;

    const [formOpen, setFormOpen] = useState(false);
    const [editingEventId, setEditingEventId] = useState<number | null>(null);
    const [defaultSlotDate, setDefaultSlotDate] = useState<Date | null>(null);

    const [detailsOpen, setDetailsOpen] = useState(false);
    const [detailsEventId, setDetailsEventId] = useState<number | null>(null);

    const [remindersOpen, setRemindersOpen] = useState(false);

    const range = useMemo(() => computeRange(view, referenceDate), [view, referenceDate]);

    const loadEvents = useCallback(async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams({
                from: toApiDate(range.from),
                to: toApiDate(range.to),
                userId: String(viewingUserId),
            });
            const res = await fetch(`/calendar/events?${params.toString()}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            setEvents(data.events || []);
        } catch {
            /* بی‌صدا */
        } finally {
            setLoading(false);
        }
    }, [range, viewingUserId]);

    useEffect(() => {
        loadEvents();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [loadEvents]);

    // نمایشِ Popupِ یادآوری‌های سررسیدشده به‌صورت سراسری در <ReminderWatcher> (MainLayout) انجام می‌شود.

    const goPrev = () => {
        if (view === 'month') setReferenceDate((d) => addJalaliMonths(d, -1));
        else if (view === 'week') setReferenceDate((d) => addDays(d, -7));
        else setReferenceDate((d) => addDays(d, -1));
    };
    const goNext = () => {
        if (view === 'month') setReferenceDate((d) => addJalaliMonths(d, 1));
        else if (view === 'week') setReferenceDate((d) => addDays(d, 7));
        else setReferenceDate((d) => addDays(d, 1));
    };
    const goToday = () => setReferenceDate(new Date());

    const handleCreate = () => {
        setEditingEventId(null);
        setDefaultSlotDate(new Date());
        setFormOpen(true);
    };
    const handleSelectSlot = (date: Date) => {
        if (isReadOnly || !can('CALENDAR_CREATE')) return;
        setEditingEventId(null);
        setDefaultSlotDate(date);
        setFormOpen(true);
    };
    const handleSelectEvent = (event: CalendarEvent) => {
        setDetailsEventId(event.EventID);
        setDetailsOpen(true);
    };
    const handleFormClose = (changed: boolean) => {
        setFormOpen(false);
        setEditingEventId(null);
        setDefaultSlotDate(null);
        if (changed) loadEvents();
    };
    const handleEditFromDetails = (eventId: number) => {
        setDetailsOpen(false);
        setEditingEventId(eventId);
        setDefaultSlotDate(null);
        setFormOpen(true);
    };

    const rangeTitle =
        view === 'month'
            ? jalaliMonthTitle(referenceDate)
            : view === 'day'
              ? jalaliFullDayTitle(referenceDate)
              : `${jalaliFullDayTitle(range.from)} — ${jalaliFullDayTitle(range.to)}`;

    const canCreate = can('CALENDAR_CREATE') && !isReadOnly;

    return (
        <MainLayout>
            <PageHeader
                icon={<CalendarOutlined />}
                title="تقویم و یادآوری‌ها"
                subtitle="مدیریت رویدادها، جلسات و یادآوری‌های شخصی و گروهی"
                stats={[{ icon: <CalendarOutlined />, label: 'رویدادهای این بازه', value: `${events.length} رویداد` }]}
                actions={
                    <Space>
                        <Button icon={<BellOutlined />} size="large" onClick={() => setRemindersOpen(true)}>
                            یادآوری‌های من
                        </Button>
                        {canCreate && (
                            <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                                رویداد جدید
                            </Button>
                        )}
                    </Space>
                }
            />

            {isReadOnly && (
                <Alert
                    type="info"
                    showIcon
                    icon={<EyeOutlined />}
                    style={{ marginBottom: 16, borderRadius: 8 }}
                    message={`نمای فقط‌خواندنی: تقویم «${props.viewingUserName ?? ''}»`}
                    description="در این حالت امکان ایجاد، ویرایش یا حذف رویداد وجود ندارد."
                />
            )}

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 12, justifyContent: 'space-between', alignItems: 'center' }}>
                    <Space wrap>
                        <Button icon={<RightOutlined />} onClick={goPrev} />
                        <Button onClick={goToday}>امروز</Button>
                        <Button icon={<LeftOutlined />} onClick={goNext} />
                        <span style={{ fontWeight: 700, fontSize: 16 }}>{toEnglishDigits(rangeTitle)}</span>
                    </Space>
                    <Space wrap>
                        {can('CALENDAR_VIEW_OTHERS') && viewableUsers.length > 0 && (
                            <Select
                                size="large"
                                value={viewingUserId}
                                style={{ minWidth: 200 }}
                                onChange={(v) => setViewingUserId(v)}
                                optionFilterProp="label"
                                showSearch
                                options={[
                                    { value: currentUserId, label: 'تقویم من' },
                                    ...viewableUsers
                                        .filter((u) => u.UserID !== currentUserId)
                                        .map((u) => ({ value: u.UserID, label: `تقویم: ${u.FullName}` })),
                                ]}
                            />
                        )}
                        <Segmented
                            value={view}
                            onChange={(v) => setView(v as ViewMode)}
                            options={[
                                { label: 'ماه', value: 'month' },
                                { label: 'هفته', value: 'week' },
                                { label: 'روز', value: 'day' },
                            ]}
                            size="large"
                        />
                    </Space>
                </div>
            </Card>

            <Card style={STYLES.card} styles={{ body: { padding: 12 } }}>
                <Spin spinning={loading}>
                    <CalendarGrid
                        view={view}
                        referenceDate={referenceDate}
                        events={events}
                        onSelectSlot={handleSelectSlot}
                        onSelectEvent={handleSelectEvent}
                    />
                </Spin>
            </Card>

            <EventFormModal
                open={formOpen}
                onClose={handleFormClose}
                users={users || []}
                reminderOptions={reminderOptions || []}
                permissions={permissions}
                currentUserId={currentUserId}
                eventId={editingEventId}
                defaultDate={defaultSlotDate}
            />

            <EventDetailsModal
                open={detailsOpen}
                eventId={detailsEventId}
                currentUserId={currentUserId}
                permissions={permissions}
                reminderOptions={reminderOptions || []}
                onClose={() => setDetailsOpen(false)}
                onEdit={handleEditFromDetails}
                onChanged={() => loadEvents()}
                onDeleted={() => {
                    setDetailsOpen(false);
                    loadEvents();
                }}
            />

            <RemindersDrawer
                open={remindersOpen}
                onClose={() => setRemindersOpen(false)}
                users={users || []}
                permissions={permissions}
                currentUserId={currentUserId}
            />
        </MainLayout>
    );
}
