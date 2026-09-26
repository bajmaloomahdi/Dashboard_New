import { useEffect, useRef, useState } from 'react';
import { Modal, Form, Input, Button, Alert, Upload, Space, Typography } from 'antd';
import { SaveOutlined, CloseOutlined, UploadOutlined, DeleteOutlined, TagsOutlined, UndoOutlined } from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';

export interface Brand {
    BrandID: number;
    Name: string;
    Description: string | null;
    IsActive: boolean | number | string;
    /** آدرسِ Routeِ محافظت‌شدهٔ لوگو (crm/brands/{id}/logo?v=…) — مسیرِ فیزیکی هرگز به Frontend نمی‌آید */
    LogoUrl?: string | null;
}

interface BrandFormModalProps {
    open: boolean;
    onClose: () => void;
    editingBrand: Brand | null;
    onSuccess: (message: string) => void;
}

const emptyValues = { name: '', description: '' };

/** همان قیودِ Backend (CrmBrandLogo) — بررسیِ اولیه در مرورگر؛ بررسیِ قطعی در سرور انجام می‌شود. */
const LOGO_TYPES: Record<string, string> = { png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', webp: 'image/webp' };
const LOGO_MAX_BYTES = 1024 * 1024;
const LOGO_MAX_DIMENSION = 4000;

function readImageSize(url: string): Promise<{ width: number; height: number } | null> {
    return new Promise((resolve) => {
        const img = new Image();
        img.onload = () => resolve({ width: img.naturalWidth, height: img.naturalHeight });
        img.onerror = () => resolve(null);
        img.src = url;
    });
}

/**
 * فرمِ ایجاد/ویرایشِ برند (CrmBrands) — موجودیتِ مستقل که از طریقِ CrmPartyBrands به
 * یک یا چند طرف‌حساب متصل می‌شود. نامِ برند یکتاست. لوگو (اختیاری) در همین فرم انتخاب/پیش‌نمایش/
 * جایگزینی/حذف می‌شود و فقط با «ذخیره» اعمال می‌شود.
 */
export default function BrandFormModal({ open, onClose, editingBrand, onSuccess }: BrandFormModalProps) {
    const isEdit = !!editingBrand;
    const [values, setValues] = useState(emptyValues);
    const [processing, setProcessing] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

    // لوگو: فایلِ انتخاب‌شدهٔ جدید (+ پیش‌نمایشِ محلی) یا درخواستِ حذفِ لوگویِ فعلی
    const [logoFile, setLogoFile] = useState<File | null>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [logoError, setLogoError] = useState<string | null>(null);
    const previewRef = useRef<string | null>(null);

    const setPreview = (url: string | null) => {
        if (previewRef.current) URL.revokeObjectURL(previewRef.current);
        previewRef.current = url;
        setLogoPreview(url);
    };

    useEffect(() => () => {
        if (previewRef.current) URL.revokeObjectURL(previewRef.current);
    }, []);

    useEffect(() => {
        if (!open) return;

        setValues(editingBrand ? { name: editingBrand.Name || '', description: editingBrand.Description || '' } : emptyValues);
        setFormError(null);
        setFieldErrors({});
        setLogoFile(null);
        setPreview(null);
        setRemoveLogo(false);
        setLogoError(null);
    }, [open, editingBrand]);

    const currentLogoUrl = editingBrand?.LogoUrl || null;
    const shownLogo = logoPreview ?? (!removeLogo ? currentLogoUrl : null);

    const pickLogo = async (file: File) => {
        setLogoError(null);

        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!LOGO_TYPES[ext] || (file.type && file.type !== LOGO_TYPES[ext])) {
            setLogoError('فرمتِ لوگو باید PNG، JPG یا WebP باشد (SVG مجاز نیست).');
            return;
        }
        if (file.size > LOGO_MAX_BYTES) {
            setLogoError('حجمِ لوگو نباید بیشتر از ۱ مگابایت باشد.');
            return;
        }

        const url = URL.createObjectURL(file);
        const size = await readImageSize(url);
        if (!size) {
            URL.revokeObjectURL(url);
            setLogoError('فایلِ انتخاب‌شده یک تصویرِ معتبر نیست.');
            return;
        }
        if (size.width > LOGO_MAX_DIMENSION || size.height > LOGO_MAX_DIMENSION) {
            URL.revokeObjectURL(url);
            setLogoError('ابعادِ لوگو نباید بیشتر از ۴۰۰۰×۴۰۰۰ پیکسل باشد.');
            return;
        }

        setLogoFile(file);
        setPreview(url);
        setRemoveLogo(false);
    };

    const clearLogo = () => {
        setLogoError(null);
        if (logoFile) {
            // انصراف از فایلِ تازه‌انتخاب‌شده → برگشت به لوگویِ فعلی (در صورتِ وجود)
            setLogoFile(null);
            setPreview(null);
            return;
        }
        setRemoveLogo(true);
    };

    const handleClose = () => {
        if (processing) return;
        onClose();
    };

    const handleSubmit = async () => {
        if (!values.name.trim()) {
            setFormError('نامِ برند الزامی است.');
            return;
        }

        setProcessing(true);
        setFormError(null);
        setFieldErrors({});

        const form = new FormData();
        if (editingBrand) form.append('brandId', String(editingBrand.BrandID));
        form.append('name', values.name.trim());
        if (values.description.trim()) form.append('description', values.description.trim());
        if (logoFile) form.append('logo', logoFile);
        else if (removeLogo && currentLogoUrl) form.append('removeLogo', '1');

        const res = await crmApi('/crm/brands', 'POST', form);

        setProcessing(false);

        if (!res.ok || !res.success) {
            if (res.errors && typeof res.errors === 'object' && !Array.isArray(res.errors)) {
                const mapped: Record<string, string> = {};
                Object.entries(res.errors as Record<string, string[]>).forEach(([field, messages]) => {
                    mapped[field] = Array.isArray(messages) ? messages[0] : String(messages);
                });
                setFieldErrors(mapped);
            }
            setFormError(res.message);
            return;
        }

        onSuccess(res.message);
    };

    return (
        <Modal
            title={isEdit ? 'ویرایشِ برند' : 'برندِ جدید'}
            open={open}
            onCancel={handleClose}
            width={520}
            footer={[
                <Button key="cancel" icon={<CloseOutlined />} onClick={handleClose} disabled={processing}>
                    انصراف
                </Button>,
                <Button key="submit" type="primary" icon={<SaveOutlined />} loading={processing} onClick={handleSubmit}>
                    {isEdit ? 'ذخیرهٔ تغییرات' : 'ایجادِ برند'}
                </Button>,
            ]}
            destroyOnClose
        >
            <style>{`
                .brand-form-logo {
                    width: 200px; height: 90px; max-width: 100%;
                    display: flex; align-items: center; justify-content: center;
                    border: 1px dashed #d9d9d9; border-radius: 8px; background: #fafafa; overflow: hidden;
                }
                .brand-form-logo img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
                .brand-form-logo .anticon { font-size: 30px; color: #bfbfbf; }
            `}</style>

            {formError ? <Alert type="error" showIcon message={formError} style={{ marginBottom: 16, borderRadius: 8 }} /> : null}

            <Form layout="vertical" requiredMark>
                <Form.Item label="نامِ برند" required validateStatus={fieldErrors.name ? 'error' : ''} help={fieldErrors.name}>
                    <Input value={values.name} maxLength={200} onChange={(e) => setValues((s) => ({ ...s, name: e.target.value }))} size="large" />
                </Form.Item>

                <Form.Item label="لوگو" validateStatus={logoError || fieldErrors.logo ? 'error' : ''} help={logoError || fieldErrors.logo}>
                    <Space align="center" size={16} wrap>
                        <div className="brand-form-logo">
                            {shownLogo ? <img src={shownLogo} alt="لوگویِ برند" /> : <TagsOutlined />}
                        </div>
                        <Space direction="vertical" size={8}>
                            <Upload
                                accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                                showUploadList={false}
                                maxCount={1}
                                beforeUpload={(file) => {
                                    void pickLogo(file);
                                    return false; // آپلود فقط همراهِ «ذخیره»
                                }}
                                disabled={processing}
                            >
                                <Button icon={<UploadOutlined />} disabled={processing}>
                                    {shownLogo ? 'جایگزینیِ لوگو' : 'انتخابِ لوگو'}
                                </Button>
                            </Upload>
                            {shownLogo ? (
                                <Button danger icon={<DeleteOutlined />} onClick={clearLogo} disabled={processing}>
                                    {logoFile ? 'انصراف از این فایل' : 'حذفِ لوگو'}
                                </Button>
                            ) : removeLogo && currentLogoUrl ? (
                                <Button icon={<UndoOutlined />} onClick={() => setRemoveLogo(false)} disabled={processing}>
                                    بازگرداندنِ لوگویِ فعلی
                                </Button>
                            ) : null}
                        </Space>
                    </Space>
                    <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 8 }}>
                        PNG، JPG یا WebP — حداکثر ۱ مگابایت و ۴۰۰۰×۴۰۰۰ پیکسل. تغییرات با «ذخیره» اعمال می‌شود.
                        {removeLogo && currentLogoUrl && !logoFile ? ' لوگویِ فعلی با ذخیره حذف می‌شود.' : ''}
                    </Typography.Text>
                </Form.Item>

                <Form.Item label="توضیحات">
                    <Input.TextArea value={values.description} onChange={(e) => setValues((s) => ({ ...s, description: e.target.value }))} rows={3} maxLength={2000} showCount />
                </Form.Item>
            </Form>
        </Modal>
    );
}
