import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Card } from 'antd';
import { EnvironmentOutlined, GlobalOutlined, BankOutlined, CompassOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import MasterDataManager, { MasterDataRow } from '../../../Components/Crm/MasterDataManager';
import { STYLES } from '../../../theme';

interface PageProps {
    provinces: (MasterDataRow & { ProvinceID: number })[];
    cities: (MasterDataRow & { CityID: number; ProvinceID: number; ProvinceName: string })[];
    counties: (MasterDataRow & { CountyID: number; CityID: number; CityName: string; ProvinceID: number; ProvinceName: string })[];
    municipalZones: MasterDataRow[];
    canManage: boolean;
}

type TabKey = 'provinces' | 'cities' | 'counties' | 'municipalZones';

/**
 * جغرافیایِ آدرس: استان → شهر → شهرستان (سلسله‌مراتبِ اختصاصیِ Raga 360،
 * نه تقسیماتِ کشوریِ واقعی) + منطقهٔ شهرداری (کاملاً مستقل).
 * فعلاً هیچ دادهٔ واقعی Seed نشده — مدیر باید از همین صفحه تکمیل کند.
 */
export default function CrmGeographyPage() {
    const { provinces, cities, counties, municipalZones, canManage } = usePage().props as unknown as PageProps;
    const [activeTab, setActiveTab] = useState<TabKey>('provinces');

    const tabDefs = [
        { key: 'provinces' as const, label: 'استان', icon: <GlobalOutlined />, count: (provinces || []).length },
        { key: 'cities' as const, label: 'شهر', icon: <BankOutlined />, count: (cities || []).length },
        { key: 'counties' as const, label: 'شهرستان', icon: <EnvironmentOutlined />, count: (counties || []).length },
        { key: 'municipalZones' as const, label: 'منطقهٔ شهرداری', icon: <CompassOutlined />, count: (municipalZones || []).length },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<EnvironmentOutlined />}
                title="جغرافیایِ آدرس"
                subtitle="استان → شهر → شهرستان + منطقهٔ شهرداری — Master Dataهایِ CRM"
                backHref="/crm/classification"
                backLabel="بازگشت"
            />

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />

            {activeTab === 'provinces' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="ProvinceID"
                        items={provinces || []}
                        apiBase="/crm/geography/provinces"
                        canManage={canManage}
                        entityLabel="استان"
                        namePlaceholder="تهران"
                    />
                </Card>
            )}

            {activeTab === 'cities' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="CityID"
                        items={cities || []}
                        apiBase="/crm/geography/cities"
                        canManage={canManage}
                        entityLabel="شهر"
                        namePlaceholder="تهران"
                        parentSelect={{
                            label: 'استان',
                            paramName: 'provinceId',
                            idField: 'ProvinceID',
                            apiBase: '/crm/geography/provinces',
                            displayColumn: { title: 'استان', dataIndex: 'ProvinceName' },
                        }}
                    />
                </Card>
            )}

            {activeTab === 'counties' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="CountyID"
                        items={counties || []}
                        apiBase="/crm/geography/counties"
                        canManage={canManage}
                        entityLabel="شهرستان"
                        namePlaceholder="منطقهٔ یک"
                        parentSelect={{
                            label: 'شهر',
                            paramName: 'cityId',
                            idField: 'CityID',
                            apiBase: '/crm/geography/cities',
                            displayColumn: { title: 'شهر', dataIndex: 'CityName' },
                        }}
                        grandParentSelect={{
                            label: 'استان',
                            apiBase: '/crm/geography/provinces',
                            idField: 'ProvinceID',
                            displayColumn: { title: 'استان', dataIndex: 'ProvinceName' },
                        }}
                    />
                </Card>
            )}

            {activeTab === 'municipalZones' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="MunicipalZoneID"
                        items={municipalZones || []}
                        apiBase="/crm/geography/municipal-zones"
                        canManage={canManage}
                        entityLabel="منطقهٔ شهرداری"
                        namePlaceholder="منطقهٔ ۱"
                    />
                </Card>
            )}
        </MainLayout>
    );
}
