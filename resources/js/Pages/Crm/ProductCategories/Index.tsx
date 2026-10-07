import { useEffect, useMemo, useRef, useState } from 'react';
import { Card, Button, Input, Row, Col, Select, Tag, Popconfirm, Space, Tooltip, Table, Typography, Empty } from 'antd';
import type { ColumnsType } from 'antd/es/table';
import {
    PlusOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    EditOutlined,
    ApartmentOutlined,
    SubnodeOutlined,
    FolderOutlined,
} from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { crmApi } from '../../../Components/Crm/crmApi';
import { buildCategoryTree, CategoryNode, ProductCategoryRow, withAncestors } from '../../../Components/Crm/productCategoryTree';
import { THEME, STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';
import CategoryFormModal from './CategoryFormModal';

const { Text } = Typography;

/**
 * دسته‌بندیِ محصولات — درختِ چندسطحیِ نامحدود. جستجو/فیلترِ وضعیت ردیف‌هایِ منطبق را همراهِ
 * اجدادشان نشان می‌دهد تا جایِ هر دسته در درخت معلوم بماند.
 */
export default function CrmProductCategoriesIndex() {
    const { categories, canManage } = usePage().props as unknown as { categories: ProductCategoryRow[]; canManage: boolean };

    const [items, setItems] = useState<ProductCategoryRow[]>(categories || []);
    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<string | null>(null);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingCategory, setEditingCategory] = useState<ProductCategoryRow | null>(null);
    const [defaultParentId, setDefaultParentId] = useState<number | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const reload = async () => {
        const res = await crmApi('/crm/product-categories-list');
        if (res.ok && res.success) setItems(res.items || []);
    };

    const filtering = searchText.trim() !== '' || statusFilter !== null;
    const tree = useMemo(() => {
        if (!filtering) return buildCategoryTree(items);
        const q = searchText.trim();
        const matched = items
            .filter((r) => (!q || r.DisplayName.includes(q) || r.Code.includes(q)) && (statusFilter === null || (toBool(r.IsActive) ? '1' : '0') === statusFilter))
            .map((r) => Number(r.ProductCategoryID));
        return buildCategoryTree(items, withAncestors(items, matched));
    }, [items, searchText, statusFilter, filtering]);

    const allKeys = useMemo(() => items.map((r) => Number(r.ProductCategoryID)), [items]);
    const [expandedKeys, setExpandedKeys] = useState<number[]>(allKeys);
    const knownKeys = useRef(new Set(allKeys));

    // دسته‌هایِ تازه‌ساخته باز نمایش داده می‌شوند؛ بسته‌شدنِ دستیِ بقیه حفظ می‌شود
    useEffect(() => {
        const fresh = allKeys.filter((k) => !knownKeys.current.has(k));
        if (fresh.length) {
            fresh.forEach((k) => knownKeys.current.add(k));
            setExpandedKeys((prev) => [...prev, ...fresh]);
        }
    }, [allKeys]);

    const openCreate = (parentId: number | null) => {
        setEditingCategory(null);
        setDefaultParentId(parentId);
        setModalOpen(true);
    };

    const openEdit = (row: ProductCategoryRow) => {
        setEditingCategory(row);
        setDefaultParentId(null);
        setModalOpen(true);
    };

    const handleModalSuccess = async (message: string) => {
        setModalOpen(false);
        notify('success', message);
        await reload();
    };

    const handleToggle = async (row: ProductCategoryRow) => {
        setTogglingId(Number(row.ProductCategoryID));
        const res = await crmApi(`/crm/product-categories/${row.ProductCategoryID}/toggle`, 'POST');
        setTogglingId(null);
        notify(res.ok && res.success ? 'success' : 'error', res.message);
        if (res.ok && res.success) reload();
    };

    const totalCount = items.length;
    const activeCount = items.filter((r) => toBool(r.IsActive)).length;
    const rootCount = items.filter((r) => r.ParentCategoryID === null).length;

    const columns: ColumnsType<CategoryNode> = [
        {
            title: 'نام',
            key: 'name',
            render: (_, r) => (
                <Space size={6}>
                    <FolderOutlined style={{ color: toBool(r.IsActive) ? THEME.primary : THEME.textLight }} />
                    <Text strong={r.ParentCategoryID === null} type={toBool(r.IsActive) ? undefined : 'secondary'}>{r.DisplayName}</Text>
                </Space>
            ),
        },
        { title: 'کد', dataIndex: 'Code', key: 'Code', width: 90, render: (v) => <Text type="secondary" dir="ltr">{v}</Text> },
        { title: 'ترتیب', dataIndex: 'SortOrder', key: 'SortOrder', width: 80, align: 'center' },
        {
            title: 'زیرمجموعه',
            key: 'children',
            width: 100,
            align: 'center',
            render: (_, r) => <Text>{Number(r.ChildCount) || 0}</Text>,
        },
        {
            title: 'استفاده',
            key: 'usage',
            width: 90,
            align: 'center',
            render: (_, r) => <Tooltip title="ردیف‌هایِ فعالِ برند در طرف‌حساب‌ها"><Text>{Number(r.ActiveUsageCount) || 0}</Text></Tooltip>,
        },
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
            width: 140,
            align: 'center',
            render: (_, r) => {
                if (!canManage) return null;
                const active = toBool(r.IsActive);
                return (
                    <Space size={0}>
                        <Tooltip title={active ? 'زیرمجموعهٔ جدید' : 'زیرِ دستهٔ غیرفعال نمی‌توان دسته تعریف کرد'}>
                            <Button type="text" size="small" disabled={!active} icon={<SubnodeOutlined />} style={{ color: active ? THEME.primary : undefined }} onClick={() => openCreate(Number(r.ProductCategoryID))} />
                        </Tooltip>
                        <Tooltip title="ویرایش / تغییرِ والد">
                            <Button type="text" size="small" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={() => openEdit(r)} />
                        </Tooltip>
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ دسته‌بندی' : 'فعال‌کردنِ دسته‌بندی'} description="آیا مطمئن هستید؟" onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                            <Button
                                type="text"
                                size="small"
                                loading={togglingId === Number(r.ProductCategoryID)}
                                icon={active ? <StopOutlined /> : <CheckCircleOutlined />}
                                style={{ color: active ? THEME.error : THEME.success }}
                            />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title="دسته‌بندی محصولات"
                subtitle="درختِ چندسطحیِ دسته‌بندیِ محصولات — برایِ ثبتِ سهمِ برندهایِ هر طرف‌حساب"
                stats={[
                    { icon: <ApartmentOutlined />, label: 'کل', value: `${totalCount} دسته` },
                    { icon: <FolderOutlined />, label: 'ریشه', value: `${rootCount} دسته` },
                    { icon: <CheckCircleOutlined />, label: 'فعال', value: `${activeCount} دسته` },
                ]}
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={() => openCreate(null)}>
                            دستهٔ ریشهٔ جدید
                        </Button>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={14}>
                        <Input placeholder="جستجو در نام، کد..." prefix={<SearchOutlined />} value={searchText} onChange={(e) => setSearchText(e.target.value)} allowClear size="large" />
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Select
                            placeholder="وضعیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={statusFilter ?? undefined}
                            onChange={(v) => setStatusFilter(v ?? null)}
                            allowClear
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={4}>
                        <Button icon={<ReloadOutlined />} onClick={() => { setSearchText(''); setStatusFilter(null); }} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            <Card style={STYLES.card} bodyStyle={{ padding: 20 }}>
                <Table<CategoryNode>
                    rowKey="key"
                    columns={columns}
                    dataSource={tree}
                    pagination={false}
                    scroll={{ x: true }}
                    expandable={{
                        expandedRowKeys: filtering ? allKeys : expandedKeys,
                        onExpandedRowsChange: (keys) => setExpandedKeys(keys as number[]),
                        indentSize: 24,
                    }}
                    locale={{ emptyText: <Empty description={filtering ? 'دسته‌ای یافت نشد' : 'هنوز دسته‌ای تعریف نشده است'} /> }}
                />
            </Card>

            <CategoryFormModal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                editingCategory={editingCategory}
                defaultParentId={defaultParentId}
                categories={items}
                onSuccess={handleModalSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
