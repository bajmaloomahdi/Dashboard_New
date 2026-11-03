import { useState } from 'react';
import { Button, Input } from 'antd';
import { EditOutlined, PaperClipOutlined } from '@ant-design/icons';
import { gregorianToJalaliDateTimeDisplay, toEnglishDigits } from '../../Utils/jalali';

/** ضمیمهٔ رویداد (پچِ ۱۱۷) — مسیرِ فیزیکی هرگز به مرورگر نمی‌آید؛ دانلود از Routeِ دارایِ کنترلِ دسترسی */
export interface EventAttachment {
    CalendarEventAttachmentID: number;
    FileName: string;
    FileExtension: string | null;
    FileSize: number | string;
    DownloadUrl: string;
    UploadedByName?: string | null;
    /** پچِ ۱۳۷ — توضیحِ ضمیمه و زمانِ افزودن («YYYY-MM-DD HH:mm:ss») */
    Description?: string | null;
    UploadedAt?: string | null;
}

function sizeLabel(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/** فهرستِ ضمیمه‌ها با لینکِ دانلود، کاربر و زمانِ افزودن و توضیح؛ با onSaveDescription توضیح قابلِ ثبت/ویرایش است */
export default function EventAttachmentList({
    items,
    onSaveDescription,
}: {
    items: EventAttachment[];
    onSaveDescription?: (attachmentId: number, description: string) => Promise<boolean>;
}) {
    const [editingId, setEditingId] = useState<number | null>(null);
    const [draft, setDraft] = useState('');
    const [saving, setSaving] = useState(false);

    const save = async (id: number) => {
        if (!onSaveDescription) return;
        setSaving(true);
        const ok = await onSaveDescription(id, draft.trim());
        setSaving(false);
        if (ok) setEditingId(null);
    };

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 6, marginBottom: 8 }}>
            {items.map((a) => {
                const id = Number(a.CalendarEventAttachmentID);
                const meta = [a.UploadedByName, a.UploadedAt ? gregorianToJalaliDateTimeDisplay(a.UploadedAt) : null].filter(Boolean).join(' — ');
                return (
                    <div key={id} style={{ padding: '6px 8px', border: '1px solid #F0F0F0', borderRadius: 8 }}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                            <a href={a.DownloadUrl} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, minWidth: 0 }}>
                                <PaperClipOutlined />
                                <span style={{ overflowWrap: 'anywhere' }}>{a.FileName}</span>
                            </a>
                            <span style={{ color: '#9CA3AF', fontSize: 12 }} dir="ltr">{toEnglishDigits(sizeLabel(Number(a.FileSize) || 0))}</span>
                            {onSaveDescription && editingId !== id ? (
                                <Button
                                    type="text"
                                    size="small"
                                    icon={<EditOutlined />}
                                    style={{ marginInlineStart: 'auto' }}
                                    onClick={() => {
                                        setEditingId(id);
                                        setDraft(a.Description || '');
                                    }}
                                >
                                    {a.Description ? 'ویرایشِ توضیح' : 'افزودنِ توضیح'}
                                </Button>
                            ) : null}
                        </div>
                        {meta ? <div style={{ color: '#6B7280', fontSize: 12, marginTop: 2 }}>{meta}</div> : null}
                        {editingId === id ? (
                            <div style={{ marginTop: 6 }}>
                                <Input.TextArea value={draft} onChange={(e) => setDraft(e.target.value)} rows={2} maxLength={500} showCount placeholder="توضیحِ این ضمیمه…" disabled={saving} />
                                <div style={{ display: 'flex', gap: 6, justifyContent: 'flex-end', marginTop: 22 }}>
                                    <Button size="small" onClick={() => setEditingId(null)} disabled={saving}>
                                        انصراف
                                    </Button>
                                    <Button size="small" type="primary" loading={saving} onClick={() => save(id)}>
                                        ثبتِ توضیح
                                    </Button>
                                </div>
                            </div>
                        ) : a.Description ? (
                            <div style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', marginTop: 4, fontSize: 13 }}>{a.Description}</div>
                        ) : null}
                    </div>
                );
            })}
        </div>
    );
}

function getXsrfToken(): string {
    const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

/** ثبت/تغییرِ توضیحِ یک ضمیمه (پچِ ۱۳۷) */
export async function saveEventAttachmentDescription(eventId: number, attachmentId: number, description: string): Promise<{ success: boolean; message: string; attachments?: EventAttachment[] }> {
    try {
        const res = await fetch(`/calendar/events/${eventId}/attachments/${attachmentId}/description`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ Description: description || null }),
        });
        const data = await res.json().catch(() => ({}));
        const firstError = data.errors ? (Object.values(data.errors).flat()[0] as string) : null;

        return { success: res.ok && !!data.success, message: firstError || data.message || (res.ok ? '' : 'خطا در ارتباط با سرور'), attachments: data.attachments };
    } catch {
        return { success: false, message: 'ارتباط با سرور برقرار نشد.' };
    }
}

/** بارگذاریِ فایل‌ها برایِ یک رویداد (همه یا هیچ در سرور) */
export async function uploadEventAttachments(eventId: number, files: File[], description?: string): Promise<{ success: boolean; message: string; attachments?: EventAttachment[] }> {
    const form = new FormData();
    files.forEach((f) => form.append('files[]', f));
    if (description && description.trim()) form.append('Description', description.trim()); // پچِ ۱۳۷
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
