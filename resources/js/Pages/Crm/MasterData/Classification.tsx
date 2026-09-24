import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Card } from 'antd';
import { ApartmentOutlined, BankOutlined, TagOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import MasterDataManager, { MasterDataRow } from '../../../Components/Crm/MasterDataManager';
import { STYLES } from '../../../theme';

interface PageProps {
    departments: MasterDataRow[];
    partyTypes: (MasterDataRow & { PartyTypeID: number })[];
    activities: (MasterDataRow & { ActivityID: number; PartyTypeID: number; PartyTypeName: string })[];
    canManage: boolean;
}

type TabKey = 'departments' | 'partyTypes' | 'activities';

/**
 * طبقه‌بندیِ طرف‌حساب: دپارتمان (مستقل)، نوع (مستقل)، فعالیت (زیرمجموعهٔ نوع).
 */
export default function CrmClassificationPage() {
    const { departments, partyTypes, activities, canManage } = usePage().props as unknown as PageProps;
    const [activeTab, setActiveTab] = useState<TabKey>('departments');

    const tabDefs = [
        { key: 'departments' as const, label: 'دپارتمان', icon: <BankOutlined />, count: (departments || []).length },
        { key: 'partyTypes' as const, label: 'نوع', icon: <TagOutlined />, count: (partyTypes || []).length },
        { key: 'activities' as const, label: 'فعالیت', icon: <ApartmentOutlined />, count: (activities || []).length },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title="طبقه‌بندیِ طرف‌حساب"
                subtitle="دپارتمان، نوع و فعالیت — Master Dataهایِ CRM"
                backHref="/crm/directory"
                backLabel="بازگشت"
            />

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />

            {activeTab === 'departments' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="DepartmentID"
                        items={departments || []}
                        apiBase="/crm/classification/departments"
                        canManage={canManage}
                        entityLabel="دپارتمان"
                        namePlaceholder="فروش"
                    />
                </Card>
            )}

            {activeTab === 'partyTypes' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="PartyTypeID"
                        items={partyTypes || []}
                        apiBase="/crm/classification/party-types"
                        canManage={canManage}
                        entityLabel="نوع"
                        namePlaceholder="مشتری"
                    />
                </Card>
            )}

            {activeTab === 'activities' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="ActivityID"
                        items={activities || []}
                        apiBase="/crm/classification/activities"
                        canManage={canManage}
                        entityLabel="فعالیت"
                        namePlaceholder="خرده‌فروشی"
                        parentSelect={{
                            label: 'نوع',
                            paramName: 'partyTypeId',
                            idField: 'PartyTypeID',
                            apiBase: '/crm/classification/party-types',
                            displayColumn: { title: 'نوع', dataIndex: 'PartyTypeName' },
                        }}
                    />
                </Card>
            )}
        </MainLayout>
    );
}
