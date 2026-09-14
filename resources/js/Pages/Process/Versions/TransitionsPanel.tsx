import { Select, Input, InputNumber, Switch, Button, Space, Typography, Empty, Table } from 'antd';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import type { ActionDraft, StepDraft, TransitionDraft } from './versionEditor';

const { Text } = Typography;

interface TransitionsPanelProps {
    transitions: TransitionDraft[];
    steps: StepDraft[];
    actions: ActionDraft[];
    readOnly: boolean;
    onChange: (transitions: TransitionDraft[]) => void;
}

function newTransition(steps: StepDraft[]): TransitionDraft {
    return {
        code: '',
        fromStepCode: steps[0]?.code ?? '',
        toStepCode: steps[0]?.code ?? '',
        triggerActionCode: null,
        priority: 100,
        isDefault: false,
        label: null,
    };
}

export default function TransitionsPanel({ transitions, steps, actions, readOnly, onChange }: TransitionsPanelProps) {
    const stepOptions = steps.map((s) => ({ value: s.code, label: `${s.name} (${s.code})` }));

    const update = (idx: number, patch: Partial<TransitionDraft>) => {
        onChange(transitions.map((t, i) => (i === idx ? { ...t, ...patch } : t)));
    };

    const remove = (idx: number) => onChange(transitions.filter((_, i) => i !== idx));

    if (readOnly) {
        const columns: ColumnsType<TransitionDraft> = [
            { title: 'کد', dataIndex: 'code', key: 'code', align: 'center' },
            { title: 'از', dataIndex: 'fromStepCode', key: 'fromStepCode', align: 'center' },
            { title: 'به', dataIndex: 'toStepCode', key: 'toStepCode', align: 'center' },
            { title: 'Trigger Action', dataIndex: 'triggerActionCode', key: 'triggerActionCode', align: 'center', render: (v) => v || <Text type="secondary">—</Text> },
            { title: 'اولویت', dataIndex: 'priority', key: 'priority', align: 'center' },
            { title: 'پیش‌فرض', dataIndex: 'isDefault', key: 'isDefault', align: 'center', render: (v) => (v ? 'بله' : 'خیر') },
            { title: 'برچسب', dataIndex: 'label', key: 'label', align: 'center', render: (v) => v || <Text type="secondary">—</Text> },
        ];
        return transitions.length === 0 ? (
            <Empty description="گذاری تعریف نشده است" />
        ) : (
            <Table rowKey={(_, i) => String(i)} dataSource={transitions} columns={columns} pagination={false} size="middle" scroll={{ x: 'max-content' }} />
        );
    }

    return (
        <>
            {transitions.length === 0 ? <Empty description="گذاری تعریف نشده است" style={{ marginBottom: 12 }} /> : null}
            <Space direction="vertical" style={{ width: '100%' }} size={10}>
                {transitions.map((t, idx) => {
                    const stepActions = actions.filter((a) => a.stepCode === t.fromStepCode);
                    return (
                        <div key={idx} style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 12 }}>
                            <Space wrap align="start">
                                <Input placeholder="کدِ گذار" dir="ltr" style={{ width: 130 }} value={t.code} onChange={(e) => update(idx, { code: e.target.value.trim() })} />
                                <Select
                                    style={{ width: 170 }}
                                    placeholder="از Step"
                                    value={t.fromStepCode || undefined}
                                    options={stepOptions}
                                    onChange={(v) => {
                                        // تغییرِ From ⇒ triggerActionCodeِ قبلی اگر متعلق به Stepِ جدید نباشد پاک می‌شود (تصمیمِ ۱۳)
                                        const stillValid = actions.some((a) => a.stepCode === v && a.code === t.triggerActionCode);
                                        update(idx, { fromStepCode: v, triggerActionCode: stillValid ? t.triggerActionCode : null });
                                    }}
                                />
                                <Select
                                    style={{ width: 170 }}
                                    placeholder="به Step"
                                    value={t.toStepCode || undefined}
                                    options={stepOptions}
                                    onChange={(v) => update(idx, { toStepCode: v })}
                                />
                                <Select
                                    style={{ width: 180 }}
                                    placeholder="بدونِ Trigger (پیش‌فرض)"
                                    allowClear
                                    value={t.triggerActionCode ?? undefined}
                                    options={stepActions.map((a) => ({ value: a.code, label: a.label || a.code }))}
                                    onChange={(v) => update(idx, { triggerActionCode: v ?? null })}
                                />
                                <InputNumber value={t.priority} onChange={(v) => update(idx, { priority: v ?? 100 })} style={{ width: 90 }} title="اولویت" />
                                <Space size={4}>
                                    <Text style={{ fontSize: 12 }}>پیش‌فرض</Text>
                                    <Switch size="small" checked={t.isDefault} onChange={(v) => update(idx, { isDefault: v })} />
                                </Space>
                                <Input placeholder="برچسب (اختیاری)" style={{ width: 140 }} value={t.label ?? ''} onChange={(e) => update(idx, { label: e.target.value || null })} />
                                <Button danger type="text" icon={<DeleteOutlined />} onClick={() => remove(idx)} />
                            </Space>
                        </div>
                    );
                })}
            </Space>
            <Button icon={<PlusOutlined />} style={{ marginTop: 12 }} disabled={steps.length === 0} onClick={() => onChange([...transitions, newTransition(steps)])}>
                گذارِ جدید
            </Button>
        </>
    );
}
