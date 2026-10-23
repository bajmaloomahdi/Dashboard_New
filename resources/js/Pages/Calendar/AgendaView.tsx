import { Fragment, useEffect, useMemo, useRef, useState } from 'react';
import { Button, Empty, Tag } from 'antd';
import { BellOutlined, LockOutlined, PlusOutlined, SyncOutlined, UserOutlined } from '@ant-design/icons';
import type { CalendarEvent } from '../../Types';
import { addDays, jalaliFullDayTitle } from '../../Utils/jalaliCalendar';
import { toEnglishDigits } from '../../Utils/jalali';
import { THEME } from '../../theme';
import { EventStatusIcon, EventStatusTag } from './EventStatusIcon';
import { eventsForDay, isAllDay, isRecurring, parseDT, sameDay, sortEvents, startOfDay, timeRangeLabel } from './calendarUtils';

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
    anchorNow = false,
    anchorDate,
    scrollToAnchor = false,
}: {
    events: CalendarEvent[];
    from: Date;
    to: Date;
    /** همهٔ رویدادها (حتی گذشته) نمایش داده می‌شوند، ولی فهرست رویِ «همین لحظه» باز می‌شود (خطِ «اکنون») */
    anchorNow?: boolean;
    /** به‌جایِ «همین لحظه» فهرست رویِ این روز باز شود (مثلاً ماهِ قبل/بعد)؛ خطِ «اکنون» فقط برایِ همین لحظه */
    anchorDate?: Date;
    /** فهرست خودکار تا همان نقطه اسکرول شود (پیش‌فرض: نه — فهرست از ابتدا نمایش داده می‌شود و خطِ «اکنون» فقط نشانگر است) */
    scrollToAnchor?: boolean;
    onSelectEvent: (ev: CalendarEvent) => void;
    onCreate?: () => void;
    emptyText?: string;
}) {
    const listRef = useRef<HTMLDivElement>(null);
    const anchorRef = useRef<HTMLDivElement>(null);
    const [now, setNow] = useState(() => new Date());
    useEffect(() => {
        if (!anchorNow) return;
        const t = window.setInterval(() => setNow(new Date()), 60000);
        return () => window.clearInterval(t);
    }, [anchorNow]);

    const groups = useMemo(() => {
        const result: { day: Date; items: CalendarEvent[] }[] = [];
        const last = startOfDay(to);
        for (let day = startOfDay(from); day <= last; day = addDays(day, 1)) {
            const items = sortEvents(eventsForDay(events, day));
            if (items.length) result.push({ day, items });
        }
        return result;
    }, [events, from, to]);

    /**
     * نقطهٔ «همین لحظه»: در روزِ جاری اولین رویدادی که هنوز تمام نشده، وگرنه اولین رویدادِ روزهایِ بعد.
     * اگر همهٔ رویدادهایِ امروز تمام شده باشد، خطِ «اکنون» انتهایِ امروز کشیده می‌شود.
     */
    const anchorDay = anchorDate ? startOfDay(anchorDate).getTime() : null;
    const anchor = useMemo(() => {
        if (anchorDay !== null) {
            const g = groups.find((x) => x.day.getTime() >= anchorDay);
            return g ? { dayKey: g.day.toISOString(), index: 0, nowLine: false } : null;
        }
        if (!anchorNow) return null;
        const today = startOfDay(now);
        for (const g of groups) {
            if (g.day < today) continue;
            if (sameDay(g.day, today)) {
                const idx = g.items.findIndex((ev) => parseDT(ev.EndDateTime) >= now);
                if (idx >= 0) return { dayKey: g.day.toISOString(), index: idx, nowLine: true };
                continue;
            }
            return { dayKey: g.day.toISOString(), index: 0, nowLine: false };
        }
        return null;
    }, [anchorNow, anchorDay, groups, now]);
    const todayKey = startOfDay(now).toISOString();
    const todayAllPast = anchorNow && anchorDay === null && groups.some((g) => g.day.toISOString() === todayKey) && anchor?.dayKey !== todayKey;

    // باز شدن رویِ همین لحظه (با عوض شدنِ بازه/داده؛ نه در هر دقیقه)
    useEffect(() => {
        const list = listRef.current;
        const target = anchorRef.current;
        if (!list) return;
        if (!scrollToAnchor || !target) {
            list.scrollTop = 0; // از ابتدا
            return;
        }
        const head = target.closest('.cal-agenda-day')?.querySelector('.cal-agenda-dayhead') as HTMLElement | null;
        list.scrollTop = Math.max(0, target.offsetTop - (head?.offsetHeight ?? 0) - 6);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [groups, anchor?.dayKey, anchor?.index]);

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
        <div className="cal-agenda" ref={listRef}>
            {groups.map(({ day, items }) => {
                const isToday = sameDay(day, today);
                const dayKey = day.toISOString();
                const nowLine = (
                    <div className="cal-agenda-now">
                        <span>اکنون · {toEnglishDigits(`${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`)}</span>
                    </div>
                );
                return (
                    <div key={dayKey} className="cal-agenda-day">
                        <div className={`cal-agenda-dayhead${isToday ? ' is-today' : ''}`}>
                            <span>{toEnglishDigits(jalaliFullDayTitle(day))}</span>
                            {isToday && <Tag color={THEME.primary} style={{ marginInlineStart: 8 }}>امروز</Tag>}
                        </div>
                        {items.map((ev, idx) => {
                            const isAnchor = !!anchor && anchor.dayKey === dayKey && anchor.index === idx;
                            const start = parseDT(ev.StartDateTime);
                            const continues = !isAllDay(ev) && !sameDay(start, day);
                            return (
                                <Fragment key={`${ev.OccurrenceKey}-${dayKey}`}>
                                {isAnchor && anchor?.nowLine && nowLine}
                                <div
                                    ref={isAnchor ? anchorRef : undefined}
                                    className="cal-agenda-item"
                                    onClick={() => onSelectEvent(ev)}
                                    style={{ opacity: ev.Status === 'CANCELLED' ? 0.6 : 1 }}
                                >
                                    <div className="cal-agenda-bar" style={{ background: ev.Color || THEME.primary }} />
                                    <div className="cal-agenda-time">{continues ? 'ادامه' : timeRangeLabel(ev)}</div>
                                    <div className="cal-agenda-main">
                                        <div className="cal-agenda-title" style={{ textDecoration: ev.Status === 'CANCELLED' ? 'line-through' : 'none' }}>
                                            {!ev.IsBusyOnly && <EventStatusIcon status={ev.Status} />}
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
                                        {!ev.IsBusyOnly && <EventStatusTag status={ev.Status} />}
                                        {ev.MyRelation === 'ATTENDEE' && (
                                            <Tag color={RESPONSE_TAG[ev.MyResponseStatus ?? 'PENDING'].color}>{RESPONSE_TAG[ev.MyResponseStatus ?? 'PENDING'].label}</Tag>
                                        )}
                                    </div>
                                </div>
                                </Fragment>
                            );
                        })}
                        {isToday && todayAllPast && nowLine}
                    </div>
                );
            })}
            <style>{`
                .cal-agenda { position: relative; display: flex; flex-direction: column; gap: 14px; max-height: min(72vh, 760px); overflow-y: auto; padding-inline-end: 2px; }
                .cal-agenda-now { display: flex; align-items: center; gap: 8px; margin: 4px 0; color: ${THEME.error}; font-size: 12px; font-weight: 700; }
                .cal-agenda-now::before, .cal-agenda-now::after { content: ''; flex: 1; border-top: 2px solid ${THEME.error}; }
                .cal-agenda-dayhead { font-weight: 700; font-size: 13px; color: ${THEME.textPrimary}; padding: 6px 10px; background: #F4F2FD; border-radius: 8px; position: sticky; top: 0; z-index: 1; display: flex; align-items: center; }
                .cal-agenda-dayhead.is-today { background: ${THEME.primary}; color: #fff; }
                .cal-agenda-item { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border-bottom: 1px solid ${THEME.borderLight}; cursor: pointer; border-radius: 8px; }
                .cal-agenda-item:hover { background: ${THEME.bgHover}; }
                .cal-agenda-bar { width: 4px; align-self: stretch; border-radius: 4px; flex-shrink: 0; }
                .cal-agenda-time { width: 112px; flex-shrink: 0; font-size: 14px; font-weight: 600; color: #111827; }
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
