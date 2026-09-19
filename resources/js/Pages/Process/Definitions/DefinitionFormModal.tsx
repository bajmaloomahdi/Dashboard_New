import { useEffect, useState } from 'react';
import { Modal, Form, Input, Switch, Select, Row, Col, Button, Typography, Alert, Space } from 'antd';
import { ApartmentOutlined, SaveOutlined, CloseOutlined, CodeOutlined, TagOutlined } from '@ant-design/icons';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { toBool } from '../../../Utils/bool';
import type { WorkflowCategory } from './CategoryManagerModal';

const { Text } = Typography;

interface Definition {
    DefinitionID: number;
    Code: string;
    Name: string;
    Description: string | null;
    EntityType: string;
    IsActive: boolean | number | string;
    CategoryID: number | null;
}

interface DefinitionFormModalProps {
    open: boolean;
    onClose: () => void;
    editingDefinition: Definition | null;
    categories: WorkflowCategory[];
    /**
     * بعد از ذخیرهٔ موفق — والد پیامِ موفقیت را نمایش می‌دهد و لیست/Meta را Refresh می‌کند.
     * `definitionId` و `isNew` هم پاس داده می‌شوند تا فراخواننده (مثلاً لیستِ فرایندها) در
     * حالتِ ایجادِ Definitionِ جدید بتواند کاربر را مستقیماً به Canvas هدایت کند.
     */
    onSuccess: (message: string, definitionId: number, isNew: boolean) => void;
}

const emptyValues = { code: '', latinName: '', name: '', description: '', entityType: '', isActive: true, categoryId: null as number | null };

/**
 * تولیدِ Code — فقط حروف/عددِ لاتین و Underscore را نگه می‌دارد. این فقط یک
 * پیش‌نمایشِ سمتِ کلاینت است؛ Backend همین الگوریتم را دوباره و مستقلاً روی
 * name/latinName اجرا می‌کند و تنها منبعِ حقیقتِ Code است.
 */
function suggestCode(title: string): string {
    return title
        .toUpperCase()
        .replace(/[^A-Z0-9\s_]/g, '')
        .trim()
        .replace(/\s+/g, '_')
        .replace(/_+/g, '_')
        .replace(/^[^A-Z]+/, '')
        .slice(0, 50);
}

/** الگویِ معتبرِ Code — هم‌ارزِ CODE_PATTERN در Backend. */
const CODE_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;

function isUsableCode(code: string): boolean {
    return CODE_PATTERN.test(code);
}

/**
 * فرمِ ایجاد/ویرایشِ WorkflowDefinition — هر دو از همان یک endpointِ Upsertِ
 * موجود (POST /workflow/definitions) عبور می‌کنند: بدونِ definitionId = ایجاد،
 * با definitionId = ویرایش. چون این مسیر JSON-only است (نه Inertia)، بر
 * خلافِ فرم‌هایِ Inertiaِ معمولِ پروژه (مثلِ MasterParameterFormModal) از
 * useForm استفاده نمی‌شود — همان الگویِ wfApi که در WorkflowReassignModal
 * ساخته شد.
 *
 * Code هرگز مستقیماً توسطِ کاربر تایپ نمی‌شود: Backend آن را از رویِ نام می‌سازد
 * (Read-only در UI، فقط برایِ نمایش). اگر نام حرفِ لاتینِ کافی نداشته باشد (مثلاً
 * کاملاً فارسی)، یک فیلدِ کوچکِ «نامِ لاتین» فقط در همان لحظه ظاهر می‌شود — دقیقاً
 * هم‌الگو با TemplateParameters/ConditionFields.
 */
