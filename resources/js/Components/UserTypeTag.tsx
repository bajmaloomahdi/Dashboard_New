import { Tag, Tooltip } from 'antd';
import { BankOutlined, ContactsOutlined, HomeOutlined } from '@ant-design/icons';

/** نوعِ کاربر (پچِ ۱۲۱): داخلی، مخاطب (CrmPersons) یا طرف‌حساب (CrmParties) */
export type UserType = 'INTERNAL' | 'PERSON' | 'PARTY';

export const USER_TYPE_META: Record<UserType, { label: string; color: string; icon: JSX.Element }> = {
    INTERNAL: { label: 'کاربرِ داخلی', color: 'blue', icon: <HomeOutlined /> },
    PERSON: { label: 'مخاطب', color: 'purple', icon: <ContactsOutlined /> },
    PARTY: { label: 'طرف‌حساب', color: 'gold', icon: <BankOutlined /> },
};

export const USER_TYPE_OPTIONS = (Object.keys(USER_TYPE_META) as UserType[]).map((v) => ({ value: v, label: USER_TYPE_META[v].label }));

export const normalizeUserType = (v?: string | null): UserType => (v === 'PERSON' || v === 'PARTY' ? v : 'INTERNAL');

/** برچسبِ نوع؛ نامِ رکوردِ متصل (کاربرِ داخلی هم ممکن است مخاطب داشته باشد — پچِ ۱۲۲) کنارش می‌آید */
export default function UserTypeTag({ type, linkName }: { type?: string | null; linkName?: string | null }) {
    const meta = USER_TYPE_META[normalizeUserType(type)];
    const tag = (
        <Tag color={meta.color} icon={meta.icon} style={{ marginInlineEnd: 0, maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            {meta.label}{linkName ? `: ${linkName}` : ''}
        </Tag>
    );

    return linkName ? <Tooltip title={`${meta.label}: ${linkName}`}>{tag}</Tooltip> : tag;
}
