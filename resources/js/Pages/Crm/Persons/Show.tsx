import { useState } from 'react';
import { Card, Typography, Button, Descriptions, Space } from 'antd';
import { router, usePage } from '@inertiajs/react';
import { TeamOutlined, EditOutlined, EnvironmentOutlined, PhoneOutlined, ApartmentOutlined, MessageOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import PersonFormModal, { Person } from './PersonFormModal';
import AddressesPanel from '../Parties/AddressesPanel';
import ContactsPanel from '../Parties/ContactsPanel';
import RelatedPartiesPanel from './RelatedPartiesPanel';
import InteractionsPanel from '../Parties/InteractionsPanel';
import CrmDeleteButton from '../../../Components/Crm/CrmDeleteButton';
import { CooperationEndedAction } from '../../../Components/Crm/CooperationEnded';
import CrmLinkedUserAction from '../../../Components/Crm/CrmLinkedUser';

const { Text } = Typography;

interface PersonDetail extends Person {
    /** کاربرِ متصل (پچِ ۱۲۱) */
    LinkedUserID?: number | string | null;
    LinkedUserName?: string | null;
    /** پچِ ۱۲۲: INTERNAL یا PERSON */
    LinkedUserType?: string | null;
    DisplayName: string;
    TitleName: string | null;
    CreatedByName: string | null;
    ModifiedByName: string | null;
    Date_InsertFirst: string;
    Date_LastUpdate: string | null;
}

export default function CrmPersonShow() {
    const props = usePage().props as unknown as {
        person: PersonDetail;
        contacts: any[];
        addresses: any[];
        relations: any[];
        interactions: any[];
        interactionTypes: any[];
        users: { UserID: number; FullName: string }[];
        addressTitles: any[];
        provinces: any[];
        contactTypes: any[];
        titles: any[];
        canManage: boolean;
        canDelete?: boolean;
    };

    const { person: currentPerson, contacts, addresses, relations, interactions, interactionTypes, users, addressTitles, provinces, contactTypes, titles, canManage, canDelete = false } = props;

    const [editOpen, setEditOpen] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const [activeTab, setActiveTab] = useState<'contacts' | 'addresses' | 'relations' | 'interactions'>('contacts');

    // طرف‌حساب‌هایی که تعاملِ جدیدِ این مخاطب می‌تواند به آن‌ها مربوط شود (فقط رابطه‌هایِ فعال)
    const personParties = (relations || [])
        .filter((r) => toBool(r.IsActive))
        .map((r) => ({ PartyID: Number(r.PartyID), DisplayName: r.PartyDisplayName as string }));

    const isActive = toBool(currentPerson.IsActive);

    const tabDefs = [
        { key: 'contacts' as const, label: 'اطلاعاتِ تماس', icon: <PhoneOutlined />, count: (contacts || []).length },
        { key: 'addresses' as const, label: 'آدرس‌ها', icon: <EnvironmentOutlined />, count: (addresses || []).length },
        { key: 'relations' as const, label: 'مخاطبین / طرف‌حساب‌هایِ مرتبط', icon: <ApartmentOutlined />, count: (relations || []).length },
        { key: 'interactions' as const, label: 'تعاملات', icon: <MessageOutlined />, count: (interactions || []).length },
    ];

    const handleEditSuccess = (message: string) => {
        setEditOpen(false);
        setNotification({ open: true, type: 'success', message });
        router.reload({ only: ['person'] });
    };

    return (
        <MainLayout>
            <PageHeader
                icon={<TeamOutlined />}
                title={currentPerson.DisplayName || '—'}
                subtitle="فرد مرتبط"
                backHref="/crm/persons-page"
                backLabel="بازگشت به افراد مرتبط"
                tags={[{ label: isActive ? 'فعال' : 'غیرفعال' }]}
                actions={
                    canManage || canDelete ? (
                        <Space wrap>
                            {canManage ? (
                                <Button icon={<EditOutlined />} onClick={() => setEditOpen(true)}>
                                    ویرایشِ فرد مرتبط
                                </Button>
                            ) : null}
                            {canDelete ? (
                                <CrmDeleteButton
                                    entity="person"
                                    id={currentPerson.PersonID}
                                    label="شخص"
                                    text="حذف"
                                    size="middle"
                                    onDone={(ok, msg) => {
                                        if (ok) router.visit('/crm/persons-page');
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
                    <Descriptions.Item label="عنوان">{currentPerson.TitleName || '—'}</Descriptions.Item>
                    <Descriptions.Item label="کدِ ملی">
                        {currentPerson.IdentifierNumber ? <Text dir="ltr">{currentPerson.IdentifierNumber}</Text> : '—'}
                    </Descriptions.Item>
                    <Descriptions.Item label="تاریخِ تولد">
                        {currentPerson.IdentifierDate ? gregorianToJalaliDisplay(currentPerson.IdentifierDate) : '—'}
                    </Descriptions.Item>
                    <Descriptions.Item label="کاربر">
                        <CrmLinkedUserAction
                            entity="persons"
                            id={currentPerson.PersonID}
                            userId={currentPerson.LinkedUserID}
                            userName={currentPerson.LinkedUserName}
                            userType={currentPerson.LinkedUserType}
                            canManage={canManage}
                            onSaved={(message, ok) => {
                                setNotification({ open: true, type: ok ? 'success' : 'error', message });
                                if (ok) router.reload({ only: ['person'] });
                            }}
                        />
                    </Descriptions.Item>
                    <Descriptions.Item label="قطعِ همکاری">
                        <CooperationEndedAction
                            entity="persons"
                            id={currentPerson.PersonID}
                            at={currentPerson.CooperationEndedAt}
                            canManage={canManage}
                            onSaved={(message, ok) => {
                                setNotification({ open: true, type: ok ? 'success' : 'error', message });
                                if (ok) router.reload({ only: ['person'] });
                            }}
                        />
                    </Descriptions.Item>
                    <Descriptions.Item label="توضیحات" span={3}>{currentPerson.Description || '—'}</Descriptions.Item>
                    <Descriptions.Item label="ایجادکننده">
                        {currentPerson.CreatedByName || '—'} — {currentPerson.Date_InsertFirst ? gregorianToJalaliDisplay(currentPerson.Date_InsertFirst) : '—'}
                    </Descriptions.Item>
                    <Descriptions.Item label="آخرین ویرایش">
                        {currentPerson.Date_LastUpdate ? `${currentPerson.ModifiedByName || '—'} — ${gregorianToJalaliDisplay(currentPerson.Date_LastUpdate)}` : '—'}
                    </Descriptions.Item>
                </Descriptions>
            </Card>

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />

            {activeTab === 'contacts' && (
                <Card style={STYLES.card}>
                    <ContactsPanel personId={currentPerson.PersonID} items={contacts || []} contactTypes={contactTypes || []} canManage={canManage} canDelete={canDelete} />
                </Card>
            )}

            {activeTab === 'addresses' && (
                <Card style={STYLES.card}>
                    <AddressesPanel personId={currentPerson.PersonID} items={addresses || []} addressTitles={addressTitles || []} provinces={provinces || []} canManage={canManage} canDelete={canDelete} />
                </Card>
            )}

            {activeTab === 'relations' && (
                <Card style={STYLES.card}>
                    <RelatedPartiesPanel items={relations || []} />
                </Card>
            )}

            {activeTab === 'interactions' && (
                <Card style={STYLES.card}>
                    <InteractionsPanel
                        personId={currentPerson.PersonID}
                        items={interactions || []}
                        personParties={personParties}
                        users={users || []}
                        interactionTypes={interactionTypes || []}
                        canManage={canManage}
                        canDelete={canDelete}
                    />
                </Card>
            )}

            <PersonFormModal
                open={editOpen}
                onClose={() => setEditOpen(false)}
                editingPerson={currentPerson}
                titles={titles || []}
                onSuccess={handleEditSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
