import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Alert, Button, Card, Checkbox, Col, Input, Modal, Radio, Row, Select, Space, Table, Tag, Tooltip, Typography } from 'antd';
import { NotificationOutlined, ReloadOutlined, SendOutlined, TeamOutlined } from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import { normalizeForSearch } from '../../Utils/persianSearch';

const { Text, Paragraph } = Typography;

interface AudienceUser {
    UserID: number;
    FullName: string;
    HasSubscription: boolean;
    DeviceCount: number;
}

interface Broadcast {
    PushBroadcastID: number;
    Title: string;
    Body: string;
    TargetMode: 'ALL' | 'USERS';
    SelectedUserCount: number;
    RecipientCount: number;
    CreatedAt: string;
    CreatedByName: string;
    Pending: number;
    Sent: number;
    Failed: number;
    Skipped: number;
    DevicesSent: number;
    DevicesFailed: number;
}

interface Preview {
    title: string;
    body: string;
    targetMode: 'ALL' | 'USERS';
    selectedUsers: number;
    recipients: number;
    devices: number;
    withoutSubscription: number;
    serviceActive: boolean;
}

function newKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    // مرورگرهایِ قدیمی (یا HTTP): UUID v4 با getRandomValues
    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}

function errorText(err: unknown): string {
    if (!axios.isAxiosError(err) || !err.response) return 'ارتباط با سرور برقرار نشد.';
    const d: any = err.response.data || {};
    if (d.errors) return Object.values(d.errors).flat().join(' ');
    if (err.response.status === 403) return 'شما دسترسی لازم برای ارسالِ اعلانِ عمومی را ندارید.';
    if (err.response.status === 429) return 'تعدادِ درخواست‌ها زیاد است؛ کمی بعد دوباره تلاش کنید.';
    return d.message || 'خطا در انجامِ عملیات.';
}

