import { useEffect, useState } from 'react';
import { Modal, Steps, Select, Radio, Input, InputNumber, Button, Space, Alert, Empty, Spin, Typography, Divider, Card } from 'antd';
import { PlayCircleOutlined, LeftOutlined, RightOutlined, SendOutlined, EyeOutlined } from '@ant-design/icons';
import { wfApi } from './workflowApi';
import PersianDateInput from '../PersianDateInput';

const { Text, Paragraph } = Typography;

interface EntityTypeOption {
    Code: string;
    DisplayName: string;
}

interface WfDefinition {
    DefinitionID: number;
    Code: string;
    Name: string;
    EntityType: string;
    ActiveVersionNo: number | null;
    CategoryID: number | null;
    CategoryName: string | null;
}

interface LetterTemplate {
    LetterTemplateID: number;
    Code: string;
    Name: string;
    EntityType: string;
    DefinitionID: number | null;
    SubjectTemplate: string;
    BodyTemplate: string;
    IsActive: boolean | number | string;
}

interface TemplateParam {
    Code: string;
    Caption: string;
    DataType: string;
    SourceType: string;
    SourceKey: string;
}

interface TargetUser {
    UserID: number;
    FullName: string;
}

interface MessageFromTemplateModalProps {
    open: boolean;
    onClose: () => void;
    targets: TargetUser[];
    /** بعد از ساختِ موفقِ Message (و در صورتِ انتخاب، Startِ Workflow) */
    onSuccess: (message: string, messageId: number) => void;
}

const TOKEN_PATTERN = /\{\{([A-Z][A-Z0-9_]{1,49})\}\}/g;

/** مقدارِ ویژهٔ Categoryِ Selectِ زیرِ گزینهٔ «بدونِ دسته‌بندی» — چون CategoryIDِ واقعی همیشه مثبت است. */
const NO_CATEGORY = 0;

/**
 * نوعِ فرایند → دسته‌بندی → Workflow → Template → تکمیلِ پارامترهایِ Form →
 * پیش‌نمایش → ایجادِ Message → (اختیاری) Startِ Workflow.
 *
 * نوعِ فرایند و دسته‌بندی صرفاً فیلترهایِ سمتِ کلاینت رویِ همان دو Registryِ
 * موجود (WorkflowEntityTypes و WorkflowDefinitions.CategoryID) هستند — هیچ
 * Endpoint یا جدولِ جدیدی برایِ این دو قدم لازم نبود. دو HTTP Request و دو
 * Transactionِ کاملاً مستقل: POST /messages/from-template سپس POST
 * /workflow/instances — هیچ Transactionِ مشترکی بینِ این دو نیست.
 */
