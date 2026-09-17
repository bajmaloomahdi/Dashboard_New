import { useEffect, useState } from 'react';
import { Modal, Steps, Select, Radio, Input, InputNumber, Button, Space, Alert, Empty, Spin, Typography, Divider, Card } from 'antd';
import { PlayCircleOutlined, LeftOutlined, RightOutlined, SendOutlined, EyeOutlined } from '@ant-design/icons';
import { wfApi } from './workflowApi';

const { Text, Paragraph } = Typography;

interface WfDefinition {
    DefinitionID: number;
    Code: string;
    Name: string;
    EntityType: string;
    ActiveVersionNo: number | null;
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

/**
 * Message → انتخابِ Workflow → انتخابِ Template → تکمیلِ پارامترهایِ Form →
 * پیش‌نمایش → ایجادِ Message → (اختیاری) Startِ Workflow.
 *
 * دو HTTP Request و دو Transactionِ کاملاً مستقل: POST /messages/from-template
 * سپس POST /workflow/instances — هیچ Transactionِ مشترکی بینِ این دو نیست.
 */
export default function MessageFromTemplateModal({ open, onClose, targets, onSuccess }: MessageFromTemplateModalProps) {
    const [step, setStep] = useState(0);

    const [definitions, setDefinitions] = useState<WfDefinition[]>([]);
    const [definitionsLoading, setDefinitionsLoading] = useState(false);
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

    const selectedTemplate = templates.find((t) => t.LetterTemplateID === templateId) || null;

    useEffect(() => {
        if (!open) return;
        setStep(0);
        setDefinitionId(null);
        setTemplateId(null);
        setFormValues({});
        setRecipientUserIds([]);
        setPreview(null);
        setError(null);
        setStartWorkflow(true);

        setDefinitionsLoading(true);
        wfApi('/workflow/definitions?isActive=1').then((res) => {
            setDefinitionsLoading(false);
            if (res.ok && res.success) {
                setDefinitions((res.items || []).filter((d: WfDefinition) => d.EntityType === 'MESSAGE' && d.ActiveVersionNo != null));
            }
        });

        wfApi('/workflow/template-parameters?isActive=1').then((res) => {
            if (res.ok && res.success) {
                setAllParams(res.items || []);
            }
        });
    }, [open]);

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

        if (startWorkflow && definitionId) {
            const wfRes = await wfApi('/workflow/instances', 'POST', {
                definitionId,
                entityType: 'MESSAGE',
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

    const canGoStep1 = definitionId !== null;
    const canGoStep2 = templateId !== null;

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
                items={[{ title: 'فرایند' }, { title: 'قالب' }, { title: 'تکمیلِ فرم' }, { title: 'ارسال' }]}
            />

            {error ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {step === 0 ? (
                <Space direction="vertical" style={{ width: '100%' }}>
                    <Text>یک فرایندِ فعال برایِ پیام انتخاب کنید:</Text>
                    {definitionsLoading ? (
                        <Spin />
                    ) : definitions.length === 0 ? (
                        <Empty description="فرایندِ فعالی برایِ پیام تعریف نشده است." />
                    ) : (
                        <Select
                            style={{ width: '100%' }}
                            placeholder="انتخابِ فرایند..."
                            value={definitionId ?? undefined}
                            onChange={handlePickDefinition}
                            options={definitions.map((d) => ({ value: d.DefinitionID, label: `${d.Name} (${d.Code})` }))}
                        />
                    )}
                    <Space style={{ justifyContent: 'flex-end', width: '100%', marginTop: 12 }}>
                        <Button onClick={onClose}>انصراف</Button>
                        <Button type="primary" icon={<RightOutlined />} disabled={!canGoStep1} onClick={() => setStep(1)}>
                            بعدی
                        </Button>
                    </Space>
                </Space>
            ) : null}

            {step === 1 ? (
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
                        <Button icon={<LeftOutlined />} onClick={() => setStep(0)}>قبلی</Button>
                        <Button type="primary" icon={<RightOutlined />} disabled={!canGoStep2} onClick={() => setStep(2)}>
                            بعدی
                        </Button>
                    </Space>
                </Space>
            ) : null}

            {step === 2 ? (
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
                        <Button icon={<LeftOutlined />} onClick={() => setStep(1)} disabled={submitting}>قبلی</Button>
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
