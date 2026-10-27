import { Badge, Empty } from 'antd';
import { BellOutlined, LockOutlined, SyncOutlined } from '@ant-design/icons';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type React from 'react';
import type { CalendarEvent } from '../../Types';
import {
    WEEKDAY_LABELS_FA,
    buildMonthGrid,
    buildWeekDays,
    jalaliDayNumber,
    type JalaliCell,
} from '../../Utils/jalaliCalendar';
import { gregorianToJalaliDisplay, toEnglishDigits } from '../../Utils/jalali';
import { THEME } from '../../theme';
import { EventStatusIcon, eventStatusLabel } from './EventStatusIcon';
import WeekdayName from './WeekdayName';
import {
    dayDiff,
    dayKey,
    eventsForDay,
    fmtTime,
    isAllDay,
    isPendingInvite,
    isRecurring,
    jalaliWeekIndex,
    parseDT,
    sameDay,
    snapMinutes,
    sortEvents,
    startOfDay,
    timeRangeLabel,
    toServerDateTime,
    type ViewMode,
    type WorkWeekSettings,
} from './calendarUtils';

type GridView = Exclude<ViewMode, 'agenda'>;

interface CalendarGridProps {
    view: GridView;
    referenceDate: Date;
    events: CalendarEvent[];
    workWeek: WorkWeekSettings;
    isMobile: boolean;
    /** null = قابلِ کشیدن؛ رشته = دلیلِ عدمِ امکان */
    dragBlockReason: (ev: CalendarEvent) => string | null;
    onSelectSlot: (date: Date) => void;
    onSelectEvent: (event: CalendarEvent) => void;
    onOpenDay: (date: Date) => void;
    onMoveEvent: (event: CalendarEvent, start: Date, end: Date) => void;
}

const HOUR_HEIGHT = 48; // px
const NOW_LINE_GAP = 10; // px — فاصلهٔ خطِ «اکنون» تا بالایِ رویدادِ در حالِ اجرا
const MIN_DURATION_MIN = 15;
const DRAG_THRESHOLD_PX = 4;

type DragKind = 'time' | 'day';

interface DragState {
    ev: CalendarEvent;
    key: string;
    kind: DragKind;
    mode: 'move' | 'resize';
    startX: number;
    startY: number;
    started: boolean;
    origStart: Date;
    origEnd: Date;
    grabOffsetMin: number;
    grabDay: Date | null;
    preview: { start: Date; end: Date };
}

function toFa(n: number): string {
    return toEnglishDigits(String(n));
}

/**
 * چیدمانِ بدونِ هم‌پوشانیِ رویدادهایِ زمان‌دارِ یک روز: فقط رویدادهایی که با هم هم‌پوشانی دارند (یک «خوشه») پهنا را تقسیم
 * می‌کنند؛ بقیهٔ رویدادهایِ همان روز پهنایِ کامل دارند. پایانِ نمایشی حداقل ۲۰ دقیقه است (همان ارتفاعِ حداقلِ بلوک).
 */
function layoutLanes(events: CalendarEvent[]): { event: CalendarEvent; lane: number; laneCount: number }[] {
    const span = (ev: CalendarEvent) => {
        const start = parseDT(ev.StartDateTime).getTime();
        return { start, end: Math.max(parseDT(ev.EndDateTime).getTime(), start + 20 * 60000) };
    };
    const sorted = [...events].sort((a, b) => span(a).start - span(b).start || span(b).end - span(a).end);
    const result: { event: CalendarEvent; lane: number; laneCount: number }[] = [];
    let cluster: { event: CalendarEvent; lane: number }[] = [];
    let laneEnds: number[] = [];
    let clusterEnd = -Infinity;
    const flush = () => {
        const laneCount = Math.max(1, laneEnds.length);
        cluster.forEach((p) => result.push({ ...p, laneCount }));
        cluster = [];
        laneEnds = [];
    };
    sorted.forEach((ev) => {
        const { start, end } = span(ev);
        if (start >= clusterEnd) flush(); // بی‌هم‌پوشانی با قبلی‌ها → خوشهٔ تازه
        let lane = laneEnds.findIndex((endTime) => endTime <= start);
        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(end);
        } else {
            laneEnds[lane] = end;
        }
        cluster.push({ event: ev, lane });
        clusterEnd = Math.max(clusterEnd === -Infinity ? end : clusterEnd, end);
    });
    flush();
    return result;
}

/** رویدادِ تمام‌روزِ چندروزه — مثلِ Outlook یک نوارِ سراسری با یک نام رویِ همهٔ روزهایش کشیده می‌شود */
function isSpanning(ev: CalendarEvent): boolean {
    return isAllDay(ev) && dayDiff(parseDT(ev.StartDateTime), parseDT(ev.EndDateTime)) >= 1;
}

interface BarSegment {
    event: CalendarEvent;
    startCol: number;
    endCol: number;
    lane: number;
    /** پیش/پس از این ردیف ادامه دارد (لبهٔ صاف به‌جایِ گرد) */
    contStart: boolean;
    contEnd: boolean;
}

const BAR_ROW = 24; // px — ارتفاعِ هر ردیفِ نوار (۲۲ + فاصله)
const MONTH_DAYNUM_ROW = 26; // px — جایِ شمارهٔ روز بالایِ خانهٔ ماه

