import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Button, Empty, List, Modal, Popconfirm, Select, Space, Spin, Typography, message } from 'antd';
import { DeleteOutlined, ShareAltOutlined, UserAddOutlined } from '@ant-design/icons';
import { NOTIFY_HOOK_CONFIG } from '../../Utils/notify';

const { Text } = Typography;

type Level = 'FREEBUSY' | 'READ';

interface ShareRow {
    CalendarShareID: number;
    SharedWithUserID: number;
    FullName: string;
    AccessLevel: Level;
}

interface UserOption {
    UserID: number;
    FullName: string;
}

export const LEVEL_OPTIONS: { value: Level; label: string }[] = [
    { value: 'FREEBUSY', label: 'فقط آزاد/مشغول' },
    { value: 'READ', label: 'دیدنِ جزئیات (فقط‌خواندنی)' },
];

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function call(url: string, method: string, body?: unknown): Promise<{ ok: boolean; data: any }> {
    try {
        const res = await fetch(url, {
            method,
            headers: { 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', Accept: 'application/json' },
            credentials: 'same-origin',
            body: body ? JSON.stringify(body) : undefined,
        });
        const data = await res.json().catch(() => ({}));
        return { ok: res.ok && data.success !== false, data };
    } catch {
        return { ok: false, data: { message: 'ارتباط با سرور برقرار نشد.' } };
    }
}

/**
 * «اشتراک‌گذاریِ تقویمِ من»: افزودن/تغییرِ سطح/لغو. سطح‌ها فقط FREEBUSY و READ (ویرایشِ به نمایندگی نیست).
 * پنهان‌سازیِ جزئیات در سطحِ FREEBUSY کاملاً در سرور انجام می‌شود.
 */
export default function ShareCalendarModal({
    open,
    onClose,
    users,
    currentUserId,
}: {
    open: boolean;
    onClose: () => void;
    users: UserOption[];
    currentUserId: number;
}) {
    const [rows, setRows] = useState<ShareRow[]>([]);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [newUser, setNewUser] = useState<number | null>(null);
    const [newLevel, setNewLevel] = useState<Level>('FREEBUSY');
    const [msgApi, msgHolder] = message.useMessage(NOTIFY_HOOK_CONFIG);

    const load = useCallback(async () => {
        setLoading(true);
        const r = await call('/calendar/shares', 'GET');
        setRows((r.data.shares || []).map((s: any) => ({ ...s, CalendarShareID: Number(s.CalendarShareID), SharedWithUserID: Number(s.SharedWithUserID) })));
        setLoading(false);
    }, []);

    useEffect(() => {
        if (open) load();
    }, [open, load]);

    const save = async (userId: number, level: Level) => {
        setBusy(true);
        const r = await call('/calendar/shares', 'POST', { SharedWithUserID: userId, AccessLevel: level });
        setBusy(false);
        if (!r.ok) {
            msgApi.error(r.data.message || 'ذخیرهٔ اشتراک ناموفق بود.');
            return false;
        }
        msgApi.success(r.data.message || 'ذخیره شد.');
        await load();
        return true;
    };

    const remove = async (row: ShareRow) => {
        setBusy(true);
        const r = await call(`/calendar/shares/${row.CalendarShareID}`, 'DELETE');
        setBusy(false);
        if (!r.ok) {
            msgApi.error(r.data.message || 'لغوِ اشتراک ناموفق بود.');
            return;
        }
        msgApi.success(r.data.message || 'اشتراک لغو شد.');
        await load();
    };

    const available = useMemo(
        () => users.filter((u) => Number(u.UserID) !== currentUserId && !rows.some((r) => r.SharedWithUserID === Number(u.UserID))),
        [users, rows, currentUserId],
    );

    return (
        <Modal open={open} onCancel={onClose} footer={null} title={<Space><ShareAltOutlined /> اشتراک‌گذاریِ تقویمِ من</Space>} width={560} destroyOnHidden>
            {msgHolder}
            <Alert
                type="info"
                showIcon
                style={{ marginBottom: 12 }}
                message="«فقط آزاد/مشغول»: فقط بازه‌هایِ زمانیِ مشغول دیده می‌شود؛ عنوان، توضیح و افراد نمایش داده نمی‌شوند. «دیدنِ جزئیات»: فقط‌خواندنی؛ امکانِ ویرایش ندارد."
            />
            <Space.Compact style={{ width: '100%', marginBottom: 14 }}>
                <Select
                    showSearch
                    optionFilterProp="label"
                    placeholder="انتخابِ کاربر"
                    value={newUser ?? undefined}
                    onChange={(v) => setNewUser(v)}
                    options={available.map((u) => ({ value: Number(u.UserID), label: u.FullName }))}
                    style={{ flex: 1, minWidth: 0 }}
                />
                <Select value={newLevel} onChange={(v) => setNewLevel(v)} options={LEVEL_OPTIONS} style={{ width: 190 }} />
                <Button
                    type="primary"
                    icon={<UserAddOutlined />}
                    disabled={!newUser}
                    loading={busy}
                    onClick={async () => {
                        if (newUser && (await save(newUser, newLevel))) setNewUser(null);
                    }}
                >
                    افزودن
                </Button>
            </Space.Compact>

            <Spin spinning={loading}>
                {rows.length === 0 && !loading ? (
                    <Empty description="تقویمِ شما با کسی به اشتراک گذاشته نشده است." />
                ) : (
                    <List
                        dataSource={rows}
                        renderItem={(row) => (
                            <List.Item
                                actions={[
                                    <Select
                                        key="level"
                                        size="small"
                                        value={row.AccessLevel}
                                        options={LEVEL_OPTIONS}
                                        style={{ width: 180 }}
                                        disabled={busy}
                                        onChange={(v) => save(row.SharedWithUserID, v)}
                                    />,
                                    <Popconfirm key="del" title="اشتراک لغو شود؟" okText="بله" cancelText="خیر" onConfirm={() => remove(row)}>
                                        <Button type="text" danger size="small" icon={<DeleteOutlined />} disabled={busy} aria-label="لغوِ اشتراک" />
                                    </Popconfirm>,
                                ]}
                            >
                                <Text>{row.FullName}</Text>
                            </List.Item>
                        )}
                    />
                )}
            </Spin>
        </Modal>
    );
}
