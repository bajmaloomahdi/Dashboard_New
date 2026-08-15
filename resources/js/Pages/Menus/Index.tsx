import { useState, useEffect, useMemo } from 'react';
import {
    Card,
    Button,
    Input,
    Space,
    Tag,
    Tooltip,
    Typography,
    Row,
    Col,
    Select,
    Popconfirm,
} from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    AppstoreOutlined,
    FolderOutlined,
    FileOutlined,
    BarChartOutlined,
    LoadingOutlined,
    EyeInvisibleOutlined,
    LinkOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import MenuFormModal from './MenuFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Menu {
    MenuID: number;
    ParentID: number | null;
    MenuCode: string;
    MenuTitle: string;
    MenuKind: string;
    Url: string | null;
    Icon: string | null;
    Level: number;
    SortOrder: number;
    OpenInNewTab: boolean | number;
    IsVisible: boolean | number;
    IsActive: boolean | number;
    Description: string | null;
    CreateDate: string;
    ParentTitle: string | null;
    ChildrenCount: number;
    RolesCount: number;
}

interface ParentOption {
    MenuID: number;
    MenuTitle: string;
    Level: number;
    DisplayTitle: string;
}

export default function MenusIndex() {
    const { allMenus, parentOptions, filters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.is_active || null);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingMenu, setEditingMenu] = useState<Menu | null>(null);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    /**
     * مرتب‌سازی درختی منوها
     */
    const sortedMenus = useMemo(() => {
        if (!allMenus || allMenus.length === 0) return [];

        const result: Menu[] = [];

        const addWithChildren = (parentId: number | null) => {
            const items = allMenus
                .filter((m: Menu) => m.ParentID === parentId)
                .sort((a: Menu, b: Menu) => a.SortOrder - b.SortOrder);

            items.forEach((item: Menu) => {
                result.push(item);
                addWithChildren(item.MenuID);
            });
        };

        addWithChildren(null);
        return result;
    }, [allMenus]);

    /**
     * فیلتر در فرانت‌اند
     */
    const filteredMenus = useMemo(() => {
        let result = sortedMenus;

        if (searchText) {
            const search = searchText.toLowerCase();
            result = result.filter((m: Menu) =>
                m.MenuTitle?.toLowerCase().includes(search) ||
                m.MenuCode?.toLowerCase().includes(search) ||
                m.Url?.toLowerCase().includes(search) ||
                m.ParentTitle?.toLowerCase().includes(search)
            );
        }

        if (statusFilter !== null && statusFilter !== '') {
            const isActive = statusFilter === '1';
            result = result.filter((m: Menu) => columnHelpers.toBool(m.IsActive) === isActive);
        }

        return result;
    }, [sortedMenus, searchText, statusFilter]);

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
    };

    const handleToggleActive = (menuId: number) => {
        router.post(`/menus/${menuId}/toggle`, {}, {
            preserveScroll: true,
            preserveState: true,
            only: ['flash', 'allMenus', 'parentOptions', 'menus'],
        });
    };

    const handleCreate = () => {
        setEditingMenu(null);
        setModalOpen(true);
    };

    const handleEdit = (menu: Menu) => {
        setEditingMenu(menu);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingMenu(null);
    };

    const getMenuKindTag = (kind: string) => {
        const config: Record<string, { color: string; icon: any; label: string }> = {
            PAGE: { color: 'blue', icon: <FileOutlined />, label: 'صفحه' },
            FOLDER: { color: 'orange', icon: <FolderOutlined />, label: 'پوشه' },
            REPORT: { color: 'purple', icon: <BarChartOutlined />, label: 'گزارش' },
        };
        const c = config[kind] || config.PAGE;
        return (
            <Tag icon={c.icon} color={c.color} style={{ fontSize: 11, borderRadius: 6 }}>
                {c.label}
            </Tag>
        );
    };

    // ستون‌های سفارشی - همه align: center
    const customColumns: ColumnsType<Menu> = [
        {
            title: 'کد',
            dataIndex: 'MenuCode',
            key: 'MenuCode',
            width: 90,
            align: 'center',
            render: (code: string) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
        {
            title: 'عنوان منو',
            key: 'title',
            align: 'center',
            render: (_, record: Menu) => {
                const paddingRight = record.Level > 1 ? (record.Level - 1) * 24 : 0;
                const isFolder = record.MenuKind === 'FOLDER';

                return (
                    <div style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                        minWidth: 250,
                        justifyContent: 'flex-start',
                        paddingRight: paddingRight,
                    }}>
                        {isFolder ? (
                            <FolderOutlined style={{ color: '#faad14', fontSize: 16 }} />
                        ) : record.MenuKind === 'REPORT' ? (
                            <BarChartOutlined style={{ color: '#722ed1', fontSize: 16 }} />
                        ) : (
                            <FileOutlined style={{ color: THEME.info, fontSize: 16 }} />
                        )}
                        <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                            <Text strong={isFolder}>{record.MenuTitle}</Text>
                            {record.ParentTitle && (
                                <Text type="secondary" style={{ fontSize: 11 }}>
                                    والد: {record.ParentTitle}
                                </Text>
                            )}
                        </div>
                    </div>
                );
            },
        },
        {
            title: 'نوع',
            dataIndex: 'MenuKind',
            key: 'MenuKind',
            width: 100,
            align: 'center',
            render: (kind: string) => getMenuKindTag(kind),
        },
        {
            title: 'آدرس',
            dataIndex: 'Url',
            key: 'Url',
            width: 180,
            align: 'center',
            render: (url: string | null) => url ? (
                <Tooltip title={url}>
                    <Text code style={{ fontSize: 11 }} dir="ltr">
                        <LinkOutlined /> {url.length > 20 ? url.substring(0, 20) + '...' : url}
                    </Text>
                </Tooltip>
            ) : (
                <Text type="secondary">-</Text>
            ),
        },
        {
            title: 'زیرمنو',
            dataIndex: 'ChildrenCount',
            key: 'ChildrenCount',
            width: 90,
            align: 'center',
            render: (count: number) => (
                <Tag color={count > 0 ? 'cyan' : 'default'} style={{ borderRadius: 6 }}>
                    {count}
                </Tag>
            ),
        },
        {
            title: 'نقش‌ها',
            dataIndex: 'RolesCount',
            key: 'RolesCount',
            width: 90,
            align: 'center',
            render: (count: number) => (
                <Tag color={count > 0 ? 'green' : 'default'} style={{ borderRadius: 6 }}>
                    {count}
                </Tag>
            ),
        },
        {
            title: 'ترتیب',
            dataIndex: 'SortOrder',
            key: 'SortOrder',
            width: 80,
            align: 'center',
            render: (sort: number) => (
                <Text style={{ fontSize: 12 }}>{sort}</Text>
            ),
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 130,
            align: 'center',
            render: (_, record: Menu) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                const isVisible = columnHelpers.toBool(record.IsVisible);
                return (
                    <Space direction="vertical" size={2}>
                        <Tag
                            icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />}
                            color={isActive ? 'success' : 'default'}
                            style={{ borderRadius: 6 }}
                        >
                            {isActive ? 'فعال' : 'غیرفعال'}
                        </Tag>
                        {!isVisible && (
                            <Tag icon={<EyeInvisibleOutlined />} color="warning" style={{ fontSize: 10, borderRadius: 6 }}>
                                مخفی
                            </Tag>
                        )}
                    </Space>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 120,
            align: 'center',
            render: (_, record: Menu) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                return (
                    <Space>
                        <Tooltip title="ویرایش">
                            <Button
                                type="text"
                                icon={<EditOutlined />}
                                style={{ color: THEME.info }}
                                onClick={() => handleEdit(record)}
                            />
                        </Tooltip>
                        <Popconfirm
                            title={isActive ? 'غیرفعال کردن منو' : 'فعال کردن منو'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.MenuID)}
                            okText="بله"
                            cancelText="خیر"
                            okButtonProps={{ danger: isActive }}
                        >
                            <Tooltip title={isActive ? 'غیرفعال کردن' : 'فعال کردن'}>
                                <Button
                                    type="text"
                                    icon={isActive ? <StopOutlined /> : <CheckCircleOutlined />}
                                    style={{ color: isActive ? THEME.error : THEME.success }}
                                />
                            </Tooltip>
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <MainLayout>
            {/* هدر */}
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Title level={3} style={{ margin: 0 }}>
                        <AppstoreOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                        مدیریت منوها
                    </Title>
                    <Text type="secondary">
                        تعریف و مدیریت منوهای سایدبار سیستم
                    </Text>
                </Col>
                <Col>
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        size="large"
                        style={STYLES.primaryButton}
                        onClick={handleCreate}
                    >
                        منوی جدید
                    </Button>
                </Col>
            </Row>

            {/* فیلترها - همرنگ هدر جدول */}
            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={12}>
                        <Input
                            placeholder="جستجوی زنده در عنوان، کد، آدرس..."
                            prefix={<SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={12} md={8}>
                        <Select
                            placeholder="فیلتر وضعیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={statusFilter}
                            onChange={(value) => setStatusFilter(value)}
                            allowClear
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={24} md={4}>
                        <Button icon={<ReloadOutlined />} onClick={handleReset} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            {/* جدول با DataGrid یکپارچه */}
            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={filteredMenus}
                    customColumns={customColumns}
                    rowKey="MenuID"
                    showColumnSearch={false}
                    pageSize={20}
                />
            </Card>

            {/* مودال ایجاد/ویرایش */}
            <MenuFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingMenu={editingMenu}
                parentOptions={parentOptions || []}
            />

            {/* مودال اعلان */}
            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}