import { useEffect, useRef, useState } from 'react';
import { Modal, Form, Input, Select, Row, Col, Button, Typography, Alert, Space } from 'antd';
import type { InputRef } from 'antd';
import type { TextAreaRef } from 'antd/es/input/TextArea';
import { FileTextOutlined, SaveOutlined, CloseOutlined, CodeOutlined, TagOutlined, PlusCircleOutlined } from '@ant-design/icons';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

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
}

interface TemplateParameter {
    Code: string;
    Caption: string;
    EntityType: string | null;
}

interface TemplateFormModalProps {
    open: boolean;
    onClose: () => void;
    editingTemplate: LetterTemplate | null;
    onSuccess: (message: string) => void;
}

const emptyValues = {
    code: '',
    name: '',
    entityType: '',
    definitionId: null as number | null,
    subjectTemplate: '',
    bodyTemplate: '',
    isActive: true,
};

/** درجِ یک Tokenِ `{{CODE}}` در موقعیتِ فعلیِ Cursor؛ اگر مرجعِ فیلد در دسترس نبود، به انتهای متن اضافه می‌شود. */
function insertAtCursor(
    el: HTMLInputElement | HTMLTextAreaElement | undefined | null,
    token: string,
    current: string,
    setValue: (v: string) => void
) {
    if (!el) {
        setValue(current + token);
        return;
    }
    const start = el.selectionStart ?? current.length;
    const end = el.selectionEnd ?? current.length;
    const next = current.slice(0, start) + token + current.slice(end);
    setValue(next);
    requestAnimationFrame(() => {
        el.focus();
        const pos = start + token.length;
        el.setSelectionRange(pos, pos);
    });
}

/**
 * فرمِ ایجاد/ویرایشِ LetterTemplate (فازِ ۳ — Phase C). هر دو حالت از یک
 * endpointِ Upsertِ موجود (POST /workflow/templates) عبور می‌کنند. Editor برایِ
 * موضوع/متن فقط یک TextArea معمولی + دکمهٔ «درجِ پارامتر» است که یک Tokenِ
 * `{{CODE}}` را در موقعیتِ Cursor درج می‌کند — بدونِ هیچ کتابخانهٔ Rich-Textِ جدید.
 * اعتبارسنجیِ نهاییِ Tokenها (ناشناخته/غیرفعال/EntityType نامرتبط) کاملاً روی
 * سرور (TemplateRenderer::validateTokens) انجام می‌شود؛ این فرم فقط UX را
 * تسهیل می‌کند.
 */
