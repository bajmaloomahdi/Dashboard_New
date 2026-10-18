/**
 * Web Push در مرورگر: ثبتِ Service Workerِ اختصاصی (/push-sw.js)، درخواستِ مجوز (فقط در پاسخ به کلیکِ کاربر)،
 * اشتراک/لغو و همگام‌سازی با سرور. Service Worker هیچ fetch/cache ای ندارد و با Vite/Inertia تداخل نمی‌کند.
 *
 * «مجوزِ اعلان» (Notification.permission = granted) با «اشتراکِ Push» یکی نیست: مجوز فقط اجازهٔ نمایش است؛
 * تا وقتی PushManager.subscribe اشتراک نسازد و سرور آن را در PushSubscriptions ذخیره نکند، اعلانی ارسال نمی‌شود.
 */

const SW_URL = '/push-sw.js';
const SYNC_FLAG = 'raga360.push.synced';
/** کاربر رویِ همین مرورگر اعلان را خاموش کرده → همگام‌سازیِ خودکار دوباره روشنش نکند */
const OPT_OUT = 'raga360.push.optout';
/** آخرین خطایِ فعال‌سازی/همگام‌سازی در همین مرورگر (برایِ نمایش در تنظیمات؛ بدونِ اطلاعاتِ محرمانه) */
const LAST_ERROR = 'raga360.push.lastError';

export type PushSupport =
    | 'supported'
    | 'insecure'          // HTTPS نیست (روی IP و HTTP اعلان ممکن نیست)
    | 'unsupported'       // مرورگر Web Push ندارد
    | 'ios-not-installed'; // iPhone/iPad: فقط وب‌اپِ اضافه‌شده به Home Screen (iOS 16.4+)

export interface PushDevice {
    PushSubscriptionID: number | string;
    UserAgent: string | null;
    IsActive: boolean | number | string;
    SubscribedAt: string;
    LastSuccessAt: string | null;
    LastFailureAt: string | null;
    ServiceHost: string;
    IsCurrent: boolean | number | string;
}

/** علتِ شکست — برایِ نمایشِ توضیح و راه‌حلِ مناسب به کاربر */
export type PushFailure =
    | 'insecure'
    | 'unsupported'
    | 'ios-not-installed'
    | 'denied'            // اجازه رد شده (پنجرهٔ مرورگر دیگر باز نمی‌شود)
    | 'dismissed'         // پنجرهٔ مرورگر بدونِ انتخاب بسته شد
    | 'not-configured'    // کلیدهایِ VAPID رویِ سرور نیست
    | 'sw-failed'         // Service Worker ثبت نشد
    | 'subscribe-failed'  // سرویسِ Push مرورگر اشتراک نساخت
    | 'session'           // CSRF/نشستِ منقضی
    | 'auth'              // ورود منقضی
    | 'server-rejected'   // سرور اشتراک را نپذیرفت
    | 'network'
    | 'unknown';

export interface PushResultInfo {
    ok: boolean;
    message: string;
    code?: PushFailure;
}

