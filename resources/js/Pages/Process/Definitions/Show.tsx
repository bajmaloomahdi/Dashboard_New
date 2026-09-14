import { useState } from 'react';
import { Card, Descriptions, Tag, Space, Button, Table, Typography, Popconfirm, Empty } from 'antd';
import {
    ApartmentOutlined,
    EditOutlined,
    CheckCircleOutlined,
    StopOutlined,
    PlusOutlined,
    CopyOutlined,
    BranchesOutlined,
    ClockCircleOutlined,
    EyeOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import DefinitionFormModal from './DefinitionFormModal';
import type { WorkflowCategory } from './CategoryManagerModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { THEME, STYLES } from '../../../theme';
import { gregorianToJalaliDateTimeDisplay } from '../../../Utils/jalali';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

const gradientHeadStyle = { background: THEME.primaryGradient, border: 'none', borderRadius: '12px 12px 0 0' };
const whiteTitle = (text: any) => <span style={{ color: '#fff', fontWeight: 600 }}>{text}</span>;

interface Definition {
    DefinitionID: number;
    Code: string;
    Name: string;
    Description: string | null;
    EntityType: string;
    IsActive: boolean | number | string;
    CategoryID: number | null;
    CategoryName: string | null;
    Date_InsertFirst: string;
    Date_LastUpdate: string | null;
}

interface VersionRow {
    VersionID: number;
    VersionNo: number;
    Status: string; // DRAFT | ACTIVE | ARCHIVED
    PublishedAt: string | null;
    ArchivedAt: string | null;
    ClonedFromVersionID: number | null;
    PublishedByName: string | null;
    Date_InsertFirst: string;
    StepCount: number;
    InstanceCount: number;
}

const versionStatusTag: Record<string, { color: string; label: string }> = {
    DRAFT: { color: 'default', label: 'پیش‌نویس' },
    ACTIVE: { color: 'success', label: 'فعال' },
    ARCHIVED: { color: 'purple', label: 'بایگانی‌شده' },
};

export default function ProcessDefinitionShow() {
    const props = usePage().props as unknown as {
        definition: Definition;
        versions: VersionRow[];
        categories: WorkflowCategory[];
        permissions: string[];
    };

    const [data, setData] = useState({ definition: props.definition, versions: props.versions || [] });
    const [modalOpen, setModalOpen] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [creatingDraft, setCreatingDraft] = useState(false);
    const [cloningId, setCloningId] = useState<number | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const canDesign = (props.permissions || []).includes('WORKFLOW_DESIGN');
    const def = data.definition;
    const isActive = toBool(def.IsActive);

    /** بازخوانیِ definition/versions از همان GET /workflow/definitions/{id} موجود (بدونِ Contractِ جدید) */
    const refresh = async () => {
        const res = await wfApi(`/workflow/definitions/${def.DefinitionID}`);
        if (res.ok && res.success) {
            setData({ definition: res.definition, versions: res.versions || [] });
        }
    };

    const handleModalSuccess = async (message: string) => {
        // این صفحه همیشه در حالتِ ویرایشِ همان Definitionِ فعلی باز می‌شود (isNew همیشه false است).
        setModalOpen(false);
        notify('success', message);
        await refresh();
    };

    const handleToggle = async () => {
        setToggling(true);
        const res = await wfApi(`/workflow/definitions/${def.DefinitionID}/toggle`, 'POST');
        setToggling(false);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        await refresh();
    };

    const handleCreateDraft = async () => {
        setCreatingDraft(true);
        const res = await wfApi(`/workflow/definitions/${def.DefinitionID}/versions`, 'POST');
        setCreatingDraft(false);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        router.visit(`/process/versions/${res.versionId}`);
    };

    const handleClone = async (versionId: number) => {
        setCloningId(versionId);
        const res = await wfApi(`/workflow/versions/${versionId}/clone`, 'POST');
        setCloningId(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        await refresh();
    };

    const versionColumns: ColumnsType<VersionRow> = [
        { title: 'نسخه', dataIndex: 'VersionNo', key: 'VersionNo', width: 90, align: 'center', render: (v: number) => <Text strong>v{v}</Text> },
        {
            title: 'وضعیت',
            dataIndex: 'Status',
            key: 'Status',
            width: 120,
            align: 'center',
            render: (v: string) => {
                const s = versionStatusTag[v] ?? { color: 'default', label: v };
                return <Tag color={s.color} style={{ borderRadius: 6 }}>{s.label}</Tag>;
            },
        },
        { title: 'تعدادِ Step', dataIndex: 'StepCount', key: 'StepCount', width: 100, align: 'center' },
        {
            title: 'تعدادِ نمونه',
            dataIndex: 'InstanceCount',
            key: 'InstanceCount',
            width: 100,
            align: 'center',
            render: (v: number) => <Tag color={v > 0 ? 'green' : 'default'} style={{ borderRadius: 6 }}>{v}</Tag>,
        },
        {
            title: 'تاریخِ ایجاد',
            dataIndex: 'Date_InsertFirst',
            key: 'Date_InsertFirst',
            width: 160,
            align: 'center',
            render: (v: string) => <Text style={{ fontSize: 12 }}>{gregorianToJalaliDateTimeDisplay(v)}</Text>,
        },
        {
            title: 'تاریخِ انتشار',
            dataIndex: 'PublishedAt',
            key: 'PublishedAt',
            width: 160,
            align: 'center',
            render: (v: string | null) => (v ? <Text style={{ fontSize: 12 }}>{gregorianToJalaliDateTimeDisplay(v)}</Text> : <Text type="secondary">—</Text>),
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 190,
            align: 'center',
            render: (_, record) => (
                <Space>
                    <Button size="small" icon={<EyeOutlined />} onClick={() => router.visit(`/process/versions/${record.VersionID}`)}>
                        باز کردن
                    </Button>
                    {canDesign && (
                        <Button
                            size="small"
                            icon={<CopyOutlined />}
                            loading={cloningId === record.VersionID}
                            disabled={cloningId !== null && cloningId !== record.VersionID}
                            onClick={() => handleClone(record.VersionID)}
                        >
                            Clone
                        </Button>
                    )}
                </Space>
            ),
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title={def.Name}
                subtitle={def.Code}
                backHref="/process/definitions"
                backLabel="بازگشت به فهرست"
                tags={[
                    { label: isActive ? 'فعال' : 'غیرفعال', color: isActive ? THEME.success : THEME.textLight },
                    { label: def.EntityType },
                    ...(def.CategoryName ? [{ label: def.CategoryName, color: THEME.info }] : []),
                ]}
                stats={[
                    { icon: <ClockCircleOutlined />, label: 'تاریخِ ایجاد', value: gregorianToJalaliDateTimeDisplay(def.Date_InsertFirst) },
                    ...(def.Date_LastUpdate
                        ? [{ icon: <ClockCircleOutlined />, label: 'آخرین تغییر', value: gregorianToJalaliDateTimeDisplay(def.Date_LastUpdate) }]
                        : []),
                ]}
                actions={
                    canDesign ? (
                        <Space wrap>
                            <Button icon={<EditOutlined />} onClick={() => setModalOpen(true)}>
                                ویرایش
                            </Button>
                            <Popconfirm
                                title={isActive ? 'غیرفعال‌کردنِ فرایند' : 'فعال‌کردنِ فرایند'}
                                description={
                                    isActive
                                        ? 'پس از غیرفعال‌شدن، Startِ نمونهٔ جدید برایِ این فرایند ممکن نخواهد بود؛ نمونه‌هایِ در‌حالِ‌اجرا تحتِ تأثیر قرار نمی‌گیرند.'
                                        : 'آیا مطمئن هستید؟'
                                }
                                onConfirm={handleToggle}
                                okText="بله"
                                cancelText="خیر"
                                okButtonProps={{ danger: isActive }}
                            >
                                <Button danger={isActive} icon={isActive ? <StopOutlined /> : <CheckCircleOutlined />} loading={toggling}>
                                    {isActive ? 'غیرفعال‌سازی' : 'فعال‌سازی'}
                                </Button>
                            </Popconfirm>
                        </Space>
                    ) : undefined
                }
            />

            <Card title={whiteTitle(<Space><ApartmentOutlined /><span>اطلاعاتِ فرایند</span></Space>)} headStyle={gradientHeadStyle} style={{ ...STYLES.card, marginBottom: 16 }}>
                <Descriptions column={{ xs: 1, sm: 2 }} size="small" bordered>
                    <Descriptions.Item label="کد">{def.Code}</Descriptions.Item>
                    <Descriptions.Item label="نام">{def.Name}</Descriptions.Item>
                    <Descriptions.Item label="نوعِ موجودیت">{def.EntityType}</Descriptions.Item>
                    <Descriptions.Item label="دسته‌بندی">
                        {def.CategoryName ? <Tag color="geekblue" style={{ borderRadius: 6 }}>{def.CategoryName}</Tag> : <Text type="secondary">بدونِ دسته</Text>}
                    </Descriptions.Item>
                    <Descriptions.Item label="وضعیت">
                        <Tag icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />} color={isActive ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                            {isActive ? 'فعال' : 'غیرفعال'}
                        </Tag>
                    </Descriptions.Item>
                    <Descriptions.Item label="توضیحات" span={2}>
                        {def.Description ? <Text>{def.Description}</Text> : <Text type="secondary">—</Text>}
                    </Descriptions.Item>
                </Descriptions>
            </Card>

            <Card
                title={whiteTitle(<Space><BranchesOutlined /><span>نسخه‌ها</span></Space>)}
                headStyle={gradientHeadStyle}
                style={{ ...STYLES.card, marginBottom: 16 }}
                extra={
                    canDesign ? (
                        <Button size="small" icon={<PlusOutlined />} loading={creatingDraft} onClick={handleCreateDraft}>
                            نسخهٔ پیش‌نویسِ جدید
                        </Button>
                    ) : null
                }
            >
                {data.versions.length === 0 ? (
                    <Empty description="هنوز نسخه‌ای ساخته نشده است" />
                ) : (
                    <Table rowKey="VersionID" dataSource={data.versions} columns={versionColumns} pagination={false} size="middle" scroll={{ x: 'max-content' }} />
                )}
            </Card>

            <DefinitionFormModal
                open={modalOpen}
                onClose={() => setModalOpen(false)}
                editingDefinition={def}
                categories={props.categories || []}
                onSuccess={handleModalSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((prev) => ({ ...prev, open: false }))} />
        </MainLayout>
    );
}
