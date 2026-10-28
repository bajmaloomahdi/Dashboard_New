import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { Button, Dropdown, Space, notification as antdNotification } from 'antd';
import { ClockCircleOutlined, MailOutlined } from '@ant-design/icons';
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
// نسخه = پایانِ تعویق (پچِ ۱۳۱) یا RemindAt — یادآوریِ تعویق‌شده کلیدِ تازه دارد و دوباره نمایش داده می‌شود
const reminderKey = (r: DueReminder) => `${r.ReminderID}:${r.SnoozedUntil || r.RemindAt}`;

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

/** فعالیت رویِ رویداد (پچِ ۱۳۰): توضیح، ضمیمه، وضعیت، پاسخ به دعوت — هر اطلاع (NoticeID) یک‌بار در هر مرورگر */
const ACTIVITY_SEEN_KEY = 'raga360.calendar.activity.seen';

interface EventNoticeLite {
    NoticeID: number | string;
    EventID: number | string;
    Kind: 'COMMENT' | 'ATTACHMENT' | 'STATUS' | 'RESPONSE';
    Detail: string | null;
    Title: string;
    ActorName: string | null;
}

const ACTIVITY_TITLES: Record<EventNoticeLite['Kind'], string> = {
    COMMENT: 'توضیحِ تازه رویِ رویداد',
    ATTACHMENT: 'ضمیمهٔ تازه رویِ رویداد',
    STATUS: 'تغییرِ وضعیتِ رویداد',
    RESPONSE: 'پاسخ به دعوتِ جلسه',
};

/** نامه/وظیفهٔ تازه یا تغییر رویِ آن (UserNotifications) — هر اعلان (UserNotificationID) یک‌بار در هر مرورگر */
const MESSAGE_POLL_MS = 30000;
const MESSAGES_SEEN_KEY = 'raga360.messages.notified';
/** شمارندهٔ زنگولهٔ سربرگ بدونِ رفتن به صفحهٔ دیگر به‌روز می‌شود (Header گوش می‌دهد) */
export const UNREAD_COUNT_EVENT = 'raga360:unread-count';

interface MessageNoticeLite {
    UserNotificationID: number;
    MessageID: number;
    MessageNumber: string | number | null;
    Subject: string | null;
    MessageTypeName: string | null;
}

/** پچِ ۱۳۲: «بعداً یادآوری کن» رویِ هر اعلان (یادآوری جدا: پنجرهٔ خودش) — منبع در سرور بررسی می‌شود */
type SnoozeKind = 'MESSAGE' | 'INVITE' | 'CANCEL' | 'CALEVENT' | 'BROADCAST';
interface SnoozeTarget {
    Kind: SnoozeKind;
    SourceID: number;
}
interface DueSnooze extends SnoozeTarget {
    SnoozeID: number;
    SnoozedUntil: string;
    Title: string | null;
    Subtitle: string | null;
    StartDateTime: string | null;
    TargetUrl: string;
}
const SNOOZE_MINUTES = [5, 10, 15, 20, 30, 45, 60];
const SNOOZE_KIND_LABELS: Record<SnoozeKind, string> = {
    MESSAGE: 'نامه',
    INVITE: 'دعوت به جلسه',
    CANCEL: 'لغوِ جلسه',
    CALEVENT: 'رویدادِ تقویم',
    BROADCAST: 'اعلانِ عمومی',
};

