import {
    Card,
    Button,
    Space,
    Tag,
    Typography,
    Row,
    Col,
    Progress,
    Descriptions,
} from 'antd';
import {
    ArrowLeftOutlined,
    ProjectOutlined,
    CrownOutlined,
    TeamOutlined,
    UserOutlined,
    CalendarOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import DataGrid from '../../Components/DataGrid';
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

export default function ProjectShow() {
    const { project, members } = usePage().props as unknown as { project: Project; members: Member[] };

    const activeMembers = (members || []).filter((m) => toBool(m.IsActive));

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
    ];

    return (
        <MainLayout>
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Space>
                        <Button
                            icon={<ArrowLeftOutlined />}
                            onClick={() => router.visit('/projects')}
                            style={{ borderColor: THEME.primary, color: THEME.primary }}
                        >
                            بازگشت به لیست
                        </Button>
                        <div>
                            <Title level={3} style={{ margin: 0 }}>
                                <ProjectOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                                {project.ProjectTitle}
                            </Title>
                            <Text type="secondary">
                                کد پروژه: <span style={STYLES.codeBadge}>{project.ProjectCode}</span>
                            </Text>
                        </div>
                    </Space>
                </Col>
            </Row>

            <Card style={{ marginBottom: 16, ...STYLES.card }}>
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

            <Card style={STYLES.card} title={<><TeamOutlined /> اعضای پروژه ({activeMembers.length} نفر)</>}>
                <DataGrid
                    columns={[]}
                    dataSource={activeMembers}
                    customColumns={columns}
                    rowKey="ProjectMemberID"
                    showColumnSearch={false}
                />
            </Card>
        </MainLayout>
    );
}