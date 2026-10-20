import { useCallback, useEffect, useState } from 'react';
import { Button, Drawer, Empty, Grid, Space, Spin, Tag, Typography, message } from 'antd';
import { CheckOutlined, CloseOutlined, EyeOutlined, QuestionOutlined, SyncOutlined, UserOutlined } from '@ant-design/icons';
import { gregorianToJalaliDateTimeDisplay, gregorianToJalaliDisplay } from '../../Utils/jalali';
import { THEME } from '../../theme';
import { previousResponseText } from './calendarUtils';
import { NOTIFY_HOOK_CONFIG } from '../../Utils/notify';

const { Text } = Typography;

/** یک دعوتِ در انتظار (sp_GetPendingCalendarInvitations) */
export interface PendingInvitation {
    CalendarEventAttendeeID: number;
    EventID: number;
    RelationType: string;
    InvitedAt: string;
    Title: string;
    StartDateTime: string;
    EndDateTime: string;
    IsAllDay: boolean | number;
    Color: string;
    RecurrenceType: string;
    OrganizerName: string | null;
    OwnerName: string | null;
    /** دعوتِ دوباره به‌خاطرِ تغییرِ زمان/تکرارِ جلسه (پچِ ۱۱۲) و پاسخِ پیش از آن */
    InviteIsUpdate?: boolean | number;
    PreviousResponseStatus?: string | null;
    PreviousResponseDate?: string | null;
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

export async function fetchPendingInvitations(): Promise<PendingInvitation[] | null> {
    try {
        const res = await fetch('/calendar/invitations', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) return null;
        const data = await res.json();
        return data.invitations || [];
    } catch {
        return null;
    }
}

type Response = 'ACCEPTED' | 'TENTATIVE' | 'REJECTED';

/**
 * پنلِ مستقلِ «دعوت‌ها»: دعوت‌هایِ در انتظارِ پاسخ با پذیرفتن / شاید / رد (همان مسیرِ پاسخِ فعلی:
 * POST /calendar/events/{id}/respond). مستقل از UserNotifications.
 */
export default function InvitationsDrawer({
    open,
    onClose,
    onOpenEvent,
    onChanged,
}: {
    open: boolean;
    onClose: () => void;
    onOpenEvent: (eventId: number) => void;
    onChanged: (remaining: number) => void;
}) {
    const [items, setItems] = useState<PendingInvitation[]>([]);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState<number | null>(null);
    const [msgApi, msgHolder] = message.useMessage(NOTIFY_HOOK_CONFIG);
    // موبایل: تمام‌عرض (۴۲۰px از صفحهٔ ۳۷۵px بیرون می‌زد)
    const isNarrow = Grid.useBreakpoint().sm === false;

    const load = useCallback(async () => {
        setLoading(true);
        const list = await fetchPendingInvitations();
        setItems(list ?? []);
        setLoading(false);
        if (list) onChanged(list.length);
    }, [onChanged]);

    useEffect(() => {
        if (open) load();
    }, [open, load]);

    const respond = async (inv: PendingInvitation, status: Response) => {
        setBusy(inv.CalendarEventAttendeeID);
        try {
            const res = await fetch(`/calendar/events/${inv.EventID}/respond`, {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ ResponseStatus: status }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                msgApi.error(data.message || 'ثبتِ پاسخ ناموفق بود.');
                return;
            }
            msgApi.success(data.message || 'پاسخ ثبت شد.');
            const rest = items.filter((x) => x.CalendarEventAttendeeID !== inv.CalendarEventAttendeeID);
            setItems(rest);
            onChanged(rest.length);
        } finally {
            setBusy(null);
        }
    };

    return (
        <Drawer open={open} onClose={onClose} title={`دعوت‌هایِ در انتظارِ پاسخ${items.length ? ` (${items.length})` : ''}`} placement="left" width={isNarrow ? '100%' : 420} styles={{ body: { padding: 12 } }}>
            {msgHolder}
            <Spin spinning={loading}>
                {!items.length && !loading ? (
                    <Empty description="دعوتِ در انتظاری ندارید." style={{ padding: '32px 0' }} />
                ) : (
                    <div className="cal-inv-list">
                        {items.map((inv) => {
                            const allDay = Number(inv.IsAllDay) === 1 || inv.IsAllDay === true;
                            return (
                                <div key={inv.CalendarEventAttendeeID} className="cal-inv-item" style={{ borderInlineStartColor: inv.Color || THEME.primary }}>
                                    <div className="cal-inv-title">{inv.Title}</div>
                                    <div className="cal-inv-meta">
                                        <span>{allDay ? `${gregorianToJalaliDisplay(inv.StartDateTime)} — تمام‌روز` : gregorianToJalaliDateTimeDisplay(inv.StartDateTime)}</span>
                                        {inv.RecurrenceType && inv.RecurrenceType !== 'NONE' && <Tag icon={<SyncOutlined />}>تکرارشونده</Tag>}
                                        {Number(inv.InviteIsUpdate) === 1 && <Tag color="orange">تغییرِ جلسه</Tag>}
                                    </div>
                                    {Number(inv.InviteIsUpdate) === 1 && (
                                        <Text style={{ fontSize: 12, display: 'block', color: '#B45309' }}>
                                            زمان یا تکرارِ این جلسه تغییر کرده است؛ لطفاً دوباره پاسخ دهید.
                                            {inv.PreviousResponseStatus ? ` ${previousResponseText(inv.PreviousResponseStatus, inv.PreviousResponseDate)}` : ''}
                                        </Text>
                                    )}
                                    {inv.OrganizerName && (
                                        <Text type="secondary" style={{ fontSize: 12 }}>
                                            <UserOutlined /> دعوت از: {inv.OrganizerName}
                                        </Text>
                                    )}
                                    <Space wrap size={6} style={{ marginTop: 8 }}>
                                        <Button size="small" type="primary" icon={<CheckOutlined />} loading={busy === inv.CalendarEventAttendeeID} onClick={() => respond(inv, 'ACCEPTED')}>
                                            می‌پذیرم
                                        </Button>
                                        <Button size="small" icon={<QuestionOutlined />} disabled={busy !== null} onClick={() => respond(inv, 'TENTATIVE')}>
                                            شاید
                                        </Button>
                                        <Button size="small" danger icon={<CloseOutlined />} disabled={busy !== null} onClick={() => respond(inv, 'REJECTED')}>
                                            رد
                                        </Button>
                                        <Button size="small" type="link" icon={<EyeOutlined />} onClick={() => onOpenEvent(inv.EventID)}>
                                            جزئیات
                                        </Button>
                                    </Space>
                                </div>
                            );
                        })}
                    </div>
                )}
            </Spin>
            <style>{`
                .cal-inv-list { display: flex; flex-direction: column; gap: 10px; }
                .cal-inv-item { border: 1px solid ${THEME.border}; border-inline-start-width: 4px; border-radius: 10px; padding: 10px 12px; background: #fff; }
                .cal-inv-title { font-weight: 700; margin-bottom: 4px; }
                .cal-inv-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 12px; color: ${THEME.textSecondary}; margin-bottom: 4px; }
            `}</style>
        </Drawer>
    );
}