export default function DefinitionFormModal({ open, onClose, editingDefinition, categories, onSuccess }: DefinitionFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingDefinition;

    const [values, setValues] = useState(emptyValues);
    const [processing, setProcessing] = useState(false);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const [formError, setFormError] = useState<string | null>(null);
    const [entityTypes, setEntityTypes] = useState<{ Code: string; DisplayName: string }[]>([]);
    const [entityTypesLoading, setEntityTypesLoading] = useState(false);

    useEffect(() => {
        if (!open) return;

        setEntityTypesLoading(true);
        wfApi('/workflow/entity-types?isActive=1&usableOnly=1').then((res) => {
            setEntityTypesLoading(false);
            if (res.ok && res.success) {
                setEntityTypes(res.items || []);
            }
        });

        const initial = editingDefinition
            ? {
                  code: editingDefinition.Code || '',
                  latinName: '',
                  name: editingDefinition.Name || '',
                  description: editingDefinition.Description || '',
                  entityType: editingDefinition.EntityType || '',
                  isActive: toBool(editingDefinition.IsActive),
                  categoryId: editingDefinition.CategoryID ?? null,
              }
            : emptyValues;

        setValues(initial);
        form.setFieldsValue(initial);
        setFieldErrors({});
        setFormError(null);
    }, [open, editingDefinition]);

    const handleClose = () => {
        if (processing) return;
        form.resetFields();
        onClose();
    };

    const codeFromName = suggestCode(values.name);
    const needsLatinName = !isEdit && values.name.trim() !== '' && !isUsableCode(codeFromName);
    const previewCode = isEdit ? values.code : (needsLatinName ? suggestCode(values.latinName) : codeFromName);

    const handleSubmit = () => {
        form.validateFields().then(async () => {
            if (needsLatinName && !isUsableCode(suggestCode(values.latinName))) {
                setFormError('نام شامل حروفِ لاتینِ کافی نیست — یک «نامِ لاتین» کوتاه و معتبر وارد کنید (مثلاً LEAVE_REQUEST).');
                return;
            }

            setProcessing(true);
            setFieldErrors({});
            setFormError(null);

            const res = await wfApi('/workflow/definitions', 'POST', {
                definitionId: editingDefinition ? editingDefinition.DefinitionID : undefined,
                name: values.name,
                latinName: !isEdit && needsLatinName ? values.latinName.trim() : undefined,
                description: values.description.trim() || undefined,
                entityType: values.entityType,
                isActive: values.isActive,
                categoryId: values.categoryId ?? undefined,
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
            onSuccess(res.message, res.definitionId, !editingDefinition);
        });
    };

    return (
        <Modal
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '4px 0' }}>
                    <div
                        style={{
                            width: 40,
                            height: 40,
                            borderRadius: 10,
                            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            boxShadow: '0 2px 4px rgba(102,126,234,0.3)',
                        }}
                    >
                        <ApartmentOutlined style={{ color: '#fff', fontSize: 18 }} />
                    </div>
                    <span style={{ fontSize: 16, fontWeight: 600, color: '#0F172A' }}>
                        {isEdit ? 'ویرایشِ فرایند' : 'فرایندِ جدید'}
                    </span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={640}
            footer={[
                <Button key="cancel" icon={<CloseOutlined />} onClick={handleClose} disabled={processing} style={{ borderRadius: 6 }}>
                    انصراف
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<SaveOutlined />}
                    loading={processing}
                    onClick={handleSubmit}
                    style={{ background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', border: 'none', borderRadius: 6 }}
                >
                    {isEdit ? 'ذخیرهٔ تغییرات' : 'ایجادِ فرایند'}
                </Button>,
            ]}
            destroyOnClose
        >
            {formError ? <Alert type="error" showIcon message={formError} style={{ marginBottom: 16, borderRadius: 8 }} /> : null}

            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نامِ فرایند"
                            name="name"
                            rules={[
                                { required: true, message: 'نام الزامی است.' },
                                { max: 200, message: 'حداکثر ۲۰۰ نویسه.' },
                            ]}
                            validateStatus={fieldErrors.name ? 'error' : ''}
                            help={fieldErrors.name}
                        >
                            <Input
                                placeholder="مرخصیِ کارمندان"
                                value={values.name}
                                onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {needsLatinName && (
                        <Col xs={24} md={12}>
                            <Form.Item label="نامِ لاتین" help="چون نام حرفِ لاتین ندارد — یک نامِ کوتاهِ لاتین برایِ ساختِ Code لازم است.">
                                <Input
                                    placeholder="LEAVE_REQUEST"
                                    value={values.latinName}
                                    onChange={(e) => setValues((v) => ({ ...v, latinName: e.target.value.toUpperCase() }))}
                                    size="large"
                                    dir="ltr"
                                />
                            </Form.Item>
                        </Col>
                    )}

                    <Col xs={24} md={12}>
                        <Form.Item label="کد فرایند">
                            <Input
                                prefix={<CodeOutlined style={{ color: '#8c8c8c' }} />}
                                placeholder="LEAVE_REQUEST"
                                value={previewCode}
                                size="large"
                                dir="ltr"
                                disabled
                            />
                        </Form.Item>
                        <Text type="secondary" style={{ fontSize: 12 }}>
                            {isEdit ? 'کد پس از ایجاد قابلِ‌تغییر نیست.' : 'کد به‌صورتِ خودکار ساخته می‌شود.'}
                        </Text>
                    </Col>
                </Row>

                <Form.Item
                    label="نوعِ موجودیت (EntityType)"
                    name="entityType"
                    rules={[{ required: true, message: 'نوعِ موجودیت الزامی است.' }]}
                    validateStatus={fieldErrors.entityType ? 'error' : ''}
                    help={fieldErrors.entityType || 'فهرست از Registryِ نوعِ موجودیت‌ها (/process/entity-types) می‌آید.'}
                >
                    <Select
                        showSearch
                        loading={entityTypesLoading}
                        placeholder="انتخابِ نوعِ موجودیت..."
                        value={values.entityType || undefined}
                        onChange={(v: string) => setValues((val) => ({ ...val, entityType: v }))}
                        size="large"
                        suffixIcon={<TagOutlined style={{ color: '#8c8c8c' }} />}
                        optionFilterProp="label"
                        notFoundContent={entityTypesLoading ? undefined : 'موجودیتِ فعالی در Registry یافت نشد.'}
                        options={entityTypes.map((e) => ({
                            value: e.Code,
                            label: `${e.DisplayName} (${e.Code})`,
                        }))}
                    />
                </Form.Item>

                <Form.Item label="دسته‌بندی" name="categoryId" help="فقط دسته‌هایِ فعال قابلِ انتخاب‌اند.">
                    <Select
                        allowClear
                        placeholder="بدونِ دسته‌بندی"
                        value={values.categoryId ?? undefined}
                        onChange={(v) => setValues((s) => ({ ...s, categoryId: v ?? null }))}
                        size="large"
                        options={categories
                            .filter((c) => toBool(c.IsActive) || c.CategoryID === editingDefinition?.CategoryID)
                            .map((c) => ({
                                value: c.CategoryID,
                                label: toBool(c.IsActive) ? c.Name : `${c.Name} (غیرفعال)`,
                            }))}
                    />
                </Form.Item>

                <Form.Item label="توضیحات" name="description" rules={[{ max: 1000, message: 'حداکثر ۱۰۰۰ نویسه.' }]} validateStatus={fieldErrors.description ? 'error' : ''} help={fieldErrors.description}>
                    <Input.TextArea
                        placeholder="توضیحِ اختیاری..."
                        value={values.description}
                        onChange={(e) => setValues((v) => ({ ...v, description: e.target.value }))}
                        rows={3}
                        maxLength={1000}
                        showCount
                    />
                </Form.Item>

                <Form.Item label="وضعیت" name="isActive">
                    <Space>
                        <Switch checked={values.isActive} onChange={(checked) => setValues((v) => ({ ...v, isActive: checked }))} />
                        <Text>{values.isActive ? 'فعال' : 'غیرفعال'}</Text>
                    </Space>
                </Form.Item>
            </Form>
        </Modal>
    );
}