/** وضعیتِ ثبتِ همین مرورگر رویِ سرور (فقط اشتراکِ خودِ کاربر) */
export interface ServerDeviceStatus {
    registered: boolean;
    active: boolean;
    reason: string | null;
    lastSuccessAt: string | null;
    lastFailureAt: string | null;
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function api(url: string, method: 'GET' | 'POST' = 'GET', body?: unknown): Promise<{ ok: boolean; status: number; data: any }> {
    const res = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
            ...(body ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
}

function storageGet(key: string): string | null {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
}

function storageSet(key: string, value: string | null): void {
    try {
        if (value === null) localStorage.removeItem(key);
        else localStorage.setItem(key, value);
    } catch {
        /* ذخیره‌نشدن مشکلی ندارد */
    }
}

function recordError(message: string | null): void {
    storageSet(LAST_ERROR, message ? JSON.stringify({ at: new Date().toISOString(), message: message.slice(0, 300) }) : null);
}

export function lastError(): { at: string; message: string } | null {
    try {
        const v = storageGet(LAST_ERROR);
        return v ? JSON.parse(v) : null;
    } catch {
        return null;
    }
}

export function isOptedOut(): boolean {
    return storageGet(OPT_OUT) === '1';
}

function describe(e: unknown): string {
    const err = e as { name?: string; message?: string };
    return [err?.name, err?.message].filter(Boolean).join(': ') || String(e);
}

export function isIos(): boolean {
    const ua = navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (ua.includes('Macintosh') && (navigator.maxTouchPoints || 0) > 1);
}

export function isStandalone(): boolean {
    return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as any).standalone === true;
}

export function pushSupport(): PushSupport {
    if (!window.isSecureContext) return 'insecure';
    const hasApis = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    if (isIos() && !isStandalone()) return 'ios-not-installed';
    return hasApis ? 'supported' : 'unsupported';
}

export function permission(): NotificationPermission | 'unsupported' {
    return 'Notification' in window ? Notification.permission : 'unsupported';
}

function base64UrlToUint8Array(base64Url: string): Uint8Array {
    const padding = '='.repeat((4 - (base64Url.length % 4)) % 4);
    const base64 = (base64Url + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
}

async function registration(): Promise<ServiceWorkerRegistration> {
    await navigator.serviceWorker.register(SW_URL, { scope: '/' });
    return navigator.serviceWorker.ready;
}

export async function currentSubscription(): Promise<PushSubscription | null> {
    if (pushSupport() !== 'supported') return null;
    const reg = await navigator.serviceWorker.getRegistration('/');
    return reg ? reg.pushManager.getSubscription() : null;
}

export async function serviceWorkerRegistered(): Promise<boolean> {
    if (!('serviceWorker' in navigator)) return false;
    const reg = await navigator.serviceWorker.getRegistration('/').catch(() => undefined);
    return !!reg;
}

export async function getConfig(): Promise<{ configured: boolean; publicKey: string | null; serviceActive: boolean }> {
    const r = await api('/push/config');
    return { configured: !!r.data.configured, publicKey: r.data.publicKey || null, serviceActive: !!r.data.serviceActive };
}

async function saveOnServer(sub: PushSubscription, source: 'manual' | 'auto'): Promise<PushResultInfo & { optedOut?: boolean }> {
    const json = sub.toJSON();
    let r: { ok: boolean; status: number; data: any };
    try {
        r = await api('/push/subscriptions', 'POST', {
            endpoint: json.endpoint,
            keys: { p256dh: json.keys?.p256dh, auth: json.keys?.auth },
            contentEncoding: (PushManager as any).supportedContentEncodings?.includes('aes128gcm') === false ? 'aesgcm' : 'aes128gcm',
            source,
        });
    } catch (e) {
        return { ok: false, code: 'network', message: `ارتباط با سرور برقرار نشد: ${describe(e)}` };
    }
    if (r.ok && r.data.success) return { ok: true, message: r.data.message || '' };
    if (r.status === 419) return { ok: false, code: 'session', message: 'نشستِ شما منقضی شده (CSRF)؛ صفحه را تازه کنید و دوباره تلاش کنید.' };
    if (r.status === 401) return { ok: false, code: 'auth', message: 'ورودِ شما منقضی شده است؛ دوباره وارد شوید.' };
    const reason = r.data.message || (r.data.errors ? Object.values(r.data.errors).flat().join(' ') : '') || `ثبتِ اشتراک رویِ سرور ناموفق بود (HTTP ${r.status}).`;
    return { ok: false, code: 'server-rejected', message: reason, optedOut: !!r.data.optedOut };
}

/** اشتراکِ موجود یا تازه؛ اشتراکِ قدیمی با کلیدِ دیگر (تعویضِ VAPID) لغو و دوباره ساخته می‌شود */
async function subscribeWith(reg: ServiceWorkerRegistration, publicKey: string): Promise<PushSubscription> {
    const key = base64UrlToUint8Array(publicKey);
    const sub = await reg.pushManager.getSubscription();
    try {
        return sub ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key as BufferSource }));
    } catch {
        if (sub) await sub.unsubscribe().catch(() => undefined);
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key as BufferSource });
    }
}

function markSynced(): void {
    try {
        sessionStorage.setItem(SYNC_FLAG, '1');
    } catch {
        /* ذخیره‌نشدنِ پرچم مشکلی ندارد */
    }
}

/**
 * فعال‌سازی برایِ همین دستگاه — باید مستقیماً از رویدادِ کلیک صدا زده شود: درخواستِ مجوز اولین کار است
 * (Safari/iOS پس از await از دست رفتنِ «اقدامِ کاربر» اجازه نمی‌دهد).
 */
export async function enablePush(): Promise<PushResultInfo> {
    const r: PushResultInfo = await enablePushInner().catch((e) => ({ ok: false, code: 'unknown' as PushFailure, message: `فعال‌سازی ناموفق بود: ${describe(e)}` }));
    recordError(r.ok ? null : r.message);
    return r;
}

