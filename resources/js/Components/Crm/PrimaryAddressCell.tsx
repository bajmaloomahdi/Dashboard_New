import { Typography } from 'antd';

const { Text } = Typography;

interface PrimaryAddressCellProps {
    province?: string | null;
    /** شهرستان (پچِ ۱۱۸؛ فعلاً فقط sp_Crm_GetParties) */
    county?: string | null;
    city?: string | null;
    text?: string | null;
    title?: string | null;
}

/** آدرسِ اصلی (PrimaryAddress* از sp_Crm_GetParties / sp_Crm_GetPersons) در یک خانهٔ جدول */
export default function PrimaryAddressCell({ province, county, city, text, title }: PrimaryAddressCellProps) {
    const place = [province ? `استان ${province}` : '', county ? `شهرستان ${county}` : '', city ? `شهر ${city}` : ''].filter(Boolean).join('، ');
    const body = (text || '').trim();
    if (!place && !body) return <Text type="secondary">—</Text>;

    return (
        <div style={{ minWidth: 0 }}>
            <Text style={{ fontSize: 12 }} type="secondary">
                {title ? `${title} — ` : ''}{place}
            </Text>
            {body ? (
                <Text style={{ display: 'block', fontSize: 13 }} ellipsis={{ tooltip: body }}>
                    {body}
                </Text>
            ) : null}
        </div>
    );
}
