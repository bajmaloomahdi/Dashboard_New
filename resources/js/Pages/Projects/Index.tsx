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
    CalendarOutlined,
    UserOutlined,
    CrownOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import ProjectFormModal from './ProjectFormModal';
import ProjectMembersModal from './ProjectMembersModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { THEME, STYLES, columnHelpers } from '../../theme';
import { gregorianToJalaliDisplay } from '../../Utils/jalali';

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
    ProjectPriorityID: number | null;
    PriorityName: string | null;
    PriorityColor: string | null;
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
interface PriorityOption {
    ProjectPriorityID: number;
    Name: string;
    ColorHex: string;
}

export default function ProjectsIndex() {
    const { projects, users, statuses, priorities, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState('');
    const [statusFilter, setStatusFilter] = useState<number | undefined>(undefined);
    const [activeFilter, setActiveFilter] = useState<string | undefined>(undefined);
    const [priorityFilter, setPriorityFilter] = useState<number | undefined>(undefined);

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
        setPriorityFilter(undefined);
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
        const matchPriority = priorityFilter === undefined || p.ProjectPriorityID === priorityFilter;
        return matchSearch && matchStatus && matchActive && matchPriority;
    });

    return (
        <MainLayout>
            <PageHeader
                icon={<ProjectOutlined />}
                title="پروژه‌ها"
                subtitle="ایجاد و مدیریت پروژه‌ها و اعضای آن‌ها"
                stats={[
                    { icon: <ProjectOutlined />, label: 'تعداد کل', value: `${(projects || []).length} پروژه` },
                    {
                        icon: <CheckCircleOutlined />,
                        label: 'فعال',
                        value: `${(projects || []).filter((p: Project) => columnHelpers.toBool(p.IsActive)).length} پروژه`,
                    },
                ]}
                actions={
                    <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                        پروژه جدید
                    </Button>
                }
            />

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

            {(priorities || []).length > 0 && (
                <div className="priority-filter-bar">
                    <span
                        className={`priority-chip ${priorityFilter === undefined ? 'active' : ''}`}
                        onClick={() => setPriorityFilter(undefined)}
                    >
                        همه
                    </span>
                    {(priorities || []).map((p: PriorityOption) => (
                        <span
                            key={p.ProjectPriorityID}
                            className={`priority-chip ${priorityFilter === p.ProjectPriorityID ? 'active' : ''}`}
                            style={
                                priorityFilter === p.ProjectPriorityID
                                    ? { background: p.ColorHex, color: '#fff', borderColor: p.ColorHex }
                                    : { borderColor: p.ColorHex, color: p.ColorHex }
                            }
                            onClick={() => setPriorityFilter(priorityFilter === p.ProjectPriorityID ? undefined : p.ProjectPriorityID)}
                        >
                            <span
                                className="priority-dot"
                                style={{ background: priorityFilter === p.ProjectPriorityID ? '#fff' : p.ColorHex }}
                            />
                            {p.Name}
                        </span>
                    ))}
                </div>
            )}

            <Card style={STYLES.card} bodyStyle={{ padding: 20 }}>
                {filteredProjects.length === 0 ? (
                    <div style={{ textAlign: 'center', padding: '48px 0', color: THEME.textLight }}>
                        هیچ پروژه‌ای یافت نشد
                    </div>
                ) : (
                    <div className="project-cards-grid">
                        {filteredProjects.map((rec: Project) => {
                            const isActive = columnHelpers.toBool(rec.IsActive);
                            return (
                                <div
                                    className="project-card"
                                    key={rec.ProjectID}
                                    onClick={() => router.visit(`/projects/${rec.ProjectID}`)}
                                    style={rec.PriorityColor ? { borderInlineEndColor: rec.PriorityColor } : undefined}
                                >
                                    <div className="project-card-header">
                                        <span style={STYLES.codeBadge}>{rec.ProjectCode}</span>
                                        <Space size={4}>
                                            {rec.PriorityName ? (
                                                <Tag
                                                    style={{
                                                        borderRadius: 6,
                                                        margin: 0,
                                                        color: rec.PriorityColor || undefined,
                                                        borderColor: rec.PriorityColor || undefined,
                                                        background: rec.PriorityColor ? `${rec.PriorityColor}18` : undefined,
                                                    }}
                                                >
                                                    {rec.PriorityName}
                                                </Tag>
                                            ) : null}
                                            <Tag
                                                icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />}
                                                color={isActive ? 'success' : 'default'}
                                                style={{ borderRadius: 6, margin: 0 }}
                                            >
                                                {isActive ? 'فعال' : 'غیرفعال'}
                                            </Tag>
                                        </Space>
                                    </div>

                                    <Title level={5} style={{ margin: '10px 0 4px', color: THEME.textPrimary }}>
                                        <ProjectOutlined style={{ marginLeft: 6, color: THEME.primary }} />
                                        {rec.ProjectTitle}
                                    </Title>

                                    {rec.Description ? (
                                        <Text type="secondary" style={{ fontSize: 12, display: 'block', marginBottom: 10 }}>
                                            {rec.Description.length > 80 ? rec.Description.substring(0, 80) + '...' : rec.Description}
                                        </Text>
                                    ) : (
                                        <div style={{ marginBottom: 10 }} />
                                    )}

                                    <Tag color="blue" style={{ borderRadius: 6, marginBottom: 12 }}>
                                        {rec.ProjectStatusTitle}
                                    </Tag>

                                    <div className="project-card-progress">
                                        <Progress percent={Number(rec.ProgressPercent) || 0} size="small" />
                                    </div>

                                    <div className="project-card-meta">
                                        <Space size={6}>
                                            <CrownOutlined style={{ color: '#D97706' }} />
                                            <Text style={{ fontSize: 12 }}>{rec.ResponsibleName || '—'}</Text>
                                        </Space>
                                        <Space size={6}>
                                            <TeamOutlined style={{ color: '#722ed1' }} />
                                            <Text style={{ fontSize: 12 }}>{rec.MemberCount} نفر</Text>
                                        </Space>
                                    </div>

                                    <div className="project-card-meta">
                                        <Space size={6}>
                                            <UserOutlined style={{ color: THEME.textLight }} />
                                            <Text type="secondary" style={{ fontSize: 12 }}>{rec.CreatorName || '—'}</Text>
                                        </Space>
                                        <Space size={6}>
                                            <CalendarOutlined style={{ color: THEME.textLight }} />
                                            <Text type="secondary" style={{ fontSize: 12 }}>
                                                {rec.Date_InsertFirst ? gregorianToJalaliDisplay(rec.Date_InsertFirst) : '—'}
                                            </Text>
                                        </Space>
                                    </div>

                                    <div className="project-card-actions" onClick={(e) => e.stopPropagation()}>
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
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </Card>

            <style>{`
                .priority-filter-bar {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin-bottom: 16px;
                }
                .priority-chip {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 6px 14px;
                    border-radius: 999px;
                    border: 1.5px solid ${THEME.border};
                    background: #fff;
                    color: ${THEME.textSecondary};
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.18s ease;
                    user-select: none;
                }
                .priority-chip:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
                }
                .priority-chip.active {
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                }
                .priority-chip:not(.active):first-child {
                    border-color: ${THEME.primary};
                    color: ${THEME.primary};
                }
                .priority-chip.active:first-child {
                    background: ${THEME.primaryGradient};
                    color: #fff;
                    border-color: transparent;
                }
                .priority-dot {
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    display: inline-block;
                }
                .project-cards-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
                    gap: 18px;
                }
                .project-card {
                    background: #fff;
                    border: 1px solid ${THEME.border};
                    border-inline-end-width: 5px;
                    border-inline-end-style: solid;
                    border-inline-end-color: transparent;
                    border-radius: 14px;
                    padding: 16px;
                    cursor: pointer;
                    transition: transform 0.22s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.22s ease, border-color 0.22s ease;
                }
                .project-card:hover {
                    transform: translateY(-8px) scale(1.02);
                    box-shadow: 0 16px 32px rgba(102, 126, 234, 0.22);
                    border-color: ${THEME.borderPrimary};
                }
                .project-card-header {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                }
                .project-card-progress {
                    margin-bottom: 10px;
                }
                .project-card-meta {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    padding-top: 8px;
                    border-top: 1px dashed ${THEME.borderLight};
                    margin-top: 6px;
                }
                .project-card-actions {
                    display: flex;
                    justify-content: flex-end;
                    gap: 2px;
                    margin-top: 10px;
                    padding-top: 8px;
                    border-top: 1px solid ${THEME.borderLight};
                }
            `}</style>

            <ProjectFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingProject={editingProject}
                users={users || []}
                statuses={statuses || []}
                priorities={priorities || []}
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