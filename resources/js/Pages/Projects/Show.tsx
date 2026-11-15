import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Space,
    Tag,
    Typography,
    Progress,
    Descriptions,
    Select,
    message,
} from 'antd';
import {
    ProjectOutlined,
    CrownOutlined,
    TeamOutlined,
    UserOutlined,
    CalendarOutlined,
    ClockCircleOutlined,
    UsergroupAddOutlined,
    ThunderboltOutlined,
    InfoCircleOutlined,
    CommentOutlined,
    SolutionOutlined,
    } from '@ant-design/icons';
import { router, useForm, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import DataGrid from '../../Components/DataGrid';
import PageHeader from '../../Components/PageHeader';
import ProjectFormModal from './ProjectFormModal';
import ChipTabs from '../../Components/ChipTabs';
import ProjectMembersModal from './ProjectMembersModal';
import ProjectContractorsModal from './ProjectContractorsModal';
import ProjectContractorInteractionModal from './ProjectContractorInteractionModal';
import ProjectOwnerTab, { ProjectOwner, BrandOption } from './ProjectOwnerTab';
import ProjectTaskCreateModal from '../../Components/ProjectTaskCreateModal';
import ProjectComments from '../../Components/ProjectComments';
import ProjectEventsTab from './ProjectEventsTab';
import { THEME, STYLES } from '../../theme';
import { gregorianToJalaliDisplay, gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import DueDateBadge from '../../Components/DueDateBadge';
import { getUserDisplayName } from '../../Utils/userHelpers';
import { toBool } from '../../Utils/bool';
import type { ColumnsType } from 'antd/es/table';
import type { CalendarPermission } from '../../Types';

const { Title, Text } = Typography;

interface Project {
    DepartmentID?: number | string | null;
    DepartmentName?: string | null;
    ProjectStatusID: number;
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

interface Contractor {
    ProjectContractorID: number;
    PartyID: number;
    OfficialName: string | null;
    TradeName: string | null;
    IdentifierNumber: string | null;
    PartyNature: string | null;
    PartyIsActive: boolean | number | null;
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
    DueDate: string | null;
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

interface InteractionTypeOption {
    InteractionTypeID: number;
    Code: string;
    DisplayName: string;
}

export default function ProjectShow() {
    const { project, members, users, msgPriorities, contractors, interactionTypes, owner, allBrands, auth, calendarPermissions, projectStatuses, parentProject, childProjects, parentOptions, editStatuses, editPriorities, departmentOptions } = usePage().props as unknown as {
        departmentOptions?: { DepartmentID: number | string; DisplayName: string }[];
        parentOptions: { id: number | string; title: string; parentId: number | string | null }[];
        editStatuses: { ProjectStatusID: number; Title: string }[];
        editPriorities: { ProjectPriorityID: number; Name: string; ColorHex: string }[];
        parentProject?: { ProjectID: number; ProjectTitle: string } | null;
        childProjects?: { id: number; title: string }[];
        projectStatuses: { ProjectStatusID: number; Title: string }[];
        project: Project;
        members: Member[];
        users: UserOption[];
        msgPriorities: MsgPriorityOption[];
        contractors: Contractor[];
        interactionTypes: InteractionTypeOption[];
        owner: ProjectOwner | null;
        allBrands: BrandOption[];
        auth: { user: any };
        /** پچِ ۱۳۸ — تبِ «رویدادها» */
        calendarPermissions?: CalendarPermission[];
    };

    const [membersModalOpen, setMembersModalOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);
    const [contractorsModalOpen, setContractorsModalOpen] = useState(false);
    const [interactionModalFor, setInteractionModalFor] = useState<Contractor | null>(null);
    const [taskModalOpen, setTaskModalOpen] = useState(false);
    const [taskTargetMember, setTaskTargetMember] = useState<Member | null>(null);
    const [tasks, setTasks] = useState<ProjectTask[]>([]);
    const [tasksLoading, setTasksLoading] = useState(false);
    const [activeTab, setActiveTab] = useState<'info' | 'members' | 'contractors' | 'tasks' | 'events' | 'comments'>('info');
    const [eventCount, setEventCount] = useState<number | null>(null);
    const [commentsAndAttachmentsCount, setCommentsAndAttachmentsCount] = useState<number | null>(null);
    const [now, setNow] = useState(Date.now);
    const [statusFormOpen, setStatusFormOpen] = useState(false);
    const statusForm = useForm<{ ProjectStatusID: number | null }>({ ProjectStatusID: null });
    const [statusMessage, statusMessageHolder] = message.useMessage();
    const plannedDate = project.PlannedEndDate?.slice(0, 10);
    const deadline = plannedDate ? Date.parse(`${plannedDate}T23:59:59+03:30`) : NaN;
    const remainingHours = Math.ceil(Math.max(0, deadline - now) / 3_600_000);
    const formatCount = (value: number) => value.toLocaleString('fa-IR');
    const countdown = project.ActualEndDate ? 'پروژه پایان یافته'
        : !Number.isFinite(deadline) ? '—'
        : deadline <= now ? 'مهلت گذشته است'
        : `${formatCount(Math.floor(remainingHours / 24))} روز و ${formatCount(remainingHours % 24)} ساعت`;

    useEffect(() => {
        if (!Number.isFinite(deadline) || project.ActualEndDate) return;
        const timer = window.setInterval(() => setNow(Date.now()), 60_000);
        return () => window.clearInterval(timer);
    }, [deadline, project.ActualEndDate]);

    const activeMembers = (members || []).filter((m) => toBool(m.IsActive));
    const activeContractors = (contractors || []).filter((c) => toBool(c.IsActive));

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

    const handleContractorsModalClose = () => {
        setContractorsModalOpen(false);
        router.reload({ only: ['contractors'] });
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
            title: 'مهلت اجرا',
            dataIndex: 'DueDate',
            key: 'DueDate',
            width: 170,
            align: 'center',
            render: (d: string | null) => <DueDateBadge value={d} />,
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
                      render: (_: any, rec: Member) => (
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
        { key: 'contractors' as const, label: 'پیمانکار', icon: <SolutionOutlined />, count: activeContractors.length },
        { key: 'tasks' as const, label: 'وظیفه‌ها', icon: <ThunderboltOutlined />, count: tasks.length },
        { key: 'events' as const, label: 'رویدادها', icon: <CalendarOutlined />, count: eventCount },
        { key: 'comments' as const, label: 'نظرات و ضمیمه‌ها', icon: <CommentOutlined />, count: commentsAndAttachmentsCount },
    ];

    const contractorColumns: ColumnsType<Contractor> = [
        {
            title: 'طرف‌حساب',
            key: 'name',
            render: (_: any, rec: Contractor) => (
                <Space>
                    <SolutionOutlined style={{ color: '#667eea' }} />
                    <div>
                        <div>
                            <a href={`/crm/parties/${rec.PartyID}?tab=interactions`}>{rec.OfficialName || `طرف‌حسابِ #${rec.PartyID}`}</a>
                            {!toBool(rec.PartyIsActive) ? (
                                <Tag color="red" style={{ marginInlineStart: 6 }}>غیرفعال در «مخاطبین و ارتباطات»</Tag>
                            ) : null}
                        </div>
                        {rec.IdentifierNumber ? (
                            <Text type="secondary" style={{ fontSize: 12 }}>
                                {rec.IdentifierNumber}
                            </Text>
                        ) : null}
                    </div>
                </Space>
            ),
        },
        {
            title: 'تاریخ افزودن',
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
                      width: 150,
                      align: 'center' as const,
                      render: (_: any, rec: Contractor) => (
                          <Button
                              size="small"
                              icon={<CommentOutlined />}
                              onClick={() => setInteractionModalFor(rec)}
                              style={{ borderColor: THEME.primary, color: THEME.primary }}
                          >
                              ثبتِ تعامل
                          </Button>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <MainLayout>
            {statusMessageHolder}
            <PageHeader
                icon={<ProjectOutlined />}
                title={project.ProjectTitle}
                backHref="/projects"
                backLabel="بازگشت به لیست"
                actions={(
                    <Space wrap>
                        <Button onClick={() => setEditOpen(true)}>ویرایش پروژه</Button>
                        {isResponsible && !!projectStatuses?.length && (statusFormOpen ? (
                            <>
                                <Select
                                    aria-label="وضعیت جدید پروژه"
                                    placeholder="وضعیت جدید پروژه"
                                    style={{ minWidth: 160 }}
                                    value={statusForm.data.ProjectStatusID ?? undefined}
                                    disabled={statusForm.processing}
                                    status={statusForm.errors.ProjectStatusID ? 'error' : undefined}
                                    onChange={(value) => statusForm.setData('ProjectStatusID', value)}
                                    options={projectStatuses.map((status) => ({ value: Number(status.ProjectStatusID), label: status.Title }))}
                                />
                                <Button loading={statusForm.processing} disabled={statusForm.data.ProjectStatusID === null}
                                    onClick={() => statusForm.patch(`/projects/${project.ProjectID}/status`, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            setStatusFormOpen(false);
                                            statusForm.reset();
                                            statusMessage.success('وضعیت پروژه ذخیره شد.');
                                        },
                                        onError: (errors) => statusMessage.error(errors.ProjectStatusID || 'ذخیرهٔ وضعیت پروژه انجام نشد.'),
                                    })}
                                >ذخیره وضعیت</Button>
                                <Button disabled={statusForm.processing} onClick={() => {
                                    statusForm.reset();
                                    statusForm.clearErrors();
                                    setStatusFormOpen(false);
                                }}>انصراف</Button>
                            </>
                        ) : <Button onClick={() => setStatusFormOpen(true)}>تغییر وضعیت</Button>)}
                    </Space>
                )}
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
                    { icon: <CalendarOutlined />, label: 'پایان پیش‌بینی‌شده', value: plannedDate ? gregorianToJalaliDisplay(plannedDate) : '—' },
                    { icon: <ClockCircleOutlined />, label: 'زمان باقی‌مانده', value: countdown },
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

            <ProjectFormModal open={editOpen} onClose={() => setEditOpen(false)}
                editingProject={project} users={users || []} statuses={editStatuses || []}
                priorities={editPriorities || []} parentOptions={parentOptions || []} departmentOptions={departmentOptions} returnToDetails />
            <ChipTabs items={tabDefs} activeKey={activeTab} onChange={setActiveTab} />
            {activeTab === 'info' && (
                <Card style={STYLES.card}>
                    <ProjectOwnerTab projectId={project.ProjectID} owner={owner ?? null} allBrands={allBrands || []} canManage={isResponsible} />
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
                        <Descriptions.Item label="دپارتمان">{project.DepartmentName || '—'}</Descriptions.Item>
                        <Descriptions.Item label="پروژهٔ والد">
                            {parentProject ? <a href={`/projects/${parentProject.ProjectID}`}>{parentProject.ProjectTitle}</a> : '—'}
                        </Descriptions.Item>
                        <Descriptions.Item label="زیرپروژه‌ها" span={2}>
                            <Space wrap>{childProjects?.length ? childProjects.map((child) =>
                                <a key={child.id} href={`/projects/${child.id}`}>{child.title}</a>) : '—'}</Space>
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
                        gridKey="projects.details.members"
                        customColumns={columns}
                        rowKey="ProjectMemberID"
                        showColumnSearch={false}
                    />
                </Card>
            )}

            {activeTab === 'contractors' && (
                <Card
                    style={STYLES.card}
                    extra={
                        isResponsible ? (
                            <Button
                                type="primary"
                                size="small"
                                icon={<SolutionOutlined />}
                                style={STYLES.primaryButton}
                                onClick={() => setContractorsModalOpen(true)}
                            >
                                مدیریتِ پیمانکار
                            </Button>
                        ) : null
                    }
                >
                    <DataGrid
                        columns={[]}
                        dataSource={activeContractors}
                        gridKey="projects.details.contractors"
                        customColumns={contractorColumns}
                        rowKey="ProjectContractorID"
                        showColumnSearch={false}
                    />
                </Card>
            )}

            {activeTab === 'tasks' && (
                <Card style={STYLES.card}>
                    <DataGrid
                        columns={[]}
                        dataSource={tasks}
                        gridKey="projects.details.tasks"
                        customColumns={taskColumns}
                        rowKey="ProjectMessageID"
                        showColumnSearch={false}
                        loading={tasksLoading}
                    />
                </Card>
            )}

            <div hidden={activeTab !== 'events'}>
                <ProjectEventsTab key={project.ProjectID} projectId={Number(project.ProjectID)} currentUserId={Number(currentUserId)} permissions={calendarPermissions || []} onCount={setEventCount} />
            </div>
            <div hidden={activeTab !== 'comments'}>
                <ProjectComments key={project.ProjectID} projectId={project.ProjectID} onCount={setCommentsAndAttachmentsCount} />
            </div>

            <ProjectMembersModal
                open={membersModalOpen}
                onClose={handleMembersModalClose}
                projectId={project.ProjectID}
                projectTitle={project.ProjectTitle}
                users={users || []}
            />

            <ProjectContractorsModal
                open={contractorsModalOpen}
                onClose={handleContractorsModalClose}
                projectId={project.ProjectID}
                projectTitle={project.ProjectTitle}
            />

            {interactionModalFor ? (
                <ProjectContractorInteractionModal
                    open={!!interactionModalFor}
                    onClose={() => setInteractionModalFor(null)}
                    projectId={project.ProjectID}
                    projectTitle={project.ProjectTitle}
                    partyId={interactionModalFor.PartyID}
                    partyName={interactionModalFor.OfficialName || `طرف‌حسابِ #${interactionModalFor.PartyID}`}
                    users={users || []}
                    interactionTypes={interactionTypes || []}
                />
            ) : null}

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
