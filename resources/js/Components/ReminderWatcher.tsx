import { useCallback, useEffect, useRef, useState } from 'react';
import { Button, notification as antdNotification } from 'antd';
import { MailOutlined } from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import ReminderNotificationModal, { DueReminder } from './ReminderNotificationModal';
import { gregorianToJalaliDateTimeDisplay } from '../Utils/jalali';
import { NOTIFY_DURATION_MS, NOTIFY_DURATION_SECONDS, NOTIFY_HOOK_CONFIG } from '../Utils/notify';

const POLL_MS = 20000;
const SOUND_URL = '/sounds/notification.wav';

/** دعوت به جلسه: هر نسخهٔ دعوت (CalendarEventAttendeeID:InvitedAt) فقط یک‌بار در هر مرورگر اعلان می‌شود */
const INVITE_POLL_MS = 60000;
const INVITES_SEEN_KEY = 'raga360.invites.notified';

interface PendingInviteLite {
    CalendarEventAttendeeID: number;
    InvitedAt: string;
    Title: string;
    StartDateTime: string;
    OrganizerName: string | null;
    /** دعوتِ دوباره به‌خاطرِ تغییرِ زمان/تکرارِ جلسه (پچِ ۱۱۲) — همان کلیدِ نسخه، فقط متنِ متفاوت */
    InviteIsUpdate?: boolean | number;
}

const inviteKey = (i: PendingInviteLite) => `${i.CalendarEventAttendeeID}:${i.InvitedAt}`;

/**
 * پنجرهٔ یادآوری پس از ۶ ثانیه خودکار بسته می‌شود — بدونِ ثبتِ «دیدم» در سرور (IsSent و جلو بردنِ تکرار دست نمی‌خورد).
 * همان نسخهٔ یادآوری (ReminderID:RemindAt) در این مرورگر دیگر نمایش داده نمی‌شود؛ وقوعِ بعدیِ تکرارشونده یا تغییرِ زمان
 * RemindAtِ تازه = کلیدِ تازه دارد و دوباره به‌موقع نمایش داده می‌شود.
 */
const REMINDERS_DISMISSED_KEY = 'raga360.reminders.dismissed';
const reminderKey = (r: DueReminder) => `${r.ReminderID}:${r.RemindAt}`;

/** لغو/حذفِ جلسه (پچِ ۱۱۳): هر اطلاع (CalendarEventAttendeeID:CancelNoticeAt) فقط یک‌بار در هر مرورگر */
const CANCELS_SEEN_KEY = 'raga360.cancels.notified';

interface CalendarNoticeLite {
    CalendarEventAttendeeID: number;
    CancelNoticeAt: string;
    Title: string;
    StartDateTime: string;
    IsDeleted: boolean | number;
}

const noticeKey = (n: CalendarNoticeLite) => `${n.CalendarEventAttendeeID}:${n.CancelNoticeAt}`;

function loadSeenInvites(storageKey: string = INVITES_SEEN_KEY): Set<string> {
    try {
        return new Set(JSON.parse(localStorage.getItem(storageKey) || '[]'));
    } catch {
        return new Set();
    }
}

function saveSeenInvites(keys: string[], storageKey: string = INVITES_SEEN_KEY): void {
    try {
        localStorage.setItem(storageKey, JSON.stringify(keys.slice(-300)));
    } catch {
        /* بی‌اهمیت */
    }
}

function getXsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/**
 * ناظرِ سراسریِ یادآوری‌ها.
 * در MainLayout سوار می‌شود، پس روی همه‌ی صفحاتِ احراز هویت‌شده فعال است.
 * هر ۲۰ ثانیه یادآوری‌های سررسیدشده را می‌گیرد و به‌صورت Modalِ وسطِ صفحه + صدا نمایش می‌دهد.
 */
