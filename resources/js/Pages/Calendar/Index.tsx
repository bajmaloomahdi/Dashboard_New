import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Badge, Button, Card, Checkbox, Col, Drawer, FloatButton, Grid, Input, Row, Segmented, Select, Space, Spin, message } from 'antd';
import {
    MailOutlined,
    CalendarOutlined,
    CloseOutlined,
    EyeOutlined,
    FilterOutlined,
    LeftOutlined,
    PlusOutlined,
    RightOutlined,
    SearchOutlined,
} from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import CalendarGrid from './CalendarGrid';
import AgendaView from './AgendaView';
import CalendarSidebar from './CalendarSidebar';
import EventFormModal from './EventFormModal';
import EventDetailsModal from './EventDetailsModal';
import InvitationsDrawer, { fetchPendingInvitations } from './InvitationsDrawer';
import ShareCalendarModal from './ShareCalendarModal';
import type { CalendarEvent, CalendarPermission, ReminderOption } from '../../Types';
import {
    addDays,
    addJalaliMonths,
    buildMonthGrid,
    buildWeekDays,
    jalaliFullDayTitle,
    jalaliMonthBounds,
    jalaliMonthTitle,
    toApiDate,
} from '../../Utils/jalaliCalendar';
import { toEnglishDigits } from '../../Utils/jalali';
import PersianDateInput from '../../Components/PersianDateInput';
import { NOTIFY_DURATION_SECONDS, NOTIFY_HOOK_CONFIG } from '../../Utils/notify';
import { STYLES, THEME } from '../../theme';
import {
    EMPTY_FILTERS,
    VIEW_OPTIONS,
    activeFilterCount,
    applyFilters,
    dayKey,
    dragBlockReason,
    jalaliWeekIndex,
    loadView,
    loadWorkWeek,
    parseDT,
    saveView,
    saveWorkWeek,
    matchesSearch,
    startOfDay,
    toServerDateTime,
    type CalendarFilterState,
    type ViewMode,
    type WorkWeekSettings,
    loadSelectedSources,
    saveSelectedSources,
    sharedKey,
    sourceColor,
    unitKey,
    type CalendarSources,
} from './calendarUtils';

interface UserOption {
    UserID: number;
    FullName: string;
}

/** بازهٔ دستورکار نسبت به ماهِ انتخابی (ماه) */
const AGENDA_PAST_MONTHS = 12;
const AGENDA_FUTURE_MONTHS = 12;

/** بازهٔ جست‌وجو: شش ماهِ گذشته تا یک سالِ آینده */
const SEARCH_PAST_DAYS = 180;
const SEARCH_FUTURE_DAYS = 365;

function computeRange(view: ViewMode, referenceDate: Date, workWeek: WorkWeekSettings, agendaFrom: string | null = null): { from: Date; to: Date } {
    if (view === 'month') {
        const cells = buildMonthGrid(referenceDate);
        return { from: cells[0].date, to: cells[cells.length - 1].date };
    }
    if (view === 'agenda') {
        // «از تاریخ»: از همان روز تا یک سال بعد
        if (agendaFrom) {
            const start = startOfDay(new Date(`${agendaFrom}T00:00:00`));
            return { from: start, to: jalaliMonthBounds(addJalaliMonths(start, AGENDA_FUTURE_MONTHS)).to };
        }
        // بدونِ فیلتر: همهٔ رویدادها (حتی گذشته) از ابتدا — یک سالِ قبل تا یک سالِ بعد از ماهِ انتخابی
        return {
            from: jalaliMonthBounds(addJalaliMonths(referenceDate, -AGENDA_PAST_MONTHS)).from,
            to: jalaliMonthBounds(addJalaliMonths(referenceDate, AGENDA_FUTURE_MONTHS)).to,
        };
    }
    if (view === 'week' || view === 'workweek') {
        let cells = buildWeekDays(referenceDate);
        if (view === 'workweek') {
            const work = cells.filter((c) => workWeek.days.includes(jalaliWeekIndex(c.date)));
            if (work.length) cells = work;
        }
        return { from: cells[0].date, to: cells[cells.length - 1].date };
    }
    return { from: startOfDay(referenceDate), to: startOfDay(referenceDate) };
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** رویدادهایِ یک تقویم؛ access = سطحِ دسترسیِ سرور (OWNER | ADMIN | READ | FREEBUSY) */
async function fetchCalendar(url: string): Promise<{ events: CalendarEvent[]; access: string | null }> {
    const res = await fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        credentials: 'same-origin',
    });
    if (!res.ok) return { events: [], access: null };
    const data = await res.json();
    return { events: data.events || [], access: data.access ?? null };
}

