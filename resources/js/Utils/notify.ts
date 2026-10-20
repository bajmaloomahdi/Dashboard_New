/**
 * مدتِ نمایشِ همهٔ اعلان‌هایِ داخلِ برنامه (Toast/message، notification، پنجرهٔ پیامِ موفقیت/خطا و اعلان‌هایِ تقویم).
 * تنظیمِ مرکزی: فقط همین مقدار را تغییر دهید. TTLِ صفِ Push و مدتِ نمایشِ اعلانِ سیستم‌عامل به این ربطی ندارد.
 */
export const NOTIFY_DURATION_MS = 6000;

/** همان مدت به ثانیه — برایِ message/notificationِ Ant Design */
export const NOTIFY_DURATION_SECONDS = NOTIFY_DURATION_MS / 1000;

/**
 * پیکربندیِ ثابت برایِ message.useMessage / notification.useNotification — حتماً همین شیءِ ثابت (نه شیءِ تازه در هر رندر)
 * تا APIِ برگشتی پایدار بماند و useCallback/useEffectهایی که به آن وابسته‌اند در هر رندر دوباره اجرا نشوند.
 */
export const NOTIFY_HOOK_CONFIG = Object.freeze({ duration: NOTIFY_DURATION_SECONDS });
