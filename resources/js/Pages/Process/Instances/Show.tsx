import { useState } from 'react';
import { Card, Descriptions, Tag, Space, Button, Table, Timeline, Typography, Alert, Popconfirm, Modal, Input, Empty, Grid } from 'antd';
import {
    ApartmentOutlined,
    UserOutlined,
    ClockCircleOutlined,
    StopOutlined,
    PauseCircleOutlined,
    PlayCircleOutlined,
    HistoryOutlined,
    NodeIndexOutlined,
    MessageOutlined,
    FileTextOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { THEME, STYLES } from '../../../theme';
import { gregorianToJalaliDateTimeDisplay } from '../../../Utils/jalali';

const { Text, Title } = Typography;

const gradientHeadStyle = { background: THEME.primaryGradient, border: 'none', borderRadius: '12px 12px 0 0' };
const whiteTitle = (text: any) => <span style={{ color: '#fff', fontWeight: 600 }}>{text}</span>;

/* ============================ شکلِ دادهٔ Contract (WorkflowQueryService::instance) ============================ */

interface WfInstance {
    InstanceID: number;
    InstanceNumber: string;
    DefinitionID: number;
    VersionID: number;
    EntityType: string;
    EntityID: number;
    Status: string; // RUNNING | COMPLETED | CANCELLED | SUSPENDED | FAILED
    StartedByUserID: number | null;
    StartedByName: string | null;
    StartedAt: string;
    CompletedAt: string | null;
    TransitionCount: number;
    DefinitionCode: string;
    DefinitionName: string;
    VersionNo: number;
}

interface WfStep {
    StepInstanceID: number;
    StepCode: string;
    StepType: string;
    Status: string; // ACTIVE | COMPLETED | SKIPPED | FAILED | WAITING_JOIN
    EnteredAt: string;
    CompletedAt: string | null;
    OutcomeActionCode: string | null;
    IterationNo: number;
    MessageID: number | null;
    AssignPolicy: string | null;
    RequiredApprovals: number | null;
    ReceivedApprovals: number;
    ReceivedRejections: number;
}

interface WfTaskRow {
    StepInstanceID: number;
    MessageID: number;
    MessageNumber: string | null;
    Title: string;
    Status: string;
    CreatedAt: string;
    StepName: string;
    AssigneeNames: string | null;
    OpenRecipientCount: number;
}

interface WfHistoryItem {
    HistoryID: number;
    EventCode: string;
    ActorType: string;
    OccurredAt: string;
    Summary: string | null;
    MessageID: number | null;
    StepInstanceID: number | null;
    ActorUserID: number | null;
    ActorName: string | null;
}

const instanceStatusTag: Record<string, { color: string; label: string }> = {
    RUNNING: { color: THEME.primary, label: 'در جریان' },
    COMPLETED: { color: THEME.success, label: 'تکمیل‌شده' },
    CANCELLED: { color: THEME.textLight, label: 'لغوشده' },
    SUSPENDED: { color: THEME.warning, label: 'معلق' },
    FAILED: { color: THEME.error, label: 'ناموفق' },
};

const stepStatusTag: Record<string, { color: string; label: string }> = {
    ACTIVE: { color: 'processing', label: 'فعال' },
    COMPLETED: { color: 'success', label: 'بسته‌شده' },
    SKIPPED: { color: 'default', label: 'رد‌شده' },
    FAILED: { color: 'error', label: 'ناموفق' },
    WAITING_JOIN: { color: 'warning', label: 'در انتظار' },
};

const assignPolicyLabel: Record<string, string> = {
    ANY: 'هر یک از انجام‌دهندگان (ANY)',
    ALL: 'همهٔ انجام‌دهندگان (ALL)',
    N_OF_M: 'حداقلِ تعدادی از انجام‌دهندگان (N_OF_M)',
};

const stepTypeLabel: Record<string, string> = {
    USER_TASK: 'وظیفهٔ کاربر',
    APPROVAL: 'تأیید',
    START: 'شروع',
    END: 'پایان',
};

const eventCodeLabel: Record<string, string> = {
    INSTANCE_STARTED: 'شروعِ فرایند',
    INSTANCE_COMPLETED: 'پایانِ فرایند',
    INSTANCE_CANCELLED: 'لغوِ فرایند',
    INSTANCE_SUSPENDED: 'تعلیقِ فرایند',
    INSTANCE_RESUMED: 'ازسرگیریِ فرایند',
    INSTANCE_FAILED: 'شکستِ فرایند',
    STEP_ENTERED: 'ورود به مرحله',
    STEP_COMPLETED: 'بستنِ مرحله',
    STEP_SKIPPED: 'ردِ مرحله',
    TASK_CREATED: 'ایجادِ تسک',
    TASK_OPENED: 'بازکردنِ تسک',
    TASK_DECISION: 'ثبتِ تصمیم',
    TASK_COMPLETED: 'بستنِ تسک',
    TASK_CANCELLED: 'لغوِ تسک',
    TASK_FORWARDED: 'ارجاعِ تسک',
    TASK_DELEGATED: 'تفویضِ تسک',
    TASK_DELEGATION_REVOKED: 'بازپس‌گیریِ تفویض',
    TRANSITION_TAKEN: 'طیِ گذار',
};

const eventCodeColor: Record<string, string> = {
    INSTANCE_STARTED: 'blue',
    INSTANCE_COMPLETED: 'green',
    INSTANCE_CANCELLED: 'red',
    INSTANCE_SUSPENDED: 'orange',
    INSTANCE_RESUMED: 'blue',
    INSTANCE_FAILED: 'red',
    TASK_COMPLETED: 'green',
    TASK_CANCELLED: 'red',
};

interface InstanceData {
    instance: WfInstance;
    steps: WfStep[];
    tasks: WfTaskRow[];
    history: WfHistoryItem[];
}

export default function ProcessInstanceShow() {
    const { instance, steps, tasks, history, permissions } = usePage().props as unknown as InstanceData & { permissions: string[] };

    const [data, setData] = useState<InstanceData>({ instance, steps, tasks, history });
    const [refreshing, setRefreshing] = useState(false);
    // موبایل: Descriptions عمودی تا برچسب و مقدار هر کدام تمامِ عرض را داشته باشند
    const screens = Grid.useBreakpoint();

    const [lifecycleModal, setLifecycleModal] = useState<'cancel' | 'suspend' | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<string | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const inst = data.instance;
    const canForCode = (code: string) => (permissions || []).includes(code);

    // بازخوانیِ instance/steps/tasks + history از همان دو endpointِ JSONِ موجود
    // (نه بیشتر) — دقیقاً همان‌طور که خودِ WorkflowRuntimeController آن‌ها را جدا می‌کند
    const refresh = async () => {
        setRefreshing(true);
        const [main, hist] = await Promise.all([
            wfApi(`/workflow/instances/${inst.InstanceID}`),
            wfApi(`/workflow/instances/${inst.InstanceID}/history`),
        ]);
        setRefreshing(false);

        if (main.ok && main.success) {
            setData((prev) => ({
                ...prev,
                instance: main.instance,
                steps: main.steps || [],
                tasks: main.tasks || [],
                history: hist.ok && hist.success ? hist.history || [] : prev.history,
            }));
        }
    };

    const iStatus = instanceStatusTag[inst.Status] ?? { color: THEME.textLight, label: inst.Status };

    const canCancel = canForCode('WORKFLOW_CANCEL') && ['RUNNING', 'SUSPENDED'].includes(inst.Status);
    const canSuspend = canForCode('WORKFLOW_SUSPEND') && inst.Status === 'RUNNING';
    const canResume = canForCode('WORKFLOW_SUSPEND') && inst.Status === 'SUSPENDED';

    const runLifecycle = async (op: 'cancel' | 'suspend' | 'resume', withReason: boolean) => {
        setBusy(op);
        const body = withReason && reason.trim() ? { reason: reason.trim() } : undefined;
        const res = await wfApi(`/workflow/instances/${inst.InstanceID}/${op}`, 'POST', body);
        setBusy(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }

        notify('success', res.message);
        setLifecycleModal(null);
        setReason('');
        await refresh();
    };

    const activeStep = data.steps.find((s) => s.Status === 'ACTIVE');
    const displayStep = activeStep ?? (data.steps.length ? data.steps[data.steps.length - 1] : null);
    const displayTask = displayStep ? data.tasks.find((t) => t.StepInstanceID === displayStep.StepInstanceID) : undefined;
    const stepById = new Map(data.steps.map((s) => [s.StepInstanceID, s]));

    const stepColumns: ColumnsType<WfStep> = [
        {
            title: 'مرحله',
            key: 'step',
            render: (_, s) => {
                const t = data.tasks.find((x) => x.StepInstanceID === s.StepInstanceID);
                return <Text strong>{t?.StepName ?? s.StepCode}</Text>;
            },
        },
        { title: 'نوع', dataIndex: 'StepType', key: 'StepType', width: 120, align: 'center', render: (v: string) => stepTypeLabel[v] ?? v },
        {
            title: 'وضعیت',
            dataIndex: 'Status',
            key: 'Status',
            width: 120,
            align: 'center',
            render: (v: string) => {
                const s = stepStatusTag[v] ?? { color: 'default', label: v };
                return <Tag color={s.color} style={{ borderRadius: 6 }}>{s.label}</Tag>;
            },
        },
        {
            title: 'ورود',
            dataIndex: 'EnteredAt',
            key: 'EnteredAt',
            width: 160,
            align: 'center',
            render: (v: string) => <Text style={{ fontSize: 12 }}>{gregorianToJalaliDateTimeDisplay(v)}</Text>,
        },
        {
            title: 'پایان',
            dataIndex: 'CompletedAt',
            key: 'CompletedAt',
            width: 160,
            align: 'center',
            render: (v: string | null) => (v ? <Text style={{ fontSize: 12 }}>{gregorianToJalaliDateTimeDisplay(v)}</Text> : <Text type="secondary">—</Text>),
        },
    ];

    return (
        <MainLayout>
            <div className="wf-instance-show">
                <style>{`
                    .wf-instance-show .wf-longtext { overflow-wrap: anywhere; word-break: break-word; }
                    .wf-instance-show .ant-card { max-width: 100%; }
                    .wf-instance-show .ant-table-wrapper { max-width: 100%; }
                    @media (max-width: 480px) {
                        .wf-instance-show .ant-descriptions-item-label,
                        .wf-instance-show .ant-descriptions-item-content { font-size: 12px; }
                    }
                `}</style>

                <PageHeader
                    icon={<ApartmentOutlined />}
                    title={inst.DefinitionName}
                    subtitle={inst.InstanceNumber}
                    tags={[
                        { label: iStatus.label, color: iStatus.color },
                        { label: inst.DefinitionCode },
                        { label: `نسخهٔ ${inst.VersionNo}` },
                        { label: `${inst.EntityType} #${inst.EntityID}` },
                    ]}
                    stats={[
                        { icon: <UserOutlined />, label: 'شروع‌شده توسط', value: inst.StartedByName ?? '—' },
                        { icon: <ClockCircleOutlined />, label: 'زمانِ شروع', value: gregorianToJalaliDateTimeDisplay(inst.StartedAt) },
                        ...(inst.CompletedAt
                            ? [{ icon: <ClockCircleOutlined />, label: 'زمانِ پایان', value: gregorianToJalaliDateTimeDisplay(inst.CompletedAt) }]
                            : []),
                    ]}
                    actions={
                        <Space wrap>
                            {canSuspend && (
                                <Button icon={<PauseCircleOutlined />} loading={busy === 'suspend'} onClick={() => setLifecycleModal('suspend')}>
                                    تعلیق
                                </Button>
                            )}
                            {canResume && (
                                <Popconfirm title="ازسرگیریِ فرایند" description="فرایند دوباره در جریان قرار می‌گیرد. ادامه می‌دهید؟" onConfirm={() => runLifecycle('resume', false)} okText="بله" cancelText="انصراف">
                                    <Button icon={<PlayCircleOutlined />} loading={busy === 'resume'}>ازسرگیری</Button>
                                </Popconfirm>
                            )}
                            {canCancel && (
                                <Button danger icon={<StopOutlined />} loading={busy === 'cancel'} onClick={() => setLifecycleModal('cancel')}>
                                    لغو
                                </Button>
                            )}
                        </Space>
                    }
                />

                {/* مرحلهٔ جاری */}
                <Card
                    title={whiteTitle(<Space><NodeIndexOutlined /><span>{activeStep ? 'مرحلهٔ جاری' : 'آخرین مرحله'}</span></Space>)}
                    headStyle={gradientHeadStyle}
                    style={{ ...STYLES.card, marginBottom: 16 }}
                >
                    {displayStep ? (
                        <Descriptions column={{ xs: 1, sm: 2 }} size="small" bordered layout={screens.xs ? 'vertical' : 'horizontal'}>
                            <Descriptions.Item label="عنوانِ مرحله">{displayTask?.StepName ?? displayStep.StepCode}</Descriptions.Item>
                            <Descriptions.Item label="نوعِ مرحله">{stepTypeLabel[displayStep.StepType] ?? displayStep.StepType}</Descriptions.Item>
                            <Descriptions.Item label="وضعیتِ مرحله">
                                {(() => {
                                    const s = stepStatusTag[displayStep.Status] ?? { color: 'default', label: displayStep.Status };
                                    return <Tag color={s.color} style={{ borderRadius: 6 }}>{s.label}</Tag>;
                                })()}
                            </Descriptions.Item>
                            <Descriptions.Item label="سیاستِ تخصیص">
                                {displayStep.AssignPolicy ? (assignPolicyLabel[displayStep.AssignPolicy] ?? displayStep.AssignPolicy) : '—'}
                            </Descriptions.Item>
                            {displayStep.AssignPolicy ? (
                                <>
                                    <Descriptions.Item label="تأییدهایِ دریافتی">
                                        {displayStep.RequiredApprovals != null
                                            ? `${displayStep.ReceivedApprovals} از ${displayStep.RequiredApprovals}`
                                            : displayStep.ReceivedApprovals}
                                    </Descriptions.Item>
                                    <Descriptions.Item label="ردهایِ دریافتی">{displayStep.ReceivedRejections}</Descriptions.Item>
                                </>
                            ) : null}
                            {displayTask?.AssigneeNames ? (
                                <Descriptions.Item label="انجام‌دهندگان" span={2}>{displayTask.AssigneeNames}</Descriptions.Item>
                            ) : null}
                            {displayStep.MessageID ? (
                                <Descriptions.Item label="تسکِ کارتابلی" span={2}>
                                    <Button size="small" icon={<MessageOutlined />} style={{ whiteSpace: 'normal', height: 'auto', maxWidth: '100%' }} onClick={() => router.visit(`/messages/${displayStep.MessageID}`)}>
                                        مشاهدهٔ تسک {displayTask?.MessageNumber ? `(${displayTask.MessageNumber})` : ''}
                                    </Button>
                                </Descriptions.Item>
                            ) : null}
                        </Descriptions>
                    ) : (
                        <Empty description="هنوز مرحله‌ای وارد نشده است" />
                    )}
                </Card>

                {/* مراحلِ طی‌شده */}
                <Card
                    title={whiteTitle(<Space><FileTextOutlined /><span>مراحلِ طی‌شده</span></Space>)}
                    headStyle={gradientHeadStyle}
                    style={{ ...STYLES.card, marginBottom: 16 }}
                >
                    {data.steps.length === 0 ? (
                        <Empty description="مرحله‌ای ثبت نشده است" />
                    ) : (
                        <Table
                            rowKey="StepInstanceID"
                            dataSource={data.steps}
                            columns={stepColumns}
                            pagination={false}
                            size="middle"
                            scroll={{ x: 'max-content' }}
                        />
                    )}
                </Card>

                {/* تاریخچه */}
                <Card
                    title={whiteTitle(<Space><HistoryOutlined /><span>تاریخچهٔ فرایند</span></Space>)}
                    headStyle={gradientHeadStyle}
                    style={{ ...STYLES.card, marginBottom: 16 }}
                    extra={<span style={{ color: 'rgba(255,255,255,0.85)', fontSize: 12 }}>{refreshing ? 'در حالِ به‌روزرسانی…' : ''}</span>}
                >
                    {data.history.length === 0 ? (
                        <Empty description="رویدادی ثبت نشده است" />
                    ) : (
                        <Timeline
                            mode="left"
                            items={data.history.map((h) => {
                                const step = h.StepInstanceID != null ? stepById.get(h.StepInstanceID) : null;
                                return {
                                    key: h.HistoryID,
                                    color: eventCodeColor[h.EventCode] ?? 'gray',
                                    label: (
                                        <Text style={{ fontSize: 12 }}>{gregorianToJalaliDateTimeDisplay(h.OccurredAt)}</Text>
                                    ),
                                    children: (
                                        <div style={{ paddingBottom: 8 }}>
                                            <Space wrap size={8}>
                                                <Tag style={{ borderRadius: 6 }}>{eventCodeLabel[h.EventCode] ?? h.EventCode}</Tag>
                                                {step ? <Tag color="default">{step.StepCode}</Tag> : null}
                                                {h.ActorName ? (
                                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                                        <UserOutlined /> {h.ActorName}
                                                    </Text>
                                                ) : h.ActorType === 'SYSTEM' ? (
                                                    <Text type="secondary" style={{ fontSize: 12 }}>سیستم</Text>
                                                ) : null}
                                                {h.MessageID ? (
                                                    <Button size="small" type="link" style={{ padding: 0, height: 'auto' }} onClick={() => router.visit(`/messages/${h.MessageID}`)}>
                                                        مشاهدهٔ پیام
                                                    </Button>
                                                ) : null}
                                            </Space>
                                            {h.Summary ? (
                                                <div>
                                                    <Text className="wf-longtext" type="secondary" style={{ fontSize: 12 }}>{h.Summary}</Text>
                                                </div>
                                            ) : null}
                                        </div>
                                    ),
                                };
                            })}
                        />
                    )}
                </Card>
            </div>

            {/* Modalِ Cancel/Suspend (با دلیلِ اختیاری) */}
            <Modal
                open={lifecycleModal !== null}
                title={lifecycleModal === 'cancel' ? 'لغوِ فرایند' : 'تعلیقِ فرایند'}
                onCancel={() => (busy ? undefined : setLifecycleModal(null))}
                onOk={() => lifecycleModal && runLifecycle(lifecycleModal, true)}
                okText={lifecycleModal === 'cancel' ? 'لغوِ فرایند' : 'تعلیق'}
                okButtonProps={{ danger: lifecycleModal === 'cancel' }}
                cancelText="انصراف"
                confirmLoading={!!busy}
                destroyOnClose
            >
                {lifecycleModal === 'cancel' ? (
                    <Alert type="warning" showIcon message="این عملیات قابلِ بازگشت نیست؛ تسک‌هایِ باز بسته می‌شوند." style={{ marginBottom: 14 }} />
                ) : (
                    <Alert type="info" showIcon message="فرایند متوقف می‌شود؛ تسک‌هایِ باز دست‌نخورده می‌مانند و بعداً با «ازسرگیری» ادامه می‌یابند." style={{ marginBottom: 14 }} />
                )}
                <Text style={{ display: 'block', marginBottom: 6 }}>دلیل (اختیاری)</Text>
                <Input.TextArea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} showCount />
            </Modal>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((prev) => ({ ...prev, open: false }))}
            />
        </MainLayout>
    );
}