/** Notification.requestPermission — نسخه‌هایِ قدیمیِ Safari به‌جایِ Promise از Callback استفاده می‌کنند */
function askPermission(): Promise<NotificationPermission> {
    return new Promise((resolve) => {
        const p = Notification.requestPermission((v) => resolve(v));
        if (p && typeof (p as Promise<NotificationPermission>).then === 'function') (p as Promise<NotificationPermission>).then(resolve, () => resolve(Notification.permission));
    });
}

async function enablePushInner(): Promise<PushResultInfo> {
    const support = pushSupport();
    if (support !== 'supported') return { ok: false, code: support, message: supportMessage(support) };

    // اولین کار در همان کلیک (بدونِ هیچ انتظارِ دیگری قبلش) — شرطِ Safari/iOS برایِ نمایشِ پنجرهٔ مجوز
    const result = Notification.permission === 'granted' ? 'granted' : await askPermission();
    if (result === 'denied') {
        return { ok: false, code: 'denied', message: 'اجازهٔ نمایشِ اعلان برایِ این سایت در مرورگر مسدود است.' };
    }
    if (result !== 'granted') {
        return { ok: false, code: 'dismissed', message: 'پنجرهٔ اجازهٔ مرورگر بدونِ انتخابِ «Allow / اجازه دادن» بسته شد.' };
    }

    let cfg: { configured: boolean; publicKey: string | null };
    try {
        cfg = await getConfig();
    } catch (e) {
        return { ok: false, code: 'network', message: `ارتباط با سرور برقرار نشد: ${describe(e)}` };
    }
    if (!cfg.configured || !cfg.publicKey) return { ok: false, code: 'not-configured', message: 'سرویسِ اعلان رویِ سرور پیکربندی نشده است.' };

    let reg: ServiceWorkerRegistration;
    try {
        reg = await registration();
    } catch (e) {
        return { ok: false, code: 'sw-failed', message: `ثبتِ Service Worker ناموفق بود: ${describe(e)}` };
    }
    let sub: PushSubscription;
    try {
        sub = await subscribeWith(reg, cfg.publicKey);
    } catch (e) {
        return { ok: false, code: 'subscribe-failed', message: `مرورگر اشتراکِ اعلان نساخت (سرویسِ Push مرورگر در دسترس نیست یا مسدود است): ${describe(e)}` };
    }

    const saved = await saveOnServer(sub, 'manual');
    if (!saved.ok) {
        await sub.unsubscribe().catch(() => undefined);
        return saved;
    }
    storageSet(OPT_OUT, null);
    markSynced();
    return { ok: true, message: saved.message };
}

/** غیرفعال‌سازی برایِ همین دستگاه (سرور + مرورگر) */
export async function disablePush(): Promise<PushResultInfo> {
    storageSet(OPT_OUT, '1');
    recordError(null);
    const sub = await currentSubscription();
    if (!sub) return { ok: true, message: 'اعلان‌ها رویِ این دستگاه فعال نبود.' };
    const r = await api('/push/subscriptions/unsubscribe', 'POST', { endpoint: sub.endpoint });
    await sub.unsubscribe().catch(() => undefined);
    return { ok: true, message: r.data.message || 'اعلان‌ها برایِ این دستگاه غیرفعال شد.' };
}

export async function listDevices(): Promise<{ items: PushDevice[]; current: ServerDeviceStatus | null }> {
    const sub = await currentSubscription().catch(() => null);
    const r = await api('/push/subscriptions/list', 'POST', { endpoint: sub?.endpoint ?? null });
    return r.ok ? { items: r.data.items || [], current: r.data.current ?? null } : { items: [], current: null };
}

export async function removeDevice(subscriptionId: number): Promise<{ ok: boolean; message: string }> {
    const r = await api('/push/subscriptions/unsubscribe', 'POST', { subscriptionId });
    return { ok: r.ok && !!r.data.success, message: r.data.message || 'خطا در لغوِ اشتراک.' };
}

export async function sendTest(): Promise<{ ok: boolean; message: string }> {
    const r = await api('/push/test', 'POST');
    return { ok: r.ok && !!r.data.success, message: r.data.message || 'خطا در ارسالِ اعلانِ آزمایشی.' };
}

/**
 * پس از ورود (یک‌بار در هر نشست): اگر این مرورگر قبلاً اجازهٔ اعلان داده، اشتراکِ Push را برایِ کاربرِ فعلی
 * ثبت/به‌روز می‌کند — اگر اشتراکی نیست، بدونِ پرسشِ دوباره می‌سازد (مجوز از قبل داده شده). هرگز مجوز درخواست
 * نمی‌کند، و دستگاهی را که کاربر خودش خاموش کرده (همین مرورگر یا از فهرستِ دستگاه‌ها) دوباره روشن نمی‌کند.
 */
