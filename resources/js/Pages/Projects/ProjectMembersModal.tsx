import { useEffect, useState } from 'react';
import { Modal, Select, Button, Table, Tag, Space, Typography } from 'antd';
import { PlusOutlined, DeleteOutlined, CrownOutlined, TeamOutlined } from '@ant-design/icons';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import { getUserDisplayName, getUserOptionLabel } from '../../Utils/userHelpers';
import { toBool } from '../../Utils/bool';

interface Member {
    ProjectMemberID: number;
    ProjectID: number;
    UserID: number;
    FullName: string;
    FirstName?: string | null;
    LastName?: string | null;
    UserName?: string | null;
    PositionTitle?: string | null;
    IsResponsible: boolean | number;
    StartDate: string | null;
    EndDate: string | null;
    IsActive: boolean | number;
    Date_InsertFirst: string;
}

interface Option {
    UserID: number;
    FullName: string;
    FirstName?: string | null;
    LastName?: string | null;
    UserName?: string | null;
    PositionTitle?: string | null;
}

interface ProjectMembersModalProps {
    open: boolean;
    onClose: () => void;
    projectId: number | null;
    projectTitle?: string;
    users: Option[];
}

/** خواندن توکن CSRF از کوکی (همان روشی که Inertia/axios استفاده می‌کند) */
function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function api(url: string, method: string, body?: any): Promise<{ success: boolean; message: string }> {
    const headers: Record<string, string> = {
        'X-XSRF-TOKEN': getXsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/json',
    };
    const res = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    return {
        success: !!data.success,
        message: data.message || (res.ok ? 'عملیات انجام شد.' : 'خطا در ارتباط با سرور'),
    };
}

export default function ProjectMembersModal({
    open,
    onClose,
    projectId,
    projectTitle,
    users,
}: ProjectMembersModalProps) {
    const [members, setMembers] = useState<Member[]>([]);
    const [loading, setLoading] = useState(false);

    const [newUser, setNewUser] = useState<number | null>(null);
    const [saving, setSaving] = useState(false);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });

    const showNotification = (type: NotificationType, message: string) =>
        setNotification({ open: true, type, message });

    const loadMembers = async () => {
        if (!projectId) return;
        setLoading(true);
        try {
            const res = await fetch(`/projects/${projectId}/members`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            setMembers(data.members || []);
        } catch {
            showNotification('error', 'خطا در بارگذاری اعضای پروژه');
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open && projectId) {
            setNewUser(null);
            loadMembers();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, projectId]);

    const handleAdd = async () => {
        if (!projectId || !newUser) {
            showNotification('warning', 'یک کاربر را انتخاب کنید');
            return;
        }
        setSaving(true);
        // اعضای جدید همیشه به‌عنوان عضو عادی اضافه می‌شوند؛ هر پروژه فقط یک مسئول دارد
        // که در زمان ایجاد پروژه تعیین شده است. تاریخ عضویت هم نیازی به انتخاب دستی ندارد؛
        // زمان واقعی ثبت (Date_InsertFirst) به‌صورت خودکار توسط سرور ثبت می‌شود.
        const result = await api(`/projects/${projectId}/members`, 'POST', {
            UserID: newUser,
            IsResponsible: false,
        });
        setSaving(false);
        if (result.success) {
            setNewUser(null);
            showNotification('success', result.message);
            loadMembers();
        } else {
            showNotification('error', result.message);
        }
    };

    const handleRemove = async (userId: number) => {
        if (!projectId) return;
        setSaving(true);
        const result = await api(`/projects/${projectId}/members?UserID=${userId}`, 'DELETE');
        setSaving(false);
        if (result.success) {
            showNotification('success', result.message);
            loadMembers();
        } else {
            showNotification('error', result.message);
        }
    };

    // کاربرانی که هنوز عضو فعال پروژه نیستند
    const activeMembers = members.filter((m) => toBool(m.IsActive));
    const availableUsers = (users || []).filter(
        (u) => !activeMembers.some((m) => m.UserID === u.UserID)
    );

    const columns = [
        {
            title: '#',
            key: 'rowNumber',
            width: 50,
            align: 'center' as const,
            render: (_: any, __: Member, index: number) => index + 1,
        },
        {
            title: 'نام',
            dataIndex: 'FullName',
            key: 'FullName',
            render: (name: string, rec: Member) => (
                <Space>
                    {toBool(rec.IsResponsible) ? (
                        <CrownOutlined style={{ color: '#D97706' }} />
                    ) : (
                        <TeamOutlined style={{ color: '#667eea' }} />
                    )}
                    <div>
                        <div>{getUserDisplayName(rec)}</div>
                        {rec.PositionTitle ? (
                            <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                                {rec.PositionTitle}
                            </Typography.Text>
                        ) : null}
                    </div>
                    {toBool(rec.IsResponsible) ? <Tag color="gold">مسئول</Tag> : null}
                </Space>
            ),
        },
        {
            title: 'تاریخ ایجاد',
            dataIndex: 'Date_InsertFirst',
            key: 'Date_InsertFirst',
            width: 160,
            render: (d: string | null) => (d ? <Typography.Text>{gregorianToJalaliDateTimeDisplay(d)}</Typography.Text> : '—'),
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 100,
            align: 'center' as const,
            render: (_: any, rec: Member) => (
                <Button
                    danger
                    type="text"
                    icon={<DeleteOutlined />}
                    disabled={toBool(rec.IsResponsible) || saving}
                    title={toBool(rec.IsResponsible) ? 'مسئول قابل حذف نیست' : 'حذف عضو'}
                    onClick={() => handleRemove(rec.UserID)}
                />
            ),
        },
    ];

    return (
        <Modal
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <TeamOutlined style={{ color: '#667eea', fontSize: 20 }} />
                    <span>مدیریت اعضای پروژه{projectTitle ? `: ${projectTitle}` : ''}</span>
                </div>
            }
            open={open}
            onCancel={onClose}
            width={620}
            className="responsive-modal"
            footer={[
                <Button key="close" onClick={onClose}>
                    بستن
                </Button>,
            ]}
        >
            <Space style={{ marginBottom: 16, width: '100%' }} direction="vertical">
                <Space.Compact style={{ width: '100%' }}>
                    <Select
                        placeholder="افزودن عضو جدید"
                        showSearch
                        optionFilterProp="label"
                        style={{ flex: 1 }}
                        value={newUser}
                        onChange={(v) => setNewUser(v)}
                        options={availableUsers.map((u) => ({ value: u.UserID, label: getUserOptionLabel(u) }))}
                    />
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        loading={saving}
                        onClick={handleAdd}
                        disabled={!newUser}
                    >
                        افزودن
                    </Button>
                </Space.Compact>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    مسئول پروژه فقط یک نفر است و هنگام ایجاد پروژه تعیین می‌شود؛ اعضای جدید همیشه به‌عنوان عضو عادی اضافه می‌شوند.
                </Typography.Text>
            </Space>

            <Table
                className="unified-table"
                columns={columns}
                dataSource={activeMembers}
                rowKey="ProjectMemberID"
                loading={loading}
                size="middle"
                pagination={false}
                scroll={{ x: 'max-content' }}
                locale={{ emptyText: 'هنوز عضوی تعریف نشده است' }}
            />

            <style>{`
                .unified-table .ant-table-thead > tr > th {
                    background: #EEEBFB !important;
                }
            `}</style>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((p) => ({ ...p, open: false }))}
            />
        </Modal>
    );
}