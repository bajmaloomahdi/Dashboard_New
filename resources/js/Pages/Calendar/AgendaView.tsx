import { useEffect, useMemo, useRef } from 'react';
import { Button, Empty, Tag } from 'antd';
import { BellOutlined, LockOutlined, PlusOutlined, SyncOutlined, UserOutlined } from '@ant-design/icons';
import type { CalendarEvent } from '../../Types';
import { addDays, jalaliFullDayTitle } from '../../Utils/jalaliCalendar';
import { toEnglishDigits } from '../../Utils/jalali';
import { THEME } from '../../theme';
import { eventsForDay, isAllDay, isRecurring, parseDT, sameDay, sortEvents, startOfDay, timeRangeLabel } from './calendarUtils';

const STATUS_TAG: Record<string, { label: string; color: string } | undefined> = {
    TENTATIVE: { label: 'موقت', color: 'warning' },
    CANCELLED: { label: 'لغو شده', color: 'default' },
};

const RESPONSE_TAG: Record<string, { label: string; color: string }> = {
    PENDING: { label: 'بدونِ پاسخ', color: 'processing' },
    ACCEPTED: { label: 'پذیرفته', color: 'success' },
    REJECTED: { label: 'رد شده', color: 'error' },
    TENTATIVE: { label: 'شاید', color: 'warning' },
};

/**
 * نمای دستورکار (Agenda) — فهرستِ روز به روزِ رویدادهایِ بازه، فقط روزهایِ دارایِ رویداد.
 * برایِ نتایجِ جست‌وجو هم استفاده می‌شود.
 */
export default function AgendaView({
    events,
    from,
    to,
    onSelectEvent,
    onCreate,
    emptyText = 'در این بازه رویدادی نیست.',
}: {
    events: CalendarEvent[];
    from: Date;
    to: Date;
    onSelectEvent: (ev: CalendarEvent) => void;
    onCreate?: () => void;
    emptyText?: string;
}) {
    const todayRef = useRef<HTMLDivElement>(null);

    const groups = useMemo(() => {
        const result: { day: Date; items: CalendarEvent[] }[] = [];
        const last = startOfDay(to);
        for (let day = startOfDay(from); day <= last; day = addDays(day, 1)) {
            const items = sortEvents(eventsForDay(events, day));
            if (items.length) result.push({ day, items });
        }
        return result;
    }, [events, from, to]);

    // اگر امروز در بازه است، فهرست از امروز دیده شود
    useEffect(() => {
        todayRef.current?.scrollIntoView({ block: 'nearest' });
    }, [groups.length]);

    if (!groups.length) {
        return (
            <Empty description={emptyText} style={{ padding: '32px 0' }}>
                {onCreate && (
                    <Button type="primary" icon={<PlusOutlined />} onClick={onCreate}>
                        رویداد جدید
                    </Button>
                )}
            </Empty>
        );
    }

    const today = new Date();
    return (
        <div className="cal-agenda">
            {groups.map(({ day, items }) => {
                const isToday = sameDay(day, today);
                return (
                    <div key={day.toISOString()} className="cal-agenda-day" ref={isToday ? todayRef : undefined}>
                        <div className={`cal-agenda-dayhead${isToday ? ' is-today' : ''}`}>
                            <span>{toEnglishDigits(jalaliFullDayTitle(day))}</span>
                            {isToday && <Tag color={THEME.primary} style={{ marginInlineStart: 8 }}>امروز</Tag>}
                        </div>
                        {items.map((ev) => {
                            const start = parseDT(ev.StartDateTime);
                            const continues = !isAllDay(ev) && !sameDay(start, day);
                            const status = STATUS_TAG[ev.Status];
                            return (
                                <div
                                    key={`${ev.OccurrenceKey}-${day.toISOString()}`}
                                    className="cal-agenda-item"
                                    onClick={() => onSelectEvent(ev)}
                                    style={{ opacity: ev.Status === 'CANCELLED' ? 0.6 : 1 }}
                                >
                                    <div className="cal-agenda-bar" style={{ background: ev.Color || THEME.primary }} />
                                    <div className="cal-agenda-time">{continues ? 'ادامه' : timeRangeLabel(ev)}</div>
                                    <div className="cal-agenda-main">
                                        <div className="cal-agenda-title" style={{ textDecoration: ev.Status === 'CANCELLED' ? 'line-through' : 'none' }}>
                                            {ev.Title}
                                        </div>
                                        <div className="cal-agenda-meta">
                                            {ev.SourceLabel && <span style={{ color: ev.Color }}>● {ev.SourceLabel}</span>}
                                            {Number(ev.IsPrivate) === 1 && <span><LockOutlined /> خصوصی</span>}
                                            {isRecurring(ev) && <span><SyncOutlined /> تکرارشونده</span>}
                                            {ev.MyReminderOffsetMinutes !== null && ev.MyReminderOffsetMinutes !== undefined && <span><BellOutlined /> یادآوری</span>}
                                            {ev.MyRelation !== 'CREATOR' && ev.CreatorName && <span><UserOutlined /> {ev.CreatorName}</span>}
                                            {ev.MyRelation === 'CREATOR' && ev.OwnerUserID !== ev.CreatedByUserID && ev.OwnerName && <span><UserOutlined /> برایِ {ev.OwnerName}</span>}
                                        </div>
                                    </div>
                                    <div className="cal-agenda-tags">
                                        {status && <Tag color={status.color}>{status.label}</Tag>}
                                        {ev.MyRelation === 'ATTENDEE' && (
                                            <Tag color={RESPONSE_TAG[ev.MyResponseStatus ?? 'PENDING'].color}>{RESPONSE_TAG[ev.MyResponseStatus ?? 'PENDING'].label}</Tag>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                );
            })}
            <style>{`
                .cal-agenda { display: flex; flex-direction: column; gap: 14px; max-height: min(72vh, 760px); overflow-y: auto; padding-inline-end: 2px; }
                .cal-agenda-dayhead { font-weight: 700; font-size: 13px; color: ${THEME.textPrimary}; padding: 6px 10px; background: #F4F2FD; border-radius: 8px; position: sticky; top: 0; z-index: 1; display: flex; align-items: center; }
                .cal-agenda-dayhead.is-today { background: ${THEME.primary}; color: #fff; }
                .cal-agenda-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid ${THEME.borderLight}; cursor: pointer; border-radius: 8px; }
                .cal-agenda-item:hover { background: ${THEME.bgHover}; }
                .cal-agenda-bar { width: 4px; align-self: stretch; border-radius: 4px; flex-shrink: 0; }
                .cal-agenda-time { width: 104px; flex-shrink: 0; font-size: 12px; color: ${THEME.textSecondary}; }
                .cal-agenda-main { flex: 1; min-width: 0; }
                .cal-agenda-title { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .cal-agenda-meta { display: flex; flex-wrap: wrap; gap: 10px; font-size: 11px; color: ${THEME.textLight}; margin-top: 2px; }
                .cal-agenda-tags { display: flex; gap: 4px; flex-shrink: 0; }
                @media (max-width: 767px) {
                    .cal-agenda { max-height: none; }
                    .cal-agenda-item { flex-wrap: wrap; gap: 6px 10px; }
                    .cal-agenda-time { width: auto; }
                    .cal-agenda-main { flex-basis: 100%; order: 3; padding-inline-start: 14px; }
                }
            `}</style>
        </div>
    );
}
