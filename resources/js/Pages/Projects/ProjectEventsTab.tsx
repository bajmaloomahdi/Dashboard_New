import { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, Space, Spin, Tag, message } from 'antd';
import { router } from '@inertiajs/react';
import AgendaFilters from '../Calendar/AgendaFilters';
import { matchesSearch } from '../Calendar/calendarUtils';
import AgendaView from '../Calendar/AgendaView';
import EventDetailsModal from '../Calendar/EventDetailsModal';
import type { CalendarEvent, CalendarPermission } from '../../Types';
import { STYLES } from '../../theme';

/** بازهٔ تبِ «رویدادها»: شش ماهِ گذشته تا یک سالِ آینده (همان بازهٔ جست‌وجویِ تقویم) */
const PAST_DAYS = 183;
const FUTURE_DAYS = 365;

const pad = (n: number) => String(n).padStart(2, '0');
const apiDate = (d: Date) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;

/**
 * پچِ ۱۳۸ — تبِ «رویدادها»ی پروژه به‌صورتِ دستورکار: رویدادهایی که به این پروژه وصل شده‌اند (از فرمِ رویداد یا
 * رویدادِ خودکارِ مهلتِ وظیفه). کلیک → همان پنجرهٔ جزئیاتِ تقویم؛ «ویرایش» به صفحهٔ تقویم می‌برد.
 */
export default function ProjectEventsTab({ projectId, currentUserId, permissions, onCount }: { projectId: number; currentUserId: number; permissions: CalendarPermission[]; onCount: (count: number) => void }) {
    const [summary, setSummary] = useState<{ events: CalendarEvent[]; eventCount?: number; commentCounts?: Record<number, number> | null } | null>(null);
    const [agendaFrom, setAgendaFrom] = useState<string | null>(null);
    const [agendaQuery, setAgendaQuery] = useState('');
    const [agendaStatuses, setAgendaStatuses] = useState(['CONFIRMED', 'DONE', 'CANCELLED']);
    const [loading, setLoading] = useState(true);
    const [details, setDetails] = useState<{ id: number; start: string; end: string } | null>(null);
    const [msgApi, msgHolder] = message.useMessage();

    const range = useMemo(() => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return { from: new Date(today.getTime() - PAST_DAYS * 86400000), to: new Date(today.getTime() + FUTURE_DAYS * 86400000) };
    }, []);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await fetch(`/projects/${projectId}/events?from=${apiDate(range.from)}&to=${apiDate(range.to)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error('Project events unavailable');
            setSummary(await res.json());
        } catch {
            msgApi.error('دریافت رویدادها و آمار پروژه انجام نشد.');
        } finally {
            setLoading(false);
        }
    }, [projectId, range, msgApi]);

    useEffect(() => {
        load();
    }, [load]);

    const agendaStart = agendaFrom ? new Date(agendaFrom + 'T00:00:00') : range.from;
    const visibleEvents = (summary?.events ?? []).filter((event) =>
        (!agendaFrom || event.EndDateTime.slice(0, 10) >= agendaFrom)
        && agendaStatuses.includes(event.Status === 'TENTATIVE' ? 'CONFIRMED' : event.Status)
        && matchesSearch(event, agendaQuery),
    );
    const eventCount = summary?.eventCount ?? (summary ? new Set(summary.events.map((event) => Number(event.EventID))).size : null);
    useEffect(() => {
        if (eventCount !== null) onCount(eventCount);
    }, [eventCount, onCount]);
    const commentCount = summary?.commentCounts ? Object.values(summary.commentCounts).reduce((total, count) => total + count, 0) : null;
    const countLabel = (count: number | null) => count?.toLocaleString('fa-IR') ?? (loading ? 'در حال دریافت' : 'در دسترس نیست');

    return (
        <Card style={STYLES.card} className="project-events">
            {msgHolder}
            <Space wrap style={{ marginBottom: 12 }} aria-live="polite">
                <Tag>رویدادها: {countLabel(eventCount)}</Tag>
                {permissions.includes('CALENDAR_COMMENT_VIEW') && <Tag>نظرات: {countLabel(commentCount)}</Tag>}
            </Space>
            <AgendaFilters agendaFrom={agendaFrom} setAgendaFrom={setAgendaFrom}
                agendaQuery={agendaQuery} setAgendaQuery={setAgendaQuery}
                agendaStatuses={agendaStatuses} setAgendaStatuses={setAgendaStatuses}
                searchPlaceholder="جستجو در موضوع، متن و کاربران مرتبط…"
            />
            <Spin spinning={loading}>
                <AgendaView
                    events={visibleEvents}
                    from={agendaStart}
                    to={range.to}
                    anchorNow
                    scrollToAnchor={!agendaFrom}
                    emptyText={eventCount ? 'رویدادی با این فیلترها پیدا نشد.' : 'در این بازه رویدادی برای پروژه ثبت نشده است.'}
                    onSelectEvent={(ev) => {
                        if (Number(ev.IsBusyOnly) === 1) {
                            msgApi.info('این رویداد خصوصی است و فقط وضعیتِ «مشغول» قابلِ مشاهده است.');
                            return;
                        }
                        setDetails({ id: Number(ev.EventID), start: ev.StartDateTime, end: ev.EndDateTime });
                    }}
                />
            </Spin>
            <EventDetailsModal
                open={!!details}
                eventId={details?.id ?? null}
                occurrence={details ? { start: details.start, end: details.end } : null}
                currentUserId={currentUserId}
                permissions={permissions}
                reminderOptions={[]}
                onClose={() => {
                    setDetails(null);
                    void load();
                }}
                onEdit={(eventId) => router.visit(`/calendar?editEventId=${eventId}`)}
                onChanged={load}
            />
        </Card>
    );
}
