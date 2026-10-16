import { useState } from 'react';
import { Button, Popconfirm } from 'antd';
import { DeleteOutlined } from '@ant-design/icons';
import { crmApi } from './crmApi';

/** slugهایِ مسیرِ POST crm/delete/{entity}/{id} (هم‌نام با CrmDeleteController::ENTITIES) */
export type CrmDeleteEntity =
    | 'party' | 'person' | 'brand' | 'party-brand-category' | 'address' | 'contact' | 'relation'
    | 'classification' | 'interaction' | 'product-category' | 'department' | 'party-type' | 'activity' | 'title'
    | 'position' | 'contact-role' | 'contact-type' | 'address-title' | 'interaction-type' | 'ownership-type'
    | 'province' | 'county' | 'city' | 'neighborhood' | 'municipal-zone';

interface CrmDeleteButtonProps {
    entity: CrmDeleteEntity;
    id: number | string;
    /** برچسبِ نوعِ رکورد در پیامِ تأیید، مثلاً «آدرس» */
    label: string;
    /** نتیجهٔ حذف (موفق یا پیامِ وابستگی/خطا) — والد با NotificationModalِ خودش نمایش می‌دهد و در صورتِ موفقیت Reload می‌کند */
    onDone: (success: boolean, message: string) => void;
    /** متنِ دکمه؛ بدونِ آن فقط آیکن */
    text?: string;
    size?: 'small' | 'middle';
}

/**
 * دکمهٔ حذفِ عمومیِ CRM (دسترسیِ CRM_DELETE) با تأیید. والد فقط وقتی canDelete=true است آن را رندر می‌کند؛
 * سرور هم دسترسی و وابستگی‌ها را دوباره بررسی می‌کند.
 */
export default function CrmDeleteButton({ entity, id, label, onDone, text, size = 'small' }: CrmDeleteButtonProps) {
    const [loading, setLoading] = useState(false);

    const handleDelete = async () => {
        setLoading(true);
        const res = await crmApi(`/crm/delete/${entity}/${id}`, 'POST');
        setLoading(false);
        onDone(res.ok && res.success, res.message);
    };

    return (
        <Popconfirm
            title={`حذفِ ${label}`}
            description="این عملیات قابلِ بازگشت نیست. ادامه می‌دهید؟"
            onConfirm={handleDelete}
            okText="حذف"
            cancelText="انصراف"
            okButtonProps={{ danger: true }}
        >
            <Button
                type={text ? 'default' : 'text'}
                size={size}
                danger
                loading={loading}
                icon={<DeleteOutlined />}
                title={`حذفِ ${label}`}
                onClick={(e) => e.stopPropagation()}
            >
                {text}
            </Button>
        </Popconfirm>
    );
}
