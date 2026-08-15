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
    Avatar,
} from 'antd';
import {
    UserAddOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    UserOutlined,
    MailOutlined,
    PhoneOutlined,
    LockOutlined,
    LoadingOutlined,
    SafetyOutlined,
    TeamOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import UserFormModal from './UserFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface User {
    UserID: number;
    UserCode: string;
    UserName: string;
    FirstName: string | null;
    LastName: string | null;
    FullName: string;
    Email: string | null;
    Mobile: string | null;
    LastLoginDate: string | null;
    IsLocked: boolean | number;
    IsActive: boolean | number;
    Description: string | null;
    RolesCount: number;
}

export default function UsersIndex() {
    const { users, filters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.is_active || null);
    const [searching, setSearching] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingUser, setEditingUser] = useState<User | null>(null);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    // کلید برای remount مودال — هر بار پیام جدید میاد زیاد می‌شود
    const [notificationKey, setNotificationKey] = useState(0);

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    // نمایش اعلان — با key جدید مودال همیشه remount می‌شود
    const showNotification = (type: NotificationType, message: string) => {
        setNotificationKey((k) => k + 1);
        setNotification({ open: true, type, message });
    };

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
            router.get('/users', {
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

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
    };

    const handleToggleActive = (userId: number) => {
        router.post(`/users/${userId}/toggle`, {}, { preserveScroll: true });
    };

    // بازنشانی پسورد — بعد از موفقیت، مستقیم پیام نشون بده
    const handleResetPassword = (userId: number, userName: string) => {
        router.post(`/users/${userId}/reset-password`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                showNotification('success', `رمز عبور «${userName}» با موفقیت به 123456 بازنشانی شد.`);
            },
        });
    };

    const handleCreate = () => {
        setEditingUser(null);
        setModalOpen(true);
    };

    const handleEdit = (user: User) => {
        setEditingUser(user);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingUser(null);
    };

    const customColumns: ColumnsType<User> = [
        {
            title: 'کد',
            dataIndex: 'UserCode',
            key: 'UserCode',
            width: 90,
            align: 'center',
            render: (code: string) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
        {
            title: 'کاربر',
            key: 'user',
            align: 'center',
            render: (_, record: User) => (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    minWidth: 200,
                    justifyContent: 'flex-start',
                }}>
                    <Avatar style={{ background: THEME.primaryGradient, flexShrink: 0 }}>
                        {record.FirstName?.charAt(0) || <UserOutlined />}
                    </Avatar>
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.FullName || '-'}</Text>
                        <Text type="secondary" style={{ fontSize: 12 }}>
                            @{record.UserName}
                        </Text>
                    </div>
                </div>
            ),
        },
        {
            title: 'اطلاعات تماس',
            key: 'contact',
            align: 'center',
            render: (_, record: User) => (
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 2 }}>
                    {record.Email && (
                        <Space size={4}>
                            <MailOutlined style={{ color: '#8c8c8c', fontSize: 12 }} />
                            <Text style={{ fontSize: 12 }}>{record.Email}</Text>
                        </Space>
                    )}
                    {record.Mobile && (
                        <Space size={4}>
                            <PhoneOutlined style={{ color: '#8c8c8c', fontSize: 12 }} />
                            <Text style={{ fontSize: 12 }}>{record.Mobile}</Text>
                        </Space>
                    )}
                    {!record.Email && !record.Mobile && <Text type="secondary">-</Text>}
                </div>
            ),
        },
        {
            title: 'نقش‌ها',
            dataIndex: 'RolesCount',
            key: 'RolesCount',
            width: 100,
            align: 'center',
            render: (count: number) => (
                <Tag color={count > 0 ? 'purple' : 'default'} style={{ borderRadius: 6 }}>
                    {count} نقش
                </Tag>
            ),
        },
        {
            title: 'آخرین ورود',
            dataIndex: 'LastLoginDate',
            key: 'LastLoginDate',
            width: 160,
            align: 'center',
            render: (date: string | null) => {
                if (!date) return <Text type="secondary">هرگز</Text>;
                return (
                    <div>
                        <Text style={{ fontSize: 12 }}>
                            {new Date(date).toLocaleDateString('fa-IR')}
                        </Text>
                        <br />
                        <Text type="secondary" style={{ fontSize: 11 }}>
                            {new Date(date).toLocaleTimeString('fa-IR')}
                        </Text>
                    </div>
                );
            },
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 130,
            align: 'center',
            render: (_, record: User) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                const isLocked = columnHelpers.toBool(record.IsLocked);
                return (
                    <Space direction="vertical" size={2}>
                        <Tag
                            icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />}
                            color={isActive ? 'success' : 'default'}
                            style={{ borderRadius: 6 }}
                        >
                            {isActive ? 'فعال' : 'غیرفعال'}
                        </Tag>
                        {isLocked && (
                            <Tag icon={<LockOutlined />} color="error" style={{ borderRadius: 6 }}>
                                قفل شده
                            </Tag>
                        )}
                    </Space>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 300,
            align: 'center',
            render: (_, record: User) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                return (
                    <Space>
                        <Tooltip title="مدیریت نقش‌ها">
                            <Button
                                type="text"
                                icon={<SafetyOutlined />}
                                style={{ color: THEME.primary }}
                                onClick={() => router.visit(`/users/${record.UserID}/roles`)}
                            />
                        </Tooltip>
                        <Tooltip title="مدیریت سمت‌ها">
                            <Button
                                type="text"
                                icon={<TeamOutlined />}
                                style={{ color: THEME.info }}
                                onClick={() => router.visit(`/users/${record.UserID}/positions`)}
                            />
                        </Tooltip>
                        <Tooltip title="بازنشانی پسورد">
                            <Popconfirm
                                title="بازنشانی پسورد"
                                description="رمز عبور کاربر به 123456 تغییر می‌کند. آیا مطمئن هستید؟"
                                onConfirm={() => handleResetPassword(record.UserID, record.FullName || record.UserName)}
                                okText="بله، بازنشانی کن"
                                cancelText="خیر"
                                okButtonProps={{ danger: true }}
                            >
                                <Button
                                    type="text"
                                    icon={<LockOutlined />}
                                    style={{ color: THEME.warning }}
                                />
                            </Popconfirm>
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
                            title={isActive ? 'غیرفعال کردن کاربر' : 'فعال کردن کاربر'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.UserID)}
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
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Title level={3} style={{ margin: 0 }}>
                        <UserOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                        مدیریت کاربران
                    </Title>
                    <Text type="secondary">
                        مشاهده، ایجاد و ویرایش کاربران سیستم
                    </Text>
                </Col>
                <Col>
                    <Button
                        type="primary"
                        icon={<UserAddOutlined />}
                        size="large"
                        style={STYLES.primaryButton}
                        onClick={handleCreate}
                    >
                        کاربر جدید
                    </Button>
                </Col>
            </Row>

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={12}>
                        <Input
                            placeholder="جستجوی زنده در نام، ایمیل، موبایل، کد..."
                            prefix={
                                searching
                                    ? <LoadingOutlined style={{ color: THEME.primary }} />
                                    : <SearchOutlined />
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

            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={users}
                    loading={searching}
                    customColumns={customColumns}
                    rowKey="UserID"
                    showColumnSearch={false}
                />
            </Card>

            <UserFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingUser={editingUser}
            />

            <NotificationModal
                key={notificationKey}
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}