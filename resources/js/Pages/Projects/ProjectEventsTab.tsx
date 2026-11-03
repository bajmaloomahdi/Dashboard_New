import { useCallback, useEffect, useMemo, useState } from 'react';
import { Card, Spin, message } from 'antd';
import { router } from '@inertiajs/react';
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
export default function ProjectEventsTab({ projectId, currentUserId, permissions }: { projectId: number; currentUserId: number; permissions: CalendarPermission[] }) {
    const [events, setEvents] = useState<CalendarEvent[]>([]);
    const [loading, setLoading] = useState(false);
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
            const data = res.ok ? await res.json() : { events: [] };
            setEvents(data.events || []);
        } finally {
            setLoading(false);
        }
    }, [projectId, range]);

    useEffect(() => {
        load();
    }, [load]);

    return (
        <Card style={STYLES.card} className="project-events">
            {msgHolder}
            <Spin spinning={loading}>
                <AgendaView
                    events={events}
                    from={range.from}
                    to={range.to}
                    anchorNow
                    emptyText="هنوز رویدادی به این پروژه وصل نشده است. در فرمِ رویدادِ تقویم، بخشِ «پروژه‌هایِ مرتبط» را انتخاب کنید."
                    onSelectEvent={(ev) => {
                        if (ev.IsBusyOnly) {
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
                onClose={() => setDetails(null)}
                onEdit={() => router.visit('/calendar')}
                onChanged={load}
            />
            <style>{`
                .project-events .cal-agenda { flex: none; min-height: 0; max-height: 70vh; }
            `}</style>
        </Card>
    );
}
