import { useEffect, useState } from 'react';
import { Modal, Radio, Space, Typography, Empty, Spin, Alert, Button, Input, InputNumber, Select, Divider } from 'antd';
import { PlayCircleOutlined } from '@ant-design/icons';
import { wfApi } from './workflowApi';
import PersianDateInput from '../PersianDateInput';
import { columnHelpers } from '../../theme';

const { Text } = Typography;

interface WfDefinition {
    DefinitionID: number;
    Code: string;
    Name: string;
    EntityType: string;
    ActiveVersionNo: number | null;
}

interface WfConditionField {
    FieldID: number;
    Code: string;
    DisplayName: string;
    DataType: string;
    SourceType: string;
    SourceKey: string;
    AllowedValuesJson: string | null;
}

interface StartWorkflowModalProps {
    open: boolean;
    onClose: () => void;
    entityType: string;
    entityId: number;
    /** بعد از Startِ موفق — والد پیامِ موفقیت را نشان می‌دهد و صفحه را Reload می‌کند. */
    onSuccess: (message: string) => void;
}

/**
 * Message Detail → «شروع فرایند» → این Modal.
 *
 * فیلترِ EntityType/ActiveVersionNo اینجا فقط UX است (کوتاه‌کردنِ لیست برایِ
 * کاربر)؛ Backendِ POST /workflow/instances مستقل و قطعی همان موارد را (و
 * موارد بیشتری مثلِ دسترسیِ کاربر به Message) دوباره Validate می‌کند.
 */
