import { useEffect, useRef, useState } from 'react';
import { Button, Modal, Result, Space, Typography } from 'antd';
import { BellOutlined } from '@ant-design/icons';
import { enablePush, getConfig, permission, pushSupport, PushResultInfo, syncOnLoad } from './pushClient';
import { failureHelp, FailureHelpView } from './pushGuide';

const { Paragraph, Text } = Typography;

/** «فعلاً نه» تا چند روز پنجره را دوباره نشان ندهد (فعال‌سازی از منوی کاربر همیشه ممکن است) */
const SNOOZE_DAYS = 7;
const SESSION_SHOWN = 'raga360.push.prompt.shown';

const snoozeKey = (userId: number | string) => `raga360.push.prompt.snoozeUntil.${userId}`;

function isSnoozed(userId: number | string): boolean {
    try {
        const until = Number(localStorage.getItem(snoozeKey(userId)) || 0);
        return until > Date.now() || sessionStorage.getItem(SESSION_SHOWN) === '1';
    } catch {
        return false;
    }
}

function snooze(userId: number | string, days = SNOOZE_DAYS): void {
    try {
        localStorage.setItem(snoozeKey(userId), String(Date.now() + days * 86400000));
    } catch {
        /* بی‌اهمیت */
    }
}

function clearSnooze(userId: number | string): void {
    try {
        localStorage.removeItem(snoozeKey(userId));
    } catch {
        /* بی‌اهمیت */
    }
}

function markShownThisSession(): void {
    try {
        sessionStorage.setItem(SESSION_SHOWN, '1');
    } catch {
        /* بی‌اهمیت */
    }
}

type Mode = 'hidden' | 'ask' | 'success' | 'failure';

/**
 * پنجرهٔ فعال‌سازیِ اعلان پس از ورود. ترتیبِ بررسی (هر کدام جدا؛ «granted» به‌تنهایی یعنی فعال نیست):
 * پشتیبانیِ مرورگر → مجوز → Service Worker/اشتراکِ محلی → ثبت رویِ سرور.
 * - مجوز از قبل داده شده: اشتراک خودکار ساخته و ثبت می‌شود (بی‌پنجره)، مگر کاربر این دستگاه را خاموش کرده باشد.
 * - هنوز پرسیده نشده: پرسشِ داخلِ سایت؛ «بله» در همان کلیک پنجرهٔ استانداردِ مرورگر را باز می‌کند.
 * - رد شده / مرورگرِ ناسازگار / iPhoneِ نصب‌نشده: راهنمایِ مناسب، بدونِ پنجرهٔ بی‌نتیجه.
 * «فعلاً نه» یا بستن: تا ۷ روز (و در همین نشست) دوباره نشان داده نمی‌شود.
 */
