import { useState } from 'react';
import { Card, Typography, Button, Descriptions, Tag, Space } from 'antd';
import { router, usePage } from '@inertiajs/react';
import { UserOutlined, BankOutlined, EditOutlined, TagOutlined, EnvironmentOutlined, PhoneOutlined, TeamOutlined, ApartmentOutlined, CommentOutlined, PaperClipOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import PartyFormModal, { Party, PartyRoleRow, PartyPositionRow } from './PartyFormModal';
import BrandCategoriesPanel, { BrandCategoryRow } from './BrandCategoriesPanel';
import AddressesPanel from './AddressesPanel';
import ContactsPanel from './ContactsPanel';
import RelationsPanel from './RelationsPanel';
import ClassificationsPanel from './ClassificationsPanel';
import InteractionsPanel from './InteractionsPanel';
import PartyImagesPanel from './PartyImagesPanel';
import PartyAttachmentsPanel, { PartyAttachment } from './PartyAttachmentsPanel';
import CrmDeleteButton from '../../../Components/Crm/CrmDeleteButton';
import { CooperationEndedAction } from '../../../Components/Crm/CooperationEnded';
import CrmLinkedUserAction from '../../../Components/Crm/CrmLinkedUser';
import PartySupplementaryInfoPanel, { PartySupplementaryInfo, OwnershipTypeOption } from './PartySupplementaryInfoPanel';

const { Text } = Typography;

interface PartyDetail extends Party {
    /** کاربرِ متصل (پچِ ۱۲۱) */
    LinkedUserID?: number | string | null;
    LinkedUserName?: string | null;
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
        partyRoles: PartyRoleRow[];
        partyPositions: PartyPositionRow[];
        brandCategories: BrandCategoryRow[];
        addresses: any[];
        contacts: any[];
        relations: any[];
        classifications: any[];
        interactions: any[];
        interactionTypes: { InteractionTypeID: number; Code: string; DisplayName: string }[];
        partyImages: { PartyImageID: number; PartyID: number; ImageMimeType: string; Description: string | null; SortOrder: number; IsActive: boolean | number | string; Date_InsertFirst: string; CreatedByName: string | null; ImageUrl: string }[];
        partyAttachments?: PartyAttachment[];
        supplementaryInfo: PartySupplementaryInfo | null;
        ownershipTypes: OwnershipTypeOption[];
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
        canDelete?: boolean;
        neshanMapKey: string | null;
        neshanSearchEnabled: boolean;
    };

    const {
        party: currentParty, partyRoles, partyPositions, brandCategories, addresses, contacts, relations, classifications, interactions, interactionTypes, partyImages, partyAttachments, supplementaryInfo, ownershipTypes, users, addressTitles, provinces, contactTypes,
        positions, contactRoles, titles, departments, partyTypes, activities, canManage, canDelete = false, neshanMapKey, neshanSearchEnabled,
    } = props;

    const [editOpen, setEditOpen] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const [activeTab, setActiveTab] = useState<'classifications' | 'interactions' | 'brands' | 'addresses' | 'contacts' | 'relations' | 'attachments'>('classifications');

    const isActive = toBool(currentParty.IsActive);

    const relatedPersons = Array.from(
        new Map((relations || []).filter((r: any) => toBool(r.IsActive)).map((r: any) => [r.PersonID, { PersonID: r.PersonID, DisplayName: r.PersonName }])).values()
    );

    const tabDefs = [
        { key: 'classifications' as const, label: 'دسته‌بندی‌ها', icon: <ApartmentOutlined />, count: (classifications || []).length },
        { key: 'relations' as const, label: 'افراد مرتبط', icon: <TeamOutlined />, count: (relations || []).length },
        { key: 'interactions' as const, label: 'تعاملات', icon: <CommentOutlined />, count: (interactions || []).length },
        { key: 'contacts' as const, label: 'اطلاعاتِ تماس', icon: <PhoneOutlined />, count: (contacts || []).length },
        { key: 'addresses' as const, label: 'آدرس‌ها', icon: <EnvironmentOutlined />, count: (addresses || []).length },
        { key: 'brands' as const, label: 'برندها', icon: <TagOutlined />, count: (brandCategories || []).filter((r) => toBool(r.IsActive)).length },
        { key: 'attachments' as const, label: 'ضمائم و سایر ویژگی‌ها', icon: <PaperClipOutlined />, count: (partyImages || []).length + (partyAttachments || []).length },
    ];

    const handleEditSuccess = (message: string) => {
        setEditOpen(false);
        setNotification({ open: true, type: 'success', message });
        // بازخوانیِ Propِ 'party' از همان اکشنِ show — بدونِ نیازِ به جست‌وجویِ
        // شکننده بر اساسِ شناسه (که اگر خودِ شناسه ویرایش شده بود پیدا نمی‌کرد).
        router.reload({ only: ['party', 'partyRoles', 'partyPositions'] });
    };

    const renderTags = (rows: { DisplayName: string; IsActive: boolean | number | string }[]) =>
        rows.length ? (
            <Space size={[4, 4]} wrap>
                {rows.map((r, i) => (
                    <Tag key={i} color={toBool(r.IsActive) ? 'blue' : 'default'} style={{ marginInlineEnd: 0 }}>
                        {r.DisplayName}{toBool(r.IsActive) ? '' : ' (غیرفعال)'}
                    </Tag>
                ))}
            </Space>
        ) : '—';

    const isLegal = currentParty.PartyNature === 'LEGAL';
    const dateLabel = isLegal ? 'تاریخِ ثبت' : 'تاریخِ افتتاح';

    return (
        <MainLayout>
            <PageHeader
                icon={isLegal ? <BankOutlined /> : <UserOutlined />}
                title={currentParty.DisplayName || '—'}
                subtitle={isLegal ? 'طرف‌حسابِ حقوقی' : 'طرف‌حسابِ حقیقی'}
                backHref="/crm/parties"
                backLabel="بازگشت به مخاطبین / طرف‌حساب‌ها"
                tags={[
                    { label: isActive ? 'فعال' : 'غیرفعال' },
                    ...(Number(currentParty.ClassificationCount) > 0 ? [{ label: `${currentParty.ClassificationCount} دسته‌بندی` }] : []),
                ]}
                actions={
                    canManage || canDelete ? (
                        <Space wrap>
                            {canManage ? (
                                <Button icon={<EditOutlined />} onClick={() => setEditOpen(true)}>
                                    ویرایشِ طرف‌حساب
                                </Button>
                            ) : null}
                            {canDelete ? (
                                <CrmDeleteButton
                                    entity="party"
                                    id={currentParty.PartyID}
                                    label="طرف‌حساب"
                                    text="حذف"
                                    size="middle"
                                    onDone={(ok, msg) => {
                                        if (ok) router.visit('/crm/parties');
                                        else setNotification({ open: true, type: 'error', message: msg });
                                    }}
                                />
                            ) : null}
                        </Space>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.card }}>
                <Descriptions column={{ xs: 1, sm: 2, md: 3 }} size="small">
                    <Descriptions.Item label={isLegal ? 'شناسهٔ ملی' : 'کدِ ملی'}>
                        {currentParty.IdentifierNumber ? <Text dir="ltr">{currentParty.IdentifierNumber}</Text> : '—'}
                    </Descriptions.Item>
                    <Descriptions.Item label="تاریخِ ایجاد">
                        {currentParty.Date_InsertFirst ? gregorianToJalaliDisplay(currentParty.Date_InsertFirst) : '—'}
                    </Descriptions.Item>
                    <Descriptions.Item label={dateLabel}>
                        {currentParty.IdentifierDate ? gregorianToJalaliDisplay(currentParty.IdentifierDate) : '—'}
                    </Descriptions.Item>
                    {isLegal ? (
                        <>
                            <Descriptions.Item label="نامِ تجاری">{currentParty.TradeName || '—'}</Descriptions.Item>
                            <Descriptions.Item label="شمارهٔ ثبت"><Text dir="ltr">{currentParty.RegistrationNumber || '—'}</Text></Descriptions.Item>
                            <Descriptions.Item label="کدِ اقتصادی"><Text dir="ltr">{currentParty.EconomicCode || '—'}</Text></Descriptions.Item>
                        </>
                    ) : (
                        // حقیقی: شناسه ۱ و ۲ (متنی، پچِ ۱۲۴)
                        <>
                            <Descriptions.Item label="شناسه ۱">{currentParty.Identifier1 || '—'}</Descriptions.Item>
                            <Descriptions.Item label="شناسه ۲">{currentParty.Identifier2 || '—'}</Descriptions.Item>
                        </>
                    )}
                    <Descriptions.Item label="کاربر">
                        <CrmLinkedUserAction
                            entity="parties"
                            id={currentParty.PartyID}
                            userId={currentParty.LinkedUserID}
                            userName={currentParty.LinkedUserName}
                            canManage={canManage}
                            onSaved={(message, ok) => {
                                setNotification({ open: true, type: ok ? 'success' : 'error', message });
                                if (ok) router.reload({ only: ['party'] });
                            }}
                        />
                    </Descriptions.Item>
                    <Descriptions.Item label="قطعِ همکاری">
                        <CooperationEndedAction
                            entity="parties"
                            id={currentParty.PartyID}
                            at={currentParty.CooperationEndedAt}
                            canManage={canManage}
                            onSaved={(message, ok) => {
                                setNotification({ open: true, type: ok ? 'success' : 'error', message });
                                if (ok) router.reload({ only: ['party'] });
                            }}
                        />
                    </Descriptions.Item>
                    <Descriptions.Item label="نقش‌ها" span={3}>{renderTags(partyRoles || [])}</Descriptions.Item>
                    <Descriptions.Item label="سمت‌ها" span={3}>{renderTags(partyPositions || [])}</Descriptions.Item>
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
                        canDelete={canDelete}
                    />
                </Card>
            )}

            {activeTab === 'relations' && (
                <Card style={STYLES.card}>
                    <RelationsPanel partyId={currentParty.PartyID} items={relations || []} positions={positions || []} contactRoles={contactRoles || []} titles={titles || []} canManage={canManage} canDelete={canDelete} />
                </Card>
            )}

            {activeTab === 'interactions' && (
                <Card style={STYLES.card}>
                    <InteractionsPanel
                        partyId={currentParty.PartyID}
                        items={interactions || []}
                        relatedPersons={relatedPersons}
                        users={users || []}
                        interactionTypes={interactionTypes || []}
                        canManage={canManage}
                        canDelete={canDelete}
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
                        canDelete={canDelete}
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
                        canDelete={canDelete}
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
                        canDelete={canDelete}
                        onChanged={() => router.reload({ only: ['brandCategories'] })}
                    />
                </Card>
            )}

            {activeTab === 'attachments' && (
                <Card style={{ ...STYLES.card, marginBottom: 16 }}>
                    <PartySupplementaryInfoPanel
                        partyId={currentParty.PartyID}
                        info={supplementaryInfo ?? null}
                        ownershipTypes={ownershipTypes || []}
                        canManage={canManage}
                    />
                </Card>
            )}

            {activeTab === 'attachments' && (
                <Card style={STYLES.card}>
                    <PartyImagesPanel
                        partyId={currentParty.PartyID}
                        items={partyImages || []}
                        canManage={canManage}
                    />
                </Card>
            )}

            {activeTab === 'attachments' && (
                <Card style={{ ...STYLES.card, marginTop: 16 }}>
                    <PartyAttachmentsPanel
                        partyId={currentParty.PartyID}
                        items={partyAttachments || []}
                        canManage={canManage}
                    />
                </Card>
            )}

            <PartyFormModal
                open={editOpen}
                onClose={() => setEditOpen(false)}
                editingParty={currentParty}
                onSuccess={handleEditSuccess}
                contactRoles={contactRoles}
                positions={positions}
                // همان ارجاعِ Propها (پایدار بین رندرها) تا فرمِ باز با هر رندر ریست نشود
                initialRoles={partyRoles}
                initialPositions={partyPositions}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
