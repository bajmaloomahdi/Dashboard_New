import { useRef, useState } from 'react';
import { Button, Select, Space, Typography, Alert, theme } from 'antd';
import { EditOutlined, SaveOutlined, CloseOutlined } from '@ant-design/icons';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';

const { Text } = Typography;

export interface ProjectOwner {
    ProjectID: number;
    OwnerPartyID: number | null;
    OwnerPartyName: string | null;
    OwnerBrandID: number | null;
    OwnerBrandName: string | null;
}

export interface BrandOption {
    BrandID: number;
    Name: string;
}

interface PartyCandidate {
    PartyID: number;
    OfficialName: string | null;
    IdentifierNumber: string | null;
}

interface ProjectOwnerTabProps {
    projectId: number;
    owner: ProjectOwner | null;
    /** همهٔ برندهایِ فعال — وقتی طرف‌حساب انتخاب نشده (پروژهٔ داخلی) */
    allBrands: BrandOption[];
    canManage: boolean;
}

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function getJson(url: string): Promise<any> {
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' });
    return res.json().catch(() => ({}));
}

const partyLabel = (p: PartyCandidate) => [p.OfficialName || `#${p.PartyID}`, p.IdentifierNumber].filter(Boolean).join(' — ');

/**
 * تبِ «مالکِ پروژه»: طرف‌حساب (اختیاری) و برند. طرف‌حسابِ خالی = پروژهٔ داخلی و همهٔ برندها قابلِ
 * انتخاب‌اند؛ با انتخابِ طرف‌حساب فقط برندهایِ همان طرف‌حساب نمایش داده می‌شوند.
 */