export default function ReminderWatcher() {
    const { auth } = usePage().props as any;
    const isAuthed = !!auth?.user;

    const [notifApi, contextHolder] = antdNotification.useNotification(NOTIFY_HOOK_CONFIG);

    const [queue, setQueue] = useState<DueReminder[]>([]);
    const [acknowledging, setAcknowledging] = useState(false);
    const [audioBlocked, setAudioBlocked] = useState(false);

    const knownIds = useRef<Set<string>>(new Set()); // کلیدِ نسخه (ReminderID:RemindAt)
    const audioRef = useRef<HTMLAudioElement | null>(null);
    const audioUnlocked = useRef(false);

    // آماده‌سازیِ صدا + بازکردنِ قفلِ Autoplay در نخستین تعاملِ کاربر
    useEffect(() => {
        const audio = new Audio(SOUND_URL);
        audio.preload = 'auto';
        audio.volume = 0.55;
        audioRef.current = audio;

        const unlock = () => {
            if (audioUnlocked.current || !audioRef.current) return;
            audioRef.current
                .play()
                .then(() => {
                    audioRef.current!.pause();
                    audioRef.current!.currentTime = 0;
                    audioUnlocked.current = true;
                    setAudioBlocked(false);
                })
                .catch(() => {
                    /* هنوز اجازه ندارد؛ در تعاملِ بعدی دوباره تلاش می‌شود */
                });
        };

        window.addEventListener('pointerdown', unlock);
        window.addEventListener('keydown', unlock);
        return () => {
            window.removeEventListener('pointerdown', unlock);
            window.removeEventListener('keydown', unlock);
        };
    }, []);

    const playSound = useCallback(() => {
        const audio = audioRef.current;
        if (!audio) return;
        audio.currentTime = 0;
        audio.play().catch(() => {
            // مرورگر اجازه‌ی پخشِ خودکار نداد → Modal بدون صدا نمایش داده می‌شود
            if (!audioUnlocked.current) setAudioBlocked(true);
        });
    }, []);

    const stopped = useRef(false);

    const poll = useCallback(async () => {
        if (!isAuthed || stopped.current) return;
        try {
            const res = await fetch('/calendar/reminders/due', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403 || res.status === 419) {
                // نشستِ کاربر منقضی/نامعتبر شده → از Poll دست بکش (بدونِ اسپمِ درخواست)
                stopped.current = true;
                return;
            }
            if (!res.ok) return;
            const data = await res.json();
            const due: DueReminder[] = data.reminders || [];

            const dismissed = loadSeenInvites(REMINDERS_DISMISSED_KEY);
            const fresh = due.filter((r) => !knownIds.current.has(reminderKey(r)) && !dismissed.has(reminderKey(r)));
            if (fresh.length === 0) return;

            fresh.forEach((r) => knownIds.current.add(reminderKey(r)));
            setQueue((q) => {
                const add = fresh.filter((f) => !q.some((x) => x.ReminderID === f.ReminderID));
                return add.length ? [...q, ...add] : q;
            });
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, playSound]);

    // دعوت‌هایِ تازه به جلسه — اعلانِ داخلِ برنامه (مستقل از UserNotifications)
    const invitesStopped = useRef(false);
    const pollInvites = useCallback(async () => {
        if (!isAuthed || invitesStopped.current) return;
        try {
            const res = await fetch('/calendar/invitations', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403 || res.status === 419) {
                invitesStopped.current = true; // بدونِ مجوزِ تقویم یا نشستِ منقضی → دیگر درخواست نده
                return;
            }
            if (!res.ok) return;
            const list: PendingInviteLite[] = (await res.json()).invitations || [];
            const seen = loadSeenInvites();
            const fresh = list.filter((i) => !seen.has(inviteKey(i)));
            if (!fresh.length) return;
            // کلیدهایِ قبلی حفظ می‌شوند (حداکثر ۳۰۰): اگر پاسخ دوباره «در انتظار» شود، همان نسخه دوباره اعلان نمی‌شود؛
            // دعوتِ دوباره (حذف و افزودن) InvitedAtِ تازه = کلیدِ تازه دارد
            saveSeenInvites([...seen, ...fresh.map(inviteKey)]);

            const open = () => {
                notifApi.destroy();
                router.visit('/calendar?invites=1');
            };
            fresh.slice(0, 3).forEach((inv) =>
                notifApi.info({
                    key: `invite-${inviteKey(inv)}`,
                    icon: <MailOutlined style={{ color: '#667eea' }} />,
                    message: Number(inv.InviteIsUpdate) === 1 ? 'تغییرِ جلسه — لطفاً دوباره پاسخ دهید' : 'دعوت به جلسه',
                    description: `${inv.Title} — ${gregorianToJalaliDateTimeDisplay(inv.StartDateTime)}${inv.OrganizerName ? ` (از: ${inv.OrganizerName})` : ''}`,
                    duration: NOTIFY_DURATION_SECONDS,
                    actions: (
                        <Button type="primary" size="small" onClick={open}>
                            مشاهدهٔ دعوت‌ها
                        </Button>
                    ),
                }),
            );
            if (fresh.length > 3) {
                notifApi.info({ key: 'invite-more', message: `و ${fresh.length - 3} دعوتِ دیگر`, duration: NOTIFY_DURATION_SECONDS });
            }
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, notifApi, playSound]);

    // لغو/حذفِ جلسه‌هایی که کاربر در آن‌ها بوده — اعلانِ داخلِ برنامه (پچِ ۱۱۳)؛ فقط برایِ همان شرکت‌کننده، هر اطلاع یک‌بار
    const noticesStopped = useRef(false);
    const pollNotices = useCallback(async () => {
        if (!isAuthed || noticesStopped.current) return;
        try {
            const res = await fetch('/calendar/notices', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403 || res.status === 419) {
                noticesStopped.current = true;
                return;
            }
            if (!res.ok) return;
            const list: CalendarNoticeLite[] = (await res.json()).notices || [];
            const seen = loadSeenInvites(CANCELS_SEEN_KEY);
            const fresh = list.filter((n) => !seen.has(noticeKey(n)));
            if (!fresh.length) return;
            saveSeenInvites([...seen, ...fresh.map(noticeKey)], CANCELS_SEEN_KEY);
            fresh.slice(0, 3).forEach((n) =>
                notifApi.warning({
                    key: `cancel-${noticeKey(n)}`,
                    message: Number(n.IsDeleted) === 1 ? 'جلسه حذف شد' : 'جلسه لغو شد',
                    description: `${n.Title} — ${gregorianToJalaliDateTimeDisplay(n.StartDateTime)}`,
                    duration: NOTIFY_DURATION_SECONDS,
                }),
            );
            if (fresh.length > 3) {
                notifApi.warning({ key: 'cancel-more', message: `و ${fresh.length - 3} جلسهٔ لغوشدهٔ دیگر`, duration: NOTIFY_DURATION_SECONDS });
            }
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, notifApi, playSound]);

    useEffect(() => {
        if (!isAuthed) return;
        const poll = () => {
            pollInvites();
            pollNotices();
        };
        poll();
        const id = window.setInterval(poll, INVITE_POLL_MS);
        return () => window.clearInterval(id);
    }, [isAuthed, pollInvites, pollNotices]);

    // تأییدِ یک یادآوری در یک زبانه، همان یادآوری را در زبانه‌هایِ بازِ دیگرِ همین مرورگر هم می‌بندد (بدونِ نمایشِ تکراری)
    const channelRef = useRef<BroadcastChannel | null>(null);
    useEffect(() => {
        if (!isAuthed || typeof BroadcastChannel === 'undefined') return;
        const channel = new BroadcastChannel('raga360-reminders');
        channel.onmessage = (e: MessageEvent) => {
            // بسته‌شدنِ خودکار در یک زبانه → همان نسخه در زبانه‌هایِ دیگر هم بسته شود (بدونِ ثبتِ «دیدم»)
            if (typeof e.data?.dismiss === 'string') {
                setQueue((q) => q.filter((r) => reminderKey(r) !== e.data.dismiss));
                return;
            }
            const id = Number(e.data?.ack);
            if (!id) return;
            knownIds.current.forEach((k) => k.startsWith(`${id}:`) && knownIds.current.delete(k));
            setQueue((q) => q.filter((r) => r.ReminderID !== id));
        };
        channelRef.current = channel;
        return () => {
            channel.close();
            channelRef.current = null;
        };
    }, [isAuthed]);

    useEffect(() => {
        if (!isAuthed) return;
        poll();
        const id = window.setInterval(poll, POLL_MS);
        const onFocus = () => poll();
        window.addEventListener('focus', onFocus);
        return () => {
            window.clearInterval(id);
            window.removeEventListener('focus', onFocus);
        };
    }, [isAuthed, poll]);

    const acknowledge = useCallback(async () => {
        const current = queue[0];
        if (!current) return;
        setAcknowledging(true);
        try {
            const res = await fetch(`/calendar/reminders/${current.ReminderID}/seen`, {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            if (data.success) {
                // برای رویدادِ تکرارشونده، یادآوری دوباره برای رخدادِ بعدی فعال می‌شود (RemindAtِ تازه = کلیدِ تازه)
                knownIds.current.delete(reminderKey(current));
                setQueue((q) => q.slice(1));
                channelRef.current?.postMessage({ ack: current.ReminderID });
            } else {
                notifApi.error({ message: 'یادآوری', description: data.message || 'ثبت تأییدِ یادآوری ناموفق بود.' });
                setQueue((q) => q.slice(1)); // کاربر گیر نکند
            }
        } catch {
            notifApi.error({ message: 'یادآوری', description: 'خطا در ارتباط با سرور.' });
            setQueue((q) => q.slice(1));
        } finally {
            setAcknowledging(false);
        }
    }, [queue, notifApi]);

    // بسته‌شدنِ خودکار پس از NOTIFY_DURATION_MS (۶ ثانیه) — فقط پنهان می‌شود؛ «دیدم» ثبت نمی‌شود
    const queueRef = useRef(queue);
    queueRef.current = queue;
    const dismissCurrent = useCallback(() => {
        const current = queueRef.current[0];
        if (!current) return;
        const key = reminderKey(current);
        saveSeenInvites([...loadSeenInvites(REMINDERS_DISMISSED_KEY), key], REMINDERS_DISMISSED_KEY);
        channelRef.current?.postMessage({ dismiss: key });
        setQueue((q) => q.filter((r) => reminderKey(r) !== key));
    }, []);
    const currentKey = queue.length ? reminderKey(queue[0]) : null;
    useEffect(() => {
        // زمان‌سنج فقط با عوض‌شدنِ یادآوریِ جاری از نو شروع می‌شود (نه با اضافه‌شدنِ یادآوریِ دیگر به صف)؛
        // در حینِ ثبتِ «متوجه شدم» بسته نمی‌شود
        if (!currentKey || acknowledging) return;
        const t = window.setTimeout(dismissCurrent, NOTIFY_DURATION_MS);
        return () => window.clearTimeout(t);
    }, [currentKey, acknowledging, dismissCurrent]);

    if (!isAuthed) return null;

    return (
        <>
            {contextHolder}
            {queue.length > 0 && (
                <ReminderNotificationModal
                    key={reminderKey(queue[0])}
                    reminder={queue[0]}
                    queueCount={queue.length - 1}
                    acknowledging={acknowledging}
                    onAcknowledge={acknowledge}
                    autoCloseMs={NOTIFY_DURATION_MS}
                />
            )}
            {audioBlocked && queue.length > 0 && (
                <div
                    style={{
                        position: 'fixed',
                        insetInlineStart: 16,
                        bottom: 16,
                        zIndex: 2000,
                        background: '#fff',
                        border: '1px solid #E5E7EB',
                        borderRadius: 10,
                        padding: '8px 12px',
                        fontSize: 12,
                        color: '#6B7280',
                        boxShadow: '0 4px 14px rgba(0,0,0,.1)',
                    }}
                >
                    🔔 صدای یادآوری غیرفعال است — یک‌بار روی صفحه کلیک کنید تا فعال شود
                </div>
            )}
        </>
    );
}
