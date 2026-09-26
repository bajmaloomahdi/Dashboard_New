import { useEffect, useState } from 'react';
import { Table, Button, Input, Select, Space, Tag, Popconfirm, Typography, Alert, Empty, Row, Col } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, EnvironmentOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from '../../../Components/Crm/crmApi';
import AddressLocationPicker from '../../../Components/Crm/AddressLocationPicker';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface Address {
    AddressID: number;
    PartyID: number | null;
    PersonID: number | null;
    AddressTitleID: number;
    AddressTitleName: string;
    ProvinceID: number;
    ProvinceName: string;
    CountyID: number;
    CountyName: string;
    CityID: number;
    CityName: string;
    NeighborhoodID: number | null;
    NeighborhoodName: string | null;
    MunicipalZoneID: number | null;
    MunicipalZoneName: string | null;
    PostalCode: string | null;
    AddressText: string;
    PlateNumber: string | null;
    Unit: string | null;
    Latitude: number | string | null;
    Longitude: number | string | null;
    IsActive: boolean | number | string;
}

interface Opt { value: number; label: string; }

interface AddressesPanelProps {
    /** دقیقاً یکی از partyId یا personId باید مشخص باشد — آدرس یا به طرف‌حساب تعلق دارد یا مستقیماً به مخاطب. */
    partyId?: number;
    personId?: number;
    items: Address[];
    addressTitles: { AddressTitleID: number; DisplayName: string }[];
    provinces: { ProvinceID: number; DisplayName: string }[];
    canManage: boolean;
    /** نمایشِ نقشهٔ انتخابِ موقعیت در فرم (فعلاً فقط آدرسِ طرف‌حساب) */
    showMap?: boolean;
    /** Map Keyِ نقشهٔ وبِ نشان (از .env، از طریقِ Propِ صفحه) */
    neshanMapKey?: string | null;
    /** جست‌وجویِ مکان رویِ نقشه (کلیدِ Service نشان سمتِ سرور تنظیم شده است) */
    neshanSearchEnabled?: boolean;
}

const toCoord = (v: number | string | null | undefined) => (v === null || v === undefined || v === '' ? null : Number(v));

const emptyDraft = {
    id: null as number | null,
    addressTitleId: null as number | null,
    provinceId: null as number | null,
    countyId: null as number | null,
    cityId: null as number | null,
    neighborhoodId: null as number | null,
    municipalZoneId: null as number | null,
    postalCode: '',
    addressText: '',
    plateNumber: '',
    unit: '',
    latitude: null as number | null,
    longitude: null as number | null,
};

/**
 * آدرس‌هایِ یک طرف‌حساب یا یک مخاطب — سلسله‌مراتبِ استان→شهرستان→شهرِ اجباری و محلهٔ اختیاری
 * با Selectِ آبشاری؛ منطقهٔ شهرداری مستقل و اختیاری. موقعیتِ رویِ نقشه (Latitude/Longitude) اختیاری و
 * مکملِ آدرسِ متنی است؛ نقشه فقط وقتی showMap باشد دیده می‌شود، ولی مختصاتِ موجود همیشه بدونِ تغییر
 * پس فرستاده می‌شود تا ذخیره از جایِ دیگر آن را پاک نکند.
 */
