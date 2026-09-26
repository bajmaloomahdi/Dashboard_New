import { useEffect, useState } from 'react';
import { Card, Button, Descriptions, Table, Tag, Typography, Empty } from 'antd';
import { router, usePage } from '@inertiajs/react';
import { TagsOutlined, EditOutlined, ApartmentOutlined, BankOutlined, UserOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import ChipTabs from '../../../Components/ChipTabs';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import BrandFormModal, { Brand } from './BrandFormModal';

const { Link, Text } = Typography;

interface BrandDetail extends Brand {
    CreatedByName: string | null;
    ModifiedByName: string | null;
    Date_InsertFirst: string;
    Date_LastUpdate: string | null;
}

interface BrandParty {
    PartyID: number;
    PartyDisplayName: string;
    PartyNature: 'INDIVIDUAL' | 'LEGAL';
    PartyIsActive: boolean | number | string;
    ActiveRowCount: number | string;
}

/**
 * جزئیاتِ برند — فقط مدیریتِ خودِ برند و نمایشِ طرف‌حساب‌هایی که این برند برایشان تعریف شده
 * (حداقل یک ردیفِ فعال). تعریف/ویرایشِ برند برایِ طرف‌حساب (دسته/سهم/تاریخ‌ها) فقط از تبِ «برندها»ی
 * جزئیاتِ طرف‌حساب انجام می‌شود، نه از اینجا.
 */
export default function CrmBrandShow() {
    const { brand: currentBrand, parties, canManage } = usePage().props as unknown as {
        brand: BrandDetail;
        parties: BrandParty[];
        canManage: boolean;
    };

    const [editOpen, setEditOpen] = useState(false);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const [activeTab, setActiveTab] = useState<'parties'>('parties');

    const isActive = toBool(currentBrand.IsActive);

    // لوگو در هدر؛ خطایِ بارگذاری → آیکونِ پیش‌فرض (با تغییرِ LogoUrl بعد از ویرایش، دوباره تلاش می‌شود)
    const [logoFailed, setLogoFailed] = useState(false);
    useEffect(() => setLogoFailed(false), [currentBrand.LogoUrl]);

    const tabDefs = [{ key: 'parties' as const, label: 'طرف‌حساب‌هایِ مرتبط', icon: <ApartmentOutlined />, count: (parties || []).length }];

    const handleEditSuccess = (message: string) => {
        setEditOpen(false);
        setNotification({ open: true, type: 'success', message });
        router.reload({ only: ['brand'] });
    };

    const columns: ColumnsType<BrandParty> = [
        {
            title: 'طرف‌حساب',
            key: 'party',
            render: (_, r) => <Link onClick={() => router.visit(`/crm/parties/${r.PartyID}`)}>{r.PartyDisplayName}</Link>,
        },
        {
            title: 'ماهیت',
            key: 'nature',
            width: 100,
            render: (_, r) => {
                const isLegal = r.PartyNature === 'LEGAL';
                return (
                    <Tag color={isLegal ? 'geekblue' : 'purple'} style={{ borderRadius: 6 }} icon={isLegal ? <BankOutlined /> : <UserOutlined />}>
                        {isLegal ? 'حقوقی' : 'حقیقی'}
                    </Tag>
                );
            },
        },
        {
            title: 'وضعیتِ طرف‌حساب',
            key: 'partyStatus',
            width: 130,
            align: 'center',
            render: (_, r) => (toBool(r.PartyIsActive) ? <Text>فعال</Text> : <Text type="secondary">غیرفعال</Text>),
        },
        {
            title: 'ردیف‌هایِ فعال',
            key: 'rows',
            width: 120,
            align: 'center',
            render: (_, r) => <Text>{Number(r.ActiveRowCount) || 0}</Text>,
        },
    ];

    return (
        <MainLayout>
            <style>{`
                .brand-show-logo {
                    display: inline-flex; align-items: center; justify-content: center; vertical-align: middle;
                    width: 96px; height: 44px; padding: 4px; border-radius: 8px; background: #fff; overflow: hidden;
                }
                .brand-show-logo img { max-width: 100%; max-height: 100%; object-fit: contain; display: block; }
            `}</style>
            <PageHeader
                icon={
                    currentBrand.LogoUrl && !logoFailed ? (
                        <span className="brand-show-logo">
                            <img src={currentBrand.LogoUrl} alt={currentBrand.Name} onError={() => setLogoFailed(true)} />
                        </span>
                    ) : (
                        <TagsOutlined />
                    )
                }
                title={currentBrand.Name || '—'}
                subtitle="برند"
                backHref="/crm/brands-page"
                backLabel="بازگشت به برندها"
                tags={[{ label: isActive ? 'فعال' : 'غیرفعال' }]}
                actions={
                    canManage ? (
                        <Button icon={<EditOutlined />} onClick={() => setEditOpen(true)}>
                            ویرایشِ برند
                        </Button>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.card }}>
                <Descriptions column={{ xs: 1, sm: 2, md: 3 }} size="small">
                    <Descriptions.Item label="توضیحات" span={3}>{currentBrand.Description || '—'}</Descriptions.Item>
                    <Descriptions.Item label="ایجادکننده">
                        {currentBrand.CreatedByName || '—'} — {currentBrand.Date_InsertFirst ? gregorianToJalaliDisplay(currentBrand.Date_InsertFirst) : '—'}
                    </Descriptions.Item>
                    <Descriptions.Item label="آخرین ویرایش">
                        {currentBrand.Date_LastUpdate ? `${currentBrand.ModifiedByName || '—'} — ${gregorianToJalaliDisplay(currentBrand.Date_LastUpdate)}` : '—'}
                    </Descriptions.Item>
                </Descriptions>
            </Card>

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />

            {activeTab === 'parties' && (
                <Card style={STYLES.card}>
                    <Table
                        rowKey="PartyID"
                        columns={columns}
                        dataSource={parties || []}
                        pagination={false}
                        locale={{ emptyText: <Empty description="این برند به هیچ طرف‌حسابی مرتبط نیست" /> }}
                    />
                </Card>
            )}

            <BrandFormModal open={editOpen} onClose={() => setEditOpen(false)} editingBrand={currentBrand} onSuccess={handleEditSuccess} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