/** قطعه‌هایِ نوارِ رویدادهایِ چندروزه در یک ردیف از روزها (ستون‌ها به ترتیبِ days) و چیدمانِ بی‌هم‌پوشانیِ لِین‌ها */
function layoutBars(events: CalendarEvent[], days: Date[]): { segments: BarSegment[]; lanes: number } {
    if (!days.length) return { segments: [], lanes: 0 };
    const dayStarts = days.map(startOfDay);
    const first = dayStarts[0];
    const last = dayStarts[dayStarts.length - 1];
    const segs: Omit<BarSegment, 'lane'>[] = [];
    events.filter(isSpanning).forEach((event) => {
        const s = startOfDay(parseDT(event.StartDateTime));
        const e = startOfDay(parseDT(event.EndDateTime));
        if (e < first || s > last) return;
        let startCol = -1;
        let endCol = -1;
        dayStarts.forEach((d, i) => {
            if (d >= s && d <= e) {
                if (startCol === -1) startCol = i;
                endCol = i;
            }
        });
        if (startCol === -1) return;
        segs.push({ event, startCol, endCol, contStart: s < dayStarts[startCol], contEnd: e > dayStarts[endCol] });
    });
    segs.sort((a, b) => a.startCol - b.startCol || b.endCol - b.startCol - (a.endCol - a.startCol));
    const laneEnds: number[] = [];
    const segments = segs.map((sg) => {
        let lane = laneEnds.findIndex((end) => end < sg.startCol);
        if (lane === -1) {
            lane = laneEnds.length;
            laneEnds.push(sg.endCol);
        } else {
            laneEnds[lane] = sg.endCol;
        }
        return { ...sg, lane };
    });
    return { segments, lanes: laneEnds.length };
}

/** خانهٔ روزِ زیرِ نشانگر — از زیرِ نوارها هم پیدا می‌شود (نوار رویِ چند خانه کشیده شده است) */
function dayCellAt(x: number, y: number): HTMLElement | null {
    for (const el of document.elementsFromPoint(x, y)) {
        const cell = (el as HTMLElement).closest?.('[data-cal-day]') as HTMLElement | null;
        if (cell) return cell;
    }
    return null;
}

function EventBar({
    seg,
    row,
    colOffset,
    onClick,
    onPointerDown,
    draggable,
    dragging,
    blockReason,
}: {
    seg: BarSegment;
    row: number;
    colOffset: number;
    onClick: () => void;
    onPointerDown?: (e: React.PointerEvent) => void;
    draggable: boolean;
    dragging: boolean;
    blockReason: string | null;
}) {
    const event = seg.event;
    const range = `${gregorianToJalaliDisplay(parseDT(event.StartDateTime))} تا ${gregorianToJalaliDisplay(parseDT(event.EndDateTime))}`;
    return (
        <div
            className={`cal-bar${dragging ? ' is-dragging' : ''}${draggable ? ' is-draggable' : ''}${isPendingInvite(event) ? ' is-pending' : ''}${seg.contStart ? ' is-cont-start' : ''}${seg.contEnd ? ' is-cont-end' : ''}${isCancelled(event) ? ' is-cancelled' : ''}`}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            onPointerDown={onPointerDown}
            title={`تمام‌روز: ${range} — ${event.Title}${event.IsBusyOnly ? '' : `\nوضعیت: ${eventStatusLabel(event.Status)}`}${event.SourceLabel ? `\n${event.SourceLabel}` : ''}${blockReason ? `\n(${blockReason})` : ''}`}
            style={{
                gridColumn: `${seg.startCol + colOffset} / ${seg.endCol + colOffset + 1}`,
                gridRow: row,
                ...eventSurface(event),
            }}
        >
            {seg.contStart && <span className="cal-bar-cont">‹</span>}
            <CancelledBadge event={event} />
            {!event.IsBusyOnly && <EventStatusIcon status={event.Status} onColored={!isDone(event)} noTooltip />}
            {Number(event.IsPrivate) === 1 && <LockOutlined className="cal-chip-icon" />}
            {isRecurring(event) && <SyncOutlined className="cal-chip-icon" />}
            <span className="cal-bar-title">{event.Title}</span>
            {seg.contEnd && <span className="cal-bar-cont">›</span>}
        </div>
    );
}

function isCancelled(ev: CalendarEvent): boolean {
    return ev.Status === 'CANCELLED';
}

/**
 * رنگِ زمینهٔ رویداد؛ لغوشده با یک نگاه پیداست: زمینهٔ خاکستریِ راه‌راه، نوارِ رنگِ اصلی در لبه، برچسبِ «لغو شد» و خط رویِ عنوان.
 */
function isDone(ev: CalendarEvent): boolean {
    return ev.Status === 'DONE';
}

function eventSurface(ev: CalendarEvent): React.CSSProperties {
    if (isCancelled(ev)) {
        return {
            background: 'repeating-linear-gradient(135deg, #9CA3AF 0 6px, #AEB4BD 6px 12px)',
            borderInlineStart: `4px solid ${ev.Color || THEME.primary}`,
            color: '#fff',
        };
    }
    // انجام‌شده: هاشورِ سبزِ کم‌رنگ (بدونِ خط رویِ عنوان) + نوارِ رنگِ اصلی در لبه
    if (isDone(ev)) {
        return {
            background: 'repeating-linear-gradient(135deg, #D9F7BE 0 6px, #C7EDA8 6px 12px)',
            borderInlineStart: `4px solid ${ev.Color || THEME.primary}`,
            color: '#1F5F12',
        };
    }
    return { background: ev.Color || THEME.primary };
}

function CancelledBadge({ event }: { event: CalendarEvent }) {
    if (isCancelled(event)) return <span className="cal-cancel-badge">لغو شد</span>;
    if (isDone(event)) return <span className="cal-cancel-badge is-done">انجام شد</span>;
    return null;
}

