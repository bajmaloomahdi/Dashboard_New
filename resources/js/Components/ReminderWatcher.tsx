import { useCallback, useEffect, useRef, useState } from 'react';
import { notification as antdNotification } from 'antd';
import { usePage } from '@inertiajs/react';
import ReminderNotificationModal, { DueReminder } from './ReminderNotificationModal';

const POLL_MS = 20000;
const SOUND_URL = '/sounds/notification.wav';

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

    const [notifApi, contextHolder] = antdNotification.useNotification();

    const [queue, setQueue] = useState<DueReminder[]>([]);
    const [acknowledging, setAcknowledging] = useState(false);
    const [audioBlocked, setAudioBlocked] = useState(false);

    const knownIds = useRef<Set<number>>(new Set());
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

            const fresh = due.filter((r) => !knownIds.current.has(r.ReminderID));
            if (fresh.length === 0) return;

            fresh.forEach((r) => knownIds.current.add(r.ReminderID));
            setQueue((q) => {
                const add = fresh.filter((f) => !q.some((x) => x.ReminderID === f.ReminderID));
                return add.length ? [...q, ...add] : q;
            });
            playSound();
        } catch {
            /* بی‌صدا — تلاشِ بعدی */
        }
    }, [isAuthed, playSound]);

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
                // برای رویدادِ تکرارشونده، یادآوری دوباره برای رخدادِ بعدی فعال می‌شود
                knownIds.current.delete(current.ReminderID);
                setQueue((q) => q.slice(1));
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

    if (!isAuthed) return null;

    return (
        <>
            {contextHolder}
            {queue.length > 0 && (
                <ReminderNotificationModal
                    key={queue[0].ReminderID}
                    reminder={queue[0]}
                    queueCount={queue.length - 1}
                    acknowledging={acknowledging}
                    onAcknowledge={acknowledge}
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
