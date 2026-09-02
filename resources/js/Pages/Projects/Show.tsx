import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Space,
    Tag,
    Typography,
    Progress,
    Descriptions,
} from 'antd';
import {
    ProjectOutlined,
    CrownOutlined,
    TeamOutlined,
    UserOutlined,
    CalendarOutlined,
    UsergroupAddOutlined,
    ThunderboltOutlined,
    InfoCircleOutlined,
    CommentOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import DataGrid from '../../Components/DataGrid';
import PageHeader from '../../Components/PageHeader';
import ChipTabs from '../../Components/ChipTabs';
import ProjectMembersModal from './ProjectMembersModal';
import ProjectTaskCreateModal from '../../Components/ProjectTaskCreateModal';
import ProjectComments from '../../Components/ProjectComments';
import { THEME, STYLES } from '../../theme';
import { gregorianToJalaliDisplay, gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import { getUserDisplayName } from '../../Utils/userHelpers';
import { toBool } from '../../Utils/bool';
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
    ProjectStatusTitle: string;
    ProjectPriorityID: number | null;
    PriorityName: string | null;
    PriorityColor: string | null;
    ProgressPercent: number;
    IsActive: boolean | number;
    Date_InsertFirst: string;
    CreatorName: string;
    ResponsibleName: string;
    MemberCount: number;
}

interface Member {
    ProjectMemberID: number;
    UserID: number;
    FullName: string;
    FirstName?: string | null;
    LastName?: string | null;
    UserName?: string | null;
    PositionTitle?: string | null;
    IsResponsible: boolean | number;
    StartDate: string | null;
    IsActive: boolean | number;
    Date_InsertFirst: string;
}

interface UserOption {
    UserID: number;
    FullName: string;
    FirstName?: string | null;
    LastName?: string | null;
    UserName?: string | null;
    PositionTitle?: string | null;
}

interface ProjectTask {
    ProjectMessageID: number;
    MessageID: number;
    MessageNumber: string;
    Subject: string;
    MessageText: string | null;
    CreateDate: string;
    SenderName: string;
    RecipientName: string | null;
    MessageStatusName: string | null;
    PriorityName: string | null;
}

interface MsgPriorityOption {
    msgPriorityID: number;
    Name: string;
}

export default function ProjectShow() {
    const { project, members, users, msgPriorities, auth } = usePage().props as unknown as {
        project: Project;
        members: Member[];
        users: UserOption[];
        msgPriorities: MsgPriorityOption[];
        auth: { user: any };
    };

    const [membersModalOpen, setMembersModalOpen] = useState(false);
    const [taskModalOpen, setTaskModalOpen] = useState(false);
    const [taskTargetMember, setTaskTargetMember] = useState<Member | null>(null);
    const [tasks, setTasks] = useState<ProjectTask[]>([]);
    const [tasksLoading, setTasksLoading] = useState(false);
    const [activeTab, setActiveTab] = useState<'info' | 'members' | 'tasks' | 'comments'>('info');

    const activeMembers = (members || []).filter((m) => toBool(m.IsActive));

    const currentUserId = Number(auth?.user?.UserID ?? auth?.user?.id ?? 0);
    const isResponsible = activeMembers.some(
        (m) => toBool(m.IsResponsible) && Number(m.UserID) === currentUserId
    );

    const loadTasks = async () => {
        setTasksLoading(true);
        try {
            const res = await fetch(`/projects/${project.ProjectID}/tasks`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const data = await res.json();
            setTasks(data.tasks || []);
        } catch {
            // بی‌صدا نادیده گرفته می‌شود؛ کاربر می‌تواند صفحه را رفرش کند
        } finally {
            setTasksLoading(false);
        }
    };

    useEffect(() => {
        loadTasks();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [project.ProjectID]);

    const handleMembersModalClose = () => {
        setMembersModalOpen(false);
        // بازخوانی اطلاعات پروژه و اعضا از سرور پس از تغییرات احتمالی
        router.reload({ only: ['project', 'members'] });
    };

    const handleTaskModalClose = (created: boolean) => {
        setTaskModalOpen(false);
        setTaskTargetMember(null);
        if (created) loadTasks();
    };

    const openTaskModalFor = (member: Member) => {
        setTaskTargetMember(member);
        setTaskModalOpen(true);
    };

    const taskColumns: ColumnsType<ProjectTask> = [
        {
            title: 'موضوع',
            dataIndex: 'Subject',
            key: 'Subject',
            width: 220,
            render: (subject: string, rec: ProjectTask) => (
                <a onClick={() => router.visit(`/messages/${rec.MessageID}`)} style={{ color: THEME.primary, cursor: 'pointer' }}>
                    {subject}
                </a>
            ),
        },
        {
            title: 'متن وظیفه',
            dataIndex: 'MessageText',
            key: 'MessageText',
            render: (text: string | null) =>
                text ? (
                    <div style={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{text}</div>
                ) : (
                    <Text type="secondary">—</Text>
                ),
        },
        {
            title: 'گیرنده',
            dataIndex: 'RecipientName',
            key: 'RecipientName',
            width: 150,
            align: 'center',
            render: (name: string | null) => name || <Text type="secondary">—</Text>,
        },
        {
            title: 'اولویت',
            dataIndex: 'PriorityName',
            key: 'PriorityName',
            width: 110,
            align: 'center',
            render: (name: string | null) => (name ? <Tag color="orange" style={{ borderRadius: 6 }}>{name}</Tag> : <Text type="secondary">—</Text>),
        },
        {
            title: 'وضعیت',
            dataIndex: 'MessageStatusName',
            key: 'MessageStatusName',
            width: 130,
            align: 'center',
            render: (name: string | null) => (name ? <Tag color="cyan" style={{ borderRadius: 6 }}>{name}</Tag> : '—'),
        },
        {
            title: 'تاریخ ایجاد',
            dataIndex: 'CreateDate',
            key: 'CreateDate',
            width: 170,
            align: 'center',
            render: (d: string | null) => (d ? gregorianToJalaliDateTimeDisplay(d) : <Text type="secondary">—</Text>),
        },
    ];

    const columns: ColumnsType<Member> = [
        {
            title: 'نام',
            key: 'FullName',
            render: (_: any, rec: Member) => (
                <Space>
                    {toBool(rec.IsResponsible) ? (
                        <CrownOutlined style={{ color: '#D97706' }} />
                    ) : (
                        <TeamOutlined style={{ color: '#667eea' }} />
                    )}
                    <div>
                        <div>{getUserDisplayName(rec)}</div>
                        {rec.PositionTitle ? (
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                {rec.PositionTitle}
                            </Text>
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
            width: 170,
            align: 'center',
            render: (d: string | null) => (d ? gregorianToJalaliDateTimeDisplay(d) : <Text type="secondary">—</Text>),
        },
        ...(isResponsible
            ? [
                  {
                      title: 'عملیات',
                      key: 'actions',
                      width: 130,
                      align: 'center' as const,
                      render: (_: any, rec: Member) =>
                          Number(rec.UserID) === currentUserId ? null : (
                              <Button
                                  size="small"
                                  icon={<ThunderboltOutlined />}
                                  onClick={() => openTaskModalFor(rec)}
                                  style={{ borderColor: THEME.primary, color: THEME.primary }}
                              >
                                  ایجاد وظیفه
                              </Button>
                          ),
                  },
              ]
            : []),
    ];

    const tabDefs = [
        { key: 'info' as const, label: 'اطلاعات پروژه', icon: <InfoCircleOutlined />, count: null },
        { key: 'members' as const, label: 'اعضا', icon: <TeamOutlined />, count: activeMembers.length },
        { key: 'tasks' as const, label: 'وظیفه‌ها', icon: <ThunderboltOutlined />, count: tasks.length },
        { key: 'comments' as const, label: 'نظرات و ضمیمه‌ها', icon: <CommentOutlined />, count: null },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<ProjectOutlined />}
                title={project.ProjectTitle}
                backHref="/projects"
                backLabel="بازگشت به لیست"
                tags={[
                    { label: project.ProjectCode },
                    { label: project.ProjectStatusTitle },
                    ...(project.PriorityName ? [{ label: `اولویت ${project.PriorityName}`, color: project.PriorityColor || '#fff' }] : []),
                ]}
                stats={[
                    { icon: <CrownOutlined />, label: 'مسئول', value: project.ResponsibleName || '—' },
                    { icon: <TeamOutlined />, label: 'اعضا', value: `${activeMembers.length} نفر` },
                    { icon: <UserOutlined />, label: 'ایجاد کننده', value: project.CreatorName || '—' },
                    {
                        icon: <CalendarOutlined />,
                        label: 'تاریخ ایجاد',
                        value: project.Date_InsertFirst ? gregorianToJalaliDisplay(project.Date_InsertFirst) : '—',
                    },
                ]}
                aside={
                    <>
                        <Progress
                            type="circle"
                            percent={Number(project.ProgressPercent) || 0}
                            size={112}
                            strokeColor="#fff"
                            trailColor="rgba(255,255,255,0.25)"
                            format={(p) => <span style={{ color: '#fff', fontWeight: 700 }}>{p}%</span>}
                        />
                        <Text style={{ color: 'rgba(255,255,255,0.85)', marginTop: 8 }}>پیشرفت پروژه</Text>
                    </>
                }
            />

            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />
            {activeTab === 'info' && (
                <Card style={STYLES.card}>
                    <Descriptions bordered column={{ xs: 1, sm: 2, md: 3 }} size="middle">
                        <Descriptions.Item label="وضعیت">
                            <Tag color="blue" style={{ borderRadius: 6 }}>{project.ProjectStatusTitle}</Tag>
                        </Descriptions.Item>
                        <Descriptions.Item label={<><UserOutlined /> ایجاد کننده</>}>
                            {project.CreatorName || <Text type="secondary">—</Text>}
                        </Descriptions.Item>
                        <Descriptions.Item label={<><CalendarOutlined /> تاریخ ایجاد</>}>
                            {project.Date_InsertFirst ? gregorianToJalaliDateTimeDisplay(project.Date_InsertFirst) : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label={<><CrownOutlined /> مسئول پروژه</>}>
                            {project.ResponsibleName || <Text type="secondary">—</Text>}
                        </Descriptions.Item>
                        <Descriptions.Item label="تاریخ شروع">
                            {project.StartDate ? gregorianToJalaliDisplay(project.StartDate) : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="تاریخ پایان پیش‌بینی">
                            {project.PlannedEndDate ? gregorianToJalaliDisplay(project.PlannedEndDate) : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="تاریخ پایان واقعی">
                            {project.ActualEndDate ? gregorianToJalaliDisplay(project.ActualEndDate) : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="پیشرفت" span={2}>
                            <Progress percent={Number(project.ProgressPercent) || 0} />
                        </Descriptions.Item>
                        {project.Description ? (
                            <Descriptions.Item label="توضیحات" span={3}>
                                {project.Description}
                            </Descriptions.Item>
                        ) : null}
                    </Descriptions>
                </Card>
            )}

            {activeTab === 'members' && (
                <Card
                    style={STYLES.card}
                    extra={
                        isResponsible ? (
                            <Button
                                type="primary"
                                size="small"
                                icon={<UsergroupAddOutlined />}
                                style={STYLES.primaryButton}
                                onClick={() => setMembersModalOpen(true)}
                            >
                                مدیریت اعضا
                            </Button>
                        ) : null
                    }
                >
                    <DataGrid
                        columns={[]}
                        dataSource={activeMembers}
                        customColumns={columns}
                        rowKey="ProjectMemberID"
                        showColumnSearch={false}
                    />
                </Card>
            )}

            {activeTab === 'tasks' && (
                <Card style={STYLES.card}>
                    <DataGrid
                        columns={[]}
                        dataSource={tasks}
                        customColumns={taskColumns}
                        rowKey="ProjectMessageID"
                        showColumnSearch={false}
                        loading={tasksLoading}
                    />
                </Card>
            )}

            {activeTab === 'comments' && <ProjectComments projectId={project.ProjectID} />}

            <ProjectMembersModal
                open={membersModalOpen}
                onClose={handleMembersModalClose}
                projectId={project.ProjectID}
                projectTitle={project.ProjectTitle}
                users={users || []}
            />

            <ProjectTaskCreateModal
                open={taskModalOpen}
                onClose={handleTaskModalClose}
                projectId={project.ProjectID}
                projectTitle={project.ProjectTitle}
                member={taskTargetMember}
                priorities={msgPriorities || []}
            />
        </MainLayout>
    );
}