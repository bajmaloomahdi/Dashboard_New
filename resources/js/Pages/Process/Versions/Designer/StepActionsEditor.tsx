import { useEffect, useState } from 'react';
import { Button, Input, Popconfirm, Select, Space, Switch, Typography } from 'antd';
import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import {
    ACTION_CODE_MAX,
    ACTION_CONFIRM_MAX,
    ACTION_KINDS,
    ACTION_LABEL_MAX,
    type ActionDraft,
    type ActionKind,
} from '../versionEditor';

const { Text } = Typography;

interface StepActionsEditorProps {
    actions: ActionDraft[];
    readOnly: boolean;
    /** تعدادِ گذارهایِ خروجی که با این Action Trigger می‌شوند (برایِ هشدارِ حذف) */
    triggeredCount: (code: string) => number;
    onAdd: () => void;
    onChangeFields: (code: string, patch: Partial<Omit<ActionDraft, 'stepCode' | 'code'>>) => void;
    onChangeKind: (code: string, kind: ActionKind) => void;
    /** خطا را به‌صورتِ متن برمی‌گرداند (Code تکراری/نامعتبر) یا null اگر اعمال شد */
    onRename: (oldCode: string, newCode: string) => string | null;
    onRemove: (code: string) => void;
    onMove: (code: string, delta: -1 | 1) => void;
}

function ActionRow({
    action,
    index,
    total,
    readOnly,
    triggeredCount,
    onChangeFields,
    onChangeKind,
    onRename,
    onRemove,
    onMove,
}: {
    action: ActionDraft;
    index: number;
    total: number;
    readOnly: boolean;
    triggeredCount: number;
    onChangeFields: StepActionsEditorProps['onChangeFields'];
    onChangeKind: StepActionsEditorProps['onChangeKind'];
    onRename: StepActionsEditorProps['onRename'];
    onRemove: StepActionsEditorProps['onRemove'];
    onMove: StepActionsEditorProps['onMove'];
}) {
    // Code فقط هنگامِ خروج از فیلد/Enter اعمال می‌شود تا در حینِ تایپ، Triggerهایِ وابسته به Codeِ ناقص تغییر نکنند.
    const [codeDraft, setCodeDraft] = useState(action.code);
    const [codeError, setCodeError] = useState<string | null>(null);

    useEffect(() => {
        setCodeDraft(action.code);
        setCodeError(null);
    }, [action.code]);

    const commitCode = () => {
        const err = onRename(action.code, codeDraft);
        if (err) {
            setCodeError(err);
        } else {
            setCodeError(null);
        }
    };

    const kindOptions = ACTION_KINDS.some((k) => k.value === action.kind)
        ? ACTION_KINDS
        : [...ACTION_KINDS, { value: action.kind, label: `${action.kind} (قدیمی)` }];

    return (
        <div style={{ border: '1px solid #f0f0f0', borderRadius: 8, padding: 8 }} data-testid={`action-row-${action.code}`}>
            <Space direction="vertical" style={{ width: '100%' }} size={6}>
                <Space wrap size={6} style={{ width: '100%' }} align="start">
                    <div>
                        <Input
                            size="small"
                            dir="ltr"
                            style={{ width: 118 }}
                            value={codeDraft}
                            maxLength={ACTION_CODE_MAX}
                            disabled={readOnly}
                            status={codeError ? 'error' : undefined}
                            placeholder="کد (APPROVE)"
                            onChange={(e) => setCodeDraft(e.target.value)}
                            onBlur={commitCode}
                            onPressEnter={commitCode}
                        />
                    </div>
                    <Select
                        size="small"
                        style={{ width: 128 }}
                        value={action.kind}
                        disabled={readOnly}
                        options={kindOptions}
                        onChange={(v) => onChangeKind(action.code, v as ActionKind)}
                    />
                    {!readOnly && (
                        <Space size={0}>
                            <Button size="small" type="text" icon={<ArrowUpOutlined />} disabled={index === 0} onClick={() => onMove(action.code, -1)} />
                            <Button size="small" type="text" icon={<ArrowDownOutlined />} disabled={index === total - 1} onClick={() => onMove(action.code, 1)} />
                            <Popconfirm
                                title="حذفِ این Action؟"
                                description={
                                    triggeredCount > 0
                                        ? `${triggeredCount} گذارِ Triggerشده با این Action، بدونِ Trigger می‌شود (حذف نمی‌شود).`
                                        : undefined
                                }
                                okText="حذف"
                                cancelText="انصراف"
                                onConfirm={() => onRemove(action.code)}
                            >
                                <Button size="small" type="text" danger icon={<DeleteOutlined />} />
                            </Popconfirm>
                        </Space>
                    )}
                </Space>
                {codeError && <Text type="danger" style={{ fontSize: 11 }}>{codeError}</Text>}

                <Input
                    size="small"
                    value={action.label}
                    maxLength={ACTION_LABEL_MAX}
                    disabled={readOnly}
                    status={action.label.trim() === '' ? 'error' : undefined}
                    placeholder="برچسبِ دکمه (الزامی)"
                    onChange={(e) => onChangeFields(action.code, { label: e.target.value })}
                />

                <Space wrap size={12}>
                    <Space size={4}>
                        <Switch size="small" checked={action.requiresComment} disabled={readOnly} onChange={(v) => onChangeFields(action.code, { requiresComment: v })} />
                        <Text style={{ fontSize: 12 }}>نیازمندِ توضیح</Text>
                    </Space>
                    <Space size={4}>
                        <Switch size="small" checked={action.requiresConfirm} disabled={readOnly} onChange={(v) => onChangeFields(action.code, { requiresConfirm: v })} />
                        <Text style={{ fontSize: 12 }}>نیازمندِ تأیید</Text>
                    </Space>
                </Space>

                {action.requiresConfirm && (
                    <Input.TextArea
                        rows={2}
                        value={action.confirmMessage ?? ''}
                        maxLength={ACTION_CONFIRM_MAX}
                        disabled={readOnly}
                        placeholder="پیامِ تأیید (اختیاری)"
                        onChange={(e) => onChangeFields(action.code, { confirmMessage: e.target.value || null })}
                    />
                )}
            </Space>
        </div>
    );
}

export default function StepActionsEditor({
    actions,
    readOnly,
    triggeredCount,
    onAdd,
    onChangeFields,
    onChangeKind,
    onRename,
    onRemove,
    onMove,
}: StepActionsEditorProps) {
    return (
        <div>
            <Text style={{ fontSize: 12, display: 'block', marginBottom: 6 }}>اقدام‌ها (Actions)</Text>
            {actions.length === 0 ? (
                <Text type="secondary" style={{ fontSize: 12, display: 'block' }}>
                    Actionی تعریف نشده — برایِ انتشار، هر مرحلهٔ قابلِ‌اقدام حداقل یک Action لازم دارد.
                </Text>
            ) : (
                <Space direction="vertical" style={{ width: '100%' }} size={8}>
                    {actions.map((a, idx) => (
                        <ActionRow
                            key={a.code}
                            action={a}
                            index={idx}
                            total={actions.length}
                            readOnly={readOnly}
                            triggeredCount={triggeredCount(a.code)}
                            onChangeFields={onChangeFields}
                            onChangeKind={onChangeKind}
                            onRename={onRename}
                            onRemove={onRemove}
                            onMove={onMove}
                        />
                    ))}
                </Space>
            )}
            {!readOnly && (
                <Button size="small" icon={<PlusOutlined />} style={{ marginTop: 8 }} onClick={onAdd}>
                    افزودنِ Action
                </Button>
            )}
        </div>
    );
}
