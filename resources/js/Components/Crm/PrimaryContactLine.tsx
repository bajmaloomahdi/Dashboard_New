import type { ReactNode } from 'react';
import { Space, Typography } from 'antd';
import { MobileOutlined, PhoneOutlined, MailOutlined, GlobalOutlined, ContactsOutlined, PrinterOutlined, UserOutlined } from '@ant-design/icons';

const { Text } = Typography;

interface PrimaryContactLineProps {
    value?: string | null;
    type?: string | null;
    extension?: string | null;
    /** فقط کارتِ طرف‌حساب: نامِ مخاطبِ این تماس (یا «مخاطبِ اصلی»ِ طرف‌حساب) — بالایِ تماس نمایش داده می‌شود */
    personName?: string | null;
}

const ICONS: Record<string, ReactNode> = {
    'موبایل': <MobileOutlined />,
    'تلفن': <PhoneOutlined />,
    'فکس': <PrinterOutlined />,
    'ایمیل': <MailOutlined />,
    'وب‌سایت': <GlobalOutlined />,
};

/**
 * تماسِ اصلیِ یک طرف‌حساب/مخاطب رویِ کارتِ فهرست (PrimaryContact* از sp_Crm_GetParties / sp_Crm_GetPersons).
 * موبایل/تلفن → لینکِ tel:، ایمیل → mailto:؛ کلیک رویِ لینک کارت را باز نمی‌کند.
 */
export default function PrimaryContactLine({ value, type, extension, personName }: PrimaryContactLineProps) {
    const name = (personName || '').trim();
    const contact = <ContactValueLine value={value} type={type} extension={extension} />;
    if (!name) return contact;

    return (
        <div className="crm-primary-contact-block">
            <Space size={6} style={{ display: 'flex' }} title="فرد مرتبط">
                <UserOutlined style={{ color: '#6B7280' }} />
                <Text style={{ fontSize: 13 }} strong>{name}</Text>
            </Space>
            {contact}
        </div>
    );
}

function ContactValueLine({ value, type, extension }: Omit<PrimaryContactLineProps, 'personName'>) {
    const t = (type || '').trim();
    const v = (value || '').trim();
    if (!v) {
        return (
            <Space size={6} className="crm-primary-contact">
                <ContactsOutlined style={{ color: '#bfbfbf' }} />
                <Text type="secondary" style={{ fontSize: 12 }}>تماسِ اصلی ثبت نشده</Text>
            </Space>
        );
    }

    const href = t === 'موبایل' || t === 'تلفن' ? `tel:${v}` : t === 'ایمیل' ? `mailto:${v}` : null;
    const valueNode = href ? (
        <a href={href} dir="ltr" onClick={(e) => e.stopPropagation()} style={{ fontSize: 13 }}>{v}</a>
    ) : (
        <Text dir="ltr" style={{ fontSize: 13 }}>{v}</Text>
    );

    return (
        <Space size={6} className="crm-primary-contact" title={t ? `تماسِ اصلی (${t})` : 'تماسِ اصلی'}>
            <span style={{ color: '#6B7280' }}>{ICONS[t] || <ContactsOutlined />}</span>
            {valueNode}
            {extension ? <Text type="secondary" style={{ fontSize: 12 }}>(داخلی: {extension})</Text> : null}
        </Space>
    );
}