function addDaysKeepTime(date: Date, days: number): Date {
    const d = new Date(date);
    d.setDate(d.getDate() + days);
    return d;
}

/** دقیقه از ابتدایِ روز در ستونِ زمانیِ زیرِ نشانگر */
function minutesInColumn(col: HTMLElement, clientY: number): number {
    const rect = col.getBoundingClientRect();
    return Math.max(0, Math.min(24 * 60, ((clientY - rect.top) / HOUR_HEIGHT) * 60));
}

function EventChip({
    event,
    onClick,
    onPointerDown,
    draggable,
    dragging,
    blockReason,
}: {
    event: CalendarEvent;
    onClick: () => void;
    onPointerDown?: (e: React.PointerEvent) => void;
    draggable: boolean;
    dragging: boolean;
    blockReason: string | null;
}) {
    const start = parseDT(event.StartDateTime);
    return (
        <div
            className={`cal-chip${dragging ? ' is-dragging' : ''}${draggable ? ' is-draggable' : ''}${isPendingInvite(event) ? ' is-pending' : ''}${isCancelled(event) ? ' is-cancelled' : ''}`}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            onPointerDown={onPointerDown}
            title={`${timeRangeLabel(event)} — ${event.Title}${event.IsBusyOnly ? '' : `\nوضعیت: ${eventStatusLabel(event.Status)}`}${event.SourceLabel ? `\n${event.SourceLabel}` : ''}${blockReason ? `\n(${blockReason})` : ''}`}
            style={eventSurface(event)}
        >
            <CancelledBadge event={event} />
            {!event.IsBusyOnly && <EventStatusIcon status={event.Status} onColored={!isDone(event)} noTooltip />}
            {!isAllDay(event) && <span className="cal-chip-time">{fmtTime(start)}</span>}
            {Number(event.IsPrivate) === 1 && <LockOutlined className="cal-chip-icon" />}
            {isRecurring(event) && <SyncOutlined className="cal-chip-icon" />}
            <span className="cal-ev-title">{event.Title}</span>
        </div>
    );
}

