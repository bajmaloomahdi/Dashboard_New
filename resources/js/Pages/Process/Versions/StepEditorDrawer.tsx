import { useEffect, useState } from 'react';
import { Drawer, Form, Input, InputNumber, Select, Switch, Button, Space, Typography, Divider, Alert, Empty } from 'antd';
import { PlusOutlined, DeleteOutlined, SaveOutlined, CloseOutlined } from '@ant-design/icons';
import {
    ACTION_KINDS,
    ASSIGNEE_TYPES,
    ASSIGN_POLICIES,
    STEP_TYPES,
    type ActionDraft,
    type AssignmentDraft,
    type StepDraft,
} from './versionEditor';

const { Text, Title } = Typography;

interface LookupOption {
    UserID?: number;
    RoleID?: number;
    PositionID?: number;
    UnitID?: number;
    FullName?: string;
    RoleName?: string;
    PositionName?: string;
    UnitName?: string;
}

interface StepEditorDrawerProps {
    open: boolean;
    readOnly: boolean;
    initialStep: StepDraft | null; // null = ایجادِ Stepِ جدید
    initialActions: ActionDraft[];
    initialAssignments: AssignmentDraft[];
    existingStepCodes: string[]; // بدونِ کدِ خودِ این Step (برایِ چکِ یکتاییِ Rename)
    users: LookupOption[];
    roles: LookupOption[];
    positions: LookupOption[];
    units: LookupOption[];
    onClose: () => void;
    onApply: (originalCode: string | null, step: StepDraft, actions: ActionDraft[], assignments: AssignmentDraft[]) => void;
    onDelete?: () => void;
}

const emptyStep: StepDraft = {
    code: '',
    name: '',
    stepType: 'USER_TASK',
    assignPolicy: 'ANY',
    requiredApprovals: null,
    allowForward: false,
    forwardMax: null,
    allowDelegation: true,
    sortOrder: 0,
};

function newAction(stepCode: string): ActionDraft {
    return {
        stepCode, code: '', kind: 'APPROVE', label: '', icon: null, style: null,
        requiresComment: false, requiresConfirm: false, confirmMessage: null, permissionCode: null, sortOrder: 0,
    };
}

function newAssignment(stepCode: string): AssignmentDraft {
    return { stepCode, assigneeType: 'USER', refId: null, refExpression: null, sortOrder: 0 };
}

