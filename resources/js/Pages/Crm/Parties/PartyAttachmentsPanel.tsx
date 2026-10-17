import { useRef, useState } from 'react';
import { Button, Space, Typography, Empty, Popconfirm, Spin, Input, Tooltip } from 'antd';
import {
    PlusOutlined, DeleteOutlined, EditOutlined, CheckOutlined, CloseOutlined, PaperClipOutlined, DownloadOutlined,
    FileOutlined, FilePdfOutlined, FileWordOutlined, FileExcelOutlined, FilePptOutlined, FileZipOutlined, FileImageOutlined, FileTextOutlined,
} from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

export interface PartyAttachment {
    PartyAttachmentID: number;
    PartyID: number;
    FileName: string;
    FileExtension: string | null;
    FileSize: number | string;
    Description: string | null;
    Date_InsertFirst: string;
    CreatedByName: string | null;
    /** Routeِ امنِ دانلود (crm.party-attachments.download) — مسیرِ فیزیکیِ فایل هرگز به Frontend نمی‌آید */
    DownloadUrl: string;
}

interface PartyAttachmentsPanelProps {
    partyId: number;
    items: PartyAttachment[];
    canManage: boolean;
}

/** همان قیودِ Backend (CrmInteractionAttachmentFiles) — بررسیِ اولیه در مرورگر؛ بررسیِ قطعی در سرور */
const MAX_BYTES = 10240 * 1024;
const MAX_FILES = 10;
const MAX_DESCRIPTION = 500;
const BLOCKED_EXT = new Set([
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht',
    'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'scr', 'dll', 'cpl', 'sys',
    'sh', 'bash', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'mjs', 'jse', 'wsf', 'wsh', 'hta', 'jar',
    'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py',
    'htaccess', 'htpasswd', 'lnk', 'reg',
]);

const checkFile = (file: File): string | null => {
    const parts = file.name.toLowerCase().split('.').slice(1);
    if (!file.name || file.name.startsWith('.') || parts.some((p) => BLOCKED_EXT.has(p))) {
        return `نوعِ فایلِ «${file.name}» مجاز نیست (فایل‌هایِ اجرایی/اسکریپتی قابلِ پیوست نیستند).`;
    }
    if (file.size === 0) return `فایلِ «${file.name}» خالی است.`;
    if (file.size > MAX_BYTES) return `حجمِ فایلِ «${file.name}» نباید بیشتر از ۱۰ مگابایت باشد.`;
    return null;
};

const formatSize = (value: number | string): string => {
    const bytes = Number(value) || 0;
    if (bytes < 1024) return `${bytes} بایت`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} کیلوبایت`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} مگابایت`;
};

const fileIcon = (ext: string | null) => {
    const e = (ext || '').toLowerCase();
    const style = { fontSize: 22 };
    if (e === 'pdf') return <FilePdfOutlined style={{ ...style, color: '#e53935' }} />;
    if (['doc', 'docx', 'rtf', 'odt'].includes(e)) return <FileWordOutlined style={{ ...style, color: '#1e63c4' }} />;
    if (['xls', 'xlsx', 'csv', 'ods'].includes(e)) return <FileExcelOutlined style={{ ...style, color: '#1d7f45' }} />;
    if (['ppt', 'pptx', 'odp'].includes(e)) return <FilePptOutlined style={{ ...style, color: '#d35400' }} />;
    if (['zip', 'rar', '7z', 'gz', 'tar'].includes(e)) return <FileZipOutlined style={{ ...style, color: '#8e6c00' }} />;
    if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tif', 'tiff'].includes(e)) return <FileImageOutlined style={{ ...style, color: '#667eea' }} />;
    if (['txt', 'log', 'md'].includes(e)) return <FileTextOutlined style={{ ...style, color: '#555' }} />;
    return <FileOutlined style={{ ...style, color: '#888' }} />;
};

/**
 * فایل‌هایِ پیوستِ طرف‌حساب (غیر از تصاویرِ Gallery) — تبِ «ضمائم و سایر ویژگی‌ها».
 * انتخابِ فایل = آپلودِ فوری (چندتایی در یک درخواست)؛ توضیحِ هر فایل بعداً با «ویرایش».
 * دانلود همیشه با نامِ اصلیِ فایل و از Routeِ دارایِ دسترسی؛ حذف منطقی است (فایل رویِ سرور می‌ماند).
 */
