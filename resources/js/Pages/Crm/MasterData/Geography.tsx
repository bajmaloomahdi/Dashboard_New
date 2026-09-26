import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Card } from 'antd';
import { EnvironmentOutlined, GlobalOutlined, BankOutlined, CompassOutlined, HomeOutlined } from '@ant-design/icons';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import MasterDataManager, { MasterDataRow } from '../../../Components/Crm/MasterDataManager';
import { STYLES } from '../../../theme';

interface PageProps {
    provinces: (MasterDataRow & { ProvinceID: number })[];
    counties: (MasterDataRow & { CountyID: number; ProvinceID: number; ProvinceName: string })[];
    cities: (MasterDataRow & { CityID: number; CountyID: number; CountyName: string; ProvinceID: number; ProvinceName: string })[];
    neighborhoods: (MasterDataRow & { NeighborhoodID: number; CityID: number; CityName: string; CountyID: number; CountyName: string })[];
    municipalZones: MasterDataRow[];
    canManage: boolean;
}

type TabKey = 'provinces' | 'counties' | 'cities' | 'neighborhoods' | 'municipalZones';

/**
 * جغرافیایِ آدرس: استان → شهرستان → شهر → محله + منطقهٔ شهرداری (کاملاً مستقل).
 * فرمِ هر سطح فقط والدِ مستقیمِ خودش را می‌پرسد؛ سطحِ بالاتر فقط در نوارِ فیلترِ گرید است.
 */
export default function CrmGeographyPage() {
    const { provinces, counties, cities, neighborhoods, municipalZones, canManage } = usePage().props as unknown as PageProps;
    const [activeTab, setActiveTab] = useState<TabKey>('provinces');

    const tabDefs = [
        { key: 'provinces' as const, label: 'استان', icon: <GlobalOutlined />, count: (provinces || []).length },
        { key: 'counties' as const, label: 'شهرستان', icon: <EnvironmentOutlined />, count: (counties || []).length },
        { key: 'cities' as const, label: 'شهر', icon: <BankOutlined />, count: (cities || []).length },
        { key: 'neighborhoods' as const, label: 'محله', icon: <HomeOutlined />, count: (neighborhoods || []).length },
        { key: 'municipalZones' as const, label: 'منطقهٔ شهرداری', icon: <CompassOutlined />, count: (municipalZones || []).length },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<EnvironmentOutlined />}
                title="جغرافیایِ آدرس"
                subtitle="استان → شهرستان → شهر → محله + منطقهٔ شهرداری — Master Dataهایِ CRM"
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

            {activeTab === 'counties' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="CountyID"
                        items={counties || []}
                        apiBase="/crm/geography/counties"
                        canManage={canManage}
                        entityLabel="شهرستان"
                        namePlaceholder="شمیرانات"
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

            {activeTab === 'cities' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="CityID"
                        items={cities || []}
                        apiBase="/crm/geography/cities"
                        canManage={canManage}
                        entityLabel="شهر"
                        namePlaceholder="تجریش"
                        parentSelect={{
                            label: 'شهرستان',
                            paramName: 'countyId',
                            idField: 'CountyID',
                            apiBase: '/crm/geography/counties',
                            displayColumn: { title: 'شهرستان', dataIndex: 'CountyName' },
                            optionContextField: 'ProvinceName',
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

            {activeTab === 'neighborhoods' && (
                <Card style={STYLES.card}>
                    <MasterDataManager
                        idField="NeighborhoodID"
                        items={neighborhoods || []}
                        apiBase="/crm/geography/neighborhoods"
                        canManage={canManage}
                        entityLabel="محله"
                        namePlaceholder="ونک"
                        parentSelect={{
                            label: 'شهر',
                            paramName: 'cityId',
                            idField: 'CityID',
                            apiBase: '/crm/geography/cities',
                            displayColumn: { title: 'شهر', dataIndex: 'CityName' },
                            optionContextField: 'CountyName',
                        }}
                        grandParentSelect={{
                            label: 'شهرستان',
                            apiBase: '/crm/geography/counties',
                            idField: 'CountyID',
                            displayColumn: { title: 'شهرستان', dataIndex: 'CountyName' },
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