export default function PushBroadcasts() {
    const { serviceActive, limits } = usePage().props as any as { serviceActive: boolean; limits: { title: number; body: number } };

    const [title, setTitle] = useState('');
    const [body, setBody] = useState('');
    const [mode, setMode] = useState<'ALL' | 'USERS'>('ALL');
    const [userIds, setUserIds] = useState<number[]>([]);
    const [users, setUsers] = useState<AudienceUser[]>([]);
    const [history, setHistory] = useState<Broadcast[]>([]);
    const [loadingHistory, setLoadingHistory] = useState(false);
    const [previewing, setPreviewing] = useState(false);
    const [sending, setSending] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [confirmChecked, setConfirmChecked] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'error' | 'warning'; text: string } | null>(null);
    // یک کلید برایِ هر «فرمِ تأیید»: کلیکِ دوباره یا ارسالِ دوبارهٔ همان درخواست، اعلانِ تازه نمی‌سازد
    const keyRef = useRef<string>(newKey());

    const loadUsers = useCallback(() => {
        axios.get('/push/broadcasts/users').then((r) => setUsers(r.data.items || [])).catch(() => setUsers([]));
    }, []);

    const loadHistory = useCallback(() => {
        setLoadingHistory(true);
        axios
            .get('/push/broadcasts/list')
            .then((r) => setHistory(r.data.items || []))
            .catch((e) => setMessage({ type: 'error', text: errorText(e) }))
            .finally(() => setLoadingHistory(false));
    }, []);

    useEffect(() => {
        loadUsers();
        loadHistory();
    }, [loadUsers, loadHistory]);

    // تا وقتی ارسالی در انتظار است، وضعیت هر ۱۵ ثانیه تازه می‌شود
    const hasPending = history.some((h) => h.Pending > 0);
    useEffect(() => {
        if (!hasPending) return;
        const t = window.setInterval(loadHistory, 15000);
        return () => window.clearInterval(t);
    }, [hasPending, loadHistory]);

    const subscribedCount = useMemo(() => users.filter((u) => u.HasSubscription).length, [users]);

    const payload = () => ({ title, body, targetMode: mode, userIds: mode === 'USERS' ? userIds : [] });

    const openPreview = () => {
        setMessage(null);
        setPreviewing(true);
        axios
            .post('/push/broadcasts/preview', payload())
            .then((r) => {
                setConfirmChecked(false);
                setPreview(r.data as Preview);
            })
            .catch((e) => setMessage({ type: 'error', text: errorText(e) }))
            .finally(() => setPreviewing(false));
    };

    const send = () => {
        if (!preview || !confirmChecked || sending) return;
        setSending(true);
        axios
            .post('/push/broadcasts', { ...payload(), idempotencyKey: keyRef.current, confirmed: true })
            .then((r) => {
                setMessage({ type: r.data.duplicate ? 'warning' : 'success', text: r.data.message });
                setPreview(null);
                setTitle('');
                setBody('');
                setUserIds([]);
                keyRef.current = newKey(); // فقط پس از ثبتِ موفق کلیدِ تازه
                loadHistory();
            })
            .catch((e) => {
                setPreview(null);
                setMessage({ type: 'error', text: errorText(e) });
            })
            .finally(() => setSending(false));
    };

    // تغییرِ متن یا گیرندگان = فرمِ تازه
    useEffect(() => {
        keyRef.current = newKey();
    }, [title, body, mode, userIds]);

    const columns: ColumnsType<Broadcast> = [
        { title: 'ردیف', key: 'row', width: 60, align: 'center', render: (_v, _r, i) => i + 1 },
        {
            title: 'اعلان',
            key: 'text',
            render: (_v, r) => (
                <div style={{ maxWidth: 420 }}>
                    <Text strong>{r.Title}</Text>
                    <Paragraph type="secondary" style={{ marginBottom: 0, whiteSpace: 'pre-line' }} ellipsis={{ rows: 2, tooltip: r.Body }}>
                        {r.Body}
                    </Paragraph>
                </div>
            ),
        },
        {
            title: 'گیرندگان',
            key: 'target',
            width: 150,
            render: (_v, r) => (
                <Space direction="vertical" size={0}>
                    <Text>{r.TargetMode === 'ALL' ? 'همهٔ کاربران' : 'کاربرانِ انتخاب‌شده'}</Text>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        {r.RecipientCount} از {r.SelectedUserCount} کاربر (دارایِ اعلانِ فعال)
                    </Text>
                </Space>
            ),
        },
        {
            title: 'وضعیتِ ارسال',
            key: 'status',
            width: 260,
            render: (_v, r) => (
                <Space size={[4, 4]} wrap>
                    <Tooltip title="دستِ‌کم یک دستگاهِ کاربر اعلان را پذیرفت">
                        <Tag color="green">موفق: {r.Sent}</Tag>
                    </Tooltip>
                    <Tag color="red">ناموفق: {r.Failed}</Tag>
                    <Tag color={r.Pending > 0 ? 'gold' : 'default'}>در انتظار: {r.Pending}</Tag>
                    {r.Skipped > 0 && (
                        <Tooltip title="کاربر در این فاصله غیرفعال شد یا دیگر دستگاهِ فعالی نداشت">
                            <Tag>ردشده: {r.Skipped}</Tag>
                        </Tooltip>
                    )}
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        دستگاه‌ها: {r.DevicesSent} موفق{r.DevicesFailed > 0 ? ` / ${r.DevicesFailed} ناموفق` : ''}
                    </Text>
                </Space>
            ),
        },
        {
            title: 'ارسال‌کننده و زمان',
            key: 'by',
            width: 190,
            render: (_v, r) => (
                <Space direction="vertical" size={0}>
                    <Text>{r.CreatedByName || '—'}</Text>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        {gregorianToJalaliDateTimeDisplay(r.CreatedAt)}
                    </Text>
                </Space>
            ),
        },
    ];

    const canPreview = serviceActive && title.trim() !== '' && body.trim() !== '' && (mode === 'ALL' || userIds.length > 0);

    return (
        <MainLayout>
            <PageHeader
                icon={<NotificationOutlined />}
                title="ارسال اعلان عمومی"
                subtitle="اعلانِ گوشی و مرورگر برایِ همهٔ کاربران یا کاربرانِ انتخاب‌شده"
                stats={[
                    { icon: <TeamOutlined />, label: 'کاربرانِ فعال', value: `${users.length} نفر` },
                    { icon: <NotificationOutlined />, label: 'دارایِ اعلانِ فعال', value: `${subscribedCount} نفر` },
                ]}
            />

            {!serviceActive && (
                <Alert
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16 }}
                    message="ارسالِ اعلان رویِ این سرور فعال نیست؛ ابتدا سرویسِ Web Push باید راه‌اندازی و فعال شود."
                />
            )}
            {message && (
                <Alert type={message.type} showIcon closable onClose={() => setMessage(null)} style={{ marginBottom: 16 }} message={message.text} />
            )}

            <Row gutter={[16, 16]}>
                <Col xs={24} lg={10}>
                    <Card title="اعلانِ تازه" styles={{ body: { paddingTop: 12 } }}>
                        <Space direction="vertical" size={12} style={{ width: '100%' }}>
                            <div>
                                <Text strong>عنوان</Text>
                                <Input
                                    value={title}
                                    onChange={(e) => setTitle(e.target.value)}
                                    maxLength={limits.title}
                                    showCount
                                    placeholder="مثلاً: به‌روزرسانیِ سامانه"
                                    style={{ marginTop: 4 }}
                                />
                            </div>
                            <div>
                                <Text strong>متن</Text>
                                <Input.TextArea
                                    value={body}
                                    onChange={(e) => setBody(e.target.value)}
                                    maxLength={limits.body}
                                    showCount
                                    autoSize={{ minRows: 3, maxRows: 6 }}
                                    placeholder="متنِ کوتاهِ اعلان"
                                    style={{ marginTop: 4 }}
                                />
                            </div>
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                این متن رویِ صفحهٔ قفلِ گوشی هم نمایش داده می‌شود؛ اطلاعاتِ محرمانه ننویسید. HTML پذیرفته نمی‌شود.
                            </Text>
                            <div>
                                <Text strong>گیرندگان</Text>
                                <div style={{ marginTop: 4 }}>
                                    <Radio.Group value={mode} onChange={(e) => setMode(e.target.value)}>
                                        <Radio value="ALL">همهٔ کاربرانِ فعال</Radio>
                                        <Radio value="USERS">کاربرانِ انتخاب‌شده</Radio>
                                    </Radio.Group>
                                </div>
                                {mode === 'USERS' && (
                                    <Select
                                        mode="multiple"
                                        allowClear
                                        value={userIds}
                                        onChange={(v) => setUserIds(v)}
                                        placeholder="انتخابِ کاربران"
                                        style={{ width: '100%', marginTop: 8 }}
                                        optionFilterProp="label"
                                        filterOption={(input, option) =>
                                            normalizeForSearch(String(option?.label ?? '')).includes(normalizeForSearch(input))
                                        }
                                        options={users.map((u) => ({
                                            value: u.UserID,
                                            label: u.FullName,
                                            title: u.HasSubscription ? undefined : 'اعلان‌ها را فعال نکرده است',
                                        }))}
                                        optionRender={(o) => {
                                            const u = users.find((x) => x.UserID === o.value);
                                            return (
                                                <Space>
                                                    <span>{o.label}</span>
                                                    {u && !u.HasSubscription && <Tag style={{ marginInlineStart: 4 }}>اعلانِ غیرفعال</Tag>}
                                                </Space>
                                            );
                                        }}
                                    />
                                )}
                                <Text type="secondary" style={{ fontSize: 12, display: 'block', marginTop: 6 }}>
                                    فقط کاربرانی که «اعلان‌هایِ گوشی و مرورگر» را فعال کرده‌اند اعلان را دریافت می‌کنند.
                                </Text>
                            </div>
                            <Button type="primary" icon={<SendOutlined />} disabled={!canPreview} loading={previewing} onClick={openPreview} block>
                                بررسی و ارسال…
                            </Button>
                        </Space>
                    </Card>
                </Col>
                <Col xs={24} lg={14}>
                    <Card
                        title="سوابقِ ارسال"
                        extra={
                            <Button icon={<ReloadOutlined />} onClick={loadHistory} loading={loadingHistory}>
                                بازخوانی
                            </Button>
                        }
                    >
                        <Table<Broadcast>
                            rowKey="PushBroadcastID"
                            size="small"
                            columns={columns}
                            dataSource={history}
                            loading={loadingHistory}
                            pagination={{ pageSize: 10, hideOnSinglePage: true }}
                            scroll={{ x: 820 }}
                            locale={{ emptyText: 'هنوز اعلانِ عمومی ارسال نشده است.' }}
                        />
                    </Card>
                </Col>
            </Row>

            <Modal
                open={!!preview}
                title="تأییدِ ارسالِ اعلانِ عمومی"
                onCancel={() => !sending && setPreview(null)}
                okText="ارسال"
                cancelText="انصراف"
                okButtonProps={{ disabled: !confirmChecked || !preview || preview.recipients === 0 || !preview.serviceActive, loading: sending, danger: true }}
                onOk={send}
                maskClosable={!sending}
                destroyOnHidden
            >
                {preview && (
                    <Space direction="vertical" size={12} style={{ width: '100%' }}>
                        <Card size="small" style={{ background: 'rgba(0,0,0,0.02)' }}>
                            <Text strong>{preview.title}</Text>
                            <Paragraph style={{ marginBottom: 0, marginTop: 4, whiteSpace: 'pre-line' }}>{preview.body}</Paragraph>
                        </Card>
                        <div>
                            <div>
                                گیرندگان: <Text strong>{preview.targetMode === 'ALL' ? 'همهٔ کاربرانِ فعال' : 'کاربرانِ انتخاب‌شده'}</Text>{' '}
                                ({preview.selectedUsers} نفر)
                            </div>
                            <div>
                                دریافت‌کنندگان (دارایِ اعلانِ فعال): <Text strong>{preview.recipients} نفر</Text> — {preview.devices} دستگاه
                            </div>
                            {preview.withoutSubscription > 0 && (
                                <Text type="secondary">{preview.withoutSubscription} نفر اعلان‌ها را فعال نکرده‌اند و این اعلان را دریافت نمی‌کنند.</Text>
                            )}
                        </div>
                        {preview.recipients === 0 && <Alert type="warning" showIcon message="هیچ‌یک از کاربرانِ انتخاب‌شده اعلان‌ها را فعال نکرده‌اند." />}
                        {!preview.serviceActive && <Alert type="warning" showIcon message="ارسالِ اعلان رویِ سرور فعال نیست." />}
                        <Checkbox checked={confirmChecked} onChange={(e) => setConfirmChecked(e.target.checked)} disabled={preview.recipients === 0}>
                            متن و گیرندگان را بررسی کردم؛ ارسال شود ({preview.recipients} نفر).
                        </Checkbox>
                    </Space>
                )}
            </Modal>
        </MainLayout>
    );
}