/** دکمهٔ «بعداً» داخلِ اعلان؛ فهرست داخلِ خودِ اعلان باز می‌شود تا با رفتنِ ماوس رویِ آن، اعلان بسته نشود */
function SnoozeLater({ onPick }: { onPick: (minutes: number) => void }) {
    return (
        <Dropdown
            trigger={['click']}
            getPopupContainer={(t) => t.parentElement || document.body}
            menu={{ items: SNOOZE_MINUTES.map((m) => ({ key: String(m), label: `${m} دقیقه بعد` })), onClick: ({ key }) => onPick(Number(key)) }}
        >
            <Button size="small" icon={<ClockCircleOutlined />}>
                بعداً
            </Button>
        </Dropdown>
    );
}

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
    const [snoozing, setSnoozing] = useState(false);
    // کاربر با پنجرهٔ یادآوری کار کرد → بسته‌شدنِ خودکار برایِ همین یادآوری متوقف می‌شود
    const [held, setHeld] = useState(false);
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

    /** پچِ ۱۳۲: تعویقِ یک اعلان (از اعلانِ داخلِ برنامه با Kind+SourceID، یا از دکمهٔ گوشی با PushOutboxID) */
    const postNotifSnooze = useCallback(async (body: Partial<SnoozeTarget> & { PushOutboxID?: number }, minutes: number) => {
        const res = await fetch('/notifications/snooze', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ ...body, Minutes: minutes }),
        });
        return { ok: res.ok, data: await res.json().catch(() => ({})) };
    }, []);
    const snoozeNotice = useCallback(
        async (noticeKey: string, target: SnoozeTarget, minutes: number) => {
            try {
                const { ok, data } = await postNotifSnooze(target, minutes);
                if (ok && data.success) {
                    notifApi.destroy(noticeKey);
                    notifApi.success({ message: 'به تعویق افتاد', description: data.message, duration: NOTIFY_DURATION_SECONDS });
                } else {
                    notifApi.error({ message: 'تعویق', description: data.message || 'تعویقِ اعلان ناموفق بود.' });
                }
            } catch {
                notifApi.error({ message: 'تعویق', description: 'خطا در ارتباط با سرور.' });
            }
        },
        [notifApi, postNotifSnooze],
    );
    /** دکمه‌هایِ پایینِ هر اعلان: «بعداً» + دکمهٔ اصلی */
    const noticeActions = useCallback(
        (noticeKey: string, target: SnoozeTarget, main?: ReactNode) => (
            <Space size={6}>
                <SnoozeLater onPick={(m) => snoozeNotice(noticeKey, target, m)} />
                {main}
            </Space>
        ),
        [snoozeNotice],
    );

    const stopped = useRef(false);
    // یادآوری‌ای که تعویق/تأییدش در جریان است یا همین الان ثبت شد: پاسخِ Pollی که قبل از آن شروع شده کهنه است و نباید
    // دوباره نمایشش دهد (ReminderID → زمانِ ثبت؛ Infinity = در حالِ ثبت)
    const settledAt = useRef<Map<number, number>>(new Map());

    const poll = useCallback(async () => {
        if (!isAuthed || stopped.current) return;
        const startedAt = Date.now();
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
            const fresh = due.filter(
                (r) =>
                    !knownIds.current.has(reminderKey(r)) &&
                    !dismissed.has(reminderKey(r)) &&
                    !((settledAt.current.get(Number(r.ReminderID)) ?? -1) >= startedAt),
            );
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
                    actions: noticeActions(
                        `invite-${inviteKey(inv)}`,
                        { Kind: 'INVITE', SourceID: Number(inv.CalendarEventAttendeeID) },
                        <Button type="primary" size="small" onClick={open}>
                            مشاهدهٔ دعوت‌ها
                        </Button>,
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
    }, [isAuthed, notifApi, playSound, noticeActions]);

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
                    actions: noticeActions(`cancel-${noticeKey(n)}`, { Kind: 'CANCEL', SourceID: Number(n.CalendarEventAttendeeID) }),
                }),
            );
            if (fresh.length > 3) {
                notifApi.warning({ key: 'cancel-more', message: `و ${fresh.length - 3} جلسهٔ لغوشدهٔ دیگر`, duration: NOTIFY_DURATION_SECONDS });
            }
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, notifApi, playSound, noticeActions]);

    // فعالیت رویِ رویدادهایِ کاربر (پچِ ۱۳۰) — اعلانِ داخلِ برنامه؛ نخستین بار در این مرورگر، موارد قبلی بی‌صدا «دیده‌شده» می‌شوند
    const activityStopped = useRef(false);
    const pollActivity = useCallback(async () => {
        if (!isAuthed || activityStopped.current) return;
        try {
            const res = await fetch('/calendar/activity', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403 || res.status === 419) {
                activityStopped.current = true;
                return;
            }
            if (!res.ok) return;
            const list: EventNoticeLite[] = (await res.json()).notices || [];
            let firstRun = false;
            try {
                firstRun = localStorage.getItem(ACTIVITY_SEEN_KEY) === null;
            } catch {
                /* بی‌اهمیت */
            }
            const seen = loadSeenInvites(ACTIVITY_SEEN_KEY);
            const fresh = list.filter((n) => !seen.has(String(n.NoticeID)));
            if (!fresh.length && !firstRun) return;
            saveSeenInvites([...seen, ...fresh.map((n) => String(n.NoticeID))], ACTIVITY_SEEN_KEY);
            if (firstRun || !fresh.length) return;

            const open = () => {
                notifApi.destroy();
                router.visit('/calendar');
            };
            fresh.slice(0, 3).forEach((n) =>
                notifApi.info({
                    key: `activity-${n.NoticeID}`,
                    message: ACTIVITY_TITLES[n.Kind] || 'تغییر رویِ رویداد',
                    description: `«${n.Title}»${n.ActorName ? ` — ${n.ActorName}` : ''}${n.Detail ? ` (${n.Detail})` : ''}`,
                    duration: NOTIFY_DURATION_SECONDS,
                    actions: noticeActions(
                        `activity-${n.NoticeID}`,
                        { Kind: 'CALEVENT', SourceID: Number(n.EventID) },
                        <Button type="primary" size="small" onClick={open}>
                            مشاهده در تقویم
                        </Button>,
                    ),
                }),
            );
            if (fresh.length > 3) {
                notifApi.info({ key: 'activity-more', message: `و ${fresh.length - 3} تغییرِ دیگر رویِ رویدادها`, duration: NOTIFY_DURATION_SECONDS });
            }
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, notifApi, playSound, noticeActions]);

    useEffect(() => {
        if (!isAuthed) return;
        const poll = () => {
            pollInvites();
            pollNotices();
            pollActivity();
        };
        poll();
        const id = window.setInterval(poll, INVITE_POLL_MS);
        return () => window.clearInterval(id);
    }, [isAuthed, pollInvites, pollNotices, pollActivity]);

    // نامه/وظیفهٔ تازه یا تغییر رویِ آن (توضیح، وضعیت، ارجاع، رونوشت) — اعلانِ داخلِ برنامه؛ نخستین بار در این مرورگر،
    // موارد قبلی بی‌صدا «دیده‌شده» می‌شوند (فقط شمارندهٔ زنگوله)
    const messagesStopped = useRef(false);
    const pollMessages = useCallback(async () => {
        if (!isAuthed || messagesStopped.current) return;
        try {
            const res = await fetch('/messages/notifications/recent', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403 || res.status === 419) {
                messagesStopped.current = true;
                return;
            }
            if (!res.ok) return;
            const data = await res.json();
            window.dispatchEvent(new CustomEvent(UNREAD_COUNT_EVENT, { detail: Number(data.unreadCount) || 0 }));
            const list: MessageNoticeLite[] = data.notifications || [];
            let firstRun = false;
            try {
                firstRun = localStorage.getItem(MESSAGES_SEEN_KEY) === null;
            } catch {
                /* بی‌اهمیت */
            }
            const seen = loadSeenInvites(MESSAGES_SEEN_KEY);
            const fresh = list.filter((n) => !seen.has(String(n.UserNotificationID)));
            if (!fresh.length && !firstRun) return;
            saveSeenInvites([...seen, ...fresh.map((n) => String(n.UserNotificationID))], MESSAGES_SEEN_KEY);
            if (firstRun || !fresh.length) return;

            fresh.slice(0, 3).forEach((n) => {
                const open = () => {
                    notifApi.destroy();
                    router.visit(`/messages/${n.MessageID}`);
                };
                notifApi.info({
                    key: `message-${n.UserNotificationID}`,
                    icon: <MailOutlined style={{ color: '#667eea' }} />,
                    message: `${n.MessageTypeName || 'نامه'}: ${n.Subject || 'بدونِ موضوع'}`,
                    description: `${n.MessageNumber ? `شمارهٔ ${n.MessageNumber} — ` : ''}مورد تازه یا تغییری (توضیح، وضعیت، ارجاع یا رونوشت) ثبت شد.`,
                    duration: NOTIFY_DURATION_SECONDS,
                    actions: noticeActions(
                        `message-${n.UserNotificationID}`,
                        { Kind: 'MESSAGE', SourceID: Number(n.MessageID) },
                        <Button type="primary" size="small" onClick={open}>
                            مشاهده
                        </Button>,
                    ),
                });
            });
            if (fresh.length > 3) {
                notifApi.info({ key: 'message-more', message: `و ${fresh.length - 3} نامهٔ دیگر با تغییرِ تازه`, duration: NOTIFY_DURATION_SECONDS });
            }
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, notifApi, playSound, noticeActions]);

    useEffect(() => {
        if (!isAuthed) return;
        pollMessages();
        const id = window.setInterval(pollMessages, MESSAGE_POLL_MS);
        const onFocus = () => pollMessages();
        window.addEventListener('focus', onFocus);
        return () => {
            window.clearInterval(id);
            window.removeEventListener('focus', onFocus);
        };
    }, [isAuthed, pollMessages]);

    // پچِ ۱۳۲: اعلان‌هایِ تعویق‌شده که زمانشان رسیده — یک‌بار نمایش (سرور «نمایش داده شد» را ثبت می‌کند) و دوباره قابلِ تعویق
    const snoozesStopped = useRef(false);
    const shownSnoozes = useRef<Set<string>>(new Set());
    const pollSnoozes = useCallback(async () => {
        if (!isAuthed || snoozesStopped.current) return;
        try {
            const res = await fetch('/notifications/snoozes/due', {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (res.status === 401 || res.status === 403 || res.status === 419) {
                snoozesStopped.current = true;
                return;
            }
            if (!res.ok) return;
            const list: DueSnooze[] = (await res.json()).snoozes || [];
            const fresh = list.filter((z) => !shownSnoozes.current.has(`${z.SnoozeID}:${z.SnoozedUntil}`));
            if (!fresh.length) return;
            fresh.forEach((z) => {
                shownSnoozes.current.add(`${z.SnoozeID}:${z.SnoozedUntil}`);
                const key = `snooze-${z.SnoozeID}-${z.SnoozedUntil}`;
                const open = () => {
                    notifApi.destroy();
                    router.visit(z.TargetUrl || '/dashboard');
                };
                const when = z.StartDateTime ? ` — ${gregorianToJalaliDateTimeDisplay(z.StartDateTime)}` : '';
                notifApi.info({
                    key,
                    icon: <ClockCircleOutlined style={{ color: '#667eea' }} />,
                    message: `یادآوریِ دوباره — ${SNOOZE_KIND_LABELS[z.Kind] || 'اعلان'}`,
                    description: `${z.Title || ''}${when}${z.Subtitle && z.Kind !== 'INVITE' && z.Kind !== 'CANCEL' ? ` (${z.Subtitle})` : ''}`,
                    duration: NOTIFY_DURATION_SECONDS,
                    actions: noticeActions(
                        key,
                        { Kind: z.Kind, SourceID: Number(z.SourceID) },
                        <Button type="primary" size="small" onClick={open}>
                            مشاهده
                        </Button>,
                    ),
                });
                fetch(`/notifications/snoozes/${z.SnoozeID}/shown`, {
                    method: 'POST',
                    headers: { 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    credentials: 'same-origin',
                }).catch(() => undefined);
            });
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, notifApi, playSound, noticeActions]);

    useEffect(() => {
        if (!isAuthed) return;
        pollSnoozes();
        const id = window.setInterval(pollSnoozes, POLL_MS);
        const onFocus = () => pollSnoozes();
        window.addEventListener('focus', onFocus);
        return () => {
            window.clearInterval(id);
            window.removeEventListener('focus', onFocus);
        };
    }, [isAuthed, pollSnoozes]);

    // دکمهٔ «… دقیقه بعد» رویِ اعلانِ گوشیِ نامه/دعوت/… صفحه را با ?psnooze={PushOutboxID}&minutes={n} باز می‌کند
    useEffect(() => {
        if (!isAuthed) return;
        const params = new URLSearchParams(window.location.search);
        const pushId = Number(params.get('psnooze'));
        const minutes = Number(params.get('minutes'));
        if (!pushId || !minutes) return;
        try {
            const u = new URL(window.location.href);
            u.searchParams.delete('psnooze');
            u.searchParams.delete('minutes');
            window.history.replaceState(window.history.state, '', u.pathname + u.search + u.hash);
        } catch {
            /* بی‌اهمیت */
        }
        postNotifSnooze({ PushOutboxID: pushId }, minutes)
            .then(({ ok, data }) => {
                if (ok && data.success) notifApi.success({ message: 'به تعویق افتاد', description: data.message, duration: NOTIFY_DURATION_SECONDS });
                else notifApi.error({ message: 'تعویق', description: data.message || 'تعویقِ اعلان ناموفق بود.' });
            })
            .catch(() => notifApi.error({ message: 'تعویق', description: 'خطا در ارتباط با سرور.' }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAuthed]);

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
            settledAt.current.set(id, Date.now()); // Pollِ در جریانِ این زبانه هم آن را برنگرداند
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
        const rid = Number(current.ReminderID);
        settledAt.current.set(rid, Infinity);
        try {
            const res = await fetch(`/calendar/reminders/${current.ReminderID}/seen`, {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await res.json().catch(() => ({}));
            settledAt.current.set(rid, Date.now());
            if (data.success) {
                // برای رویدادِ تکرارشونده، یادآوری دوباره برای رخدادِ بعدی فعال می‌شود (RemindAtِ تازه = کلیدِ تازه)
                setQueue((q) => q.slice(1));
                channelRef.current?.postMessage({ ack: current.ReminderID });
            } else {
                notifApi.error({ message: 'یادآوری', description: data.message || 'ثبت تأییدِ یادآوری ناموفق بود.' });
                setQueue((q) => q.slice(1)); // کاربر گیر نکند
            }
        } catch {
            settledAt.current.set(rid, Date.now());
            notifApi.error({ message: 'یادآوری', description: 'خطا در ارتباط با سرور.' });
            setQueue((q) => q.slice(1));
        } finally {
            setAcknowledging(false);
        }
    }, [queue, notifApi]);

    /** تعویقِ یادآوری (پچِ ۱۳۱) — با نشست و CSRFِ همین صفحه؛ هم از پنجرهٔ یادآوری، هم از دکمهٔ اعلانِ گوشی */
    // پایانِ تعویق: همان لحظه دوباره بررسی شود (نه تا ۲۰ ثانیه بعد در Pollِ عادی)
    const snoozeTimers = useRef<number[]>([]);
    useEffect(() => () => snoozeTimers.current.forEach((t) => window.clearTimeout(t)), []);

    const postSnooze = useCallback(async (reminderId: number, minutes: number) => {
        const rid = Number(reminderId);
        settledAt.current.set(rid, Infinity);
        try {
            const res = await fetch(`/calendar/reminders/${reminderId}/snooze`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ Minutes: minutes }),
            });
            const result = { ok: res.ok, data: await res.json().catch(() => ({})) };
            if (result.ok && result.data.success) {
                // همین یادآوری اگر Pollِ هم‌زمان آن را آورده باشد (مثلاً بازشدنِ صفحه از دکمهٔ اعلانِ گوشی) بسته شود
                setQueue((q) => q.filter((r) => Number(r.ReminderID) !== rid));
                channelRef.current?.postMessage({ ack: rid });
                snoozeTimers.current.push(window.setTimeout(poll, minutes * 60000 + 3000));
            }
            return result;
        } finally {
            settledAt.current.set(rid, Date.now());
        }
    }, [poll]);

    const snooze = useCallback(async (minutes: number) => {
        const current = queue[0];
        if (!current) return;
        setSnoozing(true);
        try {
            const { ok, data } = await postSnooze(current.ReminderID, minutes);
            if (ok && data.success) {
                notifApi.success({ message: 'یادآوری به تعویق افتاد', description: data.message, duration: NOTIFY_DURATION_SECONDS });
            } else {
                notifApi.error({ message: 'یادآوری', description: data.message || 'تعویقِ یادآوری ناموفق بود.' });
            }
        } catch {
            notifApi.error({ message: 'یادآوری', description: 'خطا در ارتباط با سرور.' });
        } finally {
            setSnoozing(false);
        }
    }, [queue, notifApi, postSnooze]);

    // دکمهٔ «… دقیقه بعد» رویِ اعلانِ گوشی صفحه را با ?snooze={id}&minutes={n} باز می‌کند
    useEffect(() => {
        if (!isAuthed) return;
        const params = new URLSearchParams(window.location.search);
        const id = Number(params.get('snooze'));
        const minutes = Number(params.get('minutes'));
        if (!id || !minutes) return;
        try {
            const u = new URL(window.location.href);
            u.searchParams.delete('snooze');
            u.searchParams.delete('minutes');
            window.history.replaceState(window.history.state, '', u.pathname + u.search + u.hash);
        } catch {
            /* بی‌اهمیت */
        }
        postSnooze(id, minutes).then(({ ok, data }) => {
            if (ok && data.success) notifApi.success({ message: 'یادآوری به تعویق افتاد', description: data.message, duration: NOTIFY_DURATION_SECONDS });
            else notifApi.error({ message: 'یادآوری', description: data.message || 'تعویقِ یادآوری ناموفق بود.' });
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isAuthed]);

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
        if (!currentKey || acknowledging || snoozing || held) return;
        const t = window.setTimeout(dismissCurrent, NOTIFY_DURATION_MS);
        return () => window.clearTimeout(t);
    }, [currentKey, acknowledging, snoozing, held, dismissCurrent]);
    // یادآوریِ بعدی دوباره با زمان‌سنج شروع می‌شود
    useEffect(() => setHeld(false), [currentKey]);

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
                    onSnooze={snooze}
                    snoozing={snoozing}
                    onInteract={() => setHeld(true)}
                    held={held}
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