export default function ProjectOwnerTab({ projectId, owner: initialOwner, allBrands, canManage }: ProjectOwnerTabProps) {
    const [owner, setOwner] = useState<ProjectOwner | null>(initialOwner);
    const [editing, setEditing] = useState(false);
    const [partyId, setPartyId] = useState<number | null>(null);
    const [brandId, setBrandId] = useState<number | null>(null);
    const [partyOptions, setPartyOptions] = useState<{ value: number; label: string }[]>([]);
    const [partyBrands, setPartyBrands] = useState<BrandOption[]>([]);
    const [searching, setSearching] = useState(false);
    const [brandsLoading, setBrandsLoading] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const searchTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    const searchParties = async (text: string, keep?: { value: number; label: string } | null) => {
        setSearching(true);
        try {
            const data = await getJson(`/projects/${projectId}/contractors/search?search=${encodeURIComponent(text)}`);
            const list: { value: number; label: string }[] = (data.parties || []).map((p: PartyCandidate) => ({ value: p.PartyID, label: partyLabel(p) }));
            if (keep && !list.some((o) => o.value === keep.value)) list.unshift(keep);
            setPartyOptions(list);
        } finally {
            setSearching(false);
        }
    };

    const loadPartyBrands = async (id: number | null) => {
        if (id === null) {
            setPartyBrands([]);
            return;
        }
        setBrandsLoading(true);
        try {
            const data = await getJson(`/projects/${projectId}/owner/party-brands?partyId=${id}`);
            setPartyBrands(data.brands || []);
        } finally {
            setBrandsLoading(false);
        }
    };

    const startEdit = () => {
        const currentParty = owner?.OwnerPartyID ?? null;
        setPartyId(currentParty);
        setBrandId(owner?.OwnerBrandID ?? null);
        setError(null);
        setEditing(true);
        const keep = currentParty !== null ? { value: currentParty, label: owner?.OwnerPartyName || `#${currentParty}` } : null;
        searchParties('', keep);
        loadPartyBrands(currentParty);
    };

    const onPartyChange = (value: number | undefined) => {
        const id = value ?? null;
        setPartyId(id);
        setBrandId(null);
        loadPartyBrands(id);
    };

    const onPartySearch = (text: string) => {
        if (searchTimer.current) clearTimeout(searchTimer.current);
        const selected = partyOptions.find((o) => o.value === partyId) ?? null;
        searchTimer.current = setTimeout(() => searchParties(text, selected), 350);
    };

    const brandOptions = (() => {
        const list = (partyId === null ? allBrands : partyBrands).map((b) => ({ value: b.BrandID, label: b.Name }));
        // برندِ ذخیره‌شدهٔ فعلی (اگر در فهرستِ فعلی نیست، مثلاً غیرفعال شده) تا ویرایش آن را پاک نکند
        if (brandId !== null && owner?.OwnerBrandID === brandId && !list.some((o) => o.value === brandId)) {
            list.push({ value: brandId, label: owner?.OwnerBrandName || `#${brandId}` });
        }
        return list;
    })();

    const handleSave = async () => {
        setSaving(true);
        setError(null);
        try {
            const res = await fetch(`/projects/${projectId}/owner`, {
                method: 'POST',
                headers: { 'X-XSRF-TOKEN': getXsrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/json', Accept: 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ OwnerPartyID: partyId, OwnerBrandID: brandId }),
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok || !data.success) {
                setError(data.message || 'ذخیرهٔ مالکِ پروژه ناموفق بود.');
                return;
            }
            setOwner(data.owner ?? null);
            setEditing(false);
            setNotification({ open: true, type: 'success', message: data.message });
        } finally {
            setSaving(false);
        }
    };

    const { token } = theme.useToken();

    return (
        <section aria-label="مالک پروژه" style={{
            padding: token.padding, marginBottom: token.margin,
            background: token.colorPrimaryBg, border: `1px solid ${token.colorPrimaryBorder}`,
            borderRadius: token.borderRadiusLG,
        }}>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 12, marginBottom: 12 }}>
                <Text strong style={{ color: token.colorPrimaryText }}>مالک پروژه</Text>
                {canManage && !editing && <Button icon={<EditOutlined />} onClick={startEdit}>ویرایش مالک</Button>}
            </div>
            {editing ? (
                <Space direction="vertical" style={{ width: '100%' }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    {/* flex به‌جایِ Space تا در موبایل هر فیلد تا عرضِ صفحه کوچک شود (دسکتاپ همان 320/240px) */}
                    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'flex-start', gap: 16 }}>
                        <div style={{ flex: '0 1 320px', minWidth: 0 }}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>طرف‌حساب</Text>
                            <Select
                                allowClear
                                showSearch
                                virtual={false}
                                filterOption={false}
                                style={{ width: '100%' }}
                                placeholder="انتخاب نشده (پروژهٔ داخلی)"
                                value={partyId ?? undefined}
                                onChange={onPartyChange}
                                onSearch={onPartySearch}
                                loading={searching}
                                options={partyOptions}
                                notFoundContent={searching ? 'در حالِ جست‌وجو...' : 'طرف‌حسابی یافت نشد'}
                            />
                        </div>
                        <div style={{ flex: '0 1 240px', minWidth: 0 }}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>برند</Text>
                            <Select
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                style={{ width: '100%' }}
                                placeholder="انتخابِ برند"
                                value={brandId ?? undefined}
                                onChange={(v) => setBrandId(v ?? null)}
                                loading={brandsLoading}
                                options={brandOptions}
                                notFoundContent={partyId === null ? 'برندی تعریف نشده است' : 'برایِ این طرف‌حساب برندی ثبت نشده است'}
                            />
                        </div>
                    </div>
                    <Space>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                        <Button icon={<CloseOutlined />} disabled={saving} onClick={() => setEditing(false)}>انصراف</Button>
                    </Space>
                </Space>
            ) : (
                <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16 }}>
                    <div style={{ flex: '1 1 200px', minWidth: 0, overflowWrap: 'anywhere' }}>
                        <Text type="secondary" style={{ display: 'block', marginBottom: 4 }}>طرف‌حساب</Text>
                        {owner?.OwnerPartyID
                            ? <a href={`/crm/parties/${owner.OwnerPartyID}?tab=interactions`}>{owner.OwnerPartyName || `#${owner.OwnerPartyID}`}</a>
                            : <Text>انتخاب نشده (پروژهٔ داخلی)</Text>}
                    </div>
                    <div style={{ flex: '1 1 150px', minWidth: 0, overflowWrap: 'anywhere' }}>
                        <Text type="secondary" style={{ display: 'block', marginBottom: 4 }}>برند</Text>
                        <Text>{owner?.OwnerBrandID ? owner.OwnerBrandName || `#${owner.OwnerBrandID}` : '—'}</Text>
                    </div>
                </div>
            )}

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </section>
    );
}
