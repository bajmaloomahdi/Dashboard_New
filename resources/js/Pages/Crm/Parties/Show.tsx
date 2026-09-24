import { useEffect, useState } from 'react';
import { Card, Typography, Button, Descriptions, Select, Space, Alert } from 'antd';
import { router, usePage } from '@inertiajs/react';
import { UserOutlined, BankOutlined, EditOutlined, TagOutlined, EnvironmentOutlined, PhoneOutlined, TeamOutlined, SaveOutlined, ApartmentOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { crmApi } from '../../../Components/Crm/crmApi';
import { STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import PartyFormModal, { Party } from './PartyFormModal';
import BrandsPanel from './BrandsPanel';
import AddressesPanel from './AddressesPanel';
import ContactsPanel from './ContactsPanel';
import RelationsPanel from './RelationsPanel';

const { Text } = Typography;

interface PartyDetail extends Party {
    DisplayName: string;
    DepartmentName: string | null;
    PartyTypeName: string | null;
    ActivityName: string | null;
    CreatedByName: string | null;
    ModifiedByName: string | null;
    Date_InsertFirst: string;
    Date_LastUpdate: string | null;
}

export default function CrmPartyShow() {
    const props = usePage().props as unknown as {
        party: PartyDetail;
        brands: any[];
        addresses: any[];
        contacts: any[];
        relations: any[];
        addressTitles: any[];
        provinces: any[];
        contactTypes: any[];
        positions: any[];
        contactRoles: any[];
        titles: any[];
        departments: { DepartmentID: number; DisplayName: string }[];
        partyTypes: { PartyTypeID: number; DisplayName: string }[];
        activities: { ActivityID: number; DisplayName: string; PartyTypeID: number }[];
        canManage: boolean;
    };

    const {
        party: currentParty, brands, addresses, contacts, relations, addressTitles, provinces, contactTypes,
        positions, contactRoles, titles, departments, partyTypes, activities, canManage,
    } = props;

    const [editOpen, setEditOpen] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const [activeTab, setActiveTab] = useState<'brands' | 'addresses' | 'contacts' | 'relations'>('brands');

    const [classification, setClassification] = useState({
        departmentId: currentParty.DepartmentID,
        partyTypeId: currentParty.PartyTypeID,
        activityId: currentParty.ActivityID,
    });
    const [classificationSaving, setClassificationSaving] = useState(false);
    const [classificationError, setClassificationError] = useState<string | null>(null);
    const filteredActivities = (activities || []).filter((a) => !classification.partyTypeId || a.PartyTypeID === classification.partyTypeId);

    useEffect(() => {
        setClassification({
            departmentId: currentParty.DepartmentID,
            partyTypeId: currentParty.PartyTypeID,
            activityId: currentParty.ActivityID,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [currentParty.PartyID, currentParty.DepartmentID, currentParty.PartyTypeID, currentParty.ActivityID]);

    const handleSaveClassification = async () => {
        setClassificationSaving(true);
        setClassificationError(null);
        const res = await crmApi('/crm/parties', 'POST', {
            partyId: currentParty.PartyID,
            partyNature: currentParty.PartyNature,
            officialName: currentParty.OfficialName,
            tradeName: currentParty.TradeName || undefined,
            registrationNumber: currentParty.RegistrationNumber || undefined,
            economicCode: currentParty.EconomicCode || undefined,
            identifierNumber: currentParty.IdentifierNumber,
            identifierDate: currentParty.IdentifierDate || undefined,
            description: currentParty.Description || undefined,
            departmentId: classification.departmentId ?? undefined,
            partyTypeId: classification.partyTypeId ?? undefined,
            activityId: classification.activityId ?? undefined,
        });
        setClassificationSaving(false);
        if (!res.ok || !res.success) {
            setClassificationError(res.message);
            return;
        }
        setNotification({ open: true, type: 'success', message: res.message });
        router.reload({ only: ['party'] });
    };

    const isActive = toBool(currentParty.IsActive);

    const tabDefs = [
        { key: 'brands' as const, label: 'برندها', icon: <TagOutlined />, count: (brands || []).length },
        { key: 'addresses' as const, label: 'آدرس‌ها', icon: <EnvironmentOutlined />, count: (addresses || []).length },
        { key: 'contacts' as const, label: 'اطلاعاتِ تماس', icon: <PhoneOutlined />, count: (contacts || []).length },
        { key: 'relations' as const, label: 'مخاطبین', icon: <TeamOutlined />, count: (relations || []).length },
    ];

    const handleEditSuccess = (message: string) => {
        setEditOpen(false);
        setNotification({ open: true, type: 'success', message });
        // بازخوانیِ Propِ 'party' از همان اکشنِ show — بدونِ نیازِ به جست‌وجویِ
        // شکننده بر اساسِ شناسه (که اگر خودِ شناسه ویرایش شده بود پیدا نمی‌کرد).
        router.reload({ only: ['party'] });
    };

    const isLegal = currentParty.PartyNature === 'LEGAL';
    const dateLabel = isLegal ? 'تاریخِ ثبت' : 'تاریخِ افتتاح';

    return (
        <MainLayout>
            <PageHeader
                icon={isLegal ? <BankOutlined /> : <UserOutlined />}
                title={currentParty.DisplayName || '—'}
                subtitle={isLegal ? 'طرف‌حسابِ حقوقی' : 'طرف‌حسابِ حقیقی'}
                backHref="/crm/parties"
                backLabel="بازگشت به طرف‌حساب‌ها"
                tags={[
                    { label: isActive ? 'فعال' : 'غیرفعال' },
                    ...(currentParty.DepartmentName ? [{ label: currentParty.DepartmentName }] : []),
                    ...(currentParty.PartyTypeName ? [{ label: currentParty.PartyTypeName }] : []),
                ]}
                actions={
                    canManage ? (
                        <Button icon={<EditOutlined />} onClick={() => setEditOpen(true)}>
                            ویرایشِ طرف‌حساب
                        </Button>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.card }}>
                <Descriptions column={{ xs: 1, sm: 2, md: 3 }} size="small">
                    {isLegal ? (
                        <Descriptions.Item label="شناسهٔ ملی">
                            <Text dir="ltr">{currentParty.IdentifierNumber}</Text>
                        </Descriptions.Item>
                    ) : null}
                    <Descriptions.Item label={dateLabel}>
                        {currentParty.IdentifierDate ? gregorianToJalaliDisplay(currentParty.IdentifierDate) : '—'}
                    </Descriptions.Item>
                    {isLegal ? (
                        <>
                            <Descriptions.Item label="نامِ تجاری">{currentParty.TradeName || '—'}</Descriptions.Item>
                            <Descriptions.Item label="شمارهٔ ثبت"><Text dir="ltr">{currentParty.RegistrationNumber || '—'}</Text></Descriptions.Item>
                            <Descriptions.Item label="کدِ اقتصادی"><Text dir="ltr">{currentParty.EconomicCode || '—'}</Text></Descriptions.Item>
                        </>
                    ) : null}
                    <Descriptions.Item label="توضیحات" span={3}>{currentParty.Description || '—'}</Descriptions.Item>
                    <Descriptions.Item label="ایجادکننده">
                        {currentParty.CreatedByName || '—'} — {currentParty.Date_InsertFirst ? gregorianToJalaliDisplay(currentParty.Date_InsertFirst) : '—'}
                    </Descriptions.Item>
                    <Descriptions.Item label="آخرین ویرایش">
                        {currentParty.Date_LastUpdate ? `${currentParty.ModifiedByName || '—'} — ${gregorianToJalaliDisplay(currentParty.Date_LastUpdate)}` : '—'}
                    </Descriptions.Item>
                </Descriptions>
            </Card>

            <Card
                style={{ marginBottom: 16, ...STYLES.card }}
                title={<Space><ApartmentOutlined />دسته‌بندی</Space>}
                extra={canManage ? (
                    <Button type="primary" size="small" icon={<SaveOutlined />} loading={classificationSaving} onClick={handleSaveClassification}>
                        ذخیره
                    </Button>
                ) : undefined}
            >
                {classificationError ? <Alert type="error" showIcon message={classificationError} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}
                <Space wrap size={16}>
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>دپارتمان</Text>
                        <Select
                            allowClear
                            disabled={!canManage}
                            placeholder="دپارتمان"
                            style={{ width: 220 }}
                            value={classification.departmentId ?? undefined}
                            onChange={(v) => setClassification((s) => ({ ...s, departmentId: v ?? null }))}
                            options={(departments || []).map((d) => ({ value: d.DepartmentID, label: d.DisplayName }))}
                        />
                    </div>
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوع</Text>
                        <Select
                            allowClear
                            disabled={!canManage}
                            placeholder="نوع"
                            style={{ width: 220 }}
                            value={classification.partyTypeId ?? undefined}
                            onChange={(v) => setClassification((s) => ({ ...s, partyTypeId: v ?? null, activityId: null }))}
                            options={(partyTypes || []).map((t) => ({ value: t.PartyTypeID, label: t.DisplayName }))}
                        />
                    </div>
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>فعالیت</Text>
                        <Select
                            allowClear
                            disabled={!canManage || !classification.partyTypeId}
                            placeholder="فعالیت"
                            style={{ width: 220 }}
                            value={classification.activityId ?? undefined}
                            onChange={(v) => setClassification((s) => ({ ...s, activityId: v ?? null }))}
                            options={filteredActivities.map((a) => ({ value: a.ActivityID, label: a.DisplayName }))}
                        />
                    </div>
                </Space>
            </Card>

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />

            {activeTab === 'brands' && (
                <Card style={STYLES.card}>
                    <BrandsPanel partyId={currentParty.PartyID} items={brands || []} canManage={canManage} />
                </Card>
            )}

            {activeTab === 'addresses' && (
                <Card style={STYLES.card}>
                    <AddressesPanel partyId={currentParty.PartyID} items={addresses || []} addressTitles={addressTitles || []} provinces={provinces || []} canManage={canManage} />
                </Card>
            )}

            {activeTab === 'contacts' && (
                <Card style={STYLES.card}>
                    <ContactsPanel partyId={currentParty.PartyID} items={contacts || []} contactTypes={contactTypes || []} canManage={canManage} />
                </Card>
            )}

            {activeTab === 'relations' && (
                <Card style={STYLES.card}>
                    <RelationsPanel partyId={currentParty.PartyID} items={relations || []} positions={positions || []} contactRoles={contactRoles || []} titles={titles || []} canManage={canManage} />
                </Card>
            )}

            <PartyFormModal
                open={editOpen}
                onClose={() => setEditOpen(false)}
                editingParty={currentParty}
                onSuccess={handleEditSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
