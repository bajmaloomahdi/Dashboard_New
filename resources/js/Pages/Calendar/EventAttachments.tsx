import { PaperClipOutlined } from '@ant-design/icons';
import { toEnglishDigits } from '../../Utils/jalali';

/** ضمیمهٔ رویداد (پچِ ۱۱۷) — مسیرِ فیزیکی هرگز به مرورگر نمی‌آید؛ دانلود از Routeِ دارایِ کنترلِ دسترسی */
export interface EventAttachment {
    CalendarEventAttachmentID: number;
    FileName: string;
    FileExtension: string | null;
    FileSize: number | string;
    DownloadUrl: string;
    UploadedByName?: string | null;
}

function sizeLabel(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** فهرستِ ضمیمه‌ها با لینکِ دانلود */
export default function EventAttachmentList({ items }: { items: EventAttachment[] }) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 4, marginBottom: 8 }}>
            {items.map((a) => (
                <a key={a.CalendarEventAttachmentID} href={a.DownloadUrl} style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                    <PaperClipOutlined />
                    <span style={{ overflowWrap: 'anywhere' }}>{a.FileName}</span>
                    <span style={{ color: '#9CA3AF', fontSize: 12 }} dir="ltr">{toEnglishDigits(sizeLabel(Number(a.FileSize) || 0))}</span>
                </a>
            ))}
        </div>
    );
}

function getXsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** بارگذاریِ فایل‌ها برایِ یک رویداد (همه یا هیچ در سرور) */
export async function uploadEventAttachments(eventId: number, files: File[]): Promise<{ success: boolean; message: string; attachments?: EventAttachment[] }> {
    const form = new FormData();
    files.forEach((f) => form.append('files[]', f));
    try {
        const res = await fetch(`/calendar/events/${eventId}/attachments`, {
            method: 'POST',
            headers: { 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
            body: form,
        });
        const data = await res.json().catch(() => ({}));
        const firstError = data.errors ? (Object.values(data.errors).flat()[0] as string) : null;

        return { success: res.ok && !!data.success, message: firstError || data.message || (res.ok ? '' : 'خطا در ارتباط با سرور'), attachments: data.attachments };
    } catch {
        return { success: false, message: 'ارتباط با سرور برقرار نشد.' };
    }
}