export type SyncOutcome = 'not-applicable' | 'already-synced' | 'registered' | 'opted-out' | 'not-configured' | 'failed';

export async function syncOnLoad(): Promise<{ outcome: SyncOutcome; result?: PushResultInfo }> {
    try {
        if (pushSupport() !== 'supported' || Notification.permission !== 'granted') return { outcome: 'not-applicable' };
        if (isOptedOut()) return { outcome: 'opted-out' };
        if (sessionStorage.getItem(SYNC_FLAG) === '1') return { outcome: 'already-synced' };

        let reg = await navigator.serviceWorker.getRegistration('/');
        if (reg) await reg.update().catch(() => undefined);
        let sub = reg ? await reg.pushManager.getSubscription() : null;
        if (!sub) {
            const cfg = await getConfig();
            if (!cfg.configured || !cfg.publicKey) return { outcome: 'not-configured' };
            try {
                reg = await registration();
            } catch (e) {
                const result: PushResultInfo = { ok: false, code: 'sw-failed', message: `ثبتِ Service Worker ناموفق بود: ${describe(e)}` };
                recordError(result.message);
                return { outcome: 'failed', result };
            }
            try {
                sub = await subscribeWith(reg, cfg.publicKey);
            } catch (e) {
                const result: PushResultInfo = { ok: false, code: 'subscribe-failed', message: `مرورگر اشتراکِ اعلان نساخت: ${describe(e)}` };
                recordError(result.message);
                return { outcome: 'failed', result };
            }
        }

        const saved = await saveOnServer(sub, 'auto');
        if (saved.ok) {
            markSynced();
            recordError(null);
            return { outcome: 'registered', result: saved };
        }
        if (saved.optedOut) {
            // کاربر این دستگاه را از فهرستِ دستگاه‌ها حذف کرده بود → اشتراکِ مرورگر هم لغو و دیگر خودکار ثبت نشود
            storageSet(OPT_OUT, '1');
            await sub.unsubscribe().catch(() => undefined);
            markSynced();
            return { outcome: 'opted-out' };
        }
        recordError(saved.message);
        return { outcome: 'failed', result: saved };
    } catch (e) {
        const result: PushResultInfo = { ok: false, code: 'unknown', message: `همگام‌سازیِ خودکار ناموفق بود: ${describe(e)}` };
        recordError(result.message);
        return { outcome: 'failed', result };
    }
}

/** راهنمایِ متناسب با مرورگر/دستگاه برایِ آزادکردنِ اجازهٔ اعلان (وقتی قبلاً «Block» شده) */
export function browserInfo(): { browser: 'edge' | 'chrome' | 'firefox' | 'samsung' | 'opera' | 'brave' | 'safari' | 'other'; os: 'windows' | 'android' | 'ios' | 'mac' | 'linux' | 'other'; standalone: boolean } {
    const ua = navigator.userAgent || '';
    const os = isIos() ? 'ios' : /Android/i.test(ua) ? 'android' : /Windows/i.test(ua) ? 'windows' : /Macintosh|Mac OS/i.test(ua) ? 'mac' : /Linux/i.test(ua) ? 'linux' : 'other';
    const browser = (navigator as any).brave ? 'brave'
        : /Edg(A|iOS)?\//.test(ua) ? 'edge'
        : /SamsungBrowser\//.test(ua) ? 'samsung'
        : /OPR\/|Opera/.test(ua) ? 'opera'
        : /Firefox\/|FxiOS\//.test(ua) ? 'firefox'
        : /Chrome\/|CriOS\//.test(ua) ? 'chrome'
        : /Safari\//.test(ua) ? 'safari'
        : 'other';
    return { browser, os, standalone: isStandalone() };
}

export function supportMessage(support: PushSupport): string {
    switch (support) {
        case 'insecure':
            return 'اعلانِ گوشی فقط رویِ نشانیِ امن (HTTPS) کار می‌کند.';
        case 'ios-not-installed':
            return 'در iPhone/iPad ابتدا با «Share ← Add to Home Screen» برنامه را به صفحهٔ اصلی اضافه و از همان‌جا باز کنید (iOS 16.4 یا بالاتر).';
        case 'unsupported':
            return 'این مرورگر از اعلانِ Web Push پشتیبانی نمی‌کند.';
        default:
            return '';
    }
}
