import { useRef, useState } from 'react';
import { Button, Image, Space, Typography, Empty, Popconfirm, Spin, Input, Tooltip } from 'antd';
import { PlusOutlined, DeleteOutlined, PictureOutlined, EditOutlined, CheckOutlined, CloseOutlined } from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

interface PartyImage {
    PartyImageID: number;
    PartyID: number;
    ImageMimeType: string;
    Description: string | null;
    SortOrder: number;
    IsActive: boolean | number | string;
    Date_InsertFirst: string;
    CreatedByName: string | null;
    /** Routeِ امنِ نمایشِ تصویر (crm.party-images.show) — مسیرِ فیزیکیِ فایل هرگز به Frontend نمی‌آید */
    ImageUrl: string;
}

interface PartyImagesPanelProps {
    partyId: number;
    items: PartyImage[];
    canManage: boolean;
}

/** همان قیودِ Backend (CrmPartyImageFiles) — بررسیِ اولیه در مرورگر؛ بررسیِ قطعی (MIMEِ واقعی/ابعاد) در سرور */
const ALLOWED_EXT = new Set(['png', 'jpg', 'jpeg', 'webp']);
const MAX_BYTES = 1024 * 1024;
const MAX_FILES = 10;
const MAX_DESCRIPTION = 500;

const checkFile = (file: File): string | null => {
    const ext = file.name.toLowerCase().split('.').pop() || '';
    if (!ALLOWED_EXT.has(ext)) return `فرمتِ «${file.name}» مجاز نیست؛ فقط PNG، JPG یا WebP.`;
    if (file.size === 0) return `فایلِ «${file.name}» خالی است.`;
    if (file.size > MAX_BYTES) return `حجمِ «${file.name}» نباید بیشتر از ۱ مگابایت باشد.`;
    return null;
};

/**
 * Galleryِ تصاویرِ طرف‌حساب — تبِ «ضمائم و سایر ویژگی‌ها». تصاویر در یک ردیفِ افقیِ RTL (اولین
 * تصویر سمتِ راست، بعدی‌ها به چپ ادامه؛ با Scroll در صورتِ زیاد بودن) نمایش داده می‌شوند، نه یک
 * Gridِ عمودیِ بزرگ. کلیک روی هر تصویر با antd Image.PreviewGroup بزرگ‌نمایی می‌شود.
 *
 * انتخابِ فایل = آپلودِ فوری (چندتایی در یک درخواست، بدونِ مرحلهٔ توضیحِ پیش‌از‌آپلود)؛ توضیحِ هر
 * تصویر فقط بعداً از طریقِ دکمهٔ «ویرایش» رویِ همان کارت ثبت/تغییر می‌کند. حذف همیشه منطقی است
 * (IsActive=0 در Backend)؛ تصویرِ حذف‌شده فوراً از همین لیستِ نمایشی کنار می‌رود.
 */