export default function PartyAttachmentsPanel({ partyId, items: initialItems, canManage }: PartyAttachmentsPanelProps) {
    const [files, setFiles] = useState<PartyAttachment[]>(initialItems || []);
    const [uploading, setUploading] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editValue, setEditValue] = useState('');
    const [savingEdit, setSavingEdit] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const fileInputRef = useRef<HTMLInputElement>(null);

    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const reload = async () => {
        const res = await crmApi(`/crm/parties/${partyId}/attachments`);
        if (res.ok && res.success) setFiles(res.items || []);
    };

    const handleFilesSelected = async (fileList: FileList | null) => {
        if (!fileList || fileList.length === 0) return;
        const selected = Array.from(fileList);

        if (selected.length > MAX_FILES) {
            setError(`در هر بار حداکثر ${MAX_FILES} فایل قابلِ افزودن است.`);
            return;
        }
        for (const f of selected) {
            const problem = checkFile(f);
            if (problem) {
                setError(problem);
                return;
            }
        }

        setError(null);
        setUploading(true);
        const form = new FormData();
        selected.forEach((f) => form.append('files[]', f));
        const res = await crmApi(`/crm/parties/${partyId}/attachments`, 'POST', form);
        setUploading(false);

        if (!res.ok || !res.success) {
            notify('error', res.status === 413 ? 'حجمِ مجموعِ فایل‌ها از سقفِ مجازِ سرور بیشتر است.' : res.message);
            return;
        }
        notify('success', res.message);
        await reload();
    };

    const handleDelete = async (attachmentId: number) => {
        setBusyId(attachmentId);
        const res = await crmApi(`/crm/party-attachments/${attachmentId}/delete`, 'POST');
        setBusyId(null);
        notify(res.success ? 'success' : 'error', res.message);
        if (res.success) setFiles((list) => list.filter((f) => f.PartyAttachmentID !== attachmentId));
    };

    const startEdit = (f: PartyAttachment) => {
        setEditingId(f.PartyAttachmentID);
        setEditValue(f.Description || '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditValue('');
    };

    const saveEdit = async (attachmentId: number) => {
        if (editValue.length > MAX_DESCRIPTION) {
            notify('error', `توضیحات حداکثر ${MAX_DESCRIPTION} کاراکتر است.`);
            return;
        }
        setSavingEdit(true);
        const res = await crmApi(`/crm/party-attachments/${attachmentId}/description`, 'POST', { description: editValue.trim() || null });
        setSavingEdit(false);
        notify(res.success ? 'success' : 'error', res.message);
        if (res.success) {
            setFiles((list) => list.map((f) => (f.PartyAttachmentID === attachmentId ? { ...f, Description: editValue.trim() || null } : f)));
            cancelEdit();
        }
    };

    return (
        <div>
            <Space direction="vertical" style={{ width: '100%' }} size={12}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <Text strong>
                        <PaperClipOutlined style={{ marginInlineEnd: 6, color: '#667eea' }} />
                        فایل‌هایِ پیوست
                    </Text>
                    {canManage ? (
                        <>
                            <input
                                ref={fileInputRef}
                                type="file"
                                multiple
                                style={{ display: 'none' }}
                                onChange={(e) => {
                                    handleFilesSelected(e.target.files);
                                    e.target.value = '';
                                }}
                            />
                            <Button type="primary" size="small" icon={<PlusOutlined />} loading={uploading} onClick={() => fileInputRef.current?.click()}>
                                افزودنِ فایل
                            </Button>
                        </>
                    ) : null}
                </div>

                {error ? <Text type="danger" style={{ fontSize: 12 }}>{error}</Text> : null}
                <Text type="secondary" style={{ fontSize: 12 }}>
                    برایِ اسناد و فایل‌هایِ دیگر (PDF، Word، Excel، فایلِ فشرده و…) — حداکثر ۱۰ مگابایت برایِ هر فایل؛ فایل‌هایِ اجرایی/اسکریپتی مجاز نیستند. تصاویر را در بخشِ «تصاویرِ طرف‌حساب» ثبت کنید.
                </Text>

                {uploading ? <Spin size="small" /> : null}

                {files.length === 0 ? (
                    <Empty description="هنوز فایلی برایِ این طرف‌حساب پیوست نشده است" />
                ) : (
                    <div className="crm-party-attachments-list">
                        {files.map((f) => (
                            <div key={f.PartyAttachmentID} className="crm-party-attachment-row">
                                <div className="crm-party-attachment-icon">{fileIcon(f.FileExtension)}</div>
                                <div className="crm-party-attachment-main">
                                    <a href={f.DownloadUrl} className="crm-party-attachment-name" title={`دانلودِ ${f.FileName}`}>
                                        {f.FileName}
                                    </a>
                                    {editingId === f.PartyAttachmentID ? (
                                        <Space direction="vertical" size={4} style={{ width: '100%', marginTop: 4 }}>
                                            <Input.TextArea
                                                size="small"
                                                rows={2}
                                                maxLength={MAX_DESCRIPTION}
                                                value={editValue}
                                                disabled={savingEdit}
                                                onChange={(e) => setEditValue(e.target.value)}
                                                placeholder="توضیحِ فایل"
                                            />
                                            <Space size={4}>
                                                <Button size="small" type="primary" icon={<CheckOutlined />} loading={savingEdit} onClick={() => saveEdit(f.PartyAttachmentID)}>ذخیره</Button>
                                                <Button size="small" icon={<CloseOutlined />} disabled={savingEdit} onClick={cancelEdit}>انصراف</Button>
                                            </Space>
                                        </Space>
                                    ) : (
                                        <Text className="crm-party-attachment-desc" ellipsis={{ tooltip: f.Description || undefined }}>
                                            {f.Description || <Text type="secondary">بدونِ توضیح</Text>}
                                        </Text>
                                    )}
                                    <Text type="secondary" className="crm-party-attachment-meta">
                                        {formatSize(f.FileSize)} — {f.CreatedByName || '—'} — {f.Date_InsertFirst ? gregorianToJalaliDisplay(f.Date_InsertFirst) : '—'}
                                    </Text>
                                </div>
                                <Space size={4} className="crm-party-attachment-actions">
                                    <Tooltip title="دانلود">
                                        <Button size="small" shape="circle" icon={<DownloadOutlined />} href={f.DownloadUrl} />
                                    </Tooltip>
                                    {canManage && editingId !== f.PartyAttachmentID ? (
                                        <>
                                            <Tooltip title="ویرایشِ توضیحات">
                                                <Button type="primary" size="small" shape="circle" icon={<EditOutlined />} onClick={() => startEdit(f)} />
                                            </Tooltip>
                                            <Popconfirm title="این فایل حذف شود؟" onConfirm={() => handleDelete(f.PartyAttachmentID)} okText="بله" cancelText="خیر">
                                                <Button danger type="primary" size="small" shape="circle" loading={busyId === f.PartyAttachmentID} icon={<DeleteOutlined />} />
                                            </Popconfirm>
                                        </>
                                    ) : null}
                                </Space>
                            </div>
                        ))}
                    </div>
                )}
            </Space>

            <style>{`
                .crm-party-attachments-list {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                }
                .crm-party-attachment-row {
                    display: flex;
                    align-items: flex-start;
                    gap: 12px;
                    border: 1px solid #f0f0f0;
                    border-radius: 10px;
                    padding: 10px 12px;
                    background: #fff;
                }
                .crm-party-attachment-icon {
                    flex: 0 0 auto;
                    padding-top: 2px;
                }
                .crm-party-attachment-main {
                    flex: 1 1 auto;
                    min-width: 0;
                    display: flex;
                    flex-direction: column;
                    gap: 2px;
                }
                .crm-party-attachment-name {
                    font-weight: 500;
                    overflow-wrap: anywhere;
                }
                .crm-party-attachment-desc {
                    display: block;
                    font-size: 13px;
                }
                .crm-party-attachment-meta {
                    display: block;
                    font-size: 11px;
                }
                .crm-party-attachment-actions {
                    flex: 0 0 auto;
                }
            `}</style>

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