export default function AddressesPanel({ partyId, personId, items: initialItems, addressTitles, provinces, canManage, showMap = false, neshanMapKey, neshanSearchEnabled = false }: AddressesPanelProps) {
    const [items, setItems] = useState<Address[]>(initialItems || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft);
    const [countyOptions, setCountyOptions] = useState<Opt[]>([]);
    const [cityOptions, setCityOptions] = useState<Opt[]>([]);
    const [neighborhoodOptions, setNeighborhoodOptions] = useState<Opt[]>([]);
    const [municipalZones, setMunicipalZones] = useState<Opt[]>([]);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        crmApi('/crm/addresses/municipal-zones').then((res) => {
            if (res.ok && res.success) setMunicipalZones((res.items || []).map((z: any) => ({ value: z.MunicipalZoneID, label: z.DisplayName })));
        });
    }, []);

    const loadCounties = async (provinceId: number) => {
        const res = await crmApi(`/crm/addresses/counties?provinceId=${provinceId}`);
        if (res.ok && res.success) setCountyOptions((res.items || []).map((c: any) => ({ value: c.CountyID, label: c.DisplayName })));
    };

    const loadCities = async (countyId: number) => {
        const res = await crmApi(`/crm/addresses/cities?countyId=${countyId}`);
        if (res.ok && res.success) setCityOptions((res.items || []).map((c: any) => ({ value: c.CityID, label: c.DisplayName })));
    };

    const loadNeighborhoods = async (cityId: number) => {
        const res = await crmApi(`/crm/addresses/neighborhoods?cityId=${cityId}`);
        if (res.ok && res.success) setNeighborhoodOptions((res.items || []).map((n: any) => ({ value: n.NeighborhoodID, label: n.DisplayName })));
    };

    const ownerQuery = partyId ? `partyId=${partyId}` : `personId=${personId}`;

    const reload = async () => {
        const res = await crmApi(`/crm/addresses?${ownerQuery}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    const openCreate = () => {
        setDraft(emptyDraft);
        setCountyOptions([]);
        setCityOptions([]);
        setNeighborhoodOptions([]);
        setError(null);
        setFormOpen(true);
    };

    const openEdit = async (row: Address) => {
        setDraft({
            id: row.AddressID,
            addressTitleId: row.AddressTitleID,
            provinceId: row.ProvinceID,
            countyId: row.CountyID,
            cityId: row.CityID,
            neighborhoodId: row.NeighborhoodID,
            municipalZoneId: row.MunicipalZoneID,
            postalCode: row.PostalCode || '',
            addressText: row.AddressText,
            plateNumber: row.PlateNumber || '',
            unit: row.Unit || '',
            latitude: toCoord(row.Latitude),
            longitude: toCoord(row.Longitude),
        });
        await Promise.all([loadCounties(row.ProvinceID), loadCities(row.CountyID), loadNeighborhoods(row.CityID)]);
        setError(null);
        setFormOpen(true);
    };

    const handleSave = async () => {
        if (!draft.addressTitleId || !draft.provinceId || !draft.countyId || !draft.cityId) {
            setError('عنوانِ آدرس، استان، شهرستان و شهر الزامی است.');
            return;
        }
        if (!draft.addressText.trim()) {
            setError('متنِ آدرس الزامی است.');
            return;
        }
        if ((draft.latitude === null) !== (draft.longitude === null)) {
            setError('عرض و طولِ جغرافیایی باید با هم ثبت یا با هم حذف شوند.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/addresses', 'POST', {
            addressId: draft.id ?? undefined,
            partyId,
            personId,
            addressTitleId: draft.addressTitleId,
            provinceId: draft.provinceId,
            countyId: draft.countyId,
            cityId: draft.cityId,
            neighborhoodId: draft.neighborhoodId ?? undefined,
            municipalZoneId: draft.municipalZoneId ?? undefined,
            postalCode: draft.postalCode.trim() || undefined,
            addressText: draft.addressText.trim(),
            plateNumber: draft.plateNumber.trim() || undefined,
            unit: draft.unit.trim() || undefined,
            latitude: draft.latitude,
            longitude: draft.longitude,
        });
        setSaving(false);
        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        setFormOpen(false);
        setNotification({ open: true, type: 'success', message: res.message });
        reload();
    };

    const handleToggle = async (row: Address) => {
        setTogglingId(row.AddressID);
        const res = await crmApi(`/crm/addresses/${row.AddressID}/toggle`, 'POST');
        setTogglingId(null);
        setNotification({ open: true, type: res.success ? 'success' : 'error', message: res.message });
        if (res.success) reload();
    };

    const columns: ColumnsType<Address> = [
        { title: 'عنوان', dataIndex: 'AddressTitleName', key: 'AddressTitleName', width: 110 },
        {
            title: 'آدرس',
            key: 'text',
            render: (_, r) => (
                <div>
                    <Text style={{ fontSize: 12, color: '#6B7280' }}>
                        {r.ProvinceName} / {r.CountyName} / {r.CityName}
                        {r.NeighborhoodName ? ` / ${r.NeighborhoodName}` : ''}
                        {r.MunicipalZoneName ? ` — ${r.MunicipalZoneName}` : ''}
                    </Text>
                    <div>{r.AddressText}{r.PlateNumber ? ` — پلاک ${r.PlateNumber}` : ''}{r.Unit ? `, واحد ${r.Unit}` : ''}</div>
                    {showMap && r.Latitude !== null && r.Longitude !== null ? (
                        <Text type="secondary" style={{ fontSize: 12 }} dir="ltr">
                            <EnvironmentOutlined /> {Number(r.Latitude)}, {Number(r.Longitude)}
                        </Text>
                    ) : null}
                </div>
            ),
        },
        { title: 'کدپستی', dataIndex: 'PostalCode', key: 'PostalCode', width: 100, render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, r) => {
                const active = toBool(r.IsActive);
                return (
                    <Tag icon={active ? <CheckCircleOutlined /> : <StopOutlined />} color={active ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                        {active ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 100,
            align: 'center',
            render: (_, r) => {
                if (!canManage) return null;
                const active = toBool(r.IsActive);
                return (
                    <Space>
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ آدرس' : 'فعال‌کردنِ آدرس'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                            <Button type="text" size="small" danger={active} loading={togglingId === r.AddressID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <div>
            {canManage ? (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                    <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>آدرسِ جدید</Button>
                </div>
            ) : null}

            {error && !formOpen ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {formOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    <Row gutter={[12, 12]}>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>عنوانِ آدرس</Text>
                            <Select
                                style={{ width: '100%' }}
                                value={draft.addressTitleId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, addressTitleId: v }))}
                                options={addressTitles.map((t) => ({ value: t.AddressTitleID, label: t.DisplayName }))}
                            />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>استان</Text>
                            <Select
                                style={{ width: '100%' }}
                                value={draft.provinceId ?? undefined}
                                onChange={(v) => {
                                    setDraft((d) => ({ ...d, provinceId: v, countyId: null, cityId: null, neighborhoodId: null }));
                                    setCityOptions([]);
                                    setNeighborhoodOptions([]);
                                    loadCounties(v);
                                }}
                                options={provinces.map((p) => ({ value: p.ProvinceID, label: p.DisplayName }))}
                                showSearch
                                optionFilterProp="label"
                            />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>شهرستان</Text>
                            <Select
                                style={{ width: '100%' }}
                                disabled={!draft.provinceId}
                                value={draft.countyId ?? undefined}
                                onChange={(v) => {
                                    setDraft((d) => ({ ...d, countyId: v, cityId: null, neighborhoodId: null }));
                                    setNeighborhoodOptions([]);
                                    loadCities(v);
                                }}
                                options={countyOptions}
                                showSearch
                                optionFilterProp="label"
                                notFoundContent="شهرستانی برایِ این استان ثبت نشده است"
                            />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>شهر</Text>
                            <Select
                                style={{ width: '100%' }}
                                disabled={!draft.countyId}
                                value={draft.cityId ?? undefined}
                                onChange={(v) => {
                                    setDraft((d) => ({ ...d, cityId: v, neighborhoodId: null }));
                                    loadNeighborhoods(v);
                                }}
                                options={cityOptions}
                                showSearch
                                optionFilterProp="label"
                                notFoundContent="شهری برایِ این شهرستان ثبت نشده است"
                            />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>محله (اختیاری)</Text>
                            <Select
                                allowClear
                                style={{ width: '100%' }}
                                disabled={!draft.cityId}
                                value={draft.neighborhoodId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, neighborhoodId: v ?? null }))}
                                options={neighborhoodOptions}
                                showSearch
                                optionFilterProp="label"
                                notFoundContent="محله‌ای برایِ این شهر ثبت نشده است"
                            />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>منطقهٔ شهرداری (اختیاری)</Text>
                            <Select allowClear style={{ width: '100%' }} value={draft.municipalZoneId ?? undefined} onChange={(v) => setDraft((d) => ({ ...d, municipalZoneId: v ?? null }))} options={municipalZones} />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>کدپستی</Text>
                            <Input dir="ltr" value={draft.postalCode} onChange={(e) => setDraft((d) => ({ ...d, postalCode: e.target.value }))} />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>پلاک</Text>
                            <Input value={draft.plateNumber} onChange={(e) => setDraft((d) => ({ ...d, plateNumber: e.target.value }))} />
                        </Col>
                        <Col xs={24} md={6}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>واحد</Text>
                            <Input value={draft.unit} onChange={(e) => setDraft((d) => ({ ...d, unit: e.target.value }))} />
                        </Col>
                        <Col xs={24}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>متنِ آدرس</Text>
                            <Input.TextArea rows={2} value={draft.addressText} onChange={(e) => setDraft((d) => ({ ...d, addressText: e.target.value }))} />
                        </Col>
                        {showMap ? (
                            <Col xs={24}>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>موقعیت رویِ نقشه (اختیاری)</Text>
                                <AddressLocationPicker
                                    apiKey={neshanMapKey}
                                    searchEnabled={neshanSearchEnabled}
                                    cityName={cityOptions.find((c) => c.value === draft.cityId)?.label ?? null}
                                    latitude={draft.latitude}
                                    longitude={draft.longitude}
                                    onChange={(latitude, longitude) => setDraft((d) => ({ ...d, latitude, longitude }))}
                                />
                            </Col>
                        ) : null}
                    </Row>
                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="AddressID" columns={columns} dataSource={items} pagination={false} locale={{ emptyText: <Empty description="آدرسی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
