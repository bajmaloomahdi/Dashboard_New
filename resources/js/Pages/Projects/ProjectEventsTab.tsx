import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Card, Space, Spin, Tag, message } from 'antd';
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
export default function ProjectEventsTab({ projectId, endpoint, currentUserId = 0, permissions = [], onCount }: { projectId?: number; endpoint?: string; currentUserId?: number; permissions?: CalendarPermission[]; onCount: (count: number) => void }) {
    const eventsUrl = endpoint ?? `/projects/${projectId}/events`;
    const [summary, setSummary] = useState<{ events: CalendarEvent[]; commentCounts?: Record<number, number> | null; permissions?: CalendarPermission[]; currentUserId?: number } | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
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
        setLoadError(null);
        try {
            const res = await fetch(`${eventsUrl}?from=${apiDate(range.from)}&to=${apiDate(range.to)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 403) {
                setLoadError('مجوز مشاهدهٔ رویدادها را ندارید.');
                return;
            }
            if (!res.ok) throw new Error('Events unavailable');
            setSummary(await res.json());
        } catch {
            setLoadError('دریافت رویدادها انجام نشد.');
        } finally {
            setLoading(false);
        }
    }, [eventsUrl, range]);

    useEffect(() => {
        load();
    }, [load]);

    const agendaStart = agendaFrom ? new Date(agendaFrom + 'T00:00:00') : range.from;
    const visibleEvents = (summary?.events ?? []).filter((event) =>
        (!agendaFrom || event.EndDateTime.slice(0, 10) >= agendaFrom)
        && agendaStatuses.includes(event.Status === 'TENTATIVE' ? 'CONFIRMED' : event.Status)
        && matchesSearch(event, agendaQuery),
    );
    const eventCount = summary?.events.length ?? null;
    useEffect(() => {
        if (eventCount !== null) onCount(eventCount);
    }, [eventCount, onCount]);
    const commentCount = summary?.commentCounts ? Object.values(summary.commentCounts).reduce((total, count) => total + count, 0) : null;
    const countLabel = (count: number | null) => count?.toLocaleString('fa-IR') ?? (loading ? 'در حال دریافت' : 'در دسترس نیست');

    return (
        <Card style={STYLES.card} className="project-events">
            {msgHolder}
            {loadError && <Alert showIcon type="warning" message={loadError} />}
            {!loadError && <>
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
                    emptyText={eventCount ? 'رویدادی با این فیلترها پیدا نشد.' : 'در این بازه رویداد مرتبطی ثبت نشده است.'}
                    onSelectEvent={(ev) => {
                        if (Number(ev.IsBusyOnly) === 1) {
                            msgApi.info('این رویداد خصوصی است و فقط وضعیتِ «مشغول» قابلِ مشاهده است.');
                            return;
                        }
                        setDetails({ id: Number(ev.EventID), start: ev.OriginalOccurrenceStart ?? ev.StartDateTime, end: ev.EndDateTime });
                    }}
                />
            </Spin>
            </>}
            <EventDetailsModal
                open={!!details}
                eventId={details?.id ?? null}
                occurrence={details ? { start: details.start, end: details.end } : null}
                currentUserId={summary?.currentUserId ?? currentUserId}
                permissions={summary?.permissions ?? permissions}
                reminderOptions={[]}
                onClose={() => {
                    setDetails(null);
                    void load();
                }}
                onEdit={(eventId) => {
                    const params = new URLSearchParams({ editEventId: String(eventId) });
                    if (details) {
                        params.set('occurrenceStart', details.start);
                        params.set('occurrenceEnd', details.end);
                    }
                    router.visit(`/calendar?${params}`);
                }}
                onChanged={load}
            />
        </Card>
    );
}
