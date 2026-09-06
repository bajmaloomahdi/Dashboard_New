import { Badge, Empty, Tooltip } from 'antd';
import { useMemo } from 'react';
import type { CalendarEvent } from '../../Types';
import {
    WEEKDAY_LABELS_FA,
    WEEKDAY_SHORT_FA,
    buildMonthGrid,
    buildWeekDays,
    jalaliDayNumber,
    type JalaliCell,
} from '../../Utils/jalaliCalendar';
import { toEnglishDigits } from '../../Utils/jalali';
import { THEME } from '../../theme';

type ViewMode = 'month' | 'week' | 'day';

interface CalendarGridProps {
    view: ViewMode;
    referenceDate: Date;
    events: CalendarEvent[];
    onSelectSlot: (date: Date) => void;
    onSelectEvent: (event: CalendarEvent) => void;
}

const HOUR_HEIGHT = 48; // px

function toFa(n: number): string {
    return toEnglishDigits(String(n));
}

function sameDay(a: Date, b: Date): boolean {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
}

function isAllDay(ev: CalendarEvent): boolean {
    return Number(ev.IsAllDay) === 1 || ev.IsAllDay === true;
}

function eventsForDay(events: CalendarEvent[], day: Date) {
    return events.filter((ev) => {
        const start = new Date(ev.StartDateTime);
        const end = new Date(ev.EndDateTime);
        const dayStart = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 0, 0, 0);
        const dayEnd = new Date(day.getFullYear(), day.getMonth(), day.getDate(), 23, 59, 59);
        return start <= dayEnd && end >= dayStart;
    });
}

