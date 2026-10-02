import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Card } from 'antd';
import { IdcardOutlined, UserOutlined, SolutionOutlined, TeamOutlined, PhoneOutlined, EnvironmentOutlined, CommentOutlined, HomeOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import MasterDataManager, { MasterDataRow } from '../../../Components/Crm/MasterDataManager';
import { STYLES } from '../../../theme';

interface PageProps {
    titles: MasterDataRow[];
    positions: MasterDataRow[];
    contactRoles: MasterDataRow[];
    contactTypes: MasterDataRow[];
    addressTitles: MasterDataRow[];
    interactionTypes: MasterDataRow[];
    ownershipTypes: MasterDataRow[];
    canManage: boolean;
}

type TabKey = 'titles' | 'positions' | 'contactRoles' | 'contactTypes' | 'addressTitles' | 'interactionTypes' | 'ownershipTypes';

/**
 * فهرست‌هایِ کمکیِ CRM: عنوانِ فرد، سمت، نقش، نوعِ تماس، عنوانِ آدرس —
 * همگی مستقل، بدونِ سلسله‌مراتب.
 */
export default function CrmDirectoryPage() {
    const { titles, positions, contactRoles, contactTypes, addressTitles, interactionTypes, ownershipTypes, canManage } = usePage().props as unknown as PageProps;
    const [activeTab, setActiveTab] = useState<TabKey>('titles');

    const tabDefs = [
        { key: 'titles' as const, label: 'عنوانِ فرد', icon: <UserOutlined />, count: (titles || []).length },
        { key: 'positions' as const, label: 'سمت', icon: <SolutionOutlined />, count: (positions || []).length },
        { key: 'contactRoles' as const, label: 'نقش', icon: <TeamOutlined />, count: (contactRoles || []).length },
        { key: 'contactTypes' as const, label: 'نوعِ تماس', icon: <PhoneOutlined />, count: (contactTypes || []).length },
        { key: 'addressTitles' as const, label: 'عنوانِ آدرس', icon: <EnvironmentOutlined />, count: (addressTitles || []).length },
        { key: 'interactionTypes' as const, label: 'نوعِ تعامل', icon: <CommentOutlined />, count: (interactionTypes || []).length },
        { key: 'ownershipTypes' as const, label: 'نوعِ مالکیت', icon: <HomeOutlined />, count: (ownershipTypes || []).length },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<IdcardOutlined />}
                title="فهرست‌هایِ CRM"
                subtitle="عنوانِ فرد، سمت، نقش، نوعِ تماس و عنوانِ آدرس — Master Dataهایِ CRM"
                backHref="/crm/geography"
                backLabel="بازگشت"
            />

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />

            {activeTab === 'titles' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="TitleID"
                        items={titles || []}
                        apiBase="/crm/directory/titles"
                        canManage={canManage}
                        entityLabel="عنوان"
                        namePlaceholder="آقای"
                    />
                </Card>
            )}

            {activeTab === 'positions' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="PositionID"
                        items={positions || []}
                        apiBase="/crm/directory/positions"
                        canManage={canManage}
                        entityLabel="سمت"
                        namePlaceholder="مدیرعامل"
                    />
                </Card>
            )}

            {activeTab === 'contactRoles' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="ContactRoleID"
                        items={contactRoles || []}
                        apiBase="/crm/directory/contact-roles"
                        canManage={canManage}
                        entityLabel="نقش"
                        namePlaceholder="تصمیم‌گیرنده"
                    />
                </Card>
            )}

            {activeTab === 'contactTypes' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="ContactTypeID"
                        items={contactTypes || []}
                        apiBase="/crm/directory/contact-types"
                        canManage={canManage}
                        entityLabel="نوعِ تماس"
                        namePlaceholder="تلفن"
                    />
                </Card>
            )}

            {activeTab === 'addressTitles' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="AddressTitleID"
                        items={addressTitles || []}
                        apiBase="/crm/directory/address-titles"
                        canManage={canManage}
                        entityLabel="عنوانِ آدرس"
                        namePlaceholder="دفتر مرکزی"
                    />
                </Card>
            )}

            {activeTab === 'interactionTypes' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="InteractionTypeID"
                        items={interactionTypes || []}
                        apiBase="/crm/directory/interaction-types"
                        canManage={canManage}
                        entityLabel="نوعِ تعامل"
                        namePlaceholder="تماسِ تلفنی"
                    />
                </Card>
            )}

            {activeTab === 'ownershipTypes' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="OwnershipTypeID"
                        items={ownershipTypes || []}
                        apiBase="/crm/directory/ownership-types"
                        canManage={canManage}
                        entityLabel="نوعِ مالکیت"
                        namePlaceholder="مالک"
                    />
                </Card>
            )}
        </MainLayout>
    );
}
