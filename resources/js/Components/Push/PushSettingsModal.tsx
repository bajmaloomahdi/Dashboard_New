import { useCallback, useEffect, useState } from 'react';
import { Modal, Button, Alert, Space, Typography, List, Tag, Popconfirm, Spin, Descriptions } from 'antd';
import { BellOutlined, MobileOutlined, DesktopOutlined, DeleteOutlined, SendOutlined, StopOutlined } from '@ant-design/icons';
import {
    pushSupport, permission, getConfig, PushFailure, enablePush, disablePush, listDevices, removeDevice, sendTest,
    currentSubscription, serviceWorkerRegistered, lastError, isOptedOut, PushDevice, ServerDeviceStatus,
} from './pushClient';
import { failureHelp, FailureHelpView } from './pushGuide';

const { Text, Paragraph } = Typography;

const truthy = (v: unknown) => v === true || v === 1 || v === '1';

const deviceLabel = (ua: string | null): { label: string; mobile: boolean } => {
    const s = ua || '';
    const os = /iPhone|iPad/.test(s) ? 'iPhone/iPad' : /Android/.test(s) ? 'Android' : /Windows/.test(s) ? 'Windows' : /Mac OS/.test(s) ? 'macOS' : /Linux/.test(s) ? 'Linux' : 'دستگاهِ نامشخص';
    const browser = /Edg\//.test(s) ? 'Edge' : /Firefox\//.test(s) ? 'Firefox' : /Chrome\//.test(s) ? 'Chrome' : /Safari\//.test(s) ? 'Safari' : 'مرورگر';
    return { label: `${browser} — ${os}`, mobile: /iPhone|iPad|Android/.test(s) };
};

