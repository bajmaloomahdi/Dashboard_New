import { useEffect, useMemo, useState } from 'react';
import { Card, Button, Select, Space, Tag, Typography, Alert, Avatar } from 'antd';
import {
    SaveOutlined,
    TeamOutlined,
    UserOutlined,
    DeleteOutlined,
    PlusOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { STYLES } from '../../theme';
import { toBool } from '../../Utils/bool';
import type { ColumnsType } from 'antd/es/table';

const { Text } = Typography;

interface Role {
    RoleID: number;
    RoleCode: string;
    RoleName: string;
    Description: string | null;
}

interface RoleUserRow {
    UserID: number;
    UserCode: string;
    UserName: string;
    FullName: string;
    Email: string | null;
    Mobile: string | null;
    IsActive: boolean | number;
    HasRole: boolean | number;
}

/**
 * مدیریتِ کاربرانِ یک نقش — سمتِ «Role»، طرفِ مقابلِ Users/Roles.tsx (که سمتِ «User» است).
 * هر دو رویِ همان یک جدولِ UserRoles کار می‌کنند؛ هیچ رابطهٔ موازیِ جدیدی نیست.
 *
 * یک Selectِ چندانتخابیِ Autocomplete هم برایِ نمایشِ اعضایِ فعلی و هم برایِ افزودن/حذف
 * استفاده می‌شود (مقدارش مستقیماً همان مجموعهٔ انتخاب‌شده است) — مثلِ Permissions.tsx،
 * تغییرات تا کلیکِ «ذخیره تغییرات» فقط محلی‌اند؛ آن‌وقت کلِ مجموعه یک‌جا (Bulk/
 * Transactional، از طریقِ sp_SaveRoleUsers) ارسال می‌شود.
 */
export default function RoleUsers() {
    const { role, roleUsers = [], flash, errors } = usePage().props as unknown as {
        role: Role;
        roleUsers: RoleUserRow[];
        flash?: { success?: string; error?: string };
        errors?: Record<string, string>;
    };

    const [selectedUserIds, setSelectedUserIds] = useState<number[]>([]);
    const [initialUserIds, setInitialUserIds] = useState<number[]>([]);
    const [saving, setSaving] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        const current = roleUsers.filter((u) => toBool(u.HasRole)).map((u) => u.UserID);
        setSelectedUserIds(current);
        setInitialUserIds(current);
    }, [roleUsers]);

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    // خطایِ Validationِ سرور (مثلاً UserIDِ نامعتبر/تکراری) — قبل از رسیدنِ درخواست به SP رد شده؛
    // همان NotificationModalِ بالا برایِ نمایشِ آن استفاده می‌شود.
    useEffect(() => {
        const firstError = errors ? Object.values(errors)[0] : undefined;
        if (firstError) showNotification('error', firstError);
    }, [errors]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const usersById = useMemo(() => {
        const map = new Map<number, RoleUserRow>();
        roleUsers.forEach((u) => map.set(u.UserID, u));
        return map;
    }, [roleUsers]);

    const selectedIdSet = useMemo(() => new Set(selectedUserIds), [selectedUserIds]);

    const hasChanges = useMemo(() => {
        if (selectedUserIds.length !== initialUserIds.length) return true;
        const initialSet = new Set(initialUserIds);
        return selectedUserIds.some((id) => !initialSet.has(id));
    }, [selectedUserIds, initialUserIds]);

    const selectedUsers = useMemo(
        () => selectedUserIds.map((id) => usersById.get(id)).filter((u): u is RoleUserRow => !!u),
        [selectedUserIds, usersById]
    );

    const handleRemove = (userId: number) => {
        setSelectedUserIds((prev) => prev.filter((id) => id !== userId));
    };

    const handleSave = () => {
        setSaving(true);
        router.post(
            `/roles/${role.RoleID}/users`,
            { user_ids: selectedUserIds },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['flash', 'roleUsers'],
                onFinish: () => setSaving(false),
            }
        );
    };

    const columns: ColumnsType<RoleUserRow> = [
        {
            title: 'کاربر',
            key: 'user',
            align: 'center',
            render: (_, record) => (
                <Space>
                    <Avatar size="small" icon={<UserOutlined />} />
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.FullName}</Text>
                        <Text type="secondary" style={{ fontSize: 11 }}>
                            {record.UserCode}
                        </Text>
                    </div>
                </Space>
            ),
        },
        {
            title: 'ایمیل',
            dataIndex: 'Email',
            key: 'Email',
            align: 'center',
            render: (v: string | null) => v || <Text type="secondary">—</Text>,
        },
        {
            title: 'موبایل',
            dataIndex: 'Mobile',
            key: 'Mobile',
            align: 'center',
            render: (v: string | null) => v || <Text type="secondary">—</Text>,
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 90,
            align: 'center',
            render: (_, record) => (
                <Button
                    type="text"
                    danger
                    icon={<DeleteOutlined />}
                    onClick={() => handleRemove(record.UserID)}
                />
            ),
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<TeamOutlined />}
                title="مدیریتِ کاربرانِ نقش"
                subtitle={`نقش: ${role.RoleName} (کد: ${role.RoleCode})`}
                backHref="/roles"
                backLabel="بازگشت به لیست"
                stats={[
                    { icon: <TeamOutlined />, label: 'کاربرانِ این نقش', value: `${selectedUserIds.length} نفر` },
                ]}
                actions={
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
                }
            />

            {hasChanges && (
                <Alert
                    message="تغییرات ذخیره نشده‌اند"
                    description="برای اعمال تغییرات، دکمه ذخیره را کلیک کنید."
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16, borderRadius: 8 }}
                />
            )}

            <Card style={{ ...STYLES.card, marginBottom: 16 }}>
                <Text style={{ fontSize: 13, display: 'block', marginBottom: 8 }}>
                    <PlusOutlined /> افزودن/حذفِ کاربر — جستجو بر اساسِ نام، کدِ کاربری یا ایمیل
                </Text>
                <Select
                    mode="multiple"
                    showSearch
                    allowClear
                    size="large"
                    style={{ width: '100%' }}
                    placeholder="یک یا چند کاربر را جستجو و انتخاب کنید..."
                    value={selectedUserIds}
                    onChange={(ids) => setSelectedUserIds(ids)}
                    optionFilterProp="label"
                    filterOption={(input, option) =>
                        (option?.label as string)?.toLowerCase().includes(input.toLowerCase())
                    }
                    options={roleUsers.map((u) => ({
                        value: u.UserID,
                        label: `${u.FullName} (${u.UserCode}${u.Email ? ' — ' + u.Email : ''})`,
                    }))}
                    optionRender={(opt) => {
                        const u = usersById.get(opt.value as number);
                        return (
                            <Space>
                                <UserOutlined />
                                <span>{u?.FullName}</span>
                                <Text type="secondary" style={{ fontSize: 11 }}>
                                    ({u?.UserCode})
                                </Text>
                            </Space>
                        );
                    }}
                    tagRender={(props) => {
                        const u = usersById.get(props.value as number);
                        return (
                            <Tag
                                closable={props.closable}
                                onClose={props.onClose}
                                style={{ borderRadius: 6, marginInlineEnd: 4 }}
                                color="blue"
                            >
                                {u?.FullName ?? props.label}
                            </Tag>
                        );
                    }}
                />
            </Card>

            <Card style={STYLES.card}>
                <Text strong style={{ display: 'block', marginBottom: 12 }}>
                    کاربرانِ این نقش ({selectedUsers.length} نفر)
                </Text>
                <DataGrid
                    columns={[]}
                    dataSource={selectedUsers}
                    customColumns={columns}
                    rowKey="UserID"
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
