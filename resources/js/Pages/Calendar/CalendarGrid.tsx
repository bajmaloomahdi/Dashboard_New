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
import { toEnglishDigits } from '../../Utils/jalali';
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

/** چیدمانِ بدونِ هم‌پوشانیِ رویدادهایِ زمان‌دارِ یک روز (الگوریتمِ لِینِ ساده) */
function layoutLanes(events: CalendarEvent[]): { event: CalendarEvent; lane: number; laneCount: number }[] {
    const sorted = [...events].sort((a, b) => parseDT(a.StartDateTime).getTime() - parseDT(b.StartDateTime).getTime());
    const laneEnds: number[] = [];
    const placed: { event: CalendarEvent; lane: number }[] = [];
    sorted.forEach((ev) => {
        const start = parseDT(ev.StartDateTime).getTime();
        const end = parseDT(ev.EndDateTime).getTime();
        let laneIndex = laneEnds.findIndex((endTime) => endTime <= start);
        if (laneIndex === -1) {
            laneIndex = laneEnds.length;
            laneEnds.push(end);
        } else {
            laneEnds[laneIndex] = end;
        }
        placed.push({ event: ev, lane: laneIndex });
    });
    const laneCount = Math.max(1, laneEnds.length);
    return placed.map((p) => ({ ...p, laneCount }));
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
            className={`cal-chip${dragging ? ' is-dragging' : ''}${draggable ? ' is-draggable' : ''}${isPendingInvite(event) ? ' is-pending' : ''}`}
            onClick={(e) => {
                e.stopPropagation();
                onClick();
            }}
            onPointerDown={onPointerDown}
            title={`${timeRangeLabel(event)} — ${event.Title}${event.IsBusyOnly ? '' : `\nوضعیت: ${eventStatusLabel(event.Status)}`}${event.SourceLabel ? `\n${event.SourceLabel}` : ''}${blockReason ? `\n(${blockReason})` : ''}`}
            style={{
                background: event.Color || THEME.primary,
                opacity: event.Status === 'CANCELLED' ? 0.55 : 1,
                textDecoration: event.Status === 'CANCELLED' ? 'line-through' : 'none',
            }}
        >
            {!event.IsBusyOnly && <EventStatusIcon status={event.Status} onColored noTooltip />}
            {!isAllDay(event) && <span className="cal-chip-time">{fmtTime(start)}</span>}
            {Number(event.IsPrivate) === 1 && <LockOutlined className="cal-chip-icon" />}
            {isRecurring(event) && <SyncOutlined className="cal-chip-icon" />}
            {event.Title}
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
                const cell = el?.closest('[data-cal-day]') as HTMLElement | null;
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
            const cell = target.closest('[data-cal-day]') as HTMLElement | null;
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
    const maxVisible = isMobile ? 2 : 3;

    return (
        <div className="cal-month-grid">
            {WEEKDAY_LABELS_FA.map((label, i) => (
                <div key={label} className="cal-month-head">
                    <WeekdayName index={i} />
                </div>
            ))}
            {cells.map((cell) => {
                const dayEvents = sortEvents(eventsForDay(events, cell.date));
                const visible = dayEvents.slice(0, maxVisible);
                const restCount = dayEvents.length - visible.length;
                return (
                    <div
                        key={cell.date.toISOString()}
                        data-cal-day={dayKey(cell.date)}
                        className={`cal-month-cell${cell.isCurrentMonth ? '' : ' is-outside'}${cell.isToday ? ' is-today' : ''}`}
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

            <div className="cal-timegrid-allday" style={{ gridTemplateColumns: columns }}>
                <div className="cal-timegrid-gutter cal-timegrid-allday-label">تمام‌روز</div>
                {days.map((day) => {
                    const allDayEvents = eventsForDay(events, day.date).filter(isAllDay);
                    return (
                        <div key={day.date.toISOString()} data-cal-day={dayKey(day.date)} className="cal-timegrid-allday-cell" onClick={() => onSelectSlot(day.date)}>
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
                                const slot = new Date(base.getTime() + Math.floor(minutes / 30) * 30 * 60000);
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

                            {sameDay(day.date, now) && (
                                <div className="cal-now-line" style={{ top: ((now.getHours() * 60 + now.getMinutes()) / 60) * HOUR_HEIGHT }} />
                            )}

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
                                        className={`cal-block${dragging ? ' is-dragging' : ''}${reason ? '' : ' is-draggable'}${isPendingInvite(event) ? ' is-pending' : ''}`}
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
                                            background: event.Color || THEME.primary,
                                            opacity: event.Status === 'CANCELLED' ? 0.55 : 1,
                                        }}
                                        title={`${timeRangeLabel(event)} — ${event.Title}${event.IsBusyOnly ? '' : `\nوضعیت: ${eventStatusLabel(event.Status)}`}${event.SourceLabel ? `\n${event.SourceLabel}` : ''}${reason ? `\n(${reason})` : ''}`}
                                    >
                                        <div className="cal-block-title">
                                            {!event.IsBusyOnly && <EventStatusIcon status={event.Status} onColored noTooltip />}
                                            {Number(event.IsPrivate) === 1 && <LockOutlined className="cal-chip-icon" />}
            {isRecurring(event) && <SyncOutlined className="cal-chip-icon" />}
                                            {event.MyReminderOffsetMinutes !== null && event.MyReminderOffsetMinutes !== undefined && (
                                                <BellOutlined className="cal-chip-icon" />
                                            )}
                                            {event.Title}
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
                color: #fff; border-radius: 6px; padding: 1px 6px; font-size: 11px; margin-bottom: 2px;
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
            .cal-chip-icon { font-size: 10px; margin-inline-end: 3px; opacity: 0.9; }
            body.cal-dragging, body.cal-dragging * { cursor: grabbing !important; user-select: none !important; }

            .cal-month-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); border: 1px solid ${THEME.border}; border-radius: 10px; overflow: hidden; }
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
            .cal-month-more { font-size: 10px; color: ${THEME.primary}; padding: 0 4px; font-weight: 600; }
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
            .cal-timegrid-hour-label { font-size: 12px; font-weight: 600; color: #111827; text-align: center; border-top: 1px solid ${THEME.borderLight}; }
            .cal-timegrid-daycol { position: relative; border-inline-start: 1px solid ${THEME.borderLight}; cursor: pointer; min-width: 0; }
            .cal-timegrid-daycol.is-offday { background: #FBFBFC; }
            .cal-timegrid-hour-row { border-top: 1px solid ${THEME.borderLight}; position: relative; }
            .cal-timegrid-hour-row::after { content: ''; position: absolute; inset-inline: 0; top: 50%; border-top: 1px dashed #F1F1F4; }
            .cal-timegrid-hour-row.is-offhours { background: #FAFAFB; }
            .cal-now-line { position: absolute; inset-inline: 0; height: 0; border-top: 2px solid ${THEME.error}; z-index: 3; pointer-events: none; }
            .cal-now-line::before { content: ''; position: absolute; inset-inline-start: -5px; top: -6px; width: 10px; height: 10px; border-radius: 50%; background: ${THEME.error}; }
            .cal-block {
                position: absolute; color: #fff; border-radius: 6px; padding: 2px 6px; font-size: 11px; overflow: hidden;
                cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,0.25); border-inline-start: 3px solid rgba(0,0,0,0.18); user-select: none;
            }
            .cal-block.is-draggable { cursor: grab; }
            .cal-block-title { font-weight: 600; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; }
            .cal-block-time { font-size: 10px; opacity: 0.9; white-space: nowrap; }
            .cal-block-resize { position: absolute; inset-inline: 0; bottom: 0; height: 7px; cursor: ns-resize; }
            .cal-block-resize:hover { background: rgba(255,255,255,0.35); }
            @media (max-width: 767px) {
                .cal-month-cell { min-height: 64px; padding: 2px; }
                .cal-chip { font-size: 10px; padding: 0 4px; }
                .cal-chip-time { display: none; }
                .cal-timegrid-daynum { font-size: 13px; }
                .cal-month-head { font-size: 11px; padding: 6px 1px; }
                .cal-timegrid-weekday { font-size: 10.5px; }
                .cal-timegrid-daylabel { padding: 5px 1px; }
                .cal-block { padding: 1px 3px; font-size: 10px; }
            }
        `}</style>
    );
}