export default function PushPrompt({ userId }: { userId: number | string | undefined }) {
    const [mode, setMode] = useState<Mode>('hidden');
    const [busy, setBusy] = useState(false);
    const [failure, setFailure] = useState<PushResultInfo | null>(null);
    const started = useRef<string | null>(null);

    useEffect(() => {
        if (!userId || started.current === String(userId)) return;
        started.current = String(userId);
        let cancelled = false;

        (async () => {
            const support = pushSupport();
            if (support === 'insecure') return; // نشانیِ IP/HTTP: کاری از دستِ کاربر برنمی‌آید؛ وضعیت در تنظیماتِ اعلان دیده می‌شود

            const sync = await syncOnLoad();
            if (cancelled) return;
            if (['registered', 'already-synced', 'opted-out', 'not-configured'].includes(sync.outcome)) return;
            if (isSnoozed(userId)) return;

            if (sync.outcome === 'failed') {
                setFailure(sync.result ?? null);
                setMode('failure');
            } else if (support === 'ios-not-installed' || support === 'unsupported') {
                setFailure({ ok: false, code: support, message: '' });
                setMode('failure');
            } else {
                const cfg = await getConfig().catch(() => null);
                if (cancelled || !cfg?.configured) return;
                if (permission() === 'denied') {
                    setFailure({ ok: false, code: 'denied', message: '' });
                    setMode('failure');
                } else {
                    setMode('ask');
                }
            }
            markShownThisSession();
        })();

        return () => {
            cancelled = true;
        };
    }, [userId]);

    // وقتی کاربر از تنظیماتِ مرورگر اجازه را آزاد کرد، بدونِ تازه‌سازی ادامه بده (مجوز داده شده → پنجرهٔ مرورگر لازم نیست)
    useEffect(() => {
        if (mode !== 'failure' || failure?.code !== 'denied' || !navigator.permissions?.query) return;
        let status: PermissionStatus | null = null;
        const onChange = () => {
            if (status?.state === 'granted') activate();
        };
        navigator.permissions
            .query({ name: 'notifications' as PermissionName })
            .then((s) => {
                status = s;
                s.addEventListener('change', onChange);
            })
            .catch(() => undefined);
        return () => status?.removeEventListener('change', onChange);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [mode, failure?.code]);

    // مستقیم از رویدادِ کلیک: درخواستِ مجوزِ مرورگر اولین کارِ enablePush است
    const activate = () => {
        setBusy(true);
        enablePush()
            .then((r) => {
                if (r.ok) {
                    if (userId) clearSnooze(userId);
                    setFailure(null);
                    setMode('success');
                } else {
                    setFailure(r);
                    setMode('failure');
                }
            })
            .finally(() => setBusy(false));
    };

    const later = () => {
        if (userId) snooze(userId);
        setMode('hidden');
    };

    const close = () => setMode('hidden');

    const help = failure ? failureHelp(failure.code, failure.message) : null;

    return (
        <Modal
            open={mode !== 'hidden'}
            onCancel={mode === 'success' ? close : later}
            maskClosable={false}
            width={460}
            centered
            footer={null}
            title={
                <Space>
                    <BellOutlined style={{ color: '#667eea' }} />
                    اعلان‌هایِ راگا ۳۶۰
                </Space>
            }
        >
            {mode === 'ask' ? (
                <Space direction="vertical" size={16} style={{ width: '100%' }}>
                    <div>
                        <Paragraph strong style={{ fontSize: 15, marginBottom: 6 }}>
                            آیا می‌خواهید اعلان‌های راگا ۳۶۰ را فعال کنید؟
                        </Paragraph>
                        <Paragraph style={{ marginBottom: 0 }}>با فعال‌سازی، پیام‌های جدید، ارجاع وظایف و یادآوری‌های مهم را دریافت می‌کنید.</Paragraph>
                    </div>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        پس از «بله»، مرورگر اجازهٔ نمایشِ اعلان را می‌پرسد؛ «Allow / اجازه دادن» را انتخاب کنید.
                    </Text>
                    <Space style={{ width: '100%', justifyContent: 'flex-end' }} wrap>
                        <Button onClick={later} disabled={busy}>
                            فعلاً نه
                        </Button>
                        <Button type="primary" icon={<BellOutlined />} loading={busy} onClick={activate}>
                            بله، فعال کن
                        </Button>
                    </Space>
                </Space>
            ) : null}

            {mode === 'success' ? (
                <Result
                    status="success"
                    title="اعلان‌ها با موفقیت فعال شدند"
                    subTitle="از این پس پیام‌ها، ارجاع‌ها و یادآوری‌هایِ تازه رویِ همین دستگاه اعلان می‌شوند."
                    style={{ padding: '8px 0' }}
                    extra={
                        <Button type="primary" onClick={close}>
                            بستن
                        </Button>
                    }
                />
            ) : null}

            {mode === 'failure' && help ? (
                <Space direction="vertical" size={14} style={{ width: '100%' }}>
                    <FailureHelpView help={help} detail={failure?.code === 'denied' ? undefined : failure?.message} />
                    {failure?.code === 'denied' ? (
                        <Text type="secondary" style={{ fontSize: 12 }}>
                            پس از آزادکردنِ اجازه، «بررسیِ دوباره» را بزنید (یا صفحه را تازه کنید).
                        </Text>
                    ) : null}
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        بعداً هم از منوی کاربر ← «اعلان‌هایِ گوشی و مرورگر» می‌توانید فعال کنید.
                    </Text>
                    <Space style={{ width: '100%', justifyContent: 'flex-end' }} wrap>
                        <Button onClick={later} disabled={busy}>
                            فعلاً نه
                        </Button>
                        {help.action === 'retry' ? (
                            <Button type="primary" loading={busy} onClick={activate}>
                                {failure?.code === 'denied' ? 'بررسیِ دوباره' : 'تلاشِ دوباره'}
                            </Button>
                        ) : null}
                        {help.action === 'reload' ? (
                            <Button type="primary" onClick={() => window.location.reload()}>
                                تازه‌سازیِ صفحه
                            </Button>
                        ) : null}
                    </Space>
                </Space>
            ) : null}
        </Modal>
    );
}
