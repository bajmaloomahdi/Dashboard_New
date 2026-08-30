import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Input,
    Select,
    Space,
    Tag,
    Tooltip,
    Typography,
    Row,
    Col,
    Popconfirm,
    Progress,
} from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    TeamOutlined,
    ProjectOutlined,
    EyeOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import ProjectFormModal from './ProjectFormModal';
import ProjectMembersModal from './ProjectMembersModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import { gregorianToJalaliDisplay } from '../../Utils/jalali';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Project {
    ProjectID: number;
    ProjectCode: string;
    ProjectTitle: string;
    Description: string | null;
    StartDate: string | null;
    PlannedEndDate: string | null;
    ActualEndDate: string | null;
    ProjectStatusID: number;
    ProjectStatusTitle: string;
    ProgressPercent: number;
    IsActive: boolean | number;
    CreatorName: string;
    ResponsibleName: string;
    MemberCount: number;
    Date_InsertFirst: string;
}

interface Option {
    UserID: number;
    FullName: string;
}
interface StatusOption {
    ProjectStatusID: number;
    Title: string;
}

export default function ProjectsIndex() {
    const { projects, users, statuses, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<number | undefined>(undefined);
    const [activeFilter, setActiveFilter] = useState<string | undefined>(undefined);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingProject, setEditingProject] = useState<Project | null>(null);

    const [membersModal, setMembersModal] = useState<{ open: boolean; project: Project | null }>({
        open: false,
        project: null,
    });

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };
    const closeNotification = () => setNotification((p) => ({ ...p, open: false }));

    const handleCreate = () => {
        setEditingProject(null);
        setModalOpen(true);
    };
    const handleEdit = (project: Project) => {
        setEditingProject(project);
        setModalOpen(true);
    };
    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingProject(null);
    };
    const handleToggleActive = (id: number) => {
        router.post(`/projects/${id}/toggle`, {}, { preserveScroll: true });
    };
    const handleReset = () => {
        setSearchText('');
        setStatusFilter(undefined);
        setActiveFilter(undefined);
    };

    const filteredProjects = (projects || []).filter((p: Project) => {
        const matchSearch = searchText
            ? p.ProjectTitle?.toLowerCase().includes(searchText.toLowerCase()) ||
              p.ProjectCode?.toLowerCase().includes(searchText.toLowerCase())
            : true;
        const matchStatus = statusFilter === undefined || p.ProjectStatusID === statusFilter;
        const matchActive =
            activeFilter === undefined || activeFilter === ''
                ? true
                : columnHelpers.toBool(p.IsActive) === (activeFilter === '1');
        return matchSearch && matchStatus && matchActive;
    });

    const customColumns: ColumnsType<Project> = [
        {
            title: 'کد',
            dataIndex: 'ProjectCode',
            key: 'ProjectCode',
            width: 110,
            align: 'center',
            render: (code: string) => <span style={STYLES.codeBadge}>{code}</span>,
        },
        {
            title: 'عنوان پروژه',
            dataIndex: 'ProjectTitle',
            key: 'ProjectTitle',
            render: (title: string, rec: Project) => (
                <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                    <Text strong>{title}</Text>
                    {rec.Description && (
                        <Text type="secondary" style={{ fontSize: 11 }}>
                            {rec.Description.length > 70 ? rec.Description.substring(0, 70) + '...' : rec.Description}
                        </Text>
                    )}
                </div>
            ),
        },
        {
            title: 'وضعیت',
            dataIndex: 'ProjectStatusTitle',
            key: 'ProjectStatusTitle',
            width: 130,
            align: 'center',
            render: (title: string) => <Tag color="blue" style={{ borderRadius: 6 }}>{title}</Tag>,
        },
        {
            title: 'مسئول',
            dataIndex: 'ResponsibleName',
            key: 'ResponsibleName',
            width: 150,
            align: 'center',
            render: (name: string) =>
                name ? (
                    <Space size={4}>
                        <TeamOutlined style={{ color: '#D97706' }} />
                        <Text>{name}</Text>
                    </Space>
                ) : (
                    <Text type="secondary">—</Text>
                ),
        },
        {
            title: 'ایجاد کننده',
            dataIndex: 'CreatorName',
            key: 'CreatorName',
            width: 150,
            align: 'center',
            render: (name: string) => (name ? <Text>{name}</Text> : <Text type="secondary">—</Text>),
        },
        {
            title: 'تاریخ ایجاد',
            dataIndex: 'Date_InsertFirst',
            key: 'Date_InsertFirst',
            width: 120,
            align: 'center',
            render: (d: string | null) =>
                d ? <span style={STYLES.dateBadge}>{gregorianToJalaliDisplay(d)}</span> : <Text type="secondary">—</Text>,
        },
        {
            title: 'اعضا',
            dataIndex: 'MemberCount',
            key: 'MemberCount',
            width: 90,
            align: 'center',
            render: (count: number) => (
                <Tag color="purple" style={{ borderRadius: 6 }}>{count} نفر</Tag>
            ),
        },
        {
            title: 'پیشرفت',
            dataIndex: 'ProgressPercent',
            key: 'ProgressPercent',
            width: 160,
            align: 'center',
            render: (pct: number) => (
                <Progress percent={Number(pct) || 0} size="small" />
            ),
        },
        {
            title: 'تاریخ شروع',
            dataIndex: 'StartDate',
            key: 'StartDate',
            width: 120,
            align: 'center',
            render: (d: string | null) =>
                d ? <span style={STYLES.dateBadge}>{gregorianToJalaliDisplay(d)}</span> : <Text type="secondary">—</Text>,
        },
        {
            title: 'فعالیت',
            key: 'isActive',
            width: 110,
            align: 'center',
            render: (_, rec: Project) => {
                const isActive = columnHelpers.toBool(rec.IsActive);
                return (
                    <Tag icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />} color={isActive ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                        {isActive ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 210,
            align: 'center',
            render: (_, rec: Project) => {
                const isActive = columnHelpers.toBool(rec.IsActive);
                return (
                    <Space>
                        <Tooltip title="مشاهده جزئیات">
                            <Button
                                type="text"
                                icon={<EyeOutlined />}
                                style={{ color: THEME.primary }}
                                onClick={() => router.visit(`/projects/${rec.ProjectID}`)}
                            />
                        </Tooltip>
                        <Tooltip title="ویرایش">
                            <Button type="text" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={() => handleEdit(rec)} />
                        </Tooltip>
                        <Tooltip title="مدیریت اعضا">
                            <Button
                                type="text"
                                icon={<TeamOutlined />}
                                style={{ color: '#722ed1' }}
                                onClick={() => setMembersModal({ open: true, project: rec })}
                            />
                        </Tooltip>
                        <Popconfirm
                            title={isActive ? 'غیرفعال کردن پروژه' : 'فعال کردن پروژه'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(rec.ProjectID)}
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
                        <ProjectOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                        پروژه‌ها
                    </Title>
                    <Text type="secondary">ایجاد و مدیریت پروژه‌ها و اعضای آن‌ها</Text>
                </Col>
                <Col>
                    <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                        پروژه جدید
                    </Button>
                </Col>
            </Row>

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={10} md={10}>
                        <Input
                            placeholder="جستجو در کد یا عنوان پروژه..."
                            prefix={<SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={7} md={7}>
                        <Select
                            placeholder="وضعیت پروژه"
                            value={statusFilter}
                            onChange={(v) => setStatusFilter(v)}
                            allowClear
                            size="large"
                            style={{ width: '100%' }}
                            options={(statuses || []).map((s: StatusOption) => ({ value: s.ProjectStatusID, label: s.Title }))}
                        />
                    </Col>
                    <Col xs={24} sm={4} md={4}>
                        <Select
                            placeholder="فعالیت"
                            value={activeFilter}
                            onChange={(v) => setActiveFilter(v)}
                            allowClear
                            size="large"
                            style={{ width: '100%' }}
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={3} md={3}>
                        <Button icon={<ReloadOutlined />} onClick={handleReset} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={filteredProjects}
                    customColumns={customColumns}
                    rowKey="ProjectID"
                    showColumnSearch={false}
                />
            </Card>

            <ProjectFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingProject={editingProject}
                users={users || []}
                statuses={statuses || []}
            />

            <ProjectMembersModal
                open={membersModal.open}
                onClose={() => setMembersModal({ open: false, project: null })}
                projectId={membersModal.project?.ProjectID ?? null}
                projectTitle={membersModal.project?.ProjectTitle}
                users={users || []}
            />

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}