export default function CalendarGrid(props: CalendarGridProps) {
    const { view, referenceDate, events, workWeek, isMobile, dragBlockReason, onSelectSlot, onSelectEvent, onOpenDay, onMoveEvent } = props;

    const [drag, setDrag] = useState<DragState | null>(null);
    const dragRef = useRef<DragState | null>(null);
    const suppressClickRef = useRef(false);

    const finish = useCallback(
        (commit: boolean) => {
            const d = dragRef.current;
            dragRef.current = null;
            setDrag(null);
            document.body.classList.remove('cal-dragging');
            if (!d || !d.started) return;
            // کلیکِ پس از رها کردن نباید جزئیاتِ رویداد را باز کند
            suppressClickRef.current = true;
            window.setTimeout(() => {
                suppressClickRef.current = false;
            }, 0);
            const changed = d.preview.start.getTime() !== d.origStart.getTime() || d.preview.end.getTime() !== d.origEnd.getTime();
            if (commit && changed) onMoveEvent(d.ev, d.preview.start, d.preview.end);
        },
        [onMoveEvent],
    );

    useEffect(() => {
        if (!drag) return;
        const onMove = (e: PointerEvent) => {
            const d = dragRef.current;
            if (!d) return;
            let justStarted = false;
            if (!d.started) {
                if (Math.hypot(e.clientX - d.startX, e.clientY - d.startY) < DRAG_THRESHOLD_PX) return;
                d.started = true;
                justStarted = true;
                document.body.classList.add('cal-dragging');
            }
            const el = document.elementFromPoint(e.clientX, e.clientY) as HTMLElement | null;
            let preview = d.preview;
            if (d.kind === 'time') {
                const col = el?.closest('[data-cal-daycol]') as HTMLElement | null;
                if (col) {
                    const day = parseDT(col.dataset.calDaycol as string);
                    const minutes = minutesInColumn(col, e.clientY);
                    const duration = d.origEnd.getTime() - d.origStart.getTime();
                    if (d.mode === 'move') {
                        const rawStartMin = minutes - d.grabOffsetMin;
                        const start = new Date(day.getTime() + snapMinutes(rawStartMin) * 60000);
                        preview = { start, end: new Date(start.getTime() + duration) };
                    } else {
                        let end = new Date(day.getTime() + snapMinutes(minutes) * 60000);
                        const minEnd = new Date(d.origStart.getTime() + MIN_DURATION_MIN * 60000);
                        if (end < minEnd) end = minEnd;
                        preview = { start: d.origStart, end };
                    }
                    // اسکرولِ خودکار نزدیکِ لبه‌هایِ بدنهٔ زمانی
                    const scroller = col.closest('.cal-timegrid-body') as HTMLElement | null;
                    if (scroller) {
                        const r = scroller.getBoundingClientRect();
                        if (e.clientY < r.top + 32) scroller.scrollTop -= 14;
                        else if (e.clientY > r.bottom - 32) scroller.scrollTop += 14;
                    }
                }
            } else {
                const cell = dayCellAt(e.clientX, e.clientY);
                if (cell && d.grabDay) {
                    const delta = dayDiff(d.grabDay, parseDT(cell.dataset.calDay as string));
                    preview = { start: addDaysKeepTime(d.origStart, delta), end: addDaysKeepTime(d.origEnd, delta) };
                }
            }
            if (justStarted || preview.start.getTime() !== d.preview.start.getTime() || preview.end.getTime() !== d.preview.end.getTime()) {
                d.preview = preview;
                setDrag({ ...d });
            }
        };
        const onUp = () => finish(true);
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') finish(false);
        };
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);
        window.addEventListener('pointercancel', onUp);
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
            window.removeEventListener('pointercancel', onUp);
            window.removeEventListener('keydown', onKey);
        };
        // فقط با شروع/پایانِ کشیدن دوباره ثبت شود
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [drag !== null, finish]);

    /** شروعِ کشیدن — فقط ماوس/قلم (لمس برایِ اسکرولِ صفحه آزاد می‌ماند؛ رویِ لمسی از فرمِ ویرایش استفاده می‌شود) */
    const beginDrag = (e: React.PointerEvent, ev: CalendarEvent, kind: DragKind, mode: 'move' | 'resize') => {
        if (e.button !== 0 || e.pointerType === 'touch' || dragBlockReason(ev)) return;
        e.stopPropagation();
        if (mode === 'resize') e.preventDefault();
        const origStart = parseDT(ev.StartDateTime);
        const origEnd = parseDT(ev.EndDateTime);
        let grabOffsetMin = 0;
        let grabDay: Date | null = null;
        const target = e.currentTarget as HTMLElement;
        if (kind === 'time') {
            const col = target.closest('[data-cal-daycol]') as HTMLElement | null;
            if (col) {
                // فاصلهٔ نقطهٔ گرفتن تا شروعِ رویداد (دقیقه)؛ در ستونِ مقصد: شروع = روزِ ستون + دقیقهٔ نشانگر − همین فاصله
                const colDay = parseDT(col.dataset.calDaycol as string);
                const pointerTime = colDay.getTime() + minutesInColumn(col, e.clientY) * 60000;
                grabOffsetMin = (pointerTime - origStart.getTime()) / 60000;
            }
        } else {
            const cell = (target.closest('[data-cal-day]') as HTMLElement | null) ?? dayCellAt(e.clientX, e.clientY);
            grabDay = cell ? parseDT(cell.dataset.calDay as string) : startOfDay(origStart);
        }
        const state: DragState = {
            ev,
            key: ev.OccurrenceKey,
            kind,
            mode,
            startX: e.clientX,
            startY: e.clientY,
            started: false,
            origStart,
            origEnd,
            grabOffsetMin,
            grabDay,
            preview: { start: origStart, end: origEnd },
        };
        dragRef.current = state;
        setDrag(state);
    };

    const handleEventClick = (ev: CalendarEvent) => {
        if (suppressClickRef.current) return;
        onSelectEvent(ev);
    };

    // رویدادِ در حالِ کشیدن با زمانِ پیش‌نمایش جایگزین می‌شود
    const displayEvents = useMemo(() => {
        if (!drag || !drag.started) return events;
        return events.map((e) =>
            e.OccurrenceKey === drag.key
                ? { ...e, StartDateTime: toServerDateTime(drag.preview.start), EndDateTime: toServerDateTime(drag.preview.end) }
                : e,
        );
    }, [events, drag]);
    const draggingKey = drag?.started ? drag.key : null;

    if (!events) {
        return <Empty description="رویدادی برای نمایش وجود ندارد" />;
    }

    const shared = { events: displayEvents, draggingKey, dragBlockReason, beginDrag, onEventClick: handleEventClick, onSelectSlot, onOpenDay, isMobile };

    if (view === 'month') {
        return <MonthView referenceDate={referenceDate} {...shared} />;
    }

    let days: JalaliCell[];
    if (view === 'day') {
        days = [{ date: startOfDay(referenceDate), jalaliDay: jalaliDayNumber(referenceDate), isCurrentMonth: true, isToday: sameDay(referenceDate, new Date()) }];
    } else {
        days = buildWeekDays(referenceDate);
        if (view === 'workweek') days = days.filter((d) => workWeek.days.includes(jalaliWeekIndex(d.date)));
    }
    return <TimeGridView key={view} days={days} workWeek={workWeek} showOffDays={view === 'week'} {...shared} />;
}

interface SharedViewProps {
    events: CalendarEvent[];
    draggingKey: string | null;
    dragBlockReason: (ev: CalendarEvent) => string | null;
    beginDrag: (e: React.PointerEvent, ev: CalendarEvent, kind: DragKind, mode: 'move' | 'resize') => void;
    onEventClick: (ev: CalendarEvent) => void;
    onSelectSlot: (date: Date) => void;
    onOpenDay: (date: Date) => void;
    isMobile: boolean;
}