export default function MessageFromTemplateModal({ open, onClose, targets, onSuccess }: MessageFromTemplateModalProps) {
    const [step, setStep] = useState(0);

    const [entityTypes, setEntityTypes] = useState<EntityTypeOption[]>([]);
    const [entityTypesLoading, setEntityTypesLoading] = useState(false);
    const [entityType, setEntityType] = useState<string | null>(null);

    const [allDefinitions, setAllDefinitions] = useState<WfDefinition[]>([]);
    const [definitionsLoading, setDefinitionsLoading] = useState(false);
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [categoryStepSkipped, setCategoryStepSkipped] = useState(false);
    const [definitionId, setDefinitionId] = useState<number | null>(null);

    const [templates, setTemplates] = useState<LetterTemplate[]>([]);
    const [templatesLoading, setTemplatesLoading] = useState(false);
    const [templateId, setTemplateId] = useState<number | null>(null);

    const [allParams, setAllParams] = useState<TemplateParam[]>([]);
    const [formValues, setFormValues] = useState<Record<string, string>>({});

    const [recipientUserIds, setRecipientUserIds] = useState<number[]>([]);
    const [startWorkflow, setStartWorkflow] = useState(true);

    const [preview, setPreview] = useState<{ subject: string; body: string } | null>(null);
    const [previewLoading, setPreviewLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const selectedDefinition = allDefinitions.find((d) => d.DefinitionID === definitionId) || null;
    const selectedTemplate = templates.find((t) => t.LetterTemplateID === templateId) || null;

    useEffect(() => {
        if (!open) return;
        setStep(0);
        setEntityType(null);
        setCategoryId(null);
        setCategoryStepSkipped(false);
        setDefinitionId(null);
        setTemplateId(null);
        setFormValues({});
        setRecipientUserIds([]);
        setPreview(null);
        setError(null);
        setStartWorkflow(true);

        setEntityTypesLoading(true);
        wfApi('/workflow/entity-types?isActive=1&usableOnly=1').then((res) => {
            setEntityTypesLoading(false);
            if (res.ok && res.success) {
                setEntityTypes((res.items || []).map((e: any) => ({ Code: e.Code, DisplayName: e.DisplayName })));
            }
        });

        setDefinitionsLoading(true);
        wfApi('/workflow/definitions?isActive=1').then((res) => {
            setDefinitionsLoading(false);
            if (res.ok && res.success) {
                setAllDefinitions((res.items || []).filter((d: WfDefinition) => d.ActiveVersionNo != null));
            }
        });

        wfApi('/workflow/template-parameters?isActive=1').then((res) => {
            if (res.ok && res.success) {
                setAllParams(res.items || []);
            }
        });
    }, [open]);

    /** فرایندهایِ منطبق با نوعِ فرایندِ انتخاب‌شده (فیلترِ سمتِ کلاینت رویِ همان لیستِ کاملِ Definitionها). */
    const definitionsForEntityType = entityType ? allDefinitions.filter((d) => d.EntityType === entityType) : [];

    /** گزینه‌هایِ دسته‌بندی — از رویِ CategoryID/CategoryName همان Definitionهایِ همین نوعِ فرایند، بدونِ Endpointِ جدید. */
    const categoryOptions = (() => {
        const map = new Map<number, string>();
        let hasUncategorized = false;
        definitionsForEntityType.forEach((d) => {
            if (d.CategoryID == null) {
                hasUncategorized = true;
            } else {
                map.set(d.CategoryID, d.CategoryName || `دستهٔ ${d.CategoryID}`);
            }
        });
        const opts = Array.from(map.entries()).map(([id, name]) => ({ value: id, label: name }));
        if (hasUncategorized) opts.push({ value: NO_CATEGORY, label: 'بدونِ دسته‌بندی' });
        return opts;
    })();

    const definitionsForCategory = definitionsForEntityType.filter((d) => {
        if (categoryId === null) return true;
        if (categoryId === NO_CATEGORY) return d.CategoryID == null;
        return d.CategoryID === categoryId;
    });

    const handlePickEntityType = (code: string) => {
        setEntityType(code);
        setCategoryId(null);
        setDefinitionId(null);
        setTemplateId(null);
    };

    /** بعد از انتخابِ نوعِ فرایند: اگر بیش از یک دسته‌بندی معنادار وجود نداشت، قدمِ دسته‌بندی را رد کن. */
    const handleAfterEntityType = () => {
        if (categoryOptions.length <= 1) {
            setCategoryId(categoryOptions[0]?.value ?? null);
            setCategoryStepSkipped(true);
            setStep(2);
        } else {
            setCategoryStepSkipped(false);
            setStep(1);
        }
    };

    const handleBackFromDefinition = () => {
        setStep(categoryStepSkipped ? 0 : 1);
    };

    const handlePickDefinition = (id: number) => {
        setDefinitionId(id);
        setTemplateId(null);
        setTemplates([]);
        setTemplatesLoading(true);
        wfApi(`/workflow/definitions/${id}/templates`).then((res) => {
            setTemplatesLoading(false);
            if (res.ok && res.success) {
                setTemplates(res.items || []);
            }
        });
    };

    const formTokensForTemplate = (tpl: LetterTemplate | null): TemplateParam[] => {
        if (!tpl) return [];
        const codes = new Set<string>();
        let m: RegExpExecArray | null;
        const text = tpl.SubjectTemplate + ' ' + tpl.BodyTemplate;
        TOKEN_PATTERN.lastIndex = 0;
        while ((m = TOKEN_PATTERN.exec(text)) !== null) codes.add(m[1]);

        return allParams.filter((p) => codes.has(p.Code) && p.SourceType === 'FORM');
    };

    const formFields = formTokensForTemplate(selectedTemplate);

    const handlePreview = async () => {
        if (!templateId) return;
        setPreviewLoading(true);
        setError(null);
        const res = await wfApi(`/workflow/templates/${templateId}/render`, 'POST', { formValues });
        setPreviewLoading(false);
        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        setPreview({ subject: res.subject, body: res.body });
    };

    const handleSubmit = async () => {
        if (!templateId || recipientUserIds.length === 0) {
            setError('انتخابِ گیرنده الزامی است.');
            return;
        }
        setSubmitting(true);
        setError(null);

        const msgRes = await wfApi('/messages/from-template', 'POST', {
            letterTemplateId: templateId,
            formValues,
            MessageTypeID: 1,
            msgPriorityID: 1,
            RecipientType: 1,
            RecipientUserIDs: recipientUserIds,
        });

        if (!msgRes.ok || !msgRes.success) {
            setSubmitting(false);
            setError(msgRes.message);
            return;
        }

        const messageId = msgRes.messageId;

        if (startWorkflow && definitionId && selectedDefinition) {
            const wfRes = await wfApi('/workflow/instances', 'POST', {
                definitionId,
                entityType: selectedDefinition.EntityType,
                entityId: messageId,
                context: formValues,
            });
            setSubmitting(false);

            if (!wfRes.ok || !wfRes.success) {
                // Message ساخته شده، Start شکست خورد — طبقِ اصلِ P0: Message دست‌نخورده می‌ماند
                setError(`پیام با موفقیت ساخته شد، اما شروعِ فرایند ناموفق بود: ${wfRes.message}`);
                onSuccess('پیام ساخته شد (بدونِ Workflow).', messageId);
                return;
            }

            onSuccess('پیام ساخته شد و فرایند آغاز شد.', messageId);
            return;
        }

        setSubmitting(false);
        onSuccess(msgRes.message || 'پیام ساخته شد.', messageId);
    };

    const canGoEntityType = entityType !== null;
    const canGoCategory = categoryId !== null;
    const canGoDefinition = definitionId !== null;
    const canGoTemplate = templateId !== null;

    return (
        <Modal
            title={
                <Space>
                    <PlayCircleOutlined />
                    <span>ساختِ پیام از رویِ الگو</span>
                </Space>
            }
            open={open}
            onCancel={() => (submitting ? undefined : onClose())}
            width={640}
            footer={null}
            destroyOnClose
        >
            <Steps
                size="small"
                current={step}
                style={{ marginBottom: 20 }}
                items={[{ title: 'نوعِ فرایند' }, { title: 'دسته‌بندی' }, { title: 'فرایند' }, { title: 'قالب' }, { title: 'تکمیلِ فرم' }, { title: 'ارسال' }]}
            />

            {error ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {step === 0 ? (
                <Space direction="vertical" style={{ width: '100%' }}>
                    <Text>نوعِ فرایند را انتخاب کنید:</Text>
                    {entityTypesLoading ? (
                        <Spin />
                    ) : entityTypes.length === 0 ? (
                        <Empty description="نوعِ فرایندِ فعالی در Registry ثبت نشده است." />
                    ) : (
                        <Select
                            style={{ width: '100%' }}
                            placeholder="انتخابِ نوعِ فرایند..."
                            value={entityType ?? undefined}
                            onChange={handlePickEntityType}
                            options={entityTypes.map((e) => ({ value: e.Code, label: `${e.DisplayName} (${e.Code})` }))}
                        />
                    )}
                    <Space style={{ justifyContent: 'flex-end', width: '100%', marginTop: 12 }}>
                        <Button onClick={onClose}>انصراف</Button>
                        <Button type="primary" icon={<RightOutlined />} disabled={!canGoEntityType || definitionsLoading} onClick={handleAfterEntityType}>
                            بعدی
                        </Button>
                    </Space>
                </Space>
            ) : null}

            {step === 1 ? (
                <Space direction="vertical" style={{ width: '100%' }}>
                    <Text>دسته‌بندیِ فرایند را انتخاب کنید:</Text>
                    <Select
                        style={{ width: '100%' }}
                        placeholder="انتخابِ دسته‌بندی..."
                        value={categoryId ?? undefined}
                        onChange={setCategoryId}
                        options={categoryOptions}
                    />
                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 12 }}>
                        <Button icon={<LeftOutlined />} onClick={() => setStep(0)}>قبلی</Button>
                        <Button type="primary" icon={<RightOutlined />} disabled={!canGoCategory} onClick={() => setStep(2)}>
                            بعدی
                        </Button>
                    </Space>
                </Space>
            ) : null}

            {step === 2 ? (
                <Space direction="vertical" style={{ width: '100%' }}>
                    <Text>یک فرایندِ فعال انتخاب کنید:</Text>
                    {definitionsLoading ? (
                        <Spin />
                    ) : definitionsForCategory.length === 0 ? (
                        <Empty description="فرایندِ فعالی برایِ این نوع/دسته‌بندی تعریف نشده است." />
                    ) : (
                        <Select
                            style={{ width: '100%' }}
                            placeholder="انتخابِ فرایند..."
                            value={definitionId ?? undefined}
                            onChange={handlePickDefinition}
                            options={definitionsForCategory.map((d) => ({ value: d.DefinitionID, label: `${d.Name} (${d.Code})` }))}
                        />
                    )}
                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 12 }}>
                        <Button icon={<LeftOutlined />} onClick={handleBackFromDefinition}>قبلی</Button>
                        <Button type="primary" icon={<RightOutlined />} disabled={!canGoDefinition} onClick={() => setStep(3)}>
                            بعدی
                        </Button>
                    </Space>
                </Space>
            ) : null}

            {step === 3 ? (
                <Space direction="vertical" style={{ width: '100%' }}>
                    <Text>یک قالبِ نامه انتخاب کنید:</Text>
                    {templatesLoading ? (
                        <Spin />
                    ) : templates.length === 0 ? (
                        <Empty description="قالبی برایِ این فرایند تعریف نشده است." />
                    ) : (
                        <Radio.Group
                            style={{ width: '100%' }}
                            value={templateId}
                            onChange={(e) => {
                                setTemplateId(e.target.value);
                                setFormValues({});
                                setPreview(null);
                            }}
                        >
                            <Space direction="vertical" style={{ width: '100%' }}>
                                {templates.map((t) => (
                                    <Radio key={t.LetterTemplateID} value={t.LetterTemplateID}>
                                        <Text strong>{t.Name}</Text> <Text type="secondary" style={{ fontSize: 12 }}>({t.Code})</Text>
                                    </Radio>
                                ))}
                            </Space>
                        </Radio.Group>
                    )}
                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 12 }}>
                        <Button icon={<LeftOutlined />} onClick={() => setStep(2)}>قبلی</Button>
                        <Button type="primary" icon={<RightOutlined />} disabled={!canGoTemplate} onClick={() => setStep(4)}>
                            بعدی
                        </Button>
                    </Space>
                </Space>
            ) : null}

            {step === 4 ? (
                <Space direction="vertical" style={{ width: '100%' }}>
                    {formFields.length === 0 ? (
                        <Text type="secondary">این قالب فیلدِ فرمی ندارد.</Text>
                    ) : (
                        formFields.map((f) => (
                            <div key={f.Code}>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>{f.Caption}</Text>
                                {f.DataType === 'INTEGER' || f.DataType === 'DECIMAL' ? (
                                    <InputNumber
                                        style={{ width: '100%' }}
                                        value={formValues[f.SourceKey] ? Number(formValues[f.SourceKey]) : undefined}
                                        onChange={(v) => setFormValues((s) => ({ ...s, [f.SourceKey]: v == null ? '' : String(v) }))}
                                    />
                                ) : f.DataType === 'DATE' ? (
                                    <PersianDateInput
                                        value={formValues[f.SourceKey] || null}
                                        onChange={(v) => setFormValues((s) => ({ ...s, [f.SourceKey]: v || '' }))}
                                    />
                                ) : (
                                    <Input
                                        value={formValues[f.SourceKey] || ''}
                                        onChange={(e) => setFormValues((s) => ({ ...s, [f.SourceKey]: e.target.value }))}
                                    />
                                )}
                            </div>
                        ))
                    )}

                    <Divider style={{ margin: '12px 0' }} />
                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>گیرنده</Text>
                    <Select
                        mode="multiple"
                        style={{ width: '100%' }}
                        placeholder="انتخابِ گیرنده(ها)..."
                        value={recipientUserIds}
                        onChange={setRecipientUserIds}
                        options={(targets || []).map((t) => ({ value: t.UserID, label: t.FullName }))}
                        optionFilterProp="label"
                        showSearch
                    />

                    <Button icon={<EyeOutlined />} loading={previewLoading} onClick={handlePreview} style={{ marginTop: 8 }}>
                        پیش‌نمایشِ متنِ نامه
                    </Button>

                    {preview ? (
                        <Card size="small" style={{ background: '#fafafa', marginTop: 8 }}>
                            <Text strong>{preview.subject}</Text>
                            <Paragraph style={{ marginTop: 8, marginBottom: 0, whiteSpace: 'pre-wrap' }}>{preview.body}</Paragraph>
                        </Card>
                    ) : null}

                    <Space style={{ justifyContent: 'space-between', width: '100%', marginTop: 12 }}>
                        <Button icon={<LeftOutlined />} onClick={() => setStep(3)} disabled={submitting}>قبلی</Button>
                        <Space>
                            <Button
                                type={startWorkflow ? 'primary' : 'default'}
                                ghost={startWorkflow}
                                onClick={() => setStartWorkflow((s) => !s)}
                            >
                                {startWorkflow ? '✓ شروعِ فرایند پس از ارسال' : 'بدونِ شروعِ فرایند'}
                            </Button>
                            <Button type="primary" icon={<SendOutlined />} loading={submitting} onClick={handleSubmit}>
                                ارسال
                            </Button>
                        </Space>
                    </Space>
                </Space>
            ) : null}
        </Modal>
    );
}
