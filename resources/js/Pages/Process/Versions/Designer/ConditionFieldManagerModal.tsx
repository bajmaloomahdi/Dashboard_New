import { useEffect, useState } from 'react';
import { Modal, Table, Button, Input, InputNumber, Select, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, FilterOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { wfApi } from '../../../../Components/Workflow/workflowApi';
import { toBool } from '../../../../Utils/bool';
import { THEME } from '../../../../theme';
import { DATA_TYPE_OPTIONS, parseAllowedValues, type ConditionDataType, type ConditionField } from './ruleTypes';

const { Text } = Typography;

interface ConditionFieldManagerModalProps {
    open: boolean;
    onClose: () => void;
    definitionId: number;
    fields: ConditionField[];
    /** بعد از هر تغییرِ موفق (ایجاد/ویرایش/تاگل) — والد لیستِ فیلدها را Refresh می‌کند */
    onChanged: () => void;
}

interface RowDraft {
    fieldId: number | null;
    code: string;
    displayName: string;
    dataType: ConditionDataType;
    sourceKey: string;
    allowedValuesText: string; // خط‌به‌خط، فقط برایِ SELECT
    sortOrder: number;
}

const emptyDraft: RowDraft = {
    fieldId: null, code: '', displayName: '', dataType: 'STRING', sourceKey: '', allowedValuesText: '', sortOrder: 0,
};

const CODE_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;

/**
 * مدیریتِ فیلدهایِ شرط (WorkflowConditionFields) — Definition-level؛ فقط برایِ
 * WORKFLOW_MANAGE_CONDITION_FIELDS. حذفِ فیزیکی وجود ندارد؛ فقط فعال/غیرفعال.
 * Code پس از استفاده در یک Rule، سمتِ Backend Immutable است (Guardِ DB/SP) — این
 * Modal فقط پیامِ خطایِ آن را نمایش می‌دهد، خودش هیچ قفلِ اضافه‌ای اعمال نمی‌کند.
 */
export default function ConditionFieldManagerModal({ open, onClose, definitionId, fields, onChanged }: ConditionFieldManagerModalProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState<RowDraft>(emptyDraft);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setFormOpen(false);
            setDraft(emptyDraft);
            setError(null);
        }
    }, [open]);

    const openCreate = () => {
        setDraft(emptyDraft);
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (f: ConditionField) => {
        setDraft({
            fieldId: f.FieldID,
            code: f.Code,
            displayName: f.DisplayName,
            dataType: f.DataType,
            sourceKey: f.SourceKey,
            allowedValuesText: parseAllowedValues(f.AllowedValuesJson).join('\n'),
            sortOrder: f.SortOrder,
        });
        setError(null);
        setFormOpen(true);
    };

    const handleSave = async () => {
        if (!draft.code.trim() || !draft.displayName.trim() || !draft.sourceKey.trim()) {
            setError('کد، نامِ نمایشی و SourceKey الزامی‌اند.');
            return;
        }
        if (!CODE_PATTERN.test(draft.code.trim())) {
            setError('کد باید با حرفِ بزرگِ لاتین شروع شود و فقط شاملِ حروفِ بزرگ/عدد/Underscore باشد.');
            return;
        }
        const allowedValues = draft.allowedValuesText.split('\n').map((v) => v.trim()).filter(Boolean);
        if (draft.dataType === 'SELECT' && allowedValues.length === 0) {
            setError('برایِ نوعِ «انتخابی» حداقل یک گزینه لازم است (هر گزینه در یک خط).');
            return;
        }

        setSaving(true);
        setError(null);
        const res = await wfApi(`/workflow/definitions/${definitionId}/condition-fields`, 'POST', {
            fieldId: draft.fieldId ?? undefined,
            code: draft.code.trim(),
            displayName: draft.displayName.trim(),
            dataType: draft.dataType,
            sourceType: 'START_CONTEXT',
            sourceKey: draft.sourceKey.trim(),
            allowedValues: draft.dataType === 'SELECT' ? allowedValues : undefined,
            sortOrder: draft.sortOrder,
        });
        setSaving(false);

        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        setFormOpen(false);
        onChanged();
    };

    const handleToggle = async (f: ConditionField) => {
        setTogglingId(f.FieldID);
        const res = await wfApi(`/workflow/condition-fields/${f.FieldID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        onChanged();
    };

    const columns: ColumnsType<ConditionField> = [
        { title: 'کد', dataIndex: 'Code', key: 'Code', width: 130, render: (v: string) => <Text code dir="ltr">{v}</Text> },
        { title: 'نامِ نمایشی', dataIndex: 'DisplayName', key: 'DisplayName' },
        {
            title: 'نوعِ داده',
            dataIndex: 'DataType',
            key: 'DataType',
            width: 130,
            render: (v: ConditionDataType) => <Tag style={{ borderRadius: 6 }}>{DATA_TYPE_OPTIONS.find((o) => o.value === v)?.label ?? v}</Tag>,
        },
        { title: 'SourceKey', dataIndex: 'SourceKey', key: 'SourceKey', width: 130, render: (v: string) => <Text code dir="ltr" style={{ fontSize: 12 }}>{v}</Text> },
        {
            title: 'وضعیت',
            key: 'status',
            width: 100,
            align: 'center',
            render: (_, r) => {
                const active = toBool(r.IsActive);
                return (
                    <Tag icon={active ? <CheckCircleOutlined /> : <StopOutlined />} color={active ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                        {active ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 110,
            align: 'center',
            render: (_, r) => {
                const active = toBool(r.IsActive);
                return (
                    <Space>
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />
                        <Popconfirm
                            title={active ? 'غیرفعال‌کردنِ فیلد' : 'فعال‌کردنِ فیلد'}
                            description={active ? 'این فیلد دیگر برایِ ساختِ Ruleِ جدید در دسترس نخواهد بود.' : 'آیا مطمئن هستید؟'}
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button
                                type="text"
                                size="small"
                                danger={active}
                                loading={togglingId === r.FieldID}
                                icon={active ? <StopOutlined /> : <CheckCircleOutlined />}
                            />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <Modal
            title={
                <Space>
                    <FilterOutlined style={{ color: THEME.primary }} />
                    <span>مدیریتِ فیلدهایِ شرط</span>
                </Space>
            }
            open={open}
            onCancel={onClose}
            width={780}
            footer={<Button onClick={onClose}>بستن</Button>}
            destroyOnClose
        >
            {error ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {!formOpen ? (
                <>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                        <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                            فیلدِ جدید
                        </Button>
                    </div>
                    <Table
                        size="small"
                        rowKey="FieldID"
                        columns={columns}
                        dataSource={fields}
                        pagination={false}
                        locale={{ emptyText: <Empty description="فیلدی تعریف نشده" /> }}
                    />
                </>
            ) : (
                <Space direction="vertical" style={{ width: '100%' }} size={12}>
                    <Space style={{ width: '100%' }} wrap>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>کد</Text>
                            <Input
                                dir="ltr"
                                style={{ width: 160, fontFamily: 'monospace' }}
                                disabled={!!draft.fieldId}
                                value={draft.code}
                                onChange={(e) => setDraft((d) => ({ ...d, code: e.target.value.toUpperCase() }))}
                                placeholder="AMOUNT"
                            />
                            {!!draft.fieldId && (
                                <Text type="secondary" style={{ fontSize: 11, display: 'block' }}>
                                    اگر این فیلد در Ruleی استفاده شده باشد، تغییرِ کد رد می‌شود.
                                </Text>
                            )}
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نامِ نمایشی</Text>
                            <Input style={{ width: 200 }} value={draft.displayName} onChange={(e) => setDraft((d) => ({ ...d, displayName: e.target.value }))} placeholder="مبلغ" />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوعِ داده</Text>
                            <Select
                                style={{ width: 180 }}
                                value={draft.dataType}
                                options={DATA_TYPE_OPTIONS}
                                onChange={(v) => setDraft((d) => ({ ...d, dataType: v }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ترتیب</Text>
                            <InputNumber style={{ width: 90 }} value={draft.sortOrder} onChange={(v) => setDraft((d) => ({ ...d, sortOrder: v ?? 0 }))} />
                        </div>
                    </Space>

                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
                            SourceKey <Text type="secondary" style={{ fontSize: 11 }}>(کلیدِ داده در Contextِ ارسالی هنگامِ شروعِ فرایند)</Text>
                        </Text>
                        <Input dir="ltr" style={{ fontFamily: 'monospace', maxWidth: 260 }} value={draft.sourceKey} onChange={(e) => setDraft((d) => ({ ...d, sourceKey: e.target.value }))} placeholder="amount" />
                    </div>

                    {draft.dataType === 'SELECT' && (
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>گزینه‌هایِ مجاز (هر خط یک گزینه)</Text>
                            <Input.TextArea
                                rows={4}
                                dir="ltr"
                                style={{ fontFamily: 'monospace' }}
                                value={draft.allowedValuesText}
                                onChange={(e) => setDraft((d) => ({ ...d, allowedValuesText: e.target.value }))}
                                placeholder={'PURCHASE\nSALE\nOTHER'}
                            />
                        </div>
                    )}

                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>
                            انصراف
                        </Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>
                            ذخیره
                        </Button>
                    </Space>
                </Space>
            )}
        </Modal>
    );
}
