import { useCallback, useEffect, useState } from 'react';
import { Card, Descriptions, Tag, Space, Button, Timeline, Typography, Alert, Popconfirm, Empty, Modal, Input, Spin } from 'antd';
import {
    ApartmentOutlined,
    CheckCircleOutlined,
    CloseCircleOutlined,
    RollbackOutlined,
    SendOutlined,
    SwapOutlined,
    UndoOutlined,
    HistoryOutlined,
    LoadingOutlined,
    ClockCircleOutlined,
    UserOutlined,
} from '@ant-design/icons';
import { router } from '@inertiajs/react';
import NotificationModal, { NotificationType } from '../NotificationModal';
import WorkflowReassignModal from './WorkflowReassignModal';
import { wfApi } from './workflowApi';
import { filterWorkflowHistoryForTimeline } from './workflowHistory';
import { THEME, columnHelpers } from '../../theme';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';
import { toBool } from '../../Utils/bool';

const { Text, Title } = Typography;

const gradientHeadStyle = { background: THEME.primaryGradient, border: 'none', borderRadius: '12px 12px 0 0' };
const whiteTitle = (text: any) => <span style={{ color: '#fff', fontWeight: 600 }}>{text}</span>;

/* ============================ شکلِ دادهٔ /workflow/messages/{id} ============================ */

interface WfTask {
    MessageID: number;
    StepInstanceID: number;
    StepCode: string;
    StepType: string;
    StepStatus: string;
    AssignPolicy: string | null;
    RequiredApprovals: number | null;
    ReceivedApprovals: number;
    ReceivedRejections: number;
    RowVersion: string | null;
    StepName: string;
    AllowForward: boolean | number | string;
    AllowDelegation: boolean | number | string;
    ForwardMax: number | null;
    InstanceID: number;
    InstanceNumber: string;
    InstanceStatus: string;
    DefinitionCode: string;
    DefinitionName: string;
}

interface WfAssignee {
    TaskAssigneeID: number;
    UserID: number;
    FullName: string;
    SourceType: string;
    SourceRefID: number | null;
    SourceRefName: string | null;
    IsActive: boolean | number | string;
    Decision: string | null;
    PersonalStatusID: number | null;
    PersonalStatusName: string | null;
}

interface WfAction {
    ActionID: number;
    Code: string;
    Kind: string;
    Label: string;
    RequiresComment: boolean | number;
    RequiresConfirm: boolean | number;
    ConfirmMessage: string | null;
}

interface WfHistoryItem {
    HistoryID: number;
    EventCode: string;
    OccurredAt: string;
    Summary: string | null;
    ActorName: string | null;
}

interface WfDetail {
    task: WfTask;
    assignees: WfAssignee[];
    actions: WfAction[];
    history: WfHistoryItem[];
    canAct: boolean;
}

interface UserOption {
    UserID: number;
    FullName: string;
    UnitName?: string | null;
    IsManager?: boolean | number;
}

interface WorkflowTaskCardProps {
    messageId: number;
    /**
     * پیش‌شرطِ Lazy fetch — از sp_GetMessageHeader.IsWfTask (پچ 021) می‌آید، نه صرفِ
     * «نوعِ پیام = وظیفه». برایِ وظیفهٔ عادی/پروژه false است و هیچ درخواستی به
     * /workflow/messages/{id} زده نمی‌شود.
     */
    isWfTask: boolean;
    currentUserId: number;
    /** لیستِ کاربرانِ موجودِ صفحه (همان users ای که فرمِ ارجاعِ عادی هم استفاده می‌کند) */
    users: UserOption[];
    /**
     * کدهایِ WORKFLOW_* کاربرِ جاری (MessageController::show → workflowPermissions()).
     * فقط برایِ UX (پنهان‌کردنِ دکمه‌هایِ Forward/Delegate از کاربرانِ بدونِ مجوز)؛
     * مرجعِ نهاییِ ۴۰۳ همچنان WorkflowApiController::authorizeWorkflow() است.
     */
    permissions: string[];
}

