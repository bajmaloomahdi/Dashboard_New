import { Alert, Typography } from 'antd';
import { browserInfo, PushFailure } from './pushClient';

const { Text } = Typography;

export type HelpAction = 'retry' | 'reload' | 'none';

export interface FailureHelp {
    title: string;
    explanation: string;
    steps: string[];
    action: HelpAction;
}

/** مراحلِ آزادکردنِ اجازهٔ اعلان (وقتی قبلاً «Block» شده) — متناسب با مرورگر و سیستم‌عامل */
export function deniedSteps(): string[] {
    const { browser, os, standalone } = browserInfo();
    if (os === 'ios') {
        return standalone
            ? [
                  'برنامهٔ «Settings» آیفون/آیپد را باز کنید.',
                  'وارد «Notifications» شوید و «راگا ۳۶۰» را از فهرست انتخاب کنید.',
                  '«Allow Notifications» را روشن کنید.',
                  'راگا ۳۶۰ را کامل ببندید و دوباره از صفحهٔ اصلی باز کنید.',
              ]
            : ['اعلان در iPhone/iPad فقط در وب‌اپِ نصب‌شده کار می‌کند؛ ابتدا راگا ۳۶۰ را به صفحهٔ اصلی اضافه کنید.'];
    }
    if (os === 'android') {
        if (browser === 'firefox') {
            return ['رویِ نمادِ قفل کنارِ نشانیِ سایت بزنید.', '«Notifications» را رویِ «Allowed» بگذارید.', 'صفحه را تازه کنید.'];
        }
        return [
            'رویِ نمادِ کنارِ نشانیِ سایت (قفل یا ⓘ) در نوارِ آدرس بزنید.',
            '«Permissions» یا «Site settings» را باز کنید.',
            '«Notifications» را رویِ «Allow» بگذارید و صفحه را تازه کنید.',
            'اگر باز هم اعلان نیامد: تنظیماتِ اندروید ← Apps ← مرورگرِ خود (مثلاً Chrome) ← Notifications را روشن کنید.',
        ];
    }
    if (browser === 'firefox') {
        return [
            'رویِ نمادِ قفل/تنظیمات کنارِ نشانیِ سایت در نوارِ آدرس کلیک کنید.',
            'کنارِ «Send notifications» رویِ ✕ (Clear permission) کلیک کنید.',
            'صفحه را تازه کنید و دوباره «بله، فعال کن» را بزنید.',
        ];
    }
    if (browser === 'safari') {
        return ['از منوی Safari ← Settings ← Websites ← Notifications را باز کنید.', 'برایِ این سایت «Allow» را انتخاب کنید.', 'صفحه را تازه کنید.'];
    }
    if (browser === 'edge') {
        return [
            'رویِ نمادِ قفل کنارِ نشانیِ سایت در نوارِ آدرس کلیک کنید.',
            '«Permissions for this site» (مجوزهایِ این سایت) را باز کنید.',
            '«Notifications» را رویِ «Allow» بگذارید.',
            'صفحه را تازه کنید.',
        ];
    }
    return [
        'رویِ نمادِ کنارِ نشانیِ سایت (قفل یا تنظیمات) در نوارِ آدرس کلیک کنید.',
        '«Notifications / اعلان‌ها» را رویِ «Allow / اجازه دادن» بگذارید (اگر نبود، «Site settings» را باز کنید).',
        'صفحه را تازه کنید.',
        ...(os === 'windows' ? ['اگر باز هم اعلان دیده نشد: تنظیماتِ ویندوز ← System ← Notifications را برایِ مرورگر روشن و «Do not disturb» را خاموش کنید.'] : []),
    ];
}

