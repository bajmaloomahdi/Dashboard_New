import { useState } from 'react';
import { Card, Typography, Button, Descriptions } from 'antd';
import { router, usePage } from '@inertiajs/react';
import { UserOutlined, BankOutlined, EditOutlined, TagOutlined, EnvironmentOutlined, PhoneOutlined, TeamOutlined, ApartmentOutlined, CommentOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import PartyFormModal, { Party } from './PartyFormModal';
import BrandCategoriesPanel, { BrandCategoryRow } from './BrandCategoriesPanel';
import AddressesPanel from './AddressesPanel';
import ContactsPanel from './ContactsPanel';
import RelationsPanel from './RelationsPanel';
import ClassificationsPanel from './ClassificationsPanel';
import InteractionsPanel from './InteractionsPanel';

const { Text } = Typography;

interface PartyDetail extends Party {
    DisplayName: string;
    DepartmentName: string | null;
    PartyTypeName: string | null;
    ActivityName: string | null;
    ClassificationCount: number;
    CreatedByName: string | null;
    ModifiedByName: string | null;
    Date_InsertFirst: string;
    Date_LastUpdate: string | null;
}

export default function CrmPartyShow() {
    const props = usePage().props as unknown as {
        party: PartyDetail;
        brandCategories: BrandCategoryRow[];
        addresses: any[];
        contacts: any[];
        relations: any[];
        classifications: any[];
        interactions: any[];
        users: { UserID: number; FullName: string }[];
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
        neshanMapKey: string | null;
        neshanSearchEnabled: boolean;
    };

    const {
        party: currentParty, brandCategories, addresses, contacts, relations, classifications, interactions, users, addressTitles, provinces, contactTypes,
        positions, contactRoles, titles, departments, partyTypes, activities, canManage, neshanMapKey, neshanSearchEnabled,
    } = props;

    const [editOpen, setEditOpen] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const [activeTab, setActiveTab] = useState<'classifications' | 'interactions' | 'brands' | 'addresses' | 'contacts' | 'relations'>('classifications');

    const isActive = toBool(currentParty.IsActive);

    const relatedPersons = Array.from(
        new Map((relations || []).filter((r: any) => toBool(r.IsActive)).map((r: any) => [r.PersonID, { PersonID: r.PersonID, DisplayName: r.PersonName }])).values()
    );

    const tabDefs = [
        { key: 'classifications' as const, label: 'دسته‌بندی‌ها', icon: <ApartmentOutlined />, count: (classifications || []).length },
        { key: 'relations' as const, label: 'مخاطبین', icon: <TeamOutlined />, count: (relations || []).length },
        { key: 'interactions' as const, label: 'تعاملات', icon: <CommentOutlined />, count: (interactions || []).length },
        { key: 'contacts' as const, label: 'اطلاعاتِ تماس', icon: <PhoneOutlined />, count: (contacts || []).length },
        { key: 'addresses' as const, label: 'آدرس‌ها', icon: <EnvironmentOutlined />, count: (addresses || []).length },
        { key: 'brands' as const, label: 'برندها', icon: <TagOutlined />, count: (brandCategories || []).filter((r) => toBool(r.IsActive)).length },
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
                    ...(Number(currentParty.ClassificationCount) > 0 ? [{ label: `${currentParty.ClassificationCount} دسته‌بندی` }] : []),
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

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />

            {activeTab === 'classifications' && (
                <Card style={STYLES.card}>
                    <ClassificationsPanel
                        partyId={currentParty.PartyID}
                        items={classifications || []}
                        departments={departments || []}
                        partyTypes={partyTypes || []}
                        activities={activities || []}
                        canManage={canManage}
                    />
                </Card>
            )}

            {activeTab === 'relations' && (
                <Card style={STYLES.card}>
                    <RelationsPanel partyId={currentParty.PartyID} items={relations || []} positions={positions || []} contactRoles={contactRoles || []} titles={titles || []} canManage={canManage} />
                </Card>
            )}

            {activeTab === 'interactions' && (
                <Card style={STYLES.card}>
                    <InteractionsPanel
                        partyId={currentParty.PartyID}
                        items={interactions || []}
                        relatedPersons={relatedPersons}
                        users={users || []}
                        canManage={canManage}
                    />
                </Card>
            )}

            {activeTab === 'contacts' && (
                <Card style={STYLES.card}>
                    <ContactsPanel
                        partyId={currentParty.PartyID}
                        items={contacts || []}
                        contactTypes={contactTypes || []}
                        relatedPersons={relatedPersons}
                        canManage={canManage}
                    />
                </Card>
            )}

            {activeTab === 'addresses' && (
                <Card style={STYLES.card}>
                    <AddressesPanel
                        partyId={currentParty.PartyID}
                        items={addresses || []}
                        addressTitles={addressTitles || []}
                        provinces={provinces || []}
                        canManage={canManage}
                        showMap
                        neshanMapKey={neshanMapKey}
                        neshanSearchEnabled={neshanSearchEnabled}
                    />
                </Card>
            )}

            {activeTab === 'brands' && (
                <Card style={STYLES.card}>
                    <BrandCategoriesPanel
                        partyId={currentParty.PartyID}
                        items={brandCategories || []}
                        canManage={canManage}
                        onChanged={() => router.reload({ only: ['brandCategories'] })}
                    />
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