const formatDate = (v: string | null) => {
    if (!v) return '—';
    const d = new Date(String(v).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? '—' : `${d.toLocaleDateString('fa-IR')} ${d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' })}`;
};

/**
 * تنظیمِ اعلانِ گوشی/مرورگر برایِ همین کاربر: فعال/غیرفعال‌سازی رویِ همین دستگاه (مجوز فقط با کلیکِ کاربر)،
 * فهرست و لغوِ دستگاه‌هایِ خودِ کاربر، و اعلانِ آزمایشی.
 */
export default function PushSettingsModal({ open, onClose }: { open: boolean; onClose: () => void }) {
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [devices, setDevices] = useState<PushDevice[]>([]);
    const [subscribedHere, setSubscribedHere] = useState(false);
    const [serviceActive, setServiceActive] = useState(true);
    const [configured, setConfigured] = useState(true);
    const [feedback, setFeedback] = useState<{ type: 'success' | 'error' | 'info'; text: string; code?: PushFailure } | null>(null);
    // وضعیتِ همین دستگاه: مجوزِ مرورگر ≠ اشتراکِ Push ≠ ثبت رویِ سرور
    const [diag, setDiag] = useState<{ sw: boolean; browserSub: boolean; server: ServerDeviceStatus | null; error: { at: string; message: string } | null; optedOut: boolean }>({
        sw: false, browserSub: false, server: null, error: null, optedOut: false,
    });

    const support = pushSupport();
    const perm = permission();

    const refresh = useCallback(async () => {
        setLoading(true);
        try {
            const [cfg, list, sub, sw] = await Promise.all([getConfig(), listDevices(), currentSubscription().catch(() => null), serviceWorkerRegistered()]);
            setConfigured(cfg.configured);
            setServiceActive(cfg.serviceActive);
            setDevices(list.items);
            setSubscribedHere(!!sub && !!list.current?.active);
            setDiag({ sw, browserSub: !!sub, server: sub ? list.current : null, error: lastError(), optedOut: isOptedOut() });
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        if (open) {
            setFeedback(null);
            refresh();
        }
    }, [open, refresh]);

    // فراخوانیِ مستقیم از کلیک (درخواستِ مجوز باید در پاسخ به اقدامِ کاربر باشد)
    const onEnable = () => {
        setBusy(true);
        enablePush()
            .then((r) => setFeedback(r.ok ? { type: 'success', text: 'اعلان‌ها با موفقیت فعال شدند.' } : { type: 'error', text: r.message, code: r.code ?? 'unknown' }))
            .catch(() => setFeedback({ type: 'error', text: 'فعال‌سازی ناموفق بود.' }))
            .finally(() => {
                setBusy(false);
                refresh();
            });
    };

    const run = async (fn: () => Promise<{ ok: boolean; message: string }>) => {
        setBusy(true);
        try {
            const r = await fn();
            setFeedback({ type: r.ok ? 'success' : 'error', text: r.message });
        } catch {
            setFeedback({ type: 'error', text: 'عملیات ناموفق بود.' });
        } finally {
            setBusy(false);
            refresh();
        }
    };

    const activeDevices = devices.filter((d) => truthy(d.IsActive));

    return (
        <Modal open={open} onCancel={onClose} footer={null} title={<Space><BellOutlined /> اعلان‌هایِ گوشی و مرورگر</Space>} width={560} destroyOnClose>
            <Space direction="vertical" size={12} style={{ width: '100%' }}>
                <Paragraph type="secondary" style={{ marginBottom: 0, fontSize: 13 }}>
                    با فعال‌سازی، پیام‌ها، ارجاع‌ها و یادآوری‌هایِ تازه حتی وقتی برنامه بسته است به‌صورتِ اعلان رویِ همین دستگاه می‌آید.
                    متنِ اعلان عمومی است و جزئیات فقط پس از ورود نمایش داده می‌شود. تحویل به مرورگر، سیستم‌عامل، اینترنت و تنظیماتِ دستگاه بستگی دارد و تضمینی نیست.
                </Paragraph>

                {support !== 'supported' ? (
                    <FailureHelpView help={failureHelp(support)} />
                ) : perm === 'denied' && !feedback?.code ? (
                    <FailureHelpView help={failureHelp('denied')} />
                ) : null}
                {!configured ? <Alert type="error" showIcon message="سرویسِ اعلان رویِ سرور پیکربندی نشده است (کلیدهایِ VAPID)." /> : null}
                {configured && !serviceActive ? <Alert type="info" showIcon message="ارسالِ اعلان از سمتِ سرور هنوز روشن نشده است؛ اشتراک ذخیره می‌شود و پس از روشن‌شدن اعلان‌ها می‌رسند." /> : null}
                {feedback?.code ? (
                    <FailureHelpView help={failureHelp(feedback.code, feedback.text)} detail={feedback.code === 'denied' ? undefined : feedback.text} />
                ) : feedback ? (
                    <Alert type={feedback.type} showIcon message={feedback.text} closable onClose={() => setFeedback(null)} />
                ) : null}

                {support === 'supported' && !loading ? (
                    <Descriptions size="small" bordered column={1} title={<Text style={{ fontSize: 13 }}>وضعیتِ همین دستگاه</Text>}>
                        <Descriptions.Item label="مجوزِ مرورگر">
                            {perm === 'granted' ? <Tag color="green">داده شده</Tag> : perm === 'denied' ? <Tag color="red">مسدود</Tag> : <Tag>هنوز پرسیده نشده</Tag>}
                        </Descriptions.Item>
                        <Descriptions.Item label="Service Worker">
                            {diag.sw ? <Tag color="green">ثبت شده</Tag> : <Tag>ثبت نشده</Tag>}
                        </Descriptions.Item>
                        <Descriptions.Item label="اشتراکِ Push در مرورگر">
                            {diag.browserSub ? <Tag color="green">ساخته شده</Tag> : <Tag color={perm === 'granted' ? 'orange' : undefined}>ساخته نشده</Tag>}
                        </Descriptions.Item>
                        <Descriptions.Item label="ثبت رویِ سرور">
                            {!diag.browserSub ? (
                                <Tag>—</Tag>
                            ) : diag.server?.active ? (
                                <Tag color="green">فعال</Tag>
                            ) : diag.server?.registered ? (
                                <Tag color="red">غیرفعال{diag.server.reason ? ` (${diag.server.reason})` : ''}</Tag>
                            ) : (
                                <Tag color="red">ثبت نشده</Tag>
                            )}
                        </Descriptions.Item>
                        <Descriptions.Item label="سرویسِ سرور">
                            {!configured ? <Tag color="red">پیکربندی نشده</Tag> : serviceActive ? <Tag color="green">فعال</Tag> : <Tag color="orange">خاموش</Tag>}
                        </Descriptions.Item>
                        {diag.optedOut && !subscribedHere ? (
                            <Descriptions.Item label="انتخابِ شما">
                                <Text type="secondary" style={{ fontSize: 12 }}>رویِ این مرورگر خاموش شده و خودکار روشن نمی‌شود.</Text>
                            </Descriptions.Item>
                        ) : null}
                        {diag.error ? (
                            <Descriptions.Item label="آخرین خطا">
                                <Text type="danger" style={{ fontSize: 12 }}>{diag.error.message}</Text>
                                <Text type="secondary" style={{ fontSize: 11, display: 'block' }}>{formatDate(diag.error.at)}</Text>
                            </Descriptions.Item>
                        ) : null}
                    </Descriptions>
                ) : null}
                {perm === 'granted' && !subscribedHere && support === 'supported' && !loading ? (
                    <Alert
                        type="warning"
                        showIcon
                        message="اجازهٔ اعلان داده شده، ولی این دستگاه هنوز رویِ سرور ثبت نشده است؛ تا «فعال‌سازیِ اعلان‌ها» را نزنید اعلانی دریافت نمی‌کنید."
                    />
                ) : null}

                <Space wrap>
                    {subscribedHere ? (
                        <Button icon={<StopOutlined />} danger loading={busy} onClick={() => run(disablePush)}>غیرفعال‌سازی رویِ این دستگاه</Button>
                    ) : (
                        <Button type="primary" icon={<BellOutlined />} loading={busy} disabled={support !== 'supported' || !configured} onClick={onEnable}>
                            {perm === 'denied' ? 'بررسیِ دوباره و فعال‌سازی' : 'فعال‌سازیِ اعلان‌ها'}
                        </Button>
                    )}
                    <Button icon={<SendOutlined />} disabled={busy || activeDevices.length === 0} onClick={() => run(sendTest)}>ارسالِ اعلانِ آزمایشی</Button>
                </Space>

                <div>
                    <Text strong>دستگاه‌هایِ شما</Text>
                    {loading ? (
                        <div style={{ textAlign: 'center', padding: 16 }}><Spin /></div>
                    ) : (
                        <List
                            size="small"
                            locale={{ emptyText: 'هنوز رویِ هیچ دستگاهی فعال نشده است.' }}
                            dataSource={activeDevices}
                            renderItem={(d) => {
                                const info = deviceLabel(d.UserAgent);
                                return (
                                    <List.Item
                                        actions={[
                                            <Popconfirm key="del" title="اعلان‌هایِ این دستگاه غیرفعال شود؟" okText="بله" cancelText="خیر" onConfirm={() => run(() => removeDevice(Number(d.PushSubscriptionID)))}>
                                                <Button type="text" danger size="small" icon={<DeleteOutlined />} />
                                            </Popconfirm>,
                                        ]}
                                    >
                                        <Space direction="vertical" size={0}>
                                            <Space size={6}>
                                                {info.mobile ? <MobileOutlined /> : <DesktopOutlined />}
                                                <Text>{info.label}</Text>
                                                {truthy(d.IsCurrent) ? <Tag color="blue" style={{ marginInlineEnd: 0 }}>همین دستگاه</Tag> : null}
                                            </Space>
                                            <Text type="secondary" style={{ fontSize: 11 }}>
                                                فعال از {formatDate(d.SubscribedAt)} — آخرین ارسالِ موفق: {formatDate(d.LastSuccessAt)}
                                            </Text>
                                        </Space>
                                    </List.Item>
                                );
                            }}
                        />
                    )}
                </div>
            </Space>
        </Modal>
    );
}