export default function TemplateFormModal({ open, onClose, editingTemplate, onSuccess }: TemplateFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingTemplate;

    const [values, setValues] = useState(emptyValues);
    const [processing, setProcessing] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);

    const [entityTypes, setEntityTypes] = useState<EntityTypeOption[]>([]);
    const [definitions, setDefinitions] = useState<WfDefinition[]>([]);
    const [templateParameters, setTemplateParameters] = useState<TemplateParameter[]>([]);
    const [registryLoading, setRegistryLoading] = useState(false);

    const [subjectInsertCode, setSubjectInsertCode] = useState<string | null>(null);
    const [bodyInsertCode, setBodyInsertCode] = useState<string | null>(null);

    const subjectRef = useRef<InputRef>(null);
    const bodyRef = useRef<TextAreaRef>(null);

    useEffect(() => {
        if (!open) return;

        setRegistryLoading(true);
        Promise.all([
            wfApi('/workflow/entity-types?isActive=1&usableOnly=1'),
            wfApi('/workflow/definitions?isActive=1'),
            wfApi('/workflow/template-parameters?isActive=1'),
        ]).then(([entityTypesRes, definitionsRes, paramsRes]) => {
            setRegistryLoading(false);
            if (entityTypesRes.ok && entityTypesRes.success) setEntityTypes(entityTypesRes.items || []);
            if (definitionsRes.ok && definitionsRes.success) {
                setDefinitions((definitionsRes.items || []).filter((d: WfDefinition) => d.ActiveVersionNo != null));
            }
            if (paramsRes.ok && paramsRes.success) setTemplateParameters(paramsRes.items || []);
        });

        const initial = editingTemplate
            ? {
                  code: editingTemplate.Code || '',
                  name: editingTemplate.Name || '',
                  entityType: editingTemplate.EntityType || '',
                  definitionId: editingTemplate.DefinitionID ?? null,
                  subjectTemplate: editingTemplate.SubjectTemplate || '',
                  bodyTemplate: editingTemplate.BodyTemplate || '',
                  isActive: toBool(editingTemplate.IsActive),
              }
            : emptyValues;

        setValues(initial);
        form.setFieldsValue(initial);
        setFieldErrors({});
        setFormError(null);
        setSubjectInsertCode(null);
        setBodyInsertCode(null);
    }, [open, editingTemplate]);

    const handleClose = () => {
        if (processing) return;
        form.resetFields();
        onClose();
    };

    const handleEntityTypeChange = (v: string) => {
        setValues((s) => ({
            ...s,
            entityType: v,
            definitionId: definitions.find((d) => d.DefinitionID === s.definitionId)?.EntityType === v ? s.definitionId : null,
        }));
    };

    const availableParams = templateParameters.filter((p) => !p.EntityType || p.EntityType === values.entityType);
    const paramOptions = availableParams.map((p) => ({ value: p.Code, label: `${p.Caption} (${p.Code})` }));

    const handleInsertSubject = () => {
        if (!subjectInsertCode) return;
        insertAtCursor(subjectRef.current?.input, `{{${subjectInsertCode}}}`, values.subjectTemplate, (v) => {
            setValues((s) => ({ ...s, subjectTemplate: v }));
            form.setFieldValue('subjectTemplate', v);
        });
    };

    const handleInsertBody = () => {
        if (!bodyInsertCode) return;
        insertAtCursor(bodyRef.current?.resizableTextArea?.textArea, `{{${bodyInsertCode}}}`, values.bodyTemplate, (v) => {
            setValues((s) => ({ ...s, bodyTemplate: v }));
            form.setFieldValue('bodyTemplate', v);
        });
    };

    const handleSubmit = () => {
        form.validateFields().then(async () => {
            setProcessing(true);
            setFieldErrors({});
            setFormError(null);

            const res = await wfApi('/workflow/templates', 'POST', {
                letterTemplateId: editingTemplate ? editingTemplate.LetterTemplateID : undefined,
                code: values.code,
                name: values.name,
                entityType: values.entityType,
                definitionId: values.definitionId ?? undefined,
                subjectTemplate: values.subjectTemplate,
                bodyTemplate: values.bodyTemplate,
            });

            setProcessing(false);

            if (!res.ok || !res.success) {
                if (res.errors && typeof res.errors === 'object' && !Array.isArray(res.errors)) {
                    const mapped: Record<string, string> = {};
                    Object.entries(res.errors as Record<string, string[]>).forEach(([field, messages]) => {
                        mapped[field] = Array.isArray(messages) ? messages[0] : String(messages);
                    });
                    setFieldErrors(mapped);
                }
                setFormError(res.message);
                return;
            }

            form.resetFields();
            onSuccess(res.message);
        });
    };

    const matchingDefinitions = definitions.filter((d) => !values.entityType || d.EntityType === values.entityType);

    return (
        <Modal
            title={
                <Space>
                    <FileTextOutlined />
                    <span>{isEdit ? 'ویرایشِ قالبِ نامه' : 'قالبِ نامهٔ جدید'}</span>
                </Space>
            }
            open={open}
            onCancel={handleClose}
            width={720}
            footer={[
                <Button key="cancel" icon={<CloseOutlined />} onClick={handleClose} disabled={processing}>
                    انصراف
                </Button>,
                <Button key="submit" type="primary" icon={<SaveOutlined />} loading={processing} onClick={handleSubmit}>
                    {isEdit ? 'ذخیرهٔ تغییرات' : 'ایجادِ قالب'}
                </Button>,
            ]}
            destroyOnClose
        >
            {formError ? <Alert type="error" showIcon message={formError} style={{ marginBottom: 16, borderRadius: 8 }} /> : null}

            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="کدِ قالب"
                            name="code"
                            rules={[{ required: true, message: 'کد الزامی است.' }, { max: 50, message: 'حداکثر ۵۰ نویسه.' }]}
                            validateStatus={fieldErrors.code ? 'error' : ''}
                            help={fieldErrors.code}
                        >
                            <Input
                                prefix={<CodeOutlined style={{ color: '#8c8c8c' }} />}
                                placeholder="LEAVE_APPROVAL_LETTER"
                                value={values.code}
                                onChange={(e) => setValues((v) => ({ ...v, code: e.target.value.toUpperCase() }))}
                                size="large"
                                dir="ltr"
                                disabled={isEdit}
                            />
                        </Form.Item>
                        {isEdit ? <Text type="secondary" style={{ fontSize: 12 }}>کد پس از ایجاد قابلِ ویرایش نیست.</Text> : null}
                    </Col>

                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نامِ قالب"
                            name="name"
                            rules={[{ required: true, message: 'نام الزامی است.' }, { max: 200, message: 'حداکثر ۲۰۰ نویسه.' }]}
                            validateStatus={fieldErrors.name ? 'error' : ''}
                            help={fieldErrors.name}
                        >
                            <Input
                                placeholder="نامهٔ موافقتِ مرخصی"
                                value={values.name}
                                onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))}
                                size="large"
                            />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نوعِ موجودیت (EntityType)"
                            name="entityType"
                            rules={[{ required: true, message: 'نوعِ موجودیت الزامی است.' }]}
                            validateStatus={fieldErrors.entityType ? 'error' : ''}
                            help={fieldErrors.entityType}
                        >
                            <Select
                                showSearch
                                loading={registryLoading}
                                placeholder="انتخابِ نوعِ موجودیت..."
                                value={values.entityType || undefined}
                                onChange={handleEntityTypeChange}
                                size="large"
                                suffixIcon={<TagOutlined style={{ color: '#8c8c8c' }} />}
                                optionFilterProp="label"
                                options={entityTypes.map((e) => ({ value: e.Code, label: `${e.DisplayName} (${e.Code})` }))}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={12}>
                        <Form.Item
                            label="Workflow Definition (اختیاری)"
                            name="definitionId"
                            validateStatus={fieldErrors.definitionId ? 'error' : ''}
                            help={fieldErrors.definitionId || 'خالی = قالبِ عمومی برایِ این EntityType.'}
                        >
                            <Select
                                allowClear
                                showSearch
                                disabled={!values.entityType}
                                placeholder={values.entityType ? 'عمومی (همهٔ فرایندهایِ این EntityType)' : 'ابتدا EntityType را انتخاب کنید'}
                                value={values.definitionId ?? undefined}
                                onChange={(v) => setValues((s) => ({ ...s, definitionId: v ?? null }))}
                                size="large"
                                optionFilterProp="label"
                                options={matchingDefinitions.map((d) => ({ value: d.DefinitionID, label: `${d.Name} (${d.Code})` }))}
                            />
                        </Form.Item>
                    </Col>
                </Row>

                <Form.Item
                    label="موضوعِ نامه"
                    name="subjectTemplate"
                    rules={[{ required: true, message: 'موضوع الزامی است.' }, { max: 500, message: 'حداکثر ۵۰۰ نویسه.' }]}
                    validateStatus={fieldErrors.subjectTemplate ? 'error' : ''}
                    help={fieldErrors.subjectTemplate}
                >
                    <Input
                        ref={subjectRef}
                        placeholder="ابلاغِ موافقتِ مرخصیِ {{USER_FULL_NAME}}"
                        value={values.subjectTemplate}
                        onChange={(e) => setValues((v) => ({ ...v, subjectTemplate: e.target.value }))}
                        dir="ltr"
                        style={{ direction: 'rtl', textAlign: 'right' }}
                        size="large"
                    />
                </Form.Item>
                <Space style={{ marginTop: -12, marginBottom: 16 }}>
                    <Select
                        style={{ width: 260 }}
                        size="small"
                        placeholder="انتخابِ پارامتر..."
                        value={subjectInsertCode ?? undefined}
                        onChange={setSubjectInsertCode}
                        showSearch
                        optionFilterProp="label"
                        options={paramOptions}
                    />
                    <Button size="small" icon={<PlusCircleOutlined />} disabled={!subjectInsertCode} onClick={handleInsertSubject}>
                        درجِ پارامتر در موضوع
                    </Button>
                </Space>

                <Form.Item
                    label="متنِ نامه"
                    name="bodyTemplate"
                    rules={[{ required: true, message: 'متن الزامی است.' }]}
                    validateStatus={fieldErrors.bodyTemplate ? 'error' : ''}
                    help={fieldErrors.bodyTemplate}
                >
                    <Input.TextArea
                        ref={bodyRef}
                        placeholder="با سلام، مرخصیِ {{USER_FULL_NAME}} با کدِ پرسنلیِ {{USER_PERSONNEL_CODE}} تاییدشد..."
                        value={values.bodyTemplate}
                        onChange={(e) => setValues((v) => ({ ...v, bodyTemplate: e.target.value }))}
                        rows={8}
                        style={{ direction: 'rtl', textAlign: 'right' }}
                    />
                </Form.Item>
                <Space>
                    <Select
                        style={{ width: 260 }}
                        size="small"
                        placeholder="انتخابِ پارامتر..."
                        value={bodyInsertCode ?? undefined}
                        onChange={setBodyInsertCode}
                        showSearch
                        optionFilterProp="label"
                        options={paramOptions}
                    />
                    <Button size="small" icon={<PlusCircleOutlined />} disabled={!bodyInsertCode} onClick={handleInsertBody}>
                        درجِ پارامتر در متن
                    </Button>
                </Space>

                {isEdit ? (
                    <Text type="secondary" style={{ fontSize: 12, display: 'block', marginTop: 16 }}>
                        فعال/غیرفعال‌کردنِ قالب از طریقِ دکمهٔ وضعیت در فهرست انجام می‌شود.
                    </Text>
                ) : null}
            </Form>
        </Modal>
    );
}