function MonthView({ referenceDate, events, draggingKey, dragBlockReason, beginDrag, onEventClick, onSelectSlot, onOpenDay, isMobile }: SharedViewProps & { referenceDate: Date }) {
    const cells = useMemo(() => buildMonthGrid(referenceDate), [referenceDate]);
    const weeks = useMemo(() => Array.from({ length: Math.ceil(cells.length / 7) }, (_, i) => cells.slice(i * 7, i * 7 + 7)), [cells]);
    const maxVisible = isMobile ? 2 : 3;

    return (
        <div className="cal-month-grid">
            <div className="cal-month-headrow">
                {WEEKDAY_LABELS_FA.map((label, i) => (
                    <div key={label} className="cal-month-head">
                        <WeekdayName index={i} />
                    </div>
                ))}
            </div>
            {weeks.map((week) => {
                // رویدادهایِ تمام‌روزِ چندروزه: یک نوار با یک نام رویِ روزهایِ این هفته (ادامه در هفتهٔ بعد)
                const { segments, lanes } = layoutBars(events, week.map((c) => c.date));
                const chipLimit = Math.max(1, maxVisible - lanes);
                return (
                    <div
                        key={week[0].date.toISOString()}
                        className="cal-month-week"
                        style={{ gridTemplateRows: `${MONTH_DAYNUM_ROW}px ${lanes ? `repeat(${lanes}, ${BAR_ROW}px) ` : ''}auto` }}
                    >
                        {week.map((cell, ci) => {
                            const dayEvents = sortEvents(eventsForDay(events, cell.date).filter((ev) => !isSpanning(ev)));
                            const visible = dayEvents.slice(0, chipLimit);
                            const restCount = dayEvents.length - visible.length;
                            return (
                                <div
                                    key={cell.date.toISOString()}
                                    data-cal-day={dayKey(cell.date)}
                                    className={`cal-month-cell${cell.isCurrentMonth ? '' : ' is-outside'}${cell.isToday ? ' is-today' : ''}`}
                                    style={{ gridColumn: ci + 1, gridRow: '1 / -1' }}
                                    onClick={() => onSelectSlot(cell.date)}
                                >
                                    <div
                                        className="cal-month-cell-day"
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onOpenDay(cell.date);
                                        }}
                                        title="نمایِ روز"
                                    >
                                        {cell.isToday ? <Badge count={toFa(cell.jalaliDay)} color={THEME.primary} /> : toFa(cell.jalaliDay)}
                                    </div>
                                    {lanes > 0 && <div style={{ height: lanes * BAR_ROW, flex: 'none' }} />}
                                    <div className="cal-month-cell-events">
                                        {visible.map((ev) => {
                                            const reason = dragBlockReason(ev);
                                            return (
                                                <EventChip
                                                    key={ev.OccurrenceKey}
                                                    event={ev}
                                                    onClick={() => onEventClick(ev)}
                                                    onPointerDown={(e) => beginDrag(e, ev, 'day', 'move')}
                                                    draggable={!reason}
                                                    dragging={draggingKey === ev.OccurrenceKey}
                                                    blockReason={reason}
                                                />
                                            );
                                        })}
                                        {restCount > 0 && (
                                            <div
                                                className="cal-month-more"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    onOpenDay(cell.date);
                                                }}
                                            >
                                                {`+${toFa(restCount)} بیشتر`}
                                            </div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                        {segments.map((seg) => {
                            const reason = dragBlockReason(seg.event);
                            return (
                                <EventBar
                                    key={`${seg.event.OccurrenceKey}-${seg.startCol}`}
                                    seg={seg}
                                    row={seg.lane + 2}
                                    colOffset={1}
                                    onClick={() => onEventClick(seg.event)}
                                    onPointerDown={(e) => beginDrag(e, seg.event, 'day', 'move')}
                                    draggable={!reason}
                                    dragging={draggingKey === seg.event.OccurrenceKey}
                                    blockReason={reason}
                                />
                            );
                        })}
                    </div>
                );
            })}
            <GridStyles />
        </div>
    );
}

function TimeGridView({
    days,
    workWeek,
    showOffDays,
    events,
    draggingKey,
    dragBlockReason,
    beginDrag,
    onEventClick,
    onSelectSlot,
    onOpenDay,
    isMobile,
}: SharedViewProps & { days: JalaliCell[]; workWeek: WorkWeekSettings; showOffDays: boolean }) {
    const hours = Array.from({ length: 24 }, (_, i) => i);
    const bodyRef = useRef<HTMLDivElement>(null);
    const [now, setNow] = useState(new Date());

    useEffect(() => {
        const t = window.setInterval(() => setNow(new Date()), 60000);
        return () => window.clearInterval(t);
    }, []);

    // شروعِ ساعتِ کاری در دیدرس (مثلِ Outlook)
    useEffect(() => {
        if (bodyRef.current) bodyRef.current.scrollTop = Math.max(0, (workWeek.startHour - 0.5) * HOUR_HEIGHT);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const gutter = isMobile ? 40 : 56;
    const columns = `${gutter}px repeat(${days.length}, minmax(0, 1fr))`;
    const allDayBars = layoutBars(events, days.map((d) => d.date));

    return (
        <div className="cal-timegrid">
            <div className="cal-timegrid-header" style={{ gridTemplateColumns: columns }}>
                <div className="cal-timegrid-gutter" />
                {days.map((day) => (
                    <div
                        key={day.date.toISOString()}
                        className={`cal-timegrid-daylabel${day.isToday ? ' is-today' : ''}`}
                        onClick={() => onOpenDay(day.date)}
                        title="نمایِ روز"
                    >
                        <div className="cal-timegrid-weekday">
                            <WeekdayName index={jalaliWeekIndex(day.date)} />
                        </div>
                        <div className="cal-timegrid-daynum">{toFa(jalaliDayNumber(day.date))}</div>
                    </div>
                ))}
            </div>

            <div
                className="cal-timegrid-allday"
                style={{ gridTemplateColumns: columns, gridTemplateRows: allDayBars.lanes ? `repeat(${allDayBars.lanes}, ${BAR_ROW}px) auto` : undefined }}
            >
                <div className="cal-timegrid-gutter cal-timegrid-allday-label" style={{ gridColumn: 1, gridRow: '1 / -1' }}>تمام‌روز</div>
                {days.map((day, di) => {
                    // تمام‌روزِ یک‌روزه؛ چندروزه‌ها نوارِ سراسری‌اند (پایین)
                    const allDayEvents = eventsForDay(events, day.date).filter((ev) => isAllDay(ev) && !isSpanning(ev));
                    return (
                        <div
                            key={day.date.toISOString()}
                            data-cal-day={dayKey(day.date)}
                            className="cal-timegrid-allday-cell"
                            style={{ gridColumn: di + 2, gridRow: '1 / -1', paddingTop: allDayBars.lanes ? allDayBars.lanes * BAR_ROW + 2 : undefined }}
                            onClick={() => onSelectSlot(day.date)}
                        >
                            {allDayEvents.map((ev) => {
                                const reason = dragBlockReason(ev);
                                return (
                                    <EventChip
                                        key={ev.OccurrenceKey}
                                        event={ev}
                                        onClick={() => onEventClick(ev)}
                                        onPointerDown={(e) => beginDrag(e, ev, 'day', 'move')}
                                        draggable={!reason}
                                        dragging={draggingKey === ev.OccurrenceKey}
                                        blockReason={reason}
                                    />
                                );
                            })}
                        </div>
                    );
                })}
                {allDayBars.segments.map((seg) => {
                    const reason = dragBlockReason(seg.event);
                    return (
                        <EventBar
                            key={`${seg.event.OccurrenceKey}-${seg.startCol}`}
                            seg={seg}
                            row={seg.lane + 1}
                            colOffset={2}
                            onClick={() => onEventClick(seg.event)}
                            onPointerDown={(e) => beginDrag(e, seg.event, 'day', 'move')}
                            draggable={!reason}
                            dragging={draggingKey === seg.event.OccurrenceKey}
                            blockReason={reason}
                        />
                    );
                })}
            </div>

            <div className="cal-timegrid-body" ref={bodyRef} style={{ gridTemplateColumns: columns }}>
                <div className="cal-timegrid-gutter cal-timegrid-hours">
                    {hours.map((h) => (
                        <div key={h} className="cal-timegrid-hour-label" style={{ height: HOUR_HEIGHT }}>
                            {toFa(h).padStart(2, '0')}:00
                        </div>
                    ))}
                </div>

                {days.map((day) => {
                    const timedEvents = eventsForDay(events, day.date).filter((ev) => !isAllDay(ev));
                    const laidOut = layoutLanes(timedEvents);
                    const offDay = showOffDays && !workWeek.days.includes(jalaliWeekIndex(day.date));
                    const base = startOfDay(day.date);
                    const dayEnd = new Date(base.getFullYear(), base.getMonth(), base.getDate(), 23, 59, 59);

                    return (
                        <div
                            key={day.date.toISOString()}
                            data-cal-daycol={dayKey(day.date)}
                            className={`cal-timegrid-daycol${offDay ? ' is-offday' : ''}`}
                            style={{ height: HOUR_HEIGHT * 24 }}
                            onClick={(e) => {
                                const minutes = minutesInColumn(e.currentTarget as HTMLDivElement, e.clientY);
                                const slot = new Date(base.getTime() + Math.floor(minutes / 15) * 15 * 60000); // ربعِ ساعتِ زیرِ کلیک
                                onSelectSlot(slot);
                            }}
                        >
                            {hours.map((h) => (
                                <div
                                    key={h}
                                    className={`cal-timegrid-hour-row${h < workWeek.startHour || h >= workWeek.endHour ? ' is-offhours' : ''}`}
                                    style={{ height: HOUR_HEIGHT }}
                                />
                            ))}

                            {sameDay(day.date, now) && (() => {
                                // اگر رویدادی همین حالا در جریان است، خطِ «اکنون» با فاصله بالایِ آن کشیده می‌شود (نه رویش/چسبیده به آن)
                                const nowTop = ((now.getHours() * 60 + now.getMinutes()) / 60) * HOUR_HEIGHT;
                                const ongoingTops = timedEvents
                                    .filter((ev) => parseDT(ev.StartDateTime) <= now && now < parseDT(ev.EndDateTime))
                                    .map((ev) => {
                                        const s = parseDT(ev.StartDateTime);
                                        return s < base ? 0 : ((s.getTime() - base.getTime()) / 60000 / 60) * HOUR_HEIGHT;
                                    });
                                const top = ongoingTops.length ? Math.max(0, Math.min(nowTop, ...ongoingTops) - NOW_LINE_GAP) : nowTop;
                                return <div className="cal-now-line" style={{ top }} />;
                            })()}

                            {laidOut.map(({ event, lane, laneCount }) => {
                                const evStart = parseDT(event.StartDateTime);
                                const evEnd = parseDT(event.EndDateTime);
                                const clampedStart = evStart < base ? base : evStart;
                                const clampedEnd = evEnd > dayEnd ? dayEnd : evEnd;
                                const startMinutes = (clampedStart.getTime() - base.getTime()) / 60000;
                                const durationMinutes = Math.max(20, (clampedEnd.getTime() - clampedStart.getTime()) / 60000);
                                const top = (startMinutes / 60) * HOUR_HEIGHT;
                                const height = (durationMinutes / 60) * HOUR_HEIGHT;
                                const width = 100 / laneCount;
                                const reason = dragBlockReason(event);
                                const endsToday = evEnd <= dayEnd;
                                const dragging = draggingKey === event.OccurrenceKey;

                                return (
                                    <div
                                        key={event.OccurrenceKey}
                                        className={`cal-block${dragging ? ' is-dragging' : ''}${reason ? '' : ' is-draggable'}${isPendingInvite(event) ? ' is-pending' : ''}${isCancelled(event) ? ' is-cancelled' : ''}${laneCount > 1 ? ' is-narrow' : ''}`}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onEventClick(event);
                                        }}
                                        onPointerDown={(e) => beginDrag(e, event, 'time', 'move')}
                                        style={{
                                            top,
                                            height,
                                            insetInlineStart: `${lane * width}%`,
                                            width: `calc(${width}% - 3px)`,
                                            ...eventSurface(event),
                                        }}
                                        title={`${timeRangeLabel(event)} — ${event.Title}${event.IsBusyOnly ? '' : `\nوضعیت: ${eventStatusLabel(event.Status)}`}${event.SourceLabel ? `\n${event.SourceLabel}` : ''}${reason ? `\n(${reason})` : ''}`}
                                    >
                                        <div className="cal-block-title">
                                            <CancelledBadge event={event} />
                                            {!event.IsBusyOnly && <EventStatusIcon status={event.Status} onColored={!isDone(event)} noTooltip />}
                                            {Number(event.IsPrivate) === 1 && <LockOutlined className="cal-chip-icon" />}
            {isRecurring(event) && <SyncOutlined className="cal-chip-icon" />}
                                            {event.MyReminderOffsetMinutes !== null && event.MyReminderOffsetMinutes !== undefined && (
                                                <BellOutlined className="cal-chip-icon" />
                                            )}
                                            <span className="cal-ev-title">{event.Title}</span>
                                        </div>
                                        {height >= 34 && <div className="cal-block-time">{timeRangeLabel(event)}</div>}
                                        {!reason && endsToday && (
                                            <div className="cal-block-resize" onPointerDown={(e) => beginDrag(e, event, 'time', 'resize')} title="تغییرِ مدت" />
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    );
                })}
            </div>
            <GridStyles />
        </div>
    );
}

function GridStyles() {
    return (
        <style>{`
            .cal-chip {
                color: #fff; border-radius: 6px; padding: 1px 6px; font-size: 12px; margin-bottom: 2px;
                cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; user-select: none;
            }
            .cal-chip.is-draggable { cursor: grab; }
            /* دعوتِ بی‌پاسخ: راه‌راه با حاشیهٔ خط‌چین (مثلِ Outlook) */
            .cal-chip.is-pending, .cal-block.is-pending {
                background-image: repeating-linear-gradient(135deg, rgba(255,255,255,0.28) 0 6px, transparent 6px 12px);
                outline: 1.5px dashed rgba(255,255,255,0.9); outline-offset: -3px;
            }
            .cal-chip.is-dragging, .cal-block.is-dragging { box-shadow: 0 0 0 2px #fff, 0 4px 14px rgba(0,0,0,0.35); z-index: 5; opacity: 0.92 !important; }
            .cal-chip-time { opacity: 0.85; margin-inline-end: 4px; }
            .cal-chip-icon { font-size: 11px; margin-inline-end: 3px; opacity: 0.9; }
            body.cal-dragging, body.cal-dragging * { cursor: grabbing !important; user-select: none !important; }

            .cal-month-grid { border: 1px solid ${THEME.border}; border-radius: 10px; overflow: hidden; }
            .cal-month-headrow, .cal-month-week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
            /* نوارِ رویدادِ تمام‌روزِ چندروزه: یک نام رویِ همهٔ روزها؛ لبهٔ صاف یعنی ادامه در هفتهٔ قبل/بعد */
            .cal-bar {
                position: relative; z-index: 1; align-self: start; height: 22px; line-height: 22px; margin: 1px 3px; padding: 0 6px;
                color: #fff; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; user-select: none;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; box-shadow: 0 1px 2px rgba(0,0,0,0.18);
            }
            .cal-bar.is-draggable { cursor: grab; }
            .cal-bar.is-cont-start { border-start-start-radius: 0; border-end-start-radius: 0; margin-inline-start: 0; }
            .cal-bar.is-cont-end { border-start-end-radius: 0; border-end-end-radius: 0; margin-inline-end: 0; }
            .cal-bar.is-pending {
                background-image: repeating-linear-gradient(135deg, rgba(255,255,255,0.28) 0 6px, transparent 6px 12px);
                outline: 1.5px dashed rgba(255,255,255,0.9); outline-offset: -3px;
            }
            .cal-bar.is-dragging { box-shadow: 0 0 0 2px #fff, 0 4px 14px rgba(0,0,0,0.35); z-index: 5; opacity: 0.92 !important; }
            .cal-bar-cont { opacity: 0.8; margin-inline: 2px; }
            /* رویدادِ لغوشده: خاکستریِ راه‌راه (eventSurface) + برچسبِ «لغو شد» + خط رویِ عنوان */
            .is-cancelled .cal-ev-title, .is-cancelled.cal-bar .cal-bar-title { text-decoration: line-through; text-decoration-thickness: 1.5px; }
            .is-cancelled .cal-block-time { text-decoration: line-through; }
            .cal-cancel-badge {
                display: inline-block; background: rgba(17,24,39,0.55); color: #fff; border-radius: 4px; padding: 0 4px;
                font-size: 11px; font-weight: 700; line-height: 15px; margin-inline-end: 4px; text-decoration: none; vertical-align: 1px;
            }
            .cal-cancel-badge.is-done { background: #389E0D; }
            .cal-bar-title { margin-inline-start: 2px; }
            .cal-month-head { background: #EEEBFB; text-align: center; padding: 8px 2px; font-weight: 600; font-size: 12px; line-height: 1.25; border-bottom: 2px solid #C7BFEF; min-width: 0; }
            .cal-month-cell {
                min-height: 104px; border-top: 1px solid ${THEME.borderLight}; border-inline-start: 1px solid ${THEME.borderLight};
                padding: 4px; cursor: pointer; display: flex; flex-direction: column; gap: 2px; background: #fff; min-width: 0;
            }
            .cal-month-cell:hover { background: ${THEME.bgHover}; }
            .cal-month-cell.is-outside { background: #FAFAFA; color: ${THEME.textLight}; }
            .cal-month-cell.is-today { background: #F7F5FF; }
            .cal-month-cell.is-today .cal-month-cell-day { font-weight: 700; }
            .cal-month-cell-day { font-size: 12px; margin-bottom: 2px; text-align: center; border-radius: 6px; }
            .cal-month-cell-day:hover { background: #E9E5FB; }
            .cal-month-cell-events { display: flex; flex-direction: column; gap: 2px; overflow: hidden; min-width: 0; }
            .cal-month-more { font-size: 11px; color: ${THEME.primary}; padding: 0 4px; font-weight: 600; }
            .cal-month-more:hover { text-decoration: underline; }

            .cal-timegrid { border: 1px solid ${THEME.border}; border-radius: 10px; overflow: hidden; background: #fff; }
            .cal-timegrid-header, .cal-timegrid-allday { display: grid; border-bottom: 1px solid ${THEME.borderLight}; }
            .cal-timegrid-gutter { border-inline-start: 1px solid ${THEME.borderLight}; }
            .cal-timegrid-daylabel { text-align: center; padding: 6px 2px; background: #EEEBFB; border-inline-start: 1px solid ${THEME.borderLight}; cursor: pointer; }
            .cal-timegrid-daylabel:hover { background: #E3DEFA; }
            .cal-timegrid-daylabel.is-today { background: ${THEME.primary}; color: #fff; }
            .cal-timegrid-daylabel.is-today .cal-timegrid-weekday { color: rgba(255,255,255,0.85); }
            .cal-timegrid-weekday { font-size: 12px; color: ${THEME.textSecondary}; line-height: 1.25; }
            .cal-timegrid-daynum { font-size: 15px; font-weight: 600; }
            .cal-timegrid-allday-label { font-size: 12px; font-weight: 600; color: #111827; padding: 6px 2px; text-align: center; }
            .cal-timegrid-allday-cell { min-height: 28px; padding: 2px; border-inline-start: 1px solid ${THEME.borderLight}; cursor: pointer; min-width: 0; }
            .cal-timegrid-body { display: grid; max-height: min(68vh, 720px); overflow-y: auto; overflow-x: hidden; position: relative; }
            .cal-timegrid-hours { position: relative; }
            .cal-timegrid-hour-label { font-size: 12px; font-weight: 600; color: #111827; text-align: center; border-top: 1px solid #CBD5E1; }
            .cal-timegrid-daycol { position: relative; border-inline-start: 1px solid #D9DEE5; cursor: pointer; min-width: 0; }
            .cal-timegrid-daycol.is-offday { background: #FBFBFC; }
            /* هر ساعت ۴ قسمت: خطِ ساعت پررنگ، نیم‌ساعت خط‌چینِ میانه، ربع‌ها کم‌رنگ */
            .cal-timegrid-hour-row {
                border-top: 1px solid #CBD5E1; position: relative; box-sizing: border-box;
                background-image: linear-gradient(to bottom, transparent calc(25% - 1px), #E7EAEF calc(25% - 1px), #E7EAEF 25%, transparent 25%, transparent calc(75% - 1px), #E7EAEF calc(75% - 1px), #E7EAEF 75%, transparent 75%);
            }
            .cal-timegrid-hour-row::after { content: ''; position: absolute; inset-inline: 0; top: 50%; border-top: 1px dashed #D5DBE3; }
            .cal-timegrid-hour-row.is-offhours { background-color: #F7F8FA; }
            /* رویدادِ هم‌زمان (باریک): با رفتنِ ماوس فقط همان رویداد رویِ بقیه بزرگ می‌شود؛ جایِ بقیه عوض نمی‌شود */
            .cal-block.is-narrow { transition: width 0.12s ease, inset-inline-start 0.12s ease, box-shadow 0.12s ease; }
            .cal-block.is-narrow:hover:not(.is-dragging) {
                inset-inline-start: 0 !important; width: calc(100% - 3px) !important; z-index: 4;
                box-shadow: 0 6px 18px rgba(0,0,0,0.28);
            }
            /* خطِ «اکنون» زیرِ رویدادها کشیده می‌شود؛ با رویدادِ در حالِ اجرا، با فاصله بالایِ آن (NOW_LINE_GAP) */
            .cal-now-line { position: absolute; inset-inline: 0; height: 0; border-top: 2px solid ${THEME.error}; z-index: 0; pointer-events: none; }
            .cal-now-line::before { content: ''; position: absolute; inset-inline-start: -5px; top: -6px; width: 10px; height: 10px; border-radius: 50%; background: ${THEME.error}; }
            .cal-block {
                position: absolute; z-index: 1; color: #fff; border-radius: 6px; padding: 2px 6px; font-size: 12px; overflow: hidden;
                cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.25); border-inline-start: 3px solid rgba(0,0,0,0.18); user-select: none;
            }
            .cal-block.is-draggable { cursor: grab; }
            .cal-block-title { font-weight: 600; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
            .cal-block-time { font-size: 11px; opacity: 0.9; white-space: nowrap; }
            .cal-block-resize { position: absolute; inset-inline: 0; bottom: 0; height: 7px; cursor: ns-resize; }
            .cal-block-resize:hover { background: rgba(255,255,255,0.35); }
            @media (max-width: 767px) {
                .cal-month-cell { min-height: 64px; padding: 2px; }
                .cal-chip { font-size: 11px; padding: 0 4px; }
                .cal-chip-time { display: none; }
                .cal-timegrid-daynum { font-size: 13px; }
                .cal-month-head { font-size: 11px; padding: 6px 1px; }
                .cal-timegrid-weekday { font-size: 10.5px; }
                .cal-timegrid-daylabel { padding: 5px 1px; }
                .cal-block { padding: 1px 3px; font-size: 11px; }
            }
        `}</style>
    );
}