/** چیدمان بدون هم‌پوشانی رویدادهای زمان‌دار یک روز (الگوریتم لِین ساده) */
function layoutLanes(events: CalendarEvent[]): { event: CalendarEvent; lane: number; laneCount: number }[] {
    const sorted = [...events].sort(
        (a, b) => new Date(a.StartDateTime).getTime() - new Date(b.StartDateTime).getTime()
    );
    const laneEnds: number[] = [];
    const placed: { event: CalendarEvent; lane: number }[] = [];

    sorted.forEach((ev) => {
        const start = new Date(ev.StartDateTime).getTime();
        const end = new Date(ev.EndDateTime).getTime();
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

function EventChip({ event, onClick, compact }: { event: CalendarEvent; onClick: () => void; compact?: boolean }) {
    const start = new Date(event.StartDateTime);
    const timeLabel = isAllDay(event)
        ? 'تمام‌روز'
        : `${toFa(start.getHours()).padStart(2, '0')}:${toFa(start.getMinutes()).padStart(2, '0')}`;

    return (
        <Tooltip title={event.Title}>
            <div
                onClick={(e) => {
                    e.stopPropagation();
                    onClick();
                }}
                style={{
                    background: event.Color || THEME.primary,
                    color: '#fff',
                    borderRadius: 6,
                    padding: compact ? '1px 6px' : '2px 8px',
                    fontSize: 11,
                    marginBottom: 3,
                    cursor: 'pointer',
                    whiteSpace: 'nowrap',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    opacity: event.Status === 'CANCELLED' ? 0.55 : 1,
                    textDecoration: event.Status === 'CANCELLED' ? 'line-through' : 'none',
                }}
            >
                {!isAllDay(event) && <span style={{ opacity: 0.85, marginLeft: 4 }}>{timeLabel}</span>}
                {event.Title}
            </div>
        </Tooltip>
    );
}

function MonthView({
    referenceDate,
    events,
    onSelectSlot,
    onSelectEvent,
}: Omit<CalendarGridProps, 'view'>) {
    const cells = useMemo(() => buildMonthGrid(referenceDate), [referenceDate]);

    return (
        <div className="cal-month-grid">
            {WEEKDAY_LABELS_FA.map((label) => (
                <div key={label} className="cal-month-head">
                    {label}
                </div>
            ))}
            {cells.map((cell) => {
                const dayEvents = eventsForDay(events, cell.date).sort((a, b) => {
                    const aAllDay = isAllDay(a) ? 0 : 1;
                    const bAllDay = isAllDay(b) ? 0 : 1;
                    if (aAllDay !== bAllDay) return aAllDay - bAllDay;
                    return new Date(a.StartDateTime).getTime() - new Date(b.StartDateTime).getTime();
                });
                const visible = dayEvents.slice(0, 3);
                const restCount = dayEvents.length - visible.length;

                return (
                    <div
                        key={cell.date.toISOString()}
                        className={`cal-month-cell${cell.isCurrentMonth ? '' : ' is-outside'}${cell.isToday ? ' is-today' : ''}`}
                        onClick={() => onSelectSlot(cell.date)}
                    >
                        <div className="cal-month-cell-day">
                            {cell.isToday ? <Badge count={toFa(cell.jalaliDay)} color={THEME.primary} /> : toFa(cell.jalaliDay)}
                        </div>
                        <div className="cal-month-cell-events">
                            {visible.map((ev) => (
                                <EventChip key={ev.OccurrenceKey} event={ev} onClick={() => onSelectEvent(ev)} compact />
                            ))}
                            {restCount > 0 && <div className="cal-month-more">{`+${toFa(restCount)} بیشتر`}</div>}
                        </div>
                    </div>
                );
            })}

            <style>{`
                .cal-month-grid {
                    display: grid;
                    grid-template-columns: repeat(7, 1fr);
                    border: 1px solid ${THEME.border};
                    border-radius: 10px;
                    overflow: hidden;
                }
                .cal-month-head {
                    background: #EEEBFB;
                    text-align: center;
                    padding: 8px 4px;
                    font-weight: 600;
                    font-size: 12px;
                    border-bottom: 2px solid #C7BFEF;
                }
                .cal-month-cell {
                    min-height: 100px;
                    border-top: 1px solid ${THEME.borderLight};
                    border-inline-start: 1px solid ${THEME.borderLight};
                    padding: 4px;
                    cursor: pointer;
                    display: flex;
                    flex-direction: column;
                    gap: 2px;
                    background: #fff;
                }
                .cal-month-cell:hover { background: ${THEME.bgHover}; }
                .cal-month-cell.is-outside { background: #FAFAFA; color: ${THEME.textLight}; }
                .cal-month-cell.is-today .cal-month-cell-day { font-weight: 700; }
                .cal-month-cell-day { font-size: 12px; margin-bottom: 2px; text-align: center; }
                .cal-month-cell-events { display: flex; flex-direction: column; gap: 2px; overflow: hidden; }
                .cal-month-more { font-size: 10px; color: ${THEME.textSecondary}; padding: 0 4px; }
                @media (max-width: 767px) {
                    .cal-month-cell { min-height: 64px; }
                }
            `}</style>
        </div>
    );
}

function TimeGridView({
    days,
    events,
    onSelectSlot,
    onSelectEvent,
}: {
    days: JalaliCell[];
    events: CalendarEvent[];
    onSelectSlot: (date: Date) => void;
    onSelectEvent: (event: CalendarEvent) => void;
}) {
    const hours = Array.from({ length: 24 }, (_, i) => i);

    return (
        <div className="cal-timegrid">
            <div className="cal-timegrid-header">
                <div className="cal-timegrid-gutter" />
                {days.map((day) => (
                    <div key={day.date.toISOString()} className={`cal-timegrid-daylabel${day.isToday ? ' is-today' : ''}`}>
                        <div className="cal-timegrid-weekday">{WEEKDAY_SHORT_FA[(day.date.getDay() + 1) % 7]}</div>
                        <div className="cal-timegrid-daynum">{toFa(jalaliDayNumber(day.date))}</div>
                    </div>
                ))}
            </div>

            <div className="cal-timegrid-allday">
                <div className="cal-timegrid-gutter cal-timegrid-allday-label">تمام‌روز</div>
                {days.map((day) => {
                    const allDayEvents = eventsForDay(events, day.date).filter(isAllDay);
                    return (
                        <div key={day.date.toISOString()} className="cal-timegrid-allday-cell" onClick={() => onSelectSlot(day.date)}>
                            {allDayEvents.map((ev) => (
                                <EventChip key={ev.OccurrenceKey} event={ev} onClick={() => onSelectEvent(ev)} compact />
                            ))}
                        </div>
                    );
                })}
            </div>

            <div className="cal-timegrid-body">
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

                    return (
                        <div
                            key={day.date.toISOString()}
                            className="cal-timegrid-daycol"
                            style={{ height: HOUR_HEIGHT * 24 }}
                            onClick={(e) => {
                                const rect = (e.currentTarget as HTMLDivElement).getBoundingClientRect();
                                const offsetY = e.clientY - rect.top;
                                const hour = Math.floor(offsetY / HOUR_HEIGHT);
                                const slot = new Date(day.date);
                                slot.setHours(hour, 0, 0, 0);
                                onSelectSlot(slot);
                            }}
                        >
                            {hours.map((h) => (
                                <div key={h} className="cal-timegrid-hour-row" style={{ height: HOUR_HEIGHT }} />
                            ))}

                            {laidOut.map(({ event, lane, laneCount }) => {
                                const base = new Date(day.date.getFullYear(), day.date.getMonth(), day.date.getDate(), 0, 0, 0);
                                const evStart = new Date(event.StartDateTime);
                                const evEnd = new Date(event.EndDateTime);
                                const clampedStart = evStart < base ? base : evStart;
                                const dayEnd = new Date(base);
                                dayEnd.setHours(23, 59, 59);
                                const clampedEnd = evEnd > dayEnd ? dayEnd : evEnd;

                                const startMinutes = (clampedStart.getTime() - base.getTime()) / 60000;
                                const durationMinutes = Math.max(20, (clampedEnd.getTime() - clampedStart.getTime()) / 60000);

                                const top = (startMinutes / 60) * HOUR_HEIGHT;
                                const height = (durationMinutes / 60) * HOUR_HEIGHT;
                                const width = 100 / laneCount;
                                const left = lane * width;

                                return (
                                    <div
                                        key={event.OccurrenceKey}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            onSelectEvent(event);
                                        }}
                                        style={{
                                            position: 'absolute',
                                            top,
                                            height,
                                            left: `${left}%`,
                                            width: `calc(${width}% - 4px)`,
                                            background: event.Color || THEME.primary,
                                            color: '#fff',
                                            borderRadius: 6,
                                            padding: '2px 6px',
                                            fontSize: 11,
                                            overflow: 'hidden',
                                            cursor: 'pointer',
                                            boxShadow: '0 1px 3px rgba(0,0,0,0.25)',
                                            opacity: event.Status === 'CANCELLED' ? 0.55 : 1,
                                        }}
                                        title={event.Title}
                                    >
                                        <div style={{ fontWeight: 600, whiteSpace: 'nowrap', textOverflow: 'ellipsis', overflow: 'hidden' }}>
                                            {event.Title}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    );
                })}
            </div>

            <style>{`
                .cal-timegrid { border: 1px solid ${THEME.border}; border-radius: 10px; overflow: hidden; }
                .cal-timegrid-header, .cal-timegrid-allday {
                    display: grid;
                    grid-template-columns: 56px repeat(${days.length}, 1fr);
                    border-bottom: 1px solid ${THEME.borderLight};
                }
                .cal-timegrid-gutter { border-inline-start: 1px solid ${THEME.borderLight}; }
                .cal-timegrid-daylabel {
                    text-align: center;
                    padding: 8px 4px;
                    background: #EEEBFB;
                    border-inline-start: 1px solid ${THEME.borderLight};
                }
                .cal-timegrid-daylabel.is-today .cal-timegrid-daynum { color: ${THEME.primary}; font-weight: 700; }
                .cal-timegrid-weekday { font-size: 11px; color: ${THEME.textSecondary}; }
                .cal-timegrid-daynum { font-size: 15px; font-weight: 600; }
                .cal-timegrid-allday-label { font-size: 11px; color: ${THEME.textSecondary}; padding: 6px 4px; }
                .cal-timegrid-allday-cell { min-height: 28px; padding: 2px; border-inline-start: 1px solid ${THEME.borderLight}; cursor: pointer; }
                .cal-timegrid-body { display: grid; grid-template-columns: 56px repeat(${days.length}, 1fr); max-height: 600px; overflow-y: auto; }
                .cal-timegrid-hours { position: relative; }
                .cal-timegrid-hour-label { font-size: 10px; color: ${THEME.textLight}; text-align: center; border-top: 1px solid ${THEME.borderLight}; }
                .cal-timegrid-daycol { position: relative; border-inline-start: 1px solid ${THEME.borderLight}; cursor: pointer; }
                .cal-timegrid-hour-row { border-top: 1px solid ${THEME.borderLight}; }
            `}</style>
        </div>
    );
}

export default function CalendarGrid({ view, referenceDate, events, onSelectSlot, onSelectEvent }: CalendarGridProps) {
    if (!events) {
        return <Empty description="رویدادی برای نمایش وجود ندارد" />;
    }

    if (view === 'month') {
        return <MonthView referenceDate={referenceDate} events={events} onSelectSlot={onSelectSlot} onSelectEvent={onSelectEvent} />;
    }

    if (view === 'week') {
        const days = buildWeekDays(referenceDate);
        return <TimeGridView days={days} events={events} onSelectSlot={onSelectSlot} onSelectEvent={onSelectEvent} />;
    }

    const days = [{ date: referenceDate, jalaliDay: jalaliDayNumber(referenceDate), isCurrentMonth: true, isToday: sameDay(referenceDate, new Date()) }];
    return <TimeGridView days={days} events={events} onSelectSlot={onSelectSlot} onSelectEvent={onSelectEvent} />;
}