export default function StepEditorDrawer({
    open,
    readOnly,
    initialStep,
    initialActions,
    initialAssignments,
    existingStepCodes,
    users,
    roles,
    positions,
    units,
    onClose,
    onApply,
    onDelete,
}: StepEditorDrawerProps) {
    const [step, setStep] = useState<StepDraft>(emptyStep);
    const [actions, setActions] = useState<ActionDraft[]>([]);
    const [assignments, setAssignments] = useState<AssignmentDraft[]>([]);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setStep(initialStep ? { ...initialStep } : { ...emptyStep });
        setActions(initialActions.map((a) => ({ ...a })));
        setAssignments(initialAssignments.map((a) => ({ ...a })));
        setError(null);
    }, [open, initialStep, initialActions, initialAssignments]);

    const isTaskLike = step.stepType === 'USER_TASK' || step.stepType === 'APPROVAL';

    const handleApply = () => {
        const code = step.code.trim();
        if (!code) {
            setError('کدِ Step الزامی است.');
            return;
        }
        if (existingStepCodes.includes(code)) {
            setError(`کدِ «${code}» قبلاً برایِ Stepِ دیگری استفاده شده است.`);
            return;
        }
        if (!step.name.trim()) {
            setError('نامِ Step الزامی است.');
            return;
        }

        const cleanActions = actions
            .filter((a) => a.code.trim())
            .map((a) => ({ ...a, stepCode: code }));
        const cleanAssignments = assignments
            .filter((a) => !ASSIGNEE_TYPES.find((t) => t.value === a.assigneeType)?.needsTarget || a.refId != null)
            .map((a) => ({ ...a, stepCode: code }));

        onApply(initialStep?.code ?? null, { ...step, code }, cleanActions, cleanAssignments);
    };

    const lookupOptions = (type: AssignmentDraft['assigneeType']) => {
        switch (type) {
            case 'USER': return users.map((u) => ({ value: u.UserID, label: u.FullName }));
            case 'ROLE': return roles.map((r) => ({ value: r.RoleID, label: r.RoleName }));
            case 'POSITION': return positions.map((p) => ({ value: p.PositionID, label: p.PositionName }));
            case 'UNIT': case 'UNIT_MANAGER': return units.map((u) => ({ value: u.UnitID, label: u.UnitName }));
            default: return [];
        }
    };

    return (
        <Drawer
            open={open}
            onClose={onClose}
            width={560}
            title={readOnly ? `مشاهدهٔ Step${initialStep ? `: ${initialStep.name}` : ''}` : initialStep ? 'ویرایشِ Step' : 'Stepِ جدید'}
            footer={
                readOnly ? null : (
                    <Space style={{ display: 'flex', justifyContent: 'space-between' }}>
                        <span>
                            {onDelete && initialStep ? (
                                <Button danger icon={<DeleteOutlined />} onClick={onDelete}>
                                    حذفِ Step
                                </Button>
                            ) : null}
                        </span>
                        <Space>
                            <Button icon={<CloseOutlined />} onClick={onClose}>انصراف</Button>
                            <Button type="primary" icon={<SaveOutlined />} onClick={handleApply}>اعمال</Button>
                        </Space>
                    </Space>
                )
            }
        >
            {error ? <Alert type="error" showIcon message={error} style={{ marginBottom: 14 }} /> : null}

            <Form layout="vertical" disabled={readOnly}>
                <Form.Item label="کدِ Step" required>
                    <Input dir="ltr" value={step.code} onChange={(e) => setStep((s) => ({ ...s, code: e.target.value.trim() }))} placeholder="REVIEW" />
                </Form.Item>
                <Form.Item label="نامِ نمایشی" required>
                    <Input value={step.name} onChange={(e) => setStep((s) => ({ ...s, name: e.target.value }))} placeholder="بررسیِ مدیر" />
                </Form.Item>
                <Form.Item label="نوعِ مرحله" required>
                    <Select value={step.stepType} onChange={(v) => setStep((s) => ({ ...s, stepType: v }))} options={STEP_TYPES} />
                </Form.Item>

                {isTaskLike ? (
                    <>
                        <Form.Item label="سیاستِ تخصیص">
                            <Select value={step.assignPolicy} onChange={(v) => setStep((s) => ({ ...s, assignPolicy: v }))} options={ASSIGN_POLICIES} />
                        </Form.Item>
                        {step.assignPolicy === 'N_OF_M' ? (
                            <Form.Item label="تعدادِ تأییدِ لازم">
                                <InputNumber
                                    min={1}
                                    value={step.requiredApprovals ?? undefined}
                                    onChange={(v) => setStep((s) => ({ ...s, requiredApprovals: v ?? null }))}
                                    style={{ width: '100%' }}
                                />
                            </Form.Item>
                        ) : null}
                        <Form.Item label="ارجاع (Forward) مجاز باشد">
                            <Switch checked={step.allowForward} onChange={(v) => setStep((s) => ({ ...s, allowForward: v }))} />
                        </Form.Item>
                        {step.allowForward ? (
                            <Form.Item label="سقفِ تعدادِ ارجاع (خالی = نامحدود)">
                                <InputNumber
                                    min={1}
                                    value={step.forwardMax ?? undefined}
                                    onChange={(v) => setStep((s) => ({ ...s, forwardMax: v ?? null }))}
                                    style={{ width: '100%' }}
                                />
                            </Form.Item>
                        ) : null}
                        <Form.Item label="تفویض (Delegate) مجاز باشد">
                            <Switch checked={step.allowDelegation} onChange={(v) => setStep((s) => ({ ...s, allowDelegation: v }))} />
                        </Form.Item>
                    </>
                ) : null}
            </Form>

            {isTaskLike ? (
                <>
                    <Divider />
                    <Title level={5}>Actionهایِ این Step</Title>
                    {actions.length === 0 ? <Empty description="Actionی تعریف نشده" style={{ margin: '12px 0' }} /> : null}
                    <Space direction="vertical" style={{ width: '100%' }} size={10}>
                        {actions.map((a, idx) => (
                            <div key={idx} style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12 }}>
                                <Space wrap style={{ width: '100%' }} align="start">
                                    <Input
                                        placeholder="کد (APPROVE)"
                                        dir="ltr"
                                        style={{ width: 130 }}
                                        value={a.code}
                                        disabled={readOnly}
                                        onChange={(e) => setActions((arr) => arr.map((x, i) => (i === idx ? { ...x, code: e.target.value.trim() } : x)))}
                                    />
                                    <Select
                                        style={{ width: 150 }}
                                        value={a.kind}
                                        disabled={readOnly}
                                        options={ACTION_KINDS}
                                        onChange={(v) => setActions((arr) => arr.map((x, i) => (i === idx ? { ...x, kind: v } : x)))}
                                    />
                                    <Input
                                        placeholder="برچسب نمایشی"
                                        style={{ width: 160 }}
                                        value={a.label}
                                        disabled={readOnly}
                                        onChange={(e) => setActions((arr) => arr.map((x, i) => (i === idx ? { ...x, label: e.target.value } : x)))}
                                    />
                                    <Space size={4}>
                                        <Text style={{ fontSize: 12 }}>نیازمندِ توضیح</Text>
                                        <Switch
                                            size="small"
                                            checked={a.requiresComment}
                                            disabled={readOnly}
                                            onChange={(v) => setActions((arr) => arr.map((x, i) => (i === idx ? { ...x, requiresComment: v } : x)))}
                                        />
                                    </Space>
                                    {!readOnly && (
                                        <Button danger type="text" icon={<DeleteOutlined />} onClick={() => setActions((arr) => arr.filter((_, i) => i !== idx))} />
                                    )}
                                </Space>
                            </div>
                        ))}
                    </Space>
                    {!readOnly && (
                        <Button icon={<PlusOutlined />} style={{ marginTop: 10 }} onClick={() => setActions((arr) => [...arr, newAction(step.code)])}>
                            افزودنِ Action
                        </Button>
                    )}

                    <Divider />
                    <Title level={5}>انجام‌دهندگانِ این Step</Title>
                    {assignments.length === 0 ? <Empty description="انجام‌دهنده‌ای تعریف نشده" style={{ margin: '12px 0' }} /> : null}
                    <Space direction="vertical" style={{ width: '100%' }} size={10}>
                        {assignments.map((a, idx) => {
                            const needsTarget = ASSIGNEE_TYPES.find((t) => t.value === a.assigneeType)?.needsTarget ?? false;
                            return (
                                <div key={idx} style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12 }}>
                                    <Space wrap align="start">
                                        <Select
                                            style={{ width: 190 }}
                                            value={a.assigneeType}
                                            disabled={readOnly}
                                            options={ASSIGNEE_TYPES}
                                            onChange={(v) => setAssignments((arr) => arr.map((x, i) => (i === idx ? { ...x, assigneeType: v, refId: null } : x)))}
                                        />
                                        {needsTarget ? (
                                            <Select
                                                style={{ width: 220 }}
                                                placeholder="انتخابِ هدف..."
                                                showSearch
                                                optionFilterProp="label"
                                                disabled={readOnly}
                                                value={a.refId ?? undefined}
                                                options={lookupOptions(a.assigneeType)}
                                                onChange={(v) => setAssignments((arr) => arr.map((x, i) => (i === idx ? { ...x, refId: v } : x)))}
                                            />
                                        ) : (
                                            <Text type="secondary" style={{ fontSize: 12 }}>بدونِ هدفِ اضافی</Text>
                                        )}
                                        {!readOnly && (
                                            <Button danger type="text" icon={<DeleteOutlined />} onClick={() => setAssignments((arr) => arr.filter((_, i) => i !== idx))} />
                                        )}
                                    </Space>
                                </div>
                            );
                        })}
                    </Space>
                    {!readOnly && (
                        <Button icon={<PlusOutlined />} style={{ marginTop: 10 }} onClick={() => setAssignments((arr) => [...arr, newAssignment(step.code)])}>
                            افزودنِ انجام‌دهنده
                        </Button>
                    )}
                </>
            ) : null}
        </Drawer>
    );
}