export function failureHelp(code: PushFailure | undefined, message?: string): FailureHelp {
    const { browser } = browserInfo();
    switch (code) {
        case 'denied':
            return {
                title: 'اجازهٔ اعلان در مرورگر مسدود است',
                explanation: 'قبلاً برایِ این سایت «Block» انتخاب شده و مرورگر دیگر پنجرهٔ اجازه را نشان نمی‌دهد؛ باید یک‌بار از تنظیماتِ همین سایت آزاد شود.',
                steps: deniedSteps(),
                action: 'retry',
            };
        case 'dismissed':
            return {
                title: 'اجازه داده نشد',
                explanation: 'پنجرهٔ مرورگر بدونِ انتخاب بسته شد.',
                steps: ['دوباره «تلاشِ دوباره» را بزنید و در پنجرهٔ مرورگر «Allow / اجازه دادن» را انتخاب کنید.'],
                action: 'retry',
            };
        case 'ios-not-installed':
            return {
                title: 'در iPhone/iPad ابتدا برنامه را نصب کنید',
                explanation: 'اپل اعلان را فقط برایِ وب‌اپِ اضافه‌شده به صفحهٔ اصلی (iOS/iPadOS 16.4 یا بالاتر) اجازه می‌دهد.',
                steps: [
                    'همین صفحه را در Safari باز کنید.',
                    'دکمهٔ Share (مربع با فلشِ رو به بالا) را بزنید.',
                    '«Add to Home Screen» را انتخاب کنید.',
                    'راگا ۳۶۰ را از آیکونِ رویِ صفحهٔ اصلی باز کنید، وارد شوید و اعلان‌ها را فعال کنید.',
                ],
                action: 'none',
            };
        case 'unsupported':
            return {
                title: 'این مرورگر از اعلان پشتیبانی نمی‌کند',
                explanation: 'مرورگرهایِ داخلِ برنامه‌ها (مثلِ تلگرام، اینستاگرام، واتساپ) و برخی مرورگرهایِ قدیمی اعلانِ وب ندارند.',
                steps: ['راگا ۳۶۰ را در Chrome، Edge یا Firefox (یا در iPhone به‌صورتِ وب‌اپِ نصب‌شده) باز کنید و دوباره فعال کنید.'],
                action: 'none',
            };
        case 'insecure':
            return {
                title: 'نشانیِ امن لازم است',
                explanation: 'اعلانِ مرورگر فقط رویِ نشانیِ امن (https) کار می‌کند.',
                steps: ['راگا ۳۶۰ را با نشانیِ https باز کنید.'],
                action: 'none',
            };
        case 'not-configured':
            return {
                title: 'سرویسِ اعلان رویِ سرور آماده نیست',
                explanation: 'تنظیماتِ اعلان رویِ سرور هنوز انجام نشده است.',
                steps: ['به مدیرِ سیستم اطلاع دهید.'],
                action: 'none',
            };
        case 'sw-failed':
            return {
                title: 'مرورگر نتوانست سرویسِ اعلان را آماده کند',
                explanation: 'معمولاً با تازه‌سازیِ صفحه درست می‌شود. در حالتِ ناشناس (Incognito/InPrivate) یا مرورگرِ داخلِ برنامه‌ها کار نمی‌کند.',
                steps: ['صفحه را تازه کنید و دوباره تلاش کنید.', 'اگر در حالتِ ناشناس هستید، سایت را در پنجرهٔ معمولیِ مرورگر باز کنید.'],
                action: 'reload',
            };
        case 'subscribe-failed':
            return {
                title: 'سرویسِ اعلانِ مرورگر در دسترس نیست',
                explanation: 'مرورگر برایِ اعلان به سرویسِ سازنده‌اش (Google، Microsoft، Mozilla یا Apple) وصل می‌شود و این اتصال برقرار نشد.',
                steps: browser === 'brave'
                    ? ['در Brave نشانیِ brave://settings/privacy را باز کنید.', '«Use Google services for push messaging» را روشن کنید.', 'مرورگر را ببندید و دوباره باز کنید، سپس دوباره تلاش کنید.']
                    : [
                          'اتصالِ اینترنت را بررسی کنید؛ اگر فیلترشکن/VPN یا فایروالِ سازمانی دارید، ممکن است جلویِ این سرویس را بگیرد.',
                          'حالتِ ناشناس (Incognito/InPrivate) از اعلان پشتیبانی نمی‌کند.',
                          'چند دقیقه بعد دوباره تلاش کنید.',
                      ],
                action: 'retry',
            };
        case 'session':
            return { title: 'نشستِ شما منقضی شده است', explanation: 'برایِ امنیت، صفحه باید تازه شود.', steps: ['صفحه را تازه کنید و دوباره تلاش کنید.'], action: 'reload' };
        case 'auth':
            return { title: 'ورودِ شما منقضی شده است', explanation: 'برایِ ثبتِ این دستگاه باید دوباره وارد شوید.', steps: ['صفحه را تازه کنید و دوباره وارد شوید.'], action: 'reload' };
        case 'network':
            return { title: 'ارتباط با سرور برقرار نشد', explanation: 'اتصالِ اینترنت قطع یا ناپایدار است.', steps: ['اتصال را بررسی و دوباره تلاش کنید.'], action: 'retry' };
        case 'server-rejected':
            return {
                title: 'سرور این دستگاه را ثبت نکرد',
                explanation: message || 'ثبتِ اشتراک رویِ سرور ناموفق بود.',
                steps: ['دوباره تلاش کنید؛ اگر تکرار شد، با Chrome، Edge یا Firefox امتحان کنید یا به مدیرِ سیستم اطلاع دهید.'],
                action: 'retry',
            };
        default:
            return { title: 'فعال‌سازی انجام نشد', explanation: message || 'خطایِ پیش‌بینی‌نشده.', steps: ['صفحه را تازه کنید و دوباره تلاش کنید.'], action: 'retry' };
    }
}

/** نمایشِ توضیح و مراحلِ رفعِ مشکل (بدونِ هیچ اطلاعاتِ محرمانه) */
export function FailureHelpView({ help, detail }: { help: FailureHelp; detail?: string }) {
    return (
        <Alert
            type="warning"
            showIcon
            message={help.title}
            description={
                <div>
                    <div style={{ marginBottom: help.steps.length ? 6 : 0 }}>{help.explanation}</div>
                    {help.steps.length ? (
                        <ol style={{ margin: 0, paddingInlineStart: 20 }}>
                            {help.steps.map((s) => (
                                <li key={s} style={{ marginBottom: 2 }}>{s}</li>
                            ))}
                        </ol>
                    ) : null}
                    {detail && detail !== help.explanation ? (
                        <Text type="secondary" style={{ fontSize: 11, display: 'block', marginTop: 6, unicodeBidi: 'plaintext' }}>
                            {detail}
                        </Text>
                    ) : null}
                </div>
            }
        />
    );
}
