import { useState, useEffect, useMemo } from 'react';
import {
    Card,
    Button,
    Input,
    Space,
    Tag,
    Typography,
    Row,
    Col,
    Switch,
    Statistic,
    Alert,
    Avatar,
} from 'antd';
import {
    SearchOutlined,
    SaveOutlined,
    ArrowLeftOutlined,
    SafetyOutlined,
    UserOutlined,
    TeamOutlined,
    CheckCircleOutlined,
    StopOutlined,
    CheckSquareOutlined,
    BorderOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
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
}

interface Role {
    RoleID: number;
    RoleCode: string;
    RoleName: string;
    Description: string | null;
    HasRole: boolean | number;
    UsersCount: number;
}

export default function UserRoles() {
    const { user, roles, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState('');
    const [selectedRoleIds, setSelectedRoleIds] = useState<Set<number>>(new Set());
    const [initialRoleIds, setInitialRoleIds] = useState<Set<number>>(new Set());
    const [saving, setSaving] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        const roleIds = new Set<number>(
            roles.filter((r: Role) => columnHelpers.toBool(r.HasRole)).map((r: Role) => r.RoleID)
        );
        setSelectedRoleIds(roleIds);
        setInitialRoleIds(roleIds);
    }, [roles]);

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const filteredRoles = useMemo(() => {
        if (!searchText) return roles;
        const search = searchText.toLowerCase();
        return roles.filter((r: Role) =>
            r.RoleName?.toLowerCase().includes(search) ||
            r.RoleCode?.toLowerCase().includes(search) ||
            r.Description?.toLowerCase().includes(search)
        );
    }, [roles, searchText]);

    const hasChanges = useMemo(() => {
        if (selectedRoleIds.size !== initialRoleIds.size) return true;
        for (const id of selectedRoleIds) {
            if (!initialRoleIds.has(id)) return true;
        }
        return false;
    }, [selectedRoleIds, initialRoleIds]);

    const handleToggleRole = (roleId: number, checked: boolean) => {
        const newSet = new Set(selectedRoleIds);
        if (checked) newSet.add(roleId);
        else newSet.delete(roleId);
        setSelectedRoleIds(newSet);
    };

    // انتخاب/عدم انتخاب همه نقش‌ها
    const handleSelectAll = (selectAll: boolean) => {
        const newSet = new Set(selectedRoleIds);
        filteredRoles.forEach((r: Role) => {
            if (selectAll) newSet.add(r.RoleID);
            else newSet.delete(r.RoleID);
        });
        setSelectedRoleIds(newSet);
    };

    const handleSave = () => {
        setSaving(true);

        router.post(`/users/${user.UserID}/roles`, {
            role_ids: Array.from(selectedRoleIds),
        }, {
            preserveScroll: true,
            preserveState: true,
            only: ['flash', 'roles'],
            onFinish: () => setSaving(false),
        });
    };

    // بررسی وضعیت انتخاب فعلی
    const allSelected = filteredRoles.length > 0 &&
        filteredRoles.every((r: Role) => selectedRoleIds.has(r.RoleID));
    const someSelected = filteredRoles.some((r: Role) => selectedRoleIds.has(r.RoleID));

    // ستون‌های سفارشی
    const customColumns: ColumnsType<Role> = [
        {
            title: 'دسترسی',
            key: 'access',
            width: 100,
            align: 'center',
            render: (_, record: Role) => (
                <Switch
                    checked={selectedRoleIds.has(record.RoleID)}
                    onChange={(checked) => handleToggleRole(record.RoleID, checked)}
                    checkedChildren={<CheckCircleOutlined />}
                    unCheckedChildren={<StopOutlined />}
                />
            ),
        },
        {
            title: 'کد',
            dataIndex: 'RoleCode',
            key: 'RoleCode',
            width: 100,
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
                        <SafetyOutlined style={{ color: '#fff', fontSize: 16 }} />
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.RoleName}</Text>
                        {record.Description && (
                            <Text type="secondary" style={{ fontSize: 11 }}>
                                {record.Description.length > 60
                                    ? record.Description.substring(0, 60) + '...'
                                    : record.Description}
                            </Text>
                        )}
                    </div>
                </div>
            ),
        },
        {
            title: 'تعداد کاربران',
            dataIndex: 'UsersCount',
            key: 'UsersCount',
            width: 130,
            align: 'center',
            render: (count: number) => (
                <Tag icon={<TeamOutlined />} color={count > 0 ? 'green' : 'default'} style={{ borderRadius: 6 }}>
                    {count} کاربر
                </Tag>
            ),
        },
    ];

    return (
        <MainLayout>
            {/* هدر */}
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Space>
                        <Button
                            icon={<ArrowLeftOutlined />}
                            onClick={() => router.visit('/users')}
                            style={{ borderColor: THEME.primary, color: THEME.primary }}
                        >
                            بازگشت به لیست
                        </Button>
                        <div>
                            <Title level={3} style={{ margin: 0 }}>
                                <SafetyOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                                مدیریت نقش‌های کاربر
                            </Title>
                            <Text type="secondary">
                                کاربر: <Text strong style={{ color: THEME.primary }}>{user.FullName || user.UserName}</Text>
                                {' '}
                                (@{user.UserName})
                            </Text>
                        </div>
                    </Space>
                </Col>
                <Col>
                    <Button
                        type="primary"
                        icon={<SaveOutlined />}
                        size="large"
                        loading={saving}
                        disabled={!hasChanges}
                        onClick={handleSave}
                        style={hasChanges ? STYLES.primaryButton : undefined}
                    >
                        ذخیره تغییرات
                    </Button>
                </Col>
            </Row>

            {/* هشدار تغییرات */}
            {hasChanges && (
                <Alert
                    message="تغییرات ذخیره نشده‌اند"
                    description="برای اعمال تغییرات، دکمه ذخیره را کلیک کنید."
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16, borderRadius: 8 }}
                />
            )}

            {/* کارت اطلاعات کاربر */}
            <Card
                style={{
                    marginBottom: 16,
                    ...STYLES.card,
                    background: THEME.primaryGradientLight,
                }}
            >
                <Row align="middle" gutter={16}>
                    <Col>
                        <Avatar
                            size={64}
                            style={{
                                background: THEME.primaryGradient,
                                fontSize: 24,
                            }}
                        >
                            {user.FirstName?.charAt(0) || <UserOutlined />}
                        </Avatar>
                    </Col>
                    <Col flex="auto">
                        <div>
                            <Title level={4} style={{ margin: 0 }}>
                                {user.FullName || user.UserName}
                            </Title>
                            <Space size="middle" style={{ marginTop: 4 }}>
                                <Text type="secondary">
                                    <UserOutlined /> {user.UserName}
                                </Text>
                                <Tag color="purple" style={{ borderRadius: 6 }}>کد: {user.UserCode}</Tag>
                                {user.Email && (
                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                        {user.Email}
                                    </Text>
                                )}
                                {user.Mobile && (
                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                        {user.Mobile}
                                    </Text>
                                )}
                            </Space>
                        </div>
                    </Col>
                </Row>
            </Card>

            {/* آمار */}
            <Row gutter={16} style={{ marginBottom: 16 }}>
                <Col xs={12} sm={8}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.primary}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>کل نقش‌ها</span>}
                            value={roles.length}
                            prefix={<SafetyOutlined style={{ color: THEME.primary }} />}
                            valueStyle={{ color: THEME.primary, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={8}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.success}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>نقش‌های فعلی کاربر</span>}
                            value={selectedRoleIds.size}
                            prefix={<CheckCircleOutlined style={{ color: THEME.success }} />}
                            valueStyle={{ color: THEME.success, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
                <Col xs={24} sm={8}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.warning}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>نقش‌های بدون کاربر</span>}
                            value={roles.filter((r: Role) => r.UsersCount === 0).length}
                            prefix={<StopOutlined style={{ color: THEME.warning }} />}
                            valueStyle={{ color: THEME.warning, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
            </Row>

            {/* جدول */}
            <Card style={STYLES.card}>
                {/* نوار ابزار: سرچ + دکمه‌های انتخاب همه */}
                <Row gutter={[16, 16]} align="middle" style={{ marginBottom: 16 }}>
                    <Col xs={24} md={14}>
                        <Input
                            placeholder="جستجوی زنده در نقش‌ها..."
                            prefix={<SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} md={10}>
                        <Space>
                            <Button
                                icon={<CheckSquareOutlined />}
                                onClick={() => handleSelectAll(true)}
                                disabled={allSelected}
                                style={{
                                    background: THEME.success,
                                    borderColor: THEME.success,
                                    color: '#fff',
                                }}
                                size="large"
                            >
                                انتخاب همه
                            </Button>
                            <Button
                                icon={<BorderOutlined />}
                                onClick={() => handleSelectAll(false)}
                                disabled={!someSelected}
                                danger
                                size="large"
                            >
                                برداشتن همه
                            </Button>
                        </Space>
                    </Col>
                </Row>

                <DataGrid
                    columns={[]}
                    dataSource={filteredRoles}
                    customColumns={customColumns}
                    rowKey="RoleID"
                    showRowNumber={false}
                    showColumnSearch={false}
                    pageSize={15}
                />
            </Card>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}