const rangeQuery = (from: Date, to: Date) => `from=${toApiDate(from)}&to=${toApiDate(to)}`;

async function fetchEvents(from: Date, to: Date, userId: number): Promise<CalendarEvent[]> {
    return (await fetchCalendar(`/calendar/events?${rangeQuery(from, to)}&userId=${userId}`)).events;
}

async function moveEventApi(eventId: number, start: Date, end: Date): Promise<{ ok: boolean; message: string }> {
    try {
        const res = await fetch(`/calendar/events/${eventId}/move`, {
            method: 'POST',
            headers: {
                'X-XSRF-TOKEN': xsrf(),
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ StartDateTime: toServerDateTime(start), EndDateTime: toServerDateTime(end) }),
        });
        const data = await res.json().catch(() => ({}));
        const msg = data.message || (data.errors ? Object.values(data.errors).flat().join(' ') : '') || (res.status === 419 ? 'نشست منقضی شده؛ صفحه را تازه کنید.' : 'خطا در ارتباط با سرور');
        return { ok: res.ok && !!data.success, message: msg };
    } catch {
        return { ok: false, message: 'ارتباط با سرور برقرار نشد.' };
    }
}

/** وضعیت‌هایِ انتخاب‌شدهٔ دستورکار (در همین مرورگر) */
const AGENDA_STATUSES_KEY = 'raga360.calendar.agendaStatuses';

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
        viewingAccess?: string;
    };
    const { initialEvents, users, reminderOptions, permissions, viewableUsers, currentUserId } = props;
    const can = (p: CalendarPermission) => permissions.includes(p);

    const screens = Grid.useBreakpoint();
    const isMobile = screens.lg === false;
    const [msgApi, msgHolder] = message.useMessage(NOTIFY_HOOK_CONFIG);

    const [view, setViewState] = useState<ViewMode>(() =>
        loadView(typeof window !== 'undefined' && window.matchMedia('(max-width: 991px)').matches ? 'agenda' : 'month'),
    );
    const setView = (v: ViewMode) => {
        setViewState(v);
        saveView(v);
    };
    const [referenceDate, setReferenceDate] = useState<Date>(startOfDay(new Date()));
    const [events, setEvents] = useState<CalendarEvent[]>(initialEvents || []);
    const [loading, setLoading] = useState(false);
    const [workWeek, setWorkWeekState] = useState<WorkWeekSettings>(loadWorkWeek);
    const setWorkWeek = (w: WorkWeekSettings) => {
        setWorkWeekState(w);
        saveWorkWeek(w);
    };

    const [viewingUserId, setViewingUserId] = useState<number>(props.viewingUserId || currentUserId);
    const isReadOnly = viewingUserId !== currentUserId;
    const viewingUserName = viewableUsers.find((u) => u.UserID === viewingUserId)?.FullName ?? props.viewingUserName;

    const [filters, setFilters] = useState<CalendarFilterState>(EMPTY_FILTERS);
    const [searchInput, setSearchInput] = useState('');
    const [query, setQuery] = useState('');
    const [searchEvents, setSearchEvents] = useState<CalendarEvent[] | null>(null);
    const [searchLoading, setSearchLoading] = useState(false);
    const [sideOpen, setSideOpen] = useState(false);

    const [formOpen, setFormOpen] = useState(false);
    const [editingEventId, setEditingEventId] = useState<number | null>(null);
    const [defaultSlotDate, setDefaultSlotDate] = useState<Date | null>(null);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [detailsEventId, setDetailsEventId] = useState<number | null>(null);
    const [detailsOccurrence, setDetailsOccurrence] = useState<{ start: string; end: string } | null>(null);

    // دعوت‌هایِ در انتظار: پنلِ مستقلِ «دعوت‌ها» (لینکِ Push: /calendar?invites=1)
    const [invitesOpen, setInvitesOpen] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('invites') === '1');
    const [invitesCount, setInvitesCount] = useState(0);
    // Inertia هنگامِ رفتن به همین صفحه (اعلانِ داخلِ برنامه → /calendar?invites=1) کامپوننت را دوباره نمی‌سازد؛ با تغییرِ URL باز شود
    const pageUrl = usePage().url;
    useEffect(() => {
        if (new URLSearchParams(pageUrl.split('?')[1] || '').get('invites') === '1') setInvitesOpen(true);
    }, [pageUrl]);
    const closeInvites = () => {
        setInvitesOpen(false);
        // پارامتر حذف شود تا با تازه‌سازیِ صفحه پنل دوباره باز نشود (state ِ Inertia حفظ می‌شود)
        try {
            const u = new URL(window.location.href);
            if (u.searchParams.has('invites')) {
                u.searchParams.delete('invites');
                window.history.replaceState(window.history.state, '', u.pathname + u.search + u.hash);
            }
        } catch {
            /* بی‌اهمیت */
        }
    };
    const refreshInvitesCount = useCallback(async () => {
        const list = await fetchPendingInvitations();
        if (list) setInvitesCount(list.length);
    }, []);
    useEffect(() => {
        if (can('CALENDAR_VIEW')) refreshInvitesCount();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [refreshInvitesCount]);
    // تابعِ پایدار (پنل با هر رندر دوباره بارگذاری نکند)؛ بارگذاریِ رویدادهایِ بازهٔ فعلی از طریقِ ref
    const reloadEventsRef = useRef<() => void>(() => {});
    const handleInvitesChanged = useCallback((remaining: number) => {
        setInvitesCount(remaining);
        reloadEventsRef.current();
    }, []);

    // ---------- تقویم‌هایِ هم‌زمان: اشتراک‌گذاشته‌شده با من + واحدهایِ من ----------
    const [sources, setSources] = useState<CalendarSources | null>(null);
    const [savedSelection, setSavedSelection] = useState<string[] | null>(() => loadSelectedSources(currentUserId));
    const [shareOpen, setShareOpen] = useState(false);
    const [viewingAccess, setViewingAccess] = useState<string | null>(props.viewingAccess ?? null);

    useEffect(() => {
        if (!can('CALENDAR_VIEW')) return;
        fetch('/calendar/sources', { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => {
                if (!d) return;
                setSources({
                    shared: (d.shared || []).map((x: any) => ({ ...x, OwnerUserID: Number(x.OwnerUserID), CalendarShareID: Number(x.CalendarShareID) })),
                    units: (d.units || []).map((x: any) => ({ ...x, UnitID: Number(x.UnitID) })),
                    canShare: !!d.canShare,
                });
            })
            .catch(() => undefined);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const allSourceKeys = useMemo(() => (sources ? [...sources.shared.map((s) => sharedKey(s.OwnerUserID)), ...sources.units.map((u) => unitKey(u.UnitID))] : []), [sources]);
    // انتخابِ ذخیره‌نشده = همه؛ کلیدهایِ منبعی که دیگر وجود ندارد نادیده گرفته می‌شوند
    const selectedSources = useMemo(() => (savedSelection ?? allSourceKeys).filter((k) => allSourceKeys.includes(k)), [savedSelection, allSourceKeys]);
    const sourceColors = useMemo(() => Object.fromEntries(allSourceKeys.map((k, i) => [k, sourceColor(i)])), [allSourceKeys]);
    const toggleSource = (key: string, checked: boolean) => {
        const next = checked ? [...new Set([...selectedSources, key])] : selectedSources.filter((k) => k !== key);
        setSavedSelection(next);
        saveSelectedSources(currentUserId, next);
    };

    // دستورکار: «از تاریخ» و جست‌وجو در موضوع و متن
    const [agendaFrom, setAgendaFrom] = useState<string | null>(null);
    const [agendaQuery, setAgendaQuery] = useState('');
    const range = useMemo(() => computeRange(view, referenceDate, workWeek, agendaFrom), [view, referenceDate, workWeek, agendaFrom]);
    const requestSeq = useRef(0);

    /**
     * تقویمِ اصلی + تقویم‌هایِ هم‌زمانِ انتخاب‌شده (فقط وقتی تقویمِ خودم را می‌بینم). رویدادی که در تقویمِ من هم هست
     * تکرار نمی‌شود (نسخهٔ خودم می‌ماند)؛ رنگِ هر تقویمِ هم‌زمان جداست. سطحِ دسترسی و پنهان‌سازی تماماً سمتِ سرور است.
     */
    const loadEvents = useCallback(async () => {
        const seq = ++requestSeq.current;
        setLoading(true);
        try {
            const q = rangeQuery(range.from, range.to);
            const overlays = !isReadOnly && sources
                ? [
                      ...sources.shared
                          .filter((s) => selectedSources.includes(sharedKey(s.OwnerUserID)))
                          .map((s) => ({ key: sharedKey(s.OwnerUserID), label: `تقویمِ ${s.OwnerName}`, url: `/calendar/events?${q}&userId=${s.OwnerUserID}` })),
                      ...sources.units
                          .filter((u) => selectedSources.includes(unitKey(u.UnitID)))
                          .map((u) => ({ key: unitKey(u.UnitID), label: `تقویمِ واحد: ${u.UnitName}`, url: `/calendar/units/${u.UnitID}/events?${q}` })),
                  ]
                : [];
            const [primary, ...extra] = await Promise.all([
                fetchCalendar(`/calendar/events?${q}&userId=${viewingUserId}`),
                ...overlays.map((o) => fetchCalendar(o.url)),
            ]);
            const merged: CalendarEvent[] = [...primary.events];
            // تکراری = همان رویداد در همان زمان (هر وقوعِ رویدادِ تکرارشونده جدا)؛ «مشغول»ها شناسه ندارند و جدا می‌مانند
            const occurrenceId = (e: CalendarEvent) => `${Number(e.EventID)}|${e.StartDateTime}`;
            const seen = new Set(primary.events.filter((e) => !e.IsBusyOnly).map(occurrenceId));
            extra.forEach((res, i) => {
                const o = overlays[i];
                res.events.forEach((ev) => {
                    if (!ev.IsBusyOnly) {
                        if (seen.has(occurrenceId(ev))) return;
                        seen.add(occurrenceId(ev));
                    }
                    merged.push({ ...ev, OccurrenceKey: `${o.key}|${ev.OccurrenceKey}`, SourceKey: o.key, SourceLabel: o.label, Color: sourceColors[o.key] ?? ev.Color });
                });
            });
            if (seq === requestSeq.current) {
                setEvents(merged);
                setViewingAccess(primary.access);
            }
        } catch {
            /* بی‌صدا */
        } finally {
            if (seq === requestSeq.current) setLoading(false);
        }
    }, [range, viewingUserId, isReadOnly, sources, selectedSources, sourceColors]);

    useEffect(() => {
        loadEvents();
    }, [loadEvents]);
    reloadEventsRef.current = loadEvents;

    // جست‌وجو: با تأخیرِ کوتاه؛ رویدادهایِ بازهٔ وسیع یک‌بار بارگذاری و سپس در مرورگر فیلتر می‌شوند
    useEffect(() => {
        const t = window.setTimeout(() => setQuery(searchInput.trim()), 250);
        return () => window.clearTimeout(t);
    }, [searchInput]);

    const searchRange = useMemo(() => {
        const today = startOfDay(new Date());
        return { from: addDays(today, -SEARCH_PAST_DAYS), to: addDays(today, SEARCH_FUTURE_DAYS) };
    }, []);

    const loadSearchEvents = useCallback(async () => {
        setSearchLoading(true);
        try {
            setSearchEvents(await fetchEvents(searchRange.from, searchRange.to, viewingUserId));
        } catch {
            setSearchEvents([]);
        } finally {
            setSearchLoading(false);
        }
    }, [searchRange, viewingUserId]);

    useEffect(() => {
        if (query && searchEvents === null) loadSearchEvents();
    }, [query, searchEvents, loadSearchEvents]);

    useEffect(() => {
        setSearchEvents(null); // تغییرِ تقویمِ کاربر → نتایجِ جست‌وجو دوباره بارگذاری شود
    }, [viewingUserId]);

    const searching = query !== '';
    // دستورکار جست‌وجویِ خودش را دارد (کنارِ «از تاریخ»)؛ جست‌وجویِ سراسری با رفتن به دستورکار پاک می‌شود
    useEffect(() => {
        if (view === 'agenda' && searchInput) setSearchInput('');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [view]);
    const visibleEvents = useMemo(() => applyFilters(events, filters, ''), [events, filters]);

    // وضعیت‌هایِ دستورکار (چک‌باکس بالایِ فهرست) — پیش‌فرض «ثبت شده»؛ انتخاب در همین مرورگر می‌ماند
    const [agendaStatuses, setAgendaStatuses] = useState<string[]>(() => {
        try {
            const saved = JSON.parse(localStorage.getItem(AGENDA_STATUSES_KEY) || 'null');
            if (Array.isArray(saved)) return saved;
        } catch {
            /* پیش‌فرض */
        }
        return ['CONFIRMED'];
    });
    const changeAgendaStatuses = (v: string[]) => {
        setAgendaStatuses(v);
        try {
            localStorage.setItem(AGENDA_STATUSES_KEY, JSON.stringify(v));
        } catch {
            /* بی‌اهمیت */
        }
    };
    // «موقت» (دادهٔ قدیمی) هم‌ردهٔ «ثبت شده»
    const agendaEvents = useMemo(
        () => visibleEvents.filter((ev) => agendaStatuses.includes(ev.Status === 'TENTATIVE' ? 'CONFIRMED' : ev.Status) && matchesSearch(ev, agendaQuery)),
        [visibleEvents, agendaStatuses, agendaQuery],
    );
    const searchResults = useMemo(() => (searching && searchEvents ? applyFilters(searchEvents, filters, query) : []), [searching, searchEvents, filters, query]);

    const eventDays = useMemo(() => {
        const set = new Set<string>();
        visibleEvents.forEach((ev) => {
            let d = startOfDay(parseDT(ev.StartDateTime));
            const end = parseDT(ev.EndDateTime);
            for (let i = 0; d <= end && i < 62; i++, d = addDays(d, 1)) set.add(dayKey(d));
        });
        return set;
    }, [visibleEvents]);

    const refreshAll = () => {
        loadEvents();
        if (searchEvents !== null) setSearchEvents(null);
        if (can('CALENDAR_VIEW')) refreshInvitesCount(); // پاسخ از پنجرهٔ جزئیات هم شمارهٔ دعوت‌ها را به‌روز کند
    };

    const goPrev = () => {
        if (view === 'month' || view === 'agenda') setReferenceDate((d) => addJalaliMonths(d, -1));
        else if (view === 'week' || view === 'workweek') setReferenceDate((d) => addDays(d, -7));
        else setReferenceDate((d) => addDays(d, -1));
    };
    const goNext = () => {
        if (view === 'month' || view === 'agenda') setReferenceDate((d) => addJalaliMonths(d, 1));
        else if (view === 'week' || view === 'workweek') setReferenceDate((d) => addDays(d, 7));
        else setReferenceDate((d) => addDays(d, 1));
    };
    const goToday = () => setReferenceDate(startOfDay(new Date()));

    const canCreate = can('CALENDAR_CREATE') && !isReadOnly;

    const handleCreate = () => {
        setEditingEventId(null);
        setDefaultSlotDate(new Date());
        setFormOpen(true);
    };
    const handleSelectSlot = (date: Date) => {
        if (!canCreate) return;
        setEditingEventId(null);
        setDefaultSlotDate(date);
        setFormOpen(true);
    };
    const handleSelectEvent = (event: CalendarEvent) => {
        if (event.IsBusyOnly) {
            msgApi.info('از این تقویم فقط وضعیتِ «مشغول» قابلِ مشاهده است.');
            return;
        }
        setDetailsEventId(event.EventID);
        setDetailsOccurrence({ start: event.StartDateTime, end: event.EndDateTime });
        setDetailsOpen(true);
    };
    const handleOpenDay = (date: Date) => {
        setReferenceDate(startOfDay(date));
        setView('day');
    };
    const handleFormClose = (changed: boolean) => {
        setFormOpen(false);
        setEditingEventId(null);
        setDefaultSlotDate(null);
        if (changed) refreshAll();
    };
    const handleEditFromDetails = (eventId: number) => {
        setDetailsOpen(false);
        setEditingEventId(eventId);
        setDefaultSlotDate(null);
        setFormOpen(true);
    };

    const blockReason = useCallback((ev: CalendarEvent) => dragBlockReason(ev, permissions, isReadOnly), [permissions, isReadOnly]);

    /** جابه‌جایی/تغییرِ مدت: نمایشِ فوری، سپس ثبت در سرور؛ خطا = بازگشت؛ موفق = امکانِ «بازگرداندن» */
    const handleMoveEvent = async (ev: CalendarEvent, start: Date, end: Date, isUndo = false) => {
        const original = { start: parseDT(ev.StartDateTime), end: parseDT(ev.EndDateTime) };
        const snapshot = events;
        setEvents((list) =>
            list.map((e) => (e.EventID === ev.EventID ? { ...e, StartDateTime: toServerDateTime(start), EndDateTime: toServerDateTime(end) } : e)),
        );
        const r = await moveEventApi(ev.EventID, start, end);
        if (!r.ok) {
            setEvents(snapshot);
            msgApi.error(r.message);
            return;
        }
        if (isUndo) {
            msgApi.success('زمانِ قبلی برگردانده شد.');
        } else {
            const key = `cal-move-${ev.EventID}-${Date.now()}`;
            msgApi.open({
                key,
                type: 'success',
                duration: NOTIFY_DURATION_SECONDS,
                content: (
                    <span>
                        {r.message}{' '}
                        <a
                            onClick={() => {
                                msgApi.destroy(key);
                                handleMoveEvent({ ...ev, StartDateTime: toServerDateTime(start), EndDateTime: toServerDateTime(end) }, original.start, original.end, true);
                            }}
                        >
                            بازگرداندن
                        </a>
                    </span>
                ),
            });
        }
        refreshAll();
    };

    const rangeTitle =
        view === 'month' || view === 'agenda'
            ? jalaliMonthTitle(referenceDate)
            : view === 'day'
              ? jalaliFullDayTitle(referenceDate)
              : `${jalaliFullDayTitle(range.from)} — ${jalaliFullDayTitle(range.to)}`;
    const filterCount = activeFilterCount(filters);

    const sidebar = (
        <CalendarSidebar
            referenceDate={referenceDate}
            onPickDate={(d) => {
                setReferenceDate(startOfDay(d));
                if (isMobile) setSideOpen(false);
            }}
            eventDays={eventDays}
            viewableUsers={viewableUsers}
            canViewOthers={can('CALENDAR_VIEW_OTHERS')}
            viewingUserId={viewingUserId}
            currentUserId={currentUserId}
            onChangeUser={setViewingUserId}
            filters={filters}
            onChangeFilters={setFilters}
            workWeek={workWeek}
            onChangeWorkWeek={setWorkWeek}
            sources={sources}
            selectedSources={selectedSources}
            onToggleSource={toggleSource}
            sourceColors={sourceColors}
            onOpenShare={sources?.canShare && !isReadOnly ? () => setShareOpen(true) : undefined}
        />
    );

    const searchBox = (
        <Input
            allowClear
            value={searchInput}
            onChange={(e) => setSearchInput(e.target.value)}
            prefix={<SearchOutlined style={{ color: THEME.textLight }} />}
            placeholder="جست‌وجو در رویدادها"
            style={{ width: isMobile ? '100%' : 230 }}
        />
    );

    return (
        <MainLayout>
            {msgHolder}
            <PageHeader
                icon={<CalendarOutlined />}
                title="تقویم و یادآوری‌ها"
                subtitle="مدیریت رویدادها، جلسات و یادآوری‌های شخصی و گروهی"
                stats={[{ icon: <CalendarOutlined />, label: searching ? 'نتایجِ جست‌وجو' : 'رویدادهای این بازه', value: `${searching ? searchResults.length : visibleEvents.length} رویداد` }]}
                actions={
                    <Space>
                        <Badge count={invitesCount} size="small" offset={[-4, 4]}>
                            <Button icon={<MailOutlined />} size="large" onClick={() => setInvitesOpen(true)}>
                                دعوت‌ها
                            </Button>
                        </Badge>
                        {canCreate && !isMobile && (
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
                    message={`نمای فقط‌خواندنی: تقویم «${viewingUserName ?? ''}»`}
                    description={
                        viewingAccess === 'FREEBUSY'
                            ? 'این تقویم فقط در سطحِ «آزاد/مشغول» با شما به اشتراک گذاشته شده؛ عنوان و جزئیاتِ رویدادها نمایش داده نمی‌شود.'
                            : 'در این حالت امکان ایجاد، ویرایش، جابه‌جایی یا حذف رویداد وجود ندارد.'
                    }
                />
            )}

            <Row gutter={16} wrap={false}>
                {!isMobile && (
                    <Col flex="264px">
                        <Card style={{ ...STYLES.card, position: 'sticky', top: 16 }} styles={{ body: { padding: 14 } }}>
                            {sidebar}
                        </Card>
                    </Col>
                )}
                <Col flex="auto" style={{ minWidth: 0 }}>
                    <Card style={{ marginBottom: 12, ...STYLES.filterCard }} styles={{ body: { padding: isMobile ? 10 : 14 } }}>
                        <div className="cal-toolbar">
                            <Space wrap size={6} className="cal-toolbar-nav">
                                <Button icon={<RightOutlined />} onClick={goPrev} aria-label="قبلی" />
                                <Button onClick={goToday}>امروز</Button>
                                <Button icon={<LeftOutlined />} onClick={goNext} aria-label="بعدی" />
                                <span className="cal-toolbar-title">{toEnglishDigits(rangeTitle)}</span>
                            </Space>
                            {isMobile ? (
                                <div className="cal-toolbar-mobile">
                                    {view !== 'agenda' && searchBox}
                                    <Badge count={filterCount} size="small">
                                        <Button icon={<FilterOutlined />} onClick={() => setSideOpen(true)} aria-label="فیلترها" />
                                    </Badge>
                                    <Select value={view} onChange={(v) => setView(v)} options={VIEW_OPTIONS} style={{ width: 118 }} />
                                </div>
                            ) : (
                                <Space wrap size={8}>
                                    {view !== 'agenda' && searchBox}
                                    <Segmented value={view} onChange={(v) => setView(v as ViewMode)} options={VIEW_OPTIONS} />
                                </Space>
                            )}
                        </div>
                    </Card>

                    <Card style={STYLES.card} styles={{ body: { padding: isMobile ? 8 : 12 } }}>
                        {searching ? (
                            <Spin spinning={searchLoading}>
                                <div className="cal-search-head">
                                    <span>
                                        نتایجِ جست‌وجو برایِ «{query}» — {toEnglishDigits(String(searchResults.length))} رویداد
                                        <span className="cal-search-hint"> (شش ماهِ گذشته تا یک سالِ آینده{filterCount ? '، با فیلترهایِ فعال' : ''})</span>
                                    </span>
                                    <Button size="small" icon={<CloseOutlined />} onClick={() => setSearchInput('')}>
                                        بستنِ جست‌وجو
                                    </Button>
                                </div>
                                {searchEvents !== null && (
                                    <AgendaView
                                        events={searchResults}
                                        from={searchRange.from}
                                        to={searchRange.to}
                                        onSelectEvent={handleSelectEvent}
                                        emptyText="رویدادی با این عبارت پیدا نشد."
                                    />
                                )}
                            </Spin>
                        ) : (
                            <Spin spinning={loading}>
                                {view === 'agenda' ? (
                                    <>
                                    <div className="cal-agenda-filters">
                                        <div className="cal-agenda-from">
                                            <span>از تاریخ:</span>
                                            <PersianDateInput value={agendaFrom} onChange={setAgendaFrom} size="middle" placeholder="همهٔ رویدادها" />
                                            {agendaFrom && (
                                                <Button size="small" type="link" onClick={() => setAgendaFrom(null)}>
                                                    همه
                                                </Button>
                                            )}
                                        </div>
                                        <Input
                                            allowClear
                                            prefix={<SearchOutlined style={{ color: '#bfbfbf' }} />}
                                            placeholder="جستجو در موضوع و متن…"
                                            value={agendaQuery}
                                            onChange={(e) => setAgendaQuery(e.target.value)}
                                            className="cal-agenda-search"
                                        />
                                    </div>
                                    <div className="cal-agenda-statuses">
                                        <span>وضعیت:</span>
                                        <Checkbox.Group
                                            value={agendaStatuses}
                                            onChange={(v) => changeAgendaStatuses(v as string[])}
                                            options={[
                                                { label: 'ثبت شده', value: 'CONFIRMED' },
                                                { label: 'انجام شده', value: 'DONE' },
                                                { label: 'لغو شده', value: 'CANCELLED' },
                                            ]}
                                        />
                                    </div>
                                    <AgendaView
                                        events={agendaEvents}
                                        from={range.from}
                                        anchorNow
                                        scrollToAnchor={!agendaFrom}
                                        emptyText={!agendaStatuses.length ? 'هیچ وضعیتی انتخاب نشده است.' : agendaQuery.trim() ? 'رویدادی با این عبارت پیدا نشد.' : 'در این بازه رویدادی نیست.'}
                                        to={range.to}
                                        onSelectEvent={handleSelectEvent}
                                        onCreate={canCreate ? handleCreate : undefined}
                                    />
                                    </>
                                ) : (
                                    <CalendarGrid
                                        view={view}
                                        referenceDate={referenceDate}
                                        events={visibleEvents}
                                        workWeek={workWeek}
                                        isMobile={isMobile}
                                        dragBlockReason={blockReason}
                                        onSelectSlot={handleSelectSlot}
                                        onSelectEvent={handleSelectEvent}
                                        onOpenDay={handleOpenDay}
                                        onMoveEvent={(ev, start, end) => handleMoveEvent(ev, start, end)}
                                    />
                                )}
                            </Spin>
                        )}
                    </Card>
                </Col>
            </Row>

            {isMobile && (
                <Drawer
                    open={sideOpen}
                    onClose={() => setSideOpen(false)}
                    title="تقویم و فیلترها"
                    placement="right"
                    width="86%"
                    styles={{ body: { padding: 14 } }}
                >
                    {sidebar}
                </Drawer>
            )}

            {isMobile && canCreate && (
                <FloatButton type="primary" icon={<PlusOutlined />} onClick={handleCreate} tooltip="رویداد جدید" style={{ insetInlineStart: 20, bottom: 24 }} />
            )}

            <EventFormModal
                open={formOpen}
                onClose={handleFormClose}
                users={users || []}
                reminderOptions={reminderOptions || []}
                permissions={permissions}
                currentUserId={currentUserId}
                eventId={editingEventId}
                defaultDate={defaultSlotDate}
                units={sources?.units ?? []}
            />

            <EventDetailsModal
                open={detailsOpen}
                eventId={detailsEventId}
                occurrence={detailsOccurrence}
                currentUserId={currentUserId}
                permissions={permissions}
                reminderOptions={reminderOptions || []}
                onClose={() => setDetailsOpen(false)}
                onEdit={handleEditFromDetails}
                onChanged={refreshAll}
                onDeleted={() => {
                    setDetailsOpen(false);
                    refreshAll();
                }}
            />

            <ShareCalendarModal open={shareOpen} onClose={() => setShareOpen(false)} users={users || []} currentUserId={currentUserId} />

            <InvitationsDrawer
                open={invitesOpen}
                onClose={closeInvites}
                onOpenEvent={(eventId) => {
                    setDetailsEventId(eventId);
                    setDetailsOccurrence(null);
                    setDetailsOpen(true);
                }}
                onChanged={handleInvitesChanged}
            />

            <style>{`
                .cal-agenda-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 8px; }
                .cal-agenda-from { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; }
                .cal-agenda-search { flex: 1; min-width: 200px; max-width: 360px; }
                .cal-agenda-statuses { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 10px; font-size: 13px; font-weight: 600; }
                .cal-toolbar { display: flex; flex-wrap: wrap; gap: 10px; justify-content: space-between; align-items: center; }
                .cal-toolbar-title { font-weight: 700; font-size: 16px; margin-inline-start: 4px; }
                .cal-toolbar-mobile { display: flex; gap: 8px; width: 100%; align-items: center; }
                .cal-toolbar-mobile .ant-input-affix-wrapper { flex: 1; min-width: 0; }
                .cal-search-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; margin-bottom: 10px; font-weight: 600; }
                .cal-search-hint { font-weight: 400; color: ${THEME.textLight}; font-size: 12px; }
                @media (max-width: 991px) {
                    .cal-toolbar-title { font-size: 14px; }
                }
            `}</style>
        </MainLayout>
    );
}
