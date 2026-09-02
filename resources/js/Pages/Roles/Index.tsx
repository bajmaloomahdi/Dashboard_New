import { useState, useEffect, useRef } from 'react';
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
    SafetyOutlined,
    TeamOutlined,
    AppstoreOutlined,
    BarChartOutlined,
    LoadingOutlined,
    KeyOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import RoleFormModal from './RoleFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Role {
    RoleID: number;
    RoleCode: string;
    RoleName: string;
    Description: string | null;
    IsActive: boolean | number;
    CreateDate: string;
    UsersCount: number;
    MenusCount: number;
    ReportsCount: number;
}

export default function RolesIndex() {
    const { roles, filters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.is_active || null);
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingRole, setEditingRole] = useState<Role | null>(null);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        setSearching(true);

        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current);
        }

        searchTimeoutRef.current = setTimeout(() => {
            router.get('/roles', {
                search: searchText || undefined,
                is_active: statusFilter !== null && statusFilter !== '' ? statusFilter : undefined,
            }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setSearching(false),
            });
        }, 300);

        return () => {
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
            }
        };
    }, [searchText, statusFilter]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
    };

    const handleToggleActive = (roleId: number) => {
        router.post(`/roles/${roleId}/toggle`, {}, { preserveScroll: true });
    };

    const handleCreate = () => {
        setEditingRole(null);
        setModalOpen(true);
    };

    const handleEdit = (role: Role) => {
        setEditingRole(role);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingRole(null);
    };

    // ستون‌های سفارشی - همه align: center
    const customColumns: ColumnsType<Role> = [
        {
            title: 'کد',
            dataIndex: 'RoleCode',
            key: 'RoleCode',
            width: 80,
            align: 'center',
            render: (code: string) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
{
    title: 'نام نقش',
    key: 'roleName',
    align: 'center',
    render: (_, record: Role) => (
        <div style={{
            display: 'inline-flex',
            alignItems: 'center',
            gap: 10,
            minWidth: 250,
            justifyContent: 'flex-start',
        }}>
            <div style={STYLES.iconBox}>
                <SafetyOutlined style={{ color: '#fff', fontSize: 18 }} />
            </div>
            <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                <Text strong>{record.RoleName}</Text>
                {record.Description && (
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        {record.Description.length > 50
                            ? record.Description.substring(0, 50) + '...'
                            : record.Description}
                    </Text>
                )}
            </div>
        </div>
    ),
},
        {
            title: 'کاربران',
            dataIndex: 'UsersCount',
            key: 'UsersCount',
            width: 110,
            align: 'center',
            render: (count: number) => (
                <Tooltip title="تعداد کاربران با این نقش">
                    <Tag
                        icon={<TeamOutlined />}
                        color={count > 0 ? 'blue' : 'default'}
                        style={{ borderRadius: 6 }}
                    >
                        {count} کاربر
                    </Tag>
                </Tooltip>
            ),
        },
        {
            title: 'منوها',
            dataIndex: 'MenusCount',
            key: 'MenusCount',
            width: 110,
            align: 'center',
            render: (count: number) => (
                <Tooltip title="تعداد منوهای دسترسی">
                    <Tag
                        icon={<AppstoreOutlined />}
                        color={count > 0 ? 'green' : 'default'}
                        style={{ borderRadius: 6 }}
                    >
                        {count} منو
                    </Tag>
                </Tooltip>
            ),
        },
        {
            title: 'گزارشات',
            dataIndex: 'ReportsCount',
            key: 'ReportsCount',
            width: 110,
            align: 'center',
            render: (count: number) => (
                <Tooltip title="تعداد گزارشات دسترسی">
                    <Tag
                        icon={<BarChartOutlined />}
                        color={count > 0 ? 'purple' : 'default'}
                        style={{ borderRadius: 6 }}
                    >
                        {count} گزارش
                    </Tag>
                </Tooltip>
            ),
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, record: Role) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                return (
                    <Tag
                        icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />}
                        color={isActive ? 'success' : 'default'}
                        style={{ borderRadius: 6 }}
                    >
                        {isActive ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 180,
            align: 'center',
            render: (_, record: Role) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                return (
                    <Space>
                        <Tooltip title="مدیریت دسترسی‌ها">
                            <Button
                                type="text"
                                icon={<KeyOutlined />}
                                style={{ color: THEME.warning }}
                                onClick={() => router.visit(`/roles/${record.RoleID}/permissions`)}
                            />
                        </Tooltip>
                        <Tooltip title="ویرایش">
                            <Button
                                type="text"
                                icon={<EditOutlined />}
                                style={{ color: THEME.info }}
                                onClick={() => handleEdit(record)}
                            />
                        </Tooltip>
                        <Popconfirm
                            title={isActive ? 'غیرفعال کردن نقش' : 'فعال کردن نقش'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.RoleID)}
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
            <PageHeader
                icon={<SafetyOutlined />}
                title="مدیریت نقش‌ها"
                subtitle="تعریف نقش‌ها و مدیریت دسترسی‌های سیستم"
                stats={[
                    { icon: <SafetyOutlined />, label: 'تعداد کل', value: `${(roles || []).length} نقش` },
                    {
                        icon: <CheckCircleOutlined />,
                        label: 'فعال',
                        value: `${(roles || []).filter((r: Role) => columnHelpers.toBool(r.IsActive)).length} نقش`,
                    },
                ]}
                actions={
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        size="large"
                        style={STYLES.primaryButton}
                        onClick={handleCreate}
                    >
                        نقش جدید
                    </Button>
                }
            />

            {/* فیلترها - همرنگ هدر جدول */}
            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={12}>
                        <Input
                            placeholder="جستجوی زنده در نام، کد، توضیحات..."
                            prefix={
                                searching
                                    ? <LoadingOutlined style={{ color: THEME.primary }} />
                                    : <SearchOutlined />
                            }
                            suffix={
                                searching ? (
                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                        در حال جستجو...
                                    </Text>
                                ) : null
                            }
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
                    dataSource={roles}
                    loading={searching}
                    customColumns={customColumns}
                    rowKey="RoleID"
                    showColumnSearch={false}
                />
            </Card>

            {/* مودال ایجاد/ویرایش */}
            <RoleFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingRole={editingRole}
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