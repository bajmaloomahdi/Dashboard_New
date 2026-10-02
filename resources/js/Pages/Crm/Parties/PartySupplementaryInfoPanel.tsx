import { useState } from 'react';
import { Button, Descriptions, InputNumber, Select, Space, Tag, Typography, Alert } from 'antd';
import { ProfileOutlined, EditOutlined, SaveOutlined, CloseOutlined } from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

/** یک ردیفِ CrmPartySupplementaryInfo (۱:۱ با طرف‌حساب) — فیلدهایِ تکمیلیِ آینده هم به همین شکل اضافه می‌شوند. */
export interface PartySupplementaryInfo {
    PartyID: number;
    OwnershipTypeID: number | null;
    OwnershipTypeName: string | null;
    OwnershipTypeIsActive: boolean | number | string | null;
    /** DECIMAL(12,2) — از SQL Server به‌صورتِ رشته می‌رسد */
    AreaSqm: string | number | null;
    ModifiedAt: string | null;
    ModifiedByName: string | null;
}

export interface OwnershipTypeOption {
    OwnershipTypeID: number;
    DisplayName: string;
}

interface PartySupplementaryInfoPanelProps {
    partyId: number;
    info: PartySupplementaryInfo | null;
    /** فقط نوع‌هایِ فعال (Master Data) — هیچ گزینه‌ای در کد Hard-code نشده است */
    ownershipTypes: OwnershipTypeOption[];
    canManage: boolean;
}

const AREA_MAX = 9999999999.99;

const formatArea = (v: string | number | null) =>
    v === null || v === '' ? null : `${Number(v).toLocaleString('en-US', { maximumFractionDigits: 2 })} متر مربع`;

/**
 * کادرِ «اطلاعاتِ تکمیلیِ طرف‌حساب» در تبِ «ضمائم و سایر ویژگی‌ها» — محلِ نگهداریِ فیلدهایِ تکمیلی
 * (فعلاً فقط نوعِ مالکیت و متراژ). مشاهده با CRM_VIEW، ویرایش فقط وقتی canManage (CRM_MANAGE_PARTIES).
 */
export default function PartySupplementaryInfoPanel({ partyId, info: initialInfo, ownershipTypes, canManage }: PartySupplementaryInfoPanelProps) {
    const [info, setInfo] = useState<PartySupplementaryInfo | null>(initialInfo);
    const [editing, setEditing] = useState(false);
    const [ownershipTypeId, setOwnershipTypeId] = useState<number | null>(null);
    const [areaSqm, setAreaSqm] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const currentTypeInactive = info?.OwnershipTypeID != null && !toBool(info.OwnershipTypeIsActive);

    /** نوع‌هایِ فعال + (اگر روی همین طرف‌حساب ثبت است) نوعِ فعلیِ غیرفعال، تا ویرایش آن را پاک نکند */
    const typeOptions = (() => {
        const list = ownershipTypes.map((t) => ({ value: t.OwnershipTypeID, label: t.DisplayName }));
        if (info?.OwnershipTypeID != null && !list.some((o) => o.value === info.OwnershipTypeID)) {
            list.push({ value: info.OwnershipTypeID, label: `${info.OwnershipTypeName ?? `#${info.OwnershipTypeID}`} (غیرفعال)` });
        }
        return list;
    })();

    const startEdit = () => {
        setOwnershipTypeId(info?.OwnershipTypeID ?? null);
        setAreaSqm(info?.AreaSqm === null || info?.AreaSqm === undefined || info?.AreaSqm === '' ? null : Number(info.AreaSqm));
        setError(null);
        setEditing(true);
    };

    const handleSave = async () => {
        if (areaSqm !== null && (areaSqm < 0 || areaSqm > AREA_MAX)) return setError('متراژ باید عددی بین ۰ و حداکثرِ مجاز باشد.');

        setSaving(true);
        setError(null);
        const res = await crmApi(`/crm/parties/${partyId}/supplementary-info`, 'POST', {
            ownershipTypeId,
            areaSqm,
        });
        setSaving(false);

        if (!res.ok || !res.success) return setError(res.message);

        setInfo(res.item ?? null);
        setEditing(false);
        setNotification({ open: true, type: 'success', message: res.message });
    };

    return (
        <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 12 }}>
                <Text strong>
                    <ProfileOutlined style={{ marginInlineEnd: 6, color: '#667eea' }} />
                    اطلاعاتِ تکمیلیِ طرف‌حساب
                </Text>
                {canManage && !editing ? (
                    <Button size="small" icon={<EditOutlined />} onClick={startEdit}>ویرایش</Button>
                ) : null}
            </div>

            {editing ? (
                <Space direction="vertical" style={{ width: '100%' }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    <Space wrap align="start" size={16}>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوعِ مالکیت</Text>
                            <Select
                                allowClear
                                style={{ width: 220 }}
                                placeholder="انتخابِ نوعِ مالکیت"
                                value={ownershipTypeId ?? undefined}
                                onChange={(v) => setOwnershipTypeId(v ?? null)}
                                options={typeOptions}
                                notFoundContent="هیچ نوعِ مالکیتِ فعالی تعریف نشده است"
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>متراژ</Text>
                            <InputNumber
                                style={{ width: 220 }}
                                min={0}
                                max={AREA_MAX}
                                precision={2}
                                placeholder="مثلاً 120"
                                value={areaSqm}
                                onChange={(v) => setAreaSqm(typeof v === 'number' ? v : null)}
                                addonAfter="متر مربع"
                            />
                        </div>
                    </Space>
                    <Space>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                        <Button icon={<CloseOutlined />} disabled={saving} onClick={() => setEditing(false)}>انصراف</Button>
                    </Space>
                </Space>
            ) : (
                <>
                    <Descriptions column={{ xs: 1, sm: 2 }} size="small">
                        <Descriptions.Item label="نوعِ مالکیت">
                            {info?.OwnershipTypeName ? (
                                <Space size={6}>
                                    <span>{info.OwnershipTypeName}</span>
                                    {currentTypeInactive ? <Tag style={{ borderRadius: 6, margin: 0 }}>غیرفعال</Tag> : null}
                                </Space>
                            ) : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="متراژ">{formatArea(info?.AreaSqm ?? null) ?? '—'}</Descriptions.Item>
                    </Descriptions>
                    {info?.ModifiedAt ? (
                        <Text type="secondary" style={{ fontSize: 12 }}>
                            آخرین ویرایش: {info.ModifiedByName || '—'} — {gregorianToJalaliDisplay(info.ModifiedAt)}
                        </Text>
                    ) : (
                        <Text type="secondary" style={{ fontSize: 12 }}>هنوز اطلاعاتِ تکمیلی‌ای ثبت نشده است.</Text>
                    )}
                </>
            )}

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