export default function StartWorkflowModal({ open, onClose, entityType, entityId, onSuccess }: StartWorkflowModalProps) {
    const [loading, setLoading] = useState(false);
    const [definitions, setDefinitions] = useState<WfDefinition[]>([]);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [starting, setStarting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [conditionFields, setConditionFields] = useState<WfConditionField[]>([]);
    const [fieldsLoading, setFieldsLoading] = useState(false);
    const [contextValues, setContextValues] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!open) return;

        setError(null);
        setSelectedId(null);
        setConditionFields([]);
        setContextValues({});
        setLoading(true);

        wfApi('/workflow/definitions?isActive=1').then((res) => {
            setLoading(false);
            if (!res.ok || !res.success) {
                setError(res.message || 'خطا در دریافتِ فهرستِ فرایندها.');
                return;
            }
            const items: WfDefinition[] = (res.items || []).filter(
                (d: WfDefinition) => d.EntityType === entityType && d.ActiveVersionNo != null
            );
            setDefinitions(items);
        });
    }, [open, entityType]);

    // فیلدهایِ شرط (START_CONTEXT) همان Definitionِ انتخاب‌شده — مقدارِ آن‌ها همان
    // «مقدارِ فرم» است که در ContextJsonِ Instance ذخیره و بعداً در CONDITIONها استفاده می‌شود.
    useEffect(() => {
        setContextValues({});
        if (!selectedId) {
            setConditionFields([]);
            return;
        }
        setFieldsLoading(true);
        wfApi(`/workflow/definitions/${selectedId}/condition-fields`).then((res) => {
            setFieldsLoading(false);
            if (res.ok && res.success) {
                const items: WfConditionField[] = (res.items || []).filter(
                    (f: WfConditionField) => f.SourceType === 'START_CONTEXT'
                );
                setConditionFields(items);
            }
        });
    }, [selectedId]);

    const setFieldValue = (sourceKey: string, value: string) =>
        setContextValues((s) => ({ ...s, [sourceKey]: value }));

    const buildContext = (): Record<string, unknown> => {
        const ctx: Record<string, unknown> = {};
        for (const f of conditionFields) {
            const raw = contextValues[f.SourceKey];
            if (raw === undefined || raw === '') continue;
            ctx[f.SourceKey] = f.DataType === 'BOOLEAN' ? raw === 'true' : raw;
        }
        return ctx;
    };

    const handleStart = async () => {
        if (!selectedId) return;

        setStarting(true);
        setError(null);

        const res = await wfApi('/workflow/instances', 'POST', {
            definitionId: selectedId,
            entityType,
            entityId,
            context: buildContext(),
        });

        setStarting(false);

        if (!res.ok || !res.success) {
            setError(res.message || 'شروعِ فرایند با خطا مواجه شد.');
            return;
        }

        onSuccess(res.message || 'فرایند آغاز شد.');
    };

    return (
        <Modal
            open={open}
            title={
                <Space>
                    <PlayCircleOutlined />
                    <span>شروعِ فرایند</span>
                </Space>
            }
            onCancel={() => (starting ? undefined : onClose())}
            footer={[
                <Button key="cancel" onClick={onClose} disabled={starting}>
                    انصراف
                </Button>,
                <Button
                    key="start"
                    type="primary"
                    icon={<PlayCircleOutlined />}
                    loading={starting}
                    disabled={!selectedId || loading || definitions.length === 0}
                    onClick={handleStart}
                >
                    شروع
                </Button>,
            ]}
        >
            {error ? (
                <Alert type="error" showIcon message={error} style={{ marginBottom: 14, borderRadius: 8 }} />
            ) : null}

            {loading ? (
                <div style={{ textAlign: 'center', padding: '24px 0' }}>
                    <Spin />
                </div>
            ) : definitions.length === 0 ? (
                <Empty description="فرایندِ فعالی برایِ این نوع مورد تعریف نشده است." />
            ) : (
                <Radio.Group
                    style={{ width: '100%' }}
                    value={selectedId}
                    onChange={(e) => setSelectedId(e.target.value)}
                >
                    <Space direction="vertical" style={{ width: '100%' }}>
                        {definitions.map((d) => (
                            <Radio key={d.DefinitionID} value={d.DefinitionID} style={{ width: '100%' }}>
                                <Text strong>{d.Name}</Text>{' '}
                                <Text type="secondary" style={{ fontSize: 12 }}>
                                    ({d.Code})
                                </Text>
                            </Radio>
                        ))}
                    </Space>
                </Radio.Group>
            )}

            {fieldsLoading ? (
                <div style={{ textAlign: 'center', padding: '12px 0' }}>
                    <Spin size="small" />
                </div>
            ) : conditionFields.length > 0 ? (
                <>
                    <Divider style={{ margin: '16px 0' }}>
                        <Text strong style={{ fontSize: 13 }}>اطلاعاتِ لازم برایِ این فرایند</Text>
                    </Divider>
                    <Space direction="vertical" style={{ width: '100%' }} size={12}>
                        {conditionFields.map((f) => {
                            const value = contextValues[f.SourceKey] ?? '';
                            let control: JSX.Element;

                            if (f.DataType === 'INTEGER' || f.DataType === 'DECIMAL') {
                                control = (
                                    <InputNumber
                                        style={{ width: '100%' }}
                                        value={value === '' ? undefined : Number(value)}
                                        onChange={(v) => setFieldValue(f.SourceKey, v == null ? '' : String(v))}
                                        formatter={(v) => columnHelpers.formatNumberInput(v)}
                                        parser={(v) => Number(columnHelpers.parseNumberInput(v))}
                                    />
                                );
                            } else if (f.DataType === 'DATE') {
                                control = (
                                    <PersianDateInput
                                        value={value || null}
                                        onChange={(v) => setFieldValue(f.SourceKey, v || '')}
                                    />
                                );
                            } else if (f.DataType === 'BOOLEAN') {
                                control = (
                                    <Radio.Group
                                        value={value || undefined}
                                        onChange={(e) => setFieldValue(f.SourceKey, e.target.value)}
                                    >
                                        <Radio value="true">بله</Radio>
                                        <Radio value="false">خیر</Radio>
                                    </Radio.Group>
                                );
                            } else if (f.DataType === 'SELECT') {
                                const options = ((): string[] => {
                                    try {
                                        return JSON.parse(f.AllowedValuesJson || '[]');
                                    } catch {
                                        return [];
                                    }
                                })();
                                control = (
                                    <Select
                                        style={{ width: '100%' }}
                                        value={value || undefined}
                                        onChange={(v) => setFieldValue(f.SourceKey, v)}
                                        options={options.map((o) => ({ value: o, label: o }))}
                                    />
                                );
                            } else {
                                control = (
                                    <Input
                                        value={value}
                                        onChange={(e) => setFieldValue(f.SourceKey, e.target.value)}
                                    />
                                );
                            }

                            return (
                                <div key={f.FieldID}>
                                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>{f.DisplayName}</Text>
                                    {control}
                                </div>
                            );
                        })}
                    </Space>
                </>
            ) : null}
        </Modal>
    );
}