const instanceStatusTag: Record<string, { color: string; label: string }> = {
    RUNNING: { color: 'processing', label: 'در جریان' },
    COMPLETED: { color: 'success', label: 'تکمیل‌شده' },
    CANCELLED: { color: 'default', label: 'لغوشده' },
    SUSPENDED: { color: 'warning', label: 'معلق' },
    FAILED: { color: 'error', label: 'ناموفق' },
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

const actionKindStyle: Record<string, { icon: JSX.Element; color: string }> = {
    APPROVE: { icon: <CheckCircleOutlined />, color: 'success' },
    COMPLETE: { icon: <CheckCircleOutlined />, color: 'success' },
    CUSTOM: { icon: <SendOutlined />, color: 'blue' },
    REJECT: { icon: <CloseCircleOutlined />, color: 'error' },
    RETURN: { icon: <RollbackOutlined />, color: 'warning' },
};

const sourceTypeLabel: Record<string, string> = {
    FORWARD: 'ارجاع‌شده از',
    DELEGATION: 'نمایندگی از طرفِ',
};

function statusTagColor(name: string | null): string {
    switch (name) {
        case 'ارسال شده': return 'default';
        case 'ارجاع': return 'purple';
        case 'در حال انجام': return 'processing';
        case 'انجام شده': return 'success';
        case 'عودت': return 'warning';
        case 'انجام نخواهد شد': return 'error';
        default: return 'default';
    }
}

export default function WorkflowTaskCard({ messageId, isWfTask, currentUserId, users, permissions }: WorkflowTaskCardProps) {
    const [loading, setLoading] = useState(false);
    const [notWorkflow, setNotWorkflow] = useState(false);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [detail, setDetail] = useState<WfDetail | null>(null);

    const [reassignOpen, setReassignOpen] = useState<'forward' | 'delegate' | null>(null);
    const [actionModal, setActionModal] = useState<WfAction | null>(null);
    const [actionComment, setActionComment] = useState('');
    const [busyCode, setBusyCode] = useState<string | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const loadDetail = useCallback(async () => {
        setLoading(true);
        setLoadError(null);
        const res = await wfApi(`/workflow/messages/${messageId}`);
        setLoading(false);

        if (res.status === 404) {
            // پیامِ نوعِ «وظیفه» ولی متعلق به Workflow نیست (مثلاً وظیفهٔ عادی/پروژه) — کارت مخفی می‌ماند
            setNotWorkflow(true);
            return;
        }
        if (!res.ok || !res.success) {
            setLoadError(res.message);
            return;
        }
        setDetail(res as unknown as WfDetail);
    }, [messageId]);

    useEffect(() => {
        if (isWfTask) {
            loadDetail();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [messageId, isWfTask]);

    if (!isWfTask || notWorkflow) {
        return null;
    }

    if (loading && !detail) {
        return (
            <Card style={{ borderRadius: 12, border: 'none', boxShadow: '0 2px 8px rgba(0,0,0,0.06)', marginBottom: 16, textAlign: 'center', padding: '24px 0' }}>
                <Spin indicator={<LoadingOutlined style={{ fontSize: 24, color: THEME.primary }} spin />} />
            </Card>
        );
    }

    if (loadError && !detail) {
        return (
            <Alert
                type="error"
                showIcon
                message="خطا در بارگذاریِ اطلاعاتِ فرایند"
                description={loadError}
                style={{ marginBottom: 16, borderRadius: 8 }}
            />
        );
    }

    if (!detail) {
        return null;
    }

    const { task, assignees, actions, history, canAct } = detail;
    // Timelineِ اصلی فقط رویدادهایِ اصلی/مهم را نشان می‌دهد؛ history (کاملِ خام، از همان پاسخِ API)
    // دست‌نخورده می‌ماند — این فقط یک فیلترِ نمایشی است، هم‌الگو با Process/Instances/Show.tsx.
    const visibleHistory = filterWorkflowHistoryForTimeline(history);

    // ردیفِ انجام‌دهندگیِ خودِ کاربرِ جاری (ترجیحاً ردیفِ فعال، وگرنه آخرین ردیفِ او)
    const myRows = assignees.filter((a) => Number(a.UserID) === Number(currentUserId));
    const myAssignee = myRows.find((a) => toBool(a.IsActive)) ?? myRows[myRows.length - 1] ?? null;
    const alreadyDecided = !!myAssignee?.Decision;
    const activeAssigneeIds = assignees.filter((a) => toBool(a.IsActive)).map((a) => Number(a.UserID));
    const forwardCount = assignees.filter((a) => a.SourceType === 'FORWARD').length;

    // تفویضی که خودِ کاربرِ جاری انجام داده و هنوز فعال و بی‌تصمیم است ⇒ قابلِ بازپس‌گیری
    const myDelegation = assignees.find(
        (a) => a.SourceType === 'DELEGATION' && toBool(a.IsActive) && !a.Decision && Number(a.SourceRefID) === Number(currentUserId),
    );

    // Permissionِ Frontend فقط برایِ UX است (پنهان‌کردنِ دکمه‌هایِ بی‌فایده)؛ همان کدهایِ
    // WORKFLOW_FORWARD/WORKFLOW_DELEGATE ای که Backend هم رویِ همین endpointها enforce می‌کند
    // (WorkflowTaskController::forward/delegate/revokeDelegation) — مرجعِ نهایی همچنان سرور است.
    const canForwardPerm = permissions.includes('WORKFLOW_FORWARD');
    const canDelegatePerm = permissions.includes('WORKFLOW_DELEGATE');

    const showDecisionActions = canAct && !alreadyDecided && actions.length > 0;
    const showForward = canAct && !alreadyDecided && toBool(task.AllowForward) && canForwardPerm;
    const showDelegate = canAct && !alreadyDecided && toBool(task.AllowDelegation) && canDelegatePerm;
    // بازپس‌گیری هم همان WORKFLOW_DELEGATE را روی Backend لازم دارد
    const showRevoke = !!myDelegation && canDelegatePerm;

    const iStatus = instanceStatusTag[task.InstanceStatus] ?? { color: 'default', label: task.InstanceStatus };
    const sStatus = stepStatusTag[task.StepStatus] ?? { color: 'default', label: task.StepStatus };

    /** @param code شناسه‌ای که busyCode را برای نمایشِ loading رویِ دکمهٔ درست مقداردهی می‌کند */
    const runAction = async (code: string, url: string, body: any, onOk: (message: string) => void) => {
        setBusyCode(code);
        const res = await wfApi(url, 'POST', body);
        setBusyCode(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            if (res.status === 409) {
                // احتمالِ هم‌زمانی/تغییرِ وضعیت — دادهٔ تازه را بیاور تا کاربر وضعیتِ واقعی را ببیند
                loadDetail();
            }
            return;
        }

        onOk(res.message);
        loadDetail();
    };

    const submitDecisionAction = async () => {
        if (!actionModal) return;
        if (toBool(actionModal.RequiresComment) && !actionComment.trim()) {
            notify('warning', 'برایِ این اقدام، ثبتِ توضیح الزامی است.');
            return;
        }

        await runAction(
            actionModal.Code,
            `/workflow/messages/${messageId}/actions`,
            { actionCode: actionModal.Code, comment: actionComment.trim() || undefined, rowVersion: task.RowVersion },
            (m) => notify('success', m),
        );
        setActionModal(null);
        setActionComment('');
    };

    const handleRevoke = async () => {
        if (!myDelegation) return;
        await runAction(
            'revoke',
            `/workflow/messages/${messageId}/revoke-delegation`,
            { delegateUserId: myDelegation.UserID },
            (m) => notify('success', m),
        );
    };

    return (
        <>
            <Card
                title={whiteTitle(
                    <Space>
                        <ApartmentOutlined />
                        <span>فرایندِ اتوماسیون — {task.DefinitionName}</span>
                    </Space>
                )}
                headStyle={gradientHeadStyle}
                style={{ borderRadius: 12, border: 'none', boxShadow: '0 2px 8px rgba(0,0,0,0.06)', marginBottom: 16 }}
                extra={
                    <Button
                        type="link"
                        size="small"
                        style={{ color: '#fff', textDecoration: 'underline', fontSize: 12, padding: 0, height: 'auto' }}
                        onClick={() => router.visit(`/process/instances/${task.InstanceID}`)}
                    >
                        {task.InstanceNumber}
                    </Button>
                }
            >
                <Descriptions column={{ xs: 1, sm: 2 }} size="small" bordered style={{ marginBottom: 16 }}>
                    <Descriptions.Item label="مرحلهٔ جاری">{task.StepName}</Descriptions.Item>
                    <Descriptions.Item label="نوعِ مرحله">{stepTypeLabel[task.StepType] ?? task.StepType}</Descriptions.Item>
                    <Descriptions.Item label="وضعیتِ فرایند">
                        <Tag color={iStatus.color} style={{ borderRadius: 6 }}>{iStatus.label}</Tag>
                    </Descriptions.Item>
                    <Descriptions.Item label="وضعیتِ مرحله">
                        <Tag color={sStatus.color} style={{ borderRadius: 6 }}>{sStatus.label}</Tag>
                    </Descriptions.Item>
                    <Descriptions.Item label="سیاستِ تخصیص" span={task.AssignPolicy === 'N_OF_M' ? 1 : 2}>
                        {task.AssignPolicy ? (assignPolicyLabel[task.AssignPolicy] ?? task.AssignPolicy) : '—'}
                    </Descriptions.Item>
                    {task.AssignPolicy === 'N_OF_M' && task.RequiredApprovals != null ? (
                        <Descriptions.Item label="تعدادِ تأییدِ لازم">{columnHelpers.formatNumber(task.RequiredApprovals)}</Descriptions.Item>
                    ) : null}
                    {task.ReceivedApprovals != null ? (
                        <Descriptions.Item label="تأییدهایِ دریافتی">{columnHelpers.formatNumber(task.ReceivedApprovals)}</Descriptions.Item>
                    ) : null}
                    {task.ReceivedRejections != null ? (
                        <Descriptions.Item label="ردهایِ دریافتی">{columnHelpers.formatNumber(task.ReceivedRejections)}</Descriptions.Item>
                    ) : null}
                    {task.ForwardMax != null ? (
                        <Descriptions.Item label="سقفِ ارجاع">{columnHelpers.formatNumber(forwardCount)} از {columnHelpers.formatNumber(task.ForwardMax)}</Descriptions.Item>
                    ) : null}
                    <Descriptions.Item label="وضعیتِ شخصیِ من" span={2}>
                        {myAssignee?.PersonalStatusName ? (
                            <Space size={8} wrap>
                                <Tag color={statusTagColor(myAssignee.PersonalStatusName)} style={{ borderRadius: 6 }}>
                                    {myAssignee.PersonalStatusName}
                                </Tag>
                                {myAssignee.SourceType && sourceTypeLabel[myAssignee.SourceType] && myAssignee.SourceRefName ? (
                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                        <UserOutlined /> {sourceTypeLabel[myAssignee.SourceType]} {myAssignee.SourceRefName}
                                    </Text>
                                ) : null}
                            </Space>
                        ) : (
                            <Text type="secondary">—</Text>
                        )}
                    </Descriptions.Item>
                </Descriptions>

                {!canAct ? (
                    <Alert
                        type="info"
                        showIcon
                        message="شما انجام‌دهندهٔ فعالِ این مرحله نیستید"
                        description="این تسک را فقط به‌صورتِ اطلاعاتی مشاهده می‌کنید."
                        style={{ marginBottom: 16, borderRadius: 8 }}
                    />
                ) : alreadyDecided ? (
                    <Alert
                        type="success"
                        showIcon
                        message="تصمیمِ شما ثبت شده است"
                        description="منتظرِ سایرِ انجام‌دهندگان یا تکمیلِ مرحله می‌مانید."
                        style={{ marginBottom: 16, borderRadius: 8 }}
                    />
                ) : null}

                {(showDecisionActions || showForward || showDelegate || showRevoke) && (
                    <Space wrap style={{ marginBottom: visibleHistory.length ? 16 : 0 }}>
                        {showDecisionActions && actions.map((a) => {
                            const style = actionKindStyle[a.Kind] ?? { icon: <SendOutlined />, color: 'default' };
                            const warningStyle =
                                style.color === 'warning'
                                    ? { background: THEME.warning, borderColor: THEME.warning, color: '#fff' }
                                    : undefined;
                            return (
                                <Button
                                    key={a.Code}
                                    type={style.color === 'error' || style.color === 'warning' ? 'default' : 'primary'}
                                    danger={style.color === 'error'}
                                    style={warningStyle}
                                    icon={style.icon}
                                    loading={busyCode === a.Code}
                                    disabled={busyCode !== null && busyCode !== a.Code}
                                    onClick={() => {
                                        setActionModal(a);
                                        setActionComment('');
                                    }}
                                >
                                    {a.Label}
                                </Button>
                            );
                        })}

                        {showForward && (
                            <Button
                                icon={<SendOutlined />}
                                disabled={busyCode !== null}
                                onClick={() => setReassignOpen('forward')}
                            >
                                ارجاع
                            </Button>
                        )}

                        {showDelegate && (
                            <Button
                                icon={<SwapOutlined />}
                                disabled={busyCode !== null}
                                onClick={() => setReassignOpen('delegate')}
                            >
                                تفویض
                            </Button>
                        )}

                        {showRevoke && myDelegation && (
                            <Popconfirm
                                title="بازپس‌گیریِ تفویض"
                                description={`مسئولیتِ تسک از «${myDelegation.FullName}» به شما بازمی‌گردد. ادامه می‌دهید؟`}
                                onConfirm={handleRevoke}
                                okText="بله، بازپس بگیر"
                                cancelText="انصراف"
                            >
                                <Button icon={<UndoOutlined />} loading={busyCode === 'revoke'} disabled={busyCode !== null && busyCode !== 'revoke'}>
                                    بازپس‌گیریِ تفویض
                                </Button>
                            </Popconfirm>
                        )}
                    </Space>
                )}

                {visibleHistory.length > 0 && (
                    <>
                        <Title level={5} style={{ marginTop: 8, marginBottom: 10, color: THEME.textPrimary }}>
                            <HistoryOutlined /> تاریخچهٔ فرایند
                        </Title>
                        <Timeline
                            style={{ marginTop: 8 }}
                            items={visibleHistory.map((h) => ({
                                key: h.HistoryID,
                                children: (
                                    <div style={{ paddingBottom: 8 }}>
                                        <Text>{h.Summary || h.EventCode}</Text>
                                        <div>
                                            <Space size={10} wrap>
                                                {h.ActorName ? (
                                                    <Text type="secondary" style={{ fontSize: 12 }}><UserOutlined /> {h.ActorName}</Text>
                                                ) : null}
                                                <Text type="secondary" style={{ fontSize: 12 }}>
                                                    <ClockCircleOutlined /> {gregorianToJalaliDateTimeDisplay(h.OccurredAt)}
                                                </Text>
                                            </Space>
                                        </div>
                                    </div>
                                ),
                            }))}
                        />
                    </>
                )}

                {visibleHistory.length === 0 && !showDecisionActions && !showForward && !showDelegate && !showRevoke ? (
                    <Empty description="رویدادی ثبت نشده است" />
                ) : null}
            </Card>

            {/* Modalِ اقدام (Complete/Approve/Reject/Return/...) */}
            <Modal
                open={!!actionModal}
                title={actionModal?.Label}
                onCancel={() => (busyCode ? undefined : setActionModal(null))}
                onOk={submitDecisionAction}
                okText="ثبت"
                cancelText="انصراف"
                confirmLoading={!!busyCode}
                destroyOnClose
            >
                {actionModal?.RequiresConfirm && actionModal?.ConfirmMessage ? (
                    <Alert type="warning" showIcon message={actionModal.ConfirmMessage} style={{ marginBottom: 14 }} />
                ) : null}
                <Text style={{ display: 'block', marginBottom: 6 }}>
                    توضیح {actionModal && toBool(actionModal.RequiresComment) ? '(الزامی)' : '(اختیاری)'}
                </Text>
                <Input.TextArea
                    rows={3}
                    value={actionComment}
                    onChange={(e) => setActionComment(e.target.value)}
                    maxLength={1000}
                    showCount
                />
            </Modal>

            <WorkflowReassignModal
                open={reassignOpen !== null}
                mode={reassignOpen ?? 'forward'}
                messageId={messageId}
                users={users}
                currentUserId={currentUserId}
                activeAssigneeIds={activeAssigneeIds}
                onClose={() => setReassignOpen(null)}
                onSuccess={(m) => {
                    setReassignOpen(null);
                    notify('success', m);
                    loadDetail();
                }}
            />

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={() => setNotification((prev) => ({ ...prev, open: false }))}
            />
        </>
    );
}