export default function PartyImagesPanel({ partyId, items: initialItems, canManage }: PartyImagesPanelProps) {
    const [images, setImages] = useState<PartyImage[]>(initialItems || []);
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
        const res = await crmApi(`/crm/parties/${partyId}/images`);
        if (res.ok && res.success) setImages(res.items || []);
    };

    const handleFilesSelected = async (fileList: FileList | null) => {
        if (!fileList || fileList.length === 0) return;
        const files = Array.from(fileList);

        if (files.length > MAX_FILES) {
            setError(`در هر بار حداکثر ${MAX_FILES} تصویر قابلِ افزودن است.`);
            return;
        }
        for (const f of files) {
            const problem = checkFile(f);
            if (problem) {
                setError(problem);
                return;
            }
        }

        setError(null);
        setUploading(true);
        const form = new FormData();
        files.forEach((f) => form.append('images[]', f));
        const res = await crmApi(`/crm/parties/${partyId}/images`, 'POST', form);
        setUploading(false);

        if (!res.ok || !res.success) {
            notify('error', res.status === 413 ? 'حجمِ مجموعِ تصاویر از سقفِ مجازِ سرور بیشتر است.' : res.message);
            return;
        }
        notify('success', res.message);
        await reload();
    };

    const handleDelete = async (imageId: number) => {
        setBusyId(imageId);
        const res = await crmApi(`/crm/party-images/${imageId}/delete`, 'POST');
        setBusyId(null);
        notify(res.success ? 'success' : 'error', res.message);
        if (res.success) setImages((list) => list.filter((img) => img.PartyImageID !== imageId));
    };

    const startEdit = (img: PartyImage) => {
        setEditingId(img.PartyImageID);
        setEditValue(img.Description || '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditValue('');
    };

    const saveEdit = async (imageId: number) => {
        if (editValue.length > MAX_DESCRIPTION) {
            notify('error', `توضیحات حداکثر ${MAX_DESCRIPTION} کاراکتر است.`);
            return;
        }
        setSavingEdit(true);
        const res = await crmApi(`/crm/party-images/${imageId}/description`, 'POST', { description: editValue.trim() || null });
        setSavingEdit(false);
        notify(res.success ? 'success' : 'error', res.message);
        if (res.success) {
            setImages((list) => list.map((img) => (img.PartyImageID === imageId ? { ...img, Description: editValue.trim() || null } : img)));
            cancelEdit();
        }
    };

    return (
        <div>
            <Space direction="vertical" style={{ width: '100%' }} size={12}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <Text strong>
                        <PictureOutlined style={{ marginInlineEnd: 6, color: '#667eea' }} />
                        تصاویرِ طرف‌حساب
                    </Text>
                    {canManage ? (
                        <>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                multiple
                                style={{ display: 'none' }}
                                onChange={(e) => {
                                    handleFilesSelected(e.target.files);
                                    e.target.value = '';
                                }}
                            />
                            <Button type="primary" size="small" icon={<PlusOutlined />} loading={uploading} onClick={() => fileInputRef.current?.click()}>
                                افزودنِ تصویر
                            </Button>
                        </>
                    ) : null}
                </div>

                {error ? <Text type="danger" style={{ fontSize: 12 }}>{error}</Text> : null}
                <Text type="secondary" style={{ fontSize: 12 }}>
                    فرمت‌هایِ مجاز: PNG، JPG، WebP — حداکثر ۱ مگابایت برایِ هر تصویر. توضیح برایِ هر تصویر بعداً از دکمهٔ «ویرایش» قابلِ ثبت است.
                </Text>

                {uploading ? <Spin size="small" /> : null}

                {images.length === 0 ? (
                    <Empty description="هنوز تصویری برایِ این طرف‌حساب ثبت نشده است" />
                ) : (
                    <div className="crm-party-images-row">
                        <Image.PreviewGroup>
                            {images.map((img) => (
                                <div key={img.PartyImageID} className="crm-party-image-cell">
                                    <Image
                                        src={img.ImageUrl}
                                        width={160}
                                        height={160}
                                        style={{ objectFit: 'cover', borderRadius: 8 }}
                                    />
                                    <div className="crm-party-image-info">
                                        {editingId === img.PartyImageID ? (
                                            <Space direction="vertical" size={4} style={{ width: '100%' }}>
                                                <Input.TextArea
                                                    size="small"
                                                    rows={2}
                                                    maxLength={MAX_DESCRIPTION}
                                                    value={editValue}
                                                    disabled={savingEdit}
                                                    onChange={(e) => setEditValue(e.target.value)}
                                                    placeholder="توضیحِ تصویر"
                                                />
                                                <Space size={4}>
                                                    <Button size="small" type="primary" icon={<CheckOutlined />} loading={savingEdit} onClick={() => saveEdit(img.PartyImageID)}>ذخیره</Button>
                                                    <Button size="small" icon={<CloseOutlined />} disabled={savingEdit} onClick={cancelEdit}>انصراف</Button>
                                                </Space>
                                            </Space>
                                        ) : (
                                            <>
                                                <Text className="crm-party-image-desc" ellipsis={{ tooltip: img.Description || undefined }}>
                                                    {img.Description || <Text type="secondary">بدونِ توضیح</Text>}
                                                </Text>
                                                <Text type="secondary" className="crm-party-image-meta">
                                                    {img.CreatedByName || '—'}
                                                </Text>
                                                <Text type="secondary" className="crm-party-image-meta">
                                                    {img.Date_InsertFirst ? gregorianToJalaliDisplay(img.Date_InsertFirst) : '—'}
                                                </Text>
                                            </>
                                        )}
                                    </div>
                                    {canManage && editingId !== img.PartyImageID ? (
                                        <Space size={4} className="crm-party-image-actions">
                                            <Tooltip title="ویرایشِ توضیحات">
                                                <Button type="primary" size="small" shape="circle" icon={<EditOutlined />} onClick={() => startEdit(img)} />
                                            </Tooltip>
                                            <Popconfirm title="این تصویر حذف شود؟" onConfirm={() => handleDelete(img.PartyImageID)} okText="بله" cancelText="خیر">
                                                <Button
                                                    danger
                                                    type="primary"
                                                    size="small"
                                                    shape="circle"
                                                    loading={busyId === img.PartyImageID}
                                                    icon={<DeleteOutlined />}
                                                />
                                            </Popconfirm>
                                        </Space>
                                    ) : null}
                                </div>
                            ))}
                        </Image.PreviewGroup>
                    </div>
                )}
            </Space>

            <style>{`
                .crm-party-images-row {
                    display: flex;
                    direction: rtl;
                    flex-direction: row;
                    gap: 14px;
                    overflow-x: auto;
                    padding-bottom: 8px;
                }
                .crm-party-image-cell {
                    position: relative;
                    flex: 0 0 auto;
                    width: 200px;
                    border: 1px solid #f0f0f0;
                    border-radius: 10px;
                    padding: 10px;
                    background: #fff;
                }
                .crm-party-image-info {
                    margin-top: 8px;
                    min-height: 56px;
                }
                .crm-party-image-desc {
                    display: block;
                    font-size: 13px;
                }
                .crm-party-image-meta {
                    display: block;
                    font-size: 11px;
                }
                .crm-party-image-actions {
                    position: absolute;
                    top: 16px;
                    inset-inline-start: 16px;
                }
            `}</style>

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
