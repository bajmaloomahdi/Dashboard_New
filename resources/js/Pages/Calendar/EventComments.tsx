import { useEffect, useState } from 'react';
import { Avatar, Button, Empty, Input, Spin, Tabs, Typography } from 'antd';
import { EditOutlined, SendOutlined } from '@ant-design/icons';
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
    /** پچِ ۱۳۷ — آخرین ویرایشِ متن */
    EditedAt?: string | null;
    EditedByName?: string | null;
}

function getXsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** فهرستِ توضیحاتِ رویداد + ثبتِ توضیحِ تازه (اگر canAdd) + ویرایشِ متن (اگر canEdit — پچِ ۱۳۷) */
export function EventComments({ eventId, canAdd, canEdit = false, onCount }: { eventId: number; canAdd: boolean; canEdit?: boolean; onCount?: (n: number) => void }) {
    const [editingId, setEditingId] = useState<string | null>(null);
    const [editText, setEditText] = useState('');
    const [editSaving, setEditSaving] = useState(false);
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

    const saveEdit = async (commentId: string) => {
        if (!editText.trim()) return;
        setEditSaving(true);
        setError(null);
        try {
            const res = await fetch(`/calendar/events/${eventId}/comments/${commentId}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': getXsrfToken() },
                credentials: 'same-origin',
                body: JSON.stringify({ Text: editText.trim() }),
            });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data.success) {
                apply(data.comments || []);
                setEditingId(null);
            } else {
                setError((data.errors && (Object.values(data.errors).flat()[0] as string)) || data.message || 'ویرایشِ توضیح ممکن نشد.');
            }
        } finally {
            setEditSaving(false);
        }
    };

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
                                    {canEdit && editingId !== String(c.CommentID) ? (
                                        <Button
                                            type="text"
                                            size="small"
                                            icon={<EditOutlined />}
                                            style={{ marginInlineStart: 'auto' }}
                                            onClick={() => {
                                                setEditingId(String(c.CommentID));
                                                setEditText(c.CommentText);
                                            }}
                                        >
                                            ویرایش
                                        </Button>
                                    ) : null}
                                </div>
                                {editingId === String(c.CommentID) ? (
                                    <div style={{ marginTop: 4 }}>
                                        <Input.TextArea value={editText} onChange={(e) => setEditText(e.target.value)} rows={3} maxLength={2000} showCount disabled={editSaving} />
                                        <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', marginTop: 22 }}>
                                            <Button size="small" onClick={() => setEditingId(null)} disabled={editSaving}>
                                                انصراف
                                            </Button>
                                            <Button size="small" type="primary" loading={editSaving} disabled={!editText.trim()} onClick={() => saveEdit(String(c.CommentID))}>
                                                ذخیرهٔ ویرایش
                                            </Button>
                                        </div>
                                    </div>
                                ) : (
                                    <div style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginTop: 2 }}>{c.CommentText}</div>
                                )}
                                {c.EditedAt ? (
                                    <Text type="secondary" style={{ fontSize: 11, display: 'block', marginTop: 2 }}>
                                        ویرایش‌شده{c.EditedByName ? ` توسطِ ${c.EditedByName}` : ''} — {gregorianToJalaliDateTimeDisplay(c.EditedAt)}
                                    </Text>
                                ) : null}
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
    canEditComments = false,
    onTabChange,
    attachments,
    attachmentCount = 0,
    children,
}: {
    eventId: number;
    canView: boolean;
    canAdd: boolean;
    /** ویرایشِ متنِ توضیحات (CALENDAR_COMMENT_EDIT — پچِ ۱۳۷) */
    canEditComments?: boolean;
    /** تبِ فعال برایِ پنجرهٔ والد (دکمهٔ «ویرایش» فقط در تبِ جزئیات) */
    onTabChange?: (key: string) => void;
    /** ضمائمِ رویداد (فهرست + افزودن) — بالایِ تبِ «توضیحات و ضمائم» */
    attachments?: React.ReactNode;
    attachmentCount?: number;
    children: React.ReactNode;
}) {
    const [count, setCount] = useState<number | null>(null);
    useEffect(() => setCount(null), [eventId]);
    if (!canView) return <>{children}</>;
    const total = (count || 0) + attachmentCount;

    return (
        <Tabs
            key={eventId}
            size="small"
            onChange={onTabChange}
            items={[
                { key: 'details', label: 'جزئیات', children },
                {
                    key: 'comments',
                    label: total ? `توضیحات و ضمائم (${total})` : 'توضیحات و ضمائم',
                    forceRender: true, // تعدادِ توضیحات از همان ابتدا رویِ عنوانِ تب
                    children: (
                        <>
                            {attachments}
                            <EventComments eventId={eventId} canAdd={canAdd} canEdit={canEditComments} onCount={setCount} />
                        </>
                    ),
                },
            ]}
        />
    );
}
