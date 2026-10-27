import { useEffect, useState } from 'react';
import { Avatar, Button, Empty, Input, Spin, Tabs, Typography } from 'antd';
import { SendOutlined } from '@ant-design/icons';
import type React from 'react';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';

const { Text } = Typography;

/** توضیحِ رویداد (پچِ ۱۲۹) — نویسنده و زمان را سرور ثبت می‌کند؛ ویرایش/حذف ندارد */
interface EventComment {
    CommentID: number | string;
    CommentText: string;
    UserID: number | string;
    UserName: string | null;
    CreatedAt: string;
}

function getXsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** فهرستِ توضیحاتِ رویداد + ثبتِ توضیحِ تازه (اگر canAdd) */
export function EventComments({ eventId, canAdd, onCount }: { eventId: number; canAdd: boolean; onCount?: (n: number) => void }) {
    const [items, setItems] = useState<EventComment[]>([]);
    const [loading, setLoading] = useState(false);
    const [text, setText] = useState('');
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const apply = (list: EventComment[]) => {
        setItems(list);
        onCount?.(list.length);
    };

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError(null);
        fetch(`/calendar/events/${eventId}/comments`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(async (r) => ({ ok: r.ok, data: await r.json().catch(() => ({})) }))
            .then(({ ok, data }) => {
                if (cancelled) return;
                if (ok) apply(data.comments || []);
                else setError(data.message || 'دریافتِ توضیحات ممکن نشد.');
            })
            .finally(() => !cancelled && setLoading(false));
        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [eventId]);

    const submit = async () => {
        if (!text.trim()) return;
        setSaving(true);
        setError(null);
        try {
            const res = await fetch(`/calendar/events/${eventId}/comments`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': getXsrfToken() },
                credentials: 'same-origin',
                body: JSON.stringify({ Text: text.trim() }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                apply(data.comments || []);
                setText('');
            } else {
                setError((data.errors && (Object.values(data.errors).flat()[0] as string)) || data.message || 'ثبتِ توضیح ممکن نشد.');
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <Spin spinning={loading}>
            <div style={{ maxHeight: 340, overflowY: 'auto', paddingInlineEnd: 4 }}>
                {items.length === 0 && !loading ? (
                    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="هنوز توضیحی ثبت نشده است" />
                ) : (
                    items.map((c) => (
                        <div key={c.CommentID} style={{ display: 'flex', gap: 10, padding: '10px 0', borderBottom: '1px solid #F0F0F0' }}>
                            <Avatar size={32} style={{ background: '#7C6FE8', flexShrink: 0 }}>{(c.UserName || '؟').trim().charAt(0)}</Avatar>
                            <div style={{ flex: 1, minWidth: 0 }}>
                                <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'baseline', gap: 8 }}>
                                    <Text strong>{c.UserName || 'کاربر'}</Text>
                                    <Text type="secondary" style={{ fontSize: 12 }} dir="ltr">{gregorianToJalaliDateTimeDisplay(c.CreatedAt)}</Text>
                                </div>
                                <div style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginTop: 2 }}>{c.CommentText}</div>
                            </div>
                        </div>
                    ))
                )}
            </div>
            {error ? <Text type="danger" style={{ display: 'block', marginTop: 8 }}>{error}</Text> : null}
            {canAdd ? (
                <div style={{ marginTop: 12 }}>
                    <Input.TextArea
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        rows={3}
                        maxLength={2000}
                        showCount
                        placeholder="توضیحِ تازه… (نام و زمانِ ثبت خودکار ذخیره می‌شود)"
                        disabled={saving}
                        onPressEnter={(e) => {
                            if (e.ctrlKey || e.metaKey) submit();
                        }}
                    />
                    {/* دکمهٔ ثبت پایینِ پنجره، جایِ دکمه‌هایِ پایینِ مودال (جدا از کادرِ متن) */}
                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 28, paddingTop: 12, borderTop: '1px solid #F0F0F0' }}>
                        <Button type="primary" icon={<SendOutlined />} loading={saving} disabled={!text.trim()} onClick={submit}>
                            ثبتِ توضیح
                        </Button>
                    </div>
                </div>
            ) : null}
        </Spin>
    );
}

/** جزئیات و توضیحات در دو تبِ جدا (تا پنجره شلوغ نشود)؛ بدونِ دسترسیِ دیدنِ توضیحات فقط همان جزئیات */
export function EventDetailTabs({
    eventId,
    canView,
    canAdd,
    onTabChange,
    children,
}: {
    eventId: number;
    canView: boolean;
    canAdd: boolean;
    /** تبِ فعال برایِ پنجرهٔ والد (دکمهٔ «ویرایش» فقط در تبِ جزئیات) */
    onTabChange?: (key: string) => void;
    children: React.ReactNode;
}) {
    const [count, setCount] = useState<number | null>(null);
    useEffect(() => setCount(null), [eventId]);
    if (!canView) return <>{children}</>;

    return (
        <Tabs
            key={eventId}
            size="small"
            onChange={onTabChange}
            items={[
                { key: 'details', label: 'جزئیات', children },
                {
                    key: 'comments',
                    label: count ? `توضیحات (${count})` : 'توضیحات',
                    forceRender: true, // تعدادِ توضیحات از همان ابتدا رویِ عنوانِ تب
                    children: <EventComments eventId={eventId} canAdd={canAdd} onCount={setCount} />,
                },
            ]}
        />
    );
}
