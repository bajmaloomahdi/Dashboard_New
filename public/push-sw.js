/*
 * Raga 360 — Service Workerِ اختصاصیِ Web Push.
 * عمداً هیچ fetch/cache ای ندارد: درخواست‌هایِ صفحه، Vite و Inertia مستقیم به شبکه می‌روند و از این فایل عبور نمی‌کنند.
 * محتوایِ اعلان عمومی است (اطلاعاتِ حساس رویِ صفحهٔ قفل نمایش داده نمی‌شود)؛ جزئیات فقط پس از ورود و بررسیِ دسترسی در سرور.
 * تحویل «حداقل یک‌بار» است: اعلانِ تکراری با همان Tag جایگزینِ قبلی و بی‌صدا نمایش داده می‌شود.
 */
'use strict';

const DB_NAME = 'raga360-push';
const STORE = 'seen';
const KEEP = 300;
const DEFAULT_URL = '/dashboard';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

function openDb() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open(DB_NAME, 1);
        req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id' });
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

/** آیا این شناسهٔ اعلان قبلاً در همین مرورگر نمایش داده شده؟ (و ثبتِ آن برایِ دفعه‌هایِ بعد) */
async function seenBefore(id) {
    try {
        const db = await openDb();
        return await new Promise((resolve) => {
            const tx = db.transaction(STORE, 'readwrite');
            const store = tx.objectStore(STORE);
            const get = store.get(id);
            get.onsuccess = () => {
                const seen = !!get.result;
                if (!seen) {
                    store.put({ id, at: Date.now() });
                    const all = store.getAll();
                    all.onsuccess = () => {
                        const rows = all.result || [];
                        if (rows.length > KEEP) {
                            rows.sort((a, b) => a.at - b.at).slice(0, rows.length - KEEP).forEach((r) => store.delete(r.id));
                        }
                    };
                }
                resolve(seen);
            };
            get.onerror = () => resolve(false);
        });
    } catch (e) {
        return false; // بدونِ IndexedDB هم اعلان نمایش داده می‌شود؛ فقط کنترلِ تکرار ضعیف‌تر است
    }
}

function safeUrl(url) {
    return typeof url === 'string' && url.startsWith('/') && !url.startsWith('//') ? url : DEFAULT_URL;
}

self.addEventListener('push', (event) => {
    event.waitUntil((async () => {
        let data = {};
        try {
            data = event.data ? event.data.json() : {};
        } catch (e) {
            data = {};
        }
        const tag = typeof data.tag === 'string' && data.tag ? data.tag : 'raga360';
        const duplicate = data.id ? await seenBefore(String(data.id)) : false;
        // یادآوری: دکمه‌هایِ تعویق (اندروید/کروم؛ iOS دکمهٔ اعلان ندارد) — صفحهٔ تقویم با نشست و CSRFِ خودش ثبت می‌کند.
        // Chrome حداکثر ۲ دکمه نشان می‌دهد (Notification.maxActions = 2)؛ بقیهٔ زمان‌ها با لمسِ خودِ اعلان در پنجرهٔ برنامه
        const reminderId = typeof data.rid === 'string' && /^\d+$/.test(data.rid) ? data.rid : null;
        // پچِ ۱۳۲: بقیهٔ انواع (نامه، دعوت، لغو، فعالیتِ رویداد، اعلانِ عمومی) — تعویق با شناسهٔ همین ردیفِ Push
        const pushId = !reminderId && data.snz === 1 && typeof data.id === 'string' && /^\d+$/.test(data.id) ? data.id : null;

        // مرورگرها (به‌خصوص iOS) برایِ هر Push یک اعلانِ قابلِ‌مشاهده می‌خواهند؛ تکراری فقط جایگزینِ بی‌صدا می‌شود
        await self.registration.showNotification(data.title || 'راگا ۳۶۰', {
            body: data.body || '',
            tag,
            renotify: !duplicate,
            silent: duplicate,
            icon: '/company/pwa-icon/192',
            badge: '/company/pwa-icon/192',
            dir: 'rtl',
            lang: 'fa',
            data: { url: safeUrl(data.url), reminderId, pushId },
            actions: reminderId || pushId
                ? [
                      { action: 'snooze-5', title: '۵ دقیقه بعد' },
                      { action: 'snooze-15', title: '۱۵ دقیقه بعد' },
                  ]
                : [],
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const info = event.notification.data || {};
    // دکمهٔ «… دقیقه بعد» → صفحهٔ تقویم تعویق را ثبت می‌کند (همان نشست و CSRFِ برنامه)
    const snooze = /^snooze-(5|10|15)$/.exec(event.action || '');
    const url = snooze && info.reminderId
        ? `/calendar?snooze=${info.reminderId}&minutes=${snooze[1]}`
        : snooze && info.pushId
          ? `/dashboard?psnooze=${info.pushId}&minutes=${snooze[1]}`
          : info.url;
    const target = new URL(safeUrl(url), self.location.origin).href;

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin && 'focus' in client) {
                await client.focus();
                if ('navigate' in client) {
                    try {
                        await client.navigate(target); // اگر کاربر خارج شده باشد، سرور به صفحهٔ ورود و سپس به همین مقصد می‌برد
                        return;
                    } catch (e) {
                        /* تبی که این Service Worker آن را کنترل نمی‌کند → پنجرهٔ تازه */
                    }
                } else {
                    return;
                }
            }
        }
        await self.clients.openWindow(target);
    })());
});

/* مرورگر اشتراک را عوض کرد: با همان تنظیمات دوباره مشترک می‌شویم؛ صفحهٔ برنامه در بازدیدِ بعدی آن را به سرور می‌فرستد
   (Service Worker به کوکیِ CSRF دسترسی ندارد، پس ذخیره در سرور از صفحه انجام می‌شود). */
self.addEventListener('pushsubscriptionchange', (event) => {
    const options = event.oldSubscription && event.oldSubscription.options;
    if (!options) return;
    event.waitUntil(self.registration.pushManager.subscribe(options).catch(() => undefined));
});
