import { useEffect } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography, Select, InputNumber, Switch, Divider } from 'antd';
import {
    SettingOutlined,
    SaveOutlined,
    CloseOutlined,
    DatabaseOutlined,
    FontSizeOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

const { Text } = Typography;

interface Parameter {
    ReportParameterID: number;
    ReportID: number;
    ParameterName: string;
    ParameterCaption: string;
    DataType: string;
    ControlType: string;
    DefaultValue: string | null;
    IsRequired: boolean | number;
    IsVisible: boolean | number;
    SortOrder: number;
    LookupProcedure: string | null;
    Description: string | null;
}

interface ParameterFormModalProps {
    open: boolean;
    onClose: () => void;
    editingParameter: Parameter | null;
    reportId: number;
}

const toBool = (value: any): boolean => Number(value) === 1;

const DATA_TYPES = [
    { value: 'STRING', label: '📝 رشته (STRING)' },
    { value: 'INT', label: '🔢 عدد صحیح (INT)' },
    { value: 'BIGINT', label: '🔢 عدد بزرگ (BIGINT)' },
    { value: 'DECIMAL', label: '💰 عدد اعشاری (DECIMAL)' },
    { value: 'DATE', label: '📅 تاریخ (DATE)' },
    { value: 'DATETIME', label: '🕐 تاریخ و ساعت (DATETIME)' },
    { value: 'BIT', label: '✅ بله/خیر (BIT)' },
];

const CONTROL_TYPES = [
    { value: 'TEXTBOX', label: '📝 تکست باکس' },
    { value: 'NUMBER', label: '🔢 عدد' },
    { value: 'DATE', label: '📅 تاریخ' },
    { value: 'SELECT', label: '📋 لیست کشویی' },
    { value: 'MULTISELECT', label: '☑ چند انتخابی' },
    { value: 'CHECKBOX', label: '✅ چک باکس' },
];

export default function ParameterFormModal({ open, onClose, editingParameter, reportId }: ParameterFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingParameter;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        ParameterName: '',
        ParameterCaption: '',
        DataType: 'STRING',
        ControlType: 'TEXTBOX',
        DefaultValue: '',
        IsRequired: false,
        IsVisible: true,
        SortOrder: null as number | null,
        LookupProcedure: '',
        Description: '',
    });

    const needsLookup = data.ControlType === 'SELECT' || data.ControlType === 'MULTISELECT';

    useEffect(() => {
        if (open) {
            if (editingParameter) {
                const formData = {
                    ParameterName: editingParameter.ParameterName || '',
                    ParameterCaption: editingParameter.ParameterCaption || '',
                    DataType: editingParameter.DataType || 'STRING',
                    ControlType: editingParameter.ControlType || 'TEXTBOX',
                    DefaultValue: editingParameter.DefaultValue || '',
                    IsRequired: toBool(editingParameter.IsRequired),
                    IsVisible: toBool(editingParameter.IsVisible),
                    SortOrder: editingParameter.SortOrder,
                    LookupProcedure: editingParameter.LookupProcedure || '',
                    Description: editingParameter.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                const defaultData = {
                    ParameterName: '',
                    ParameterCaption: '',
                    DataType: 'STRING',
                    ControlType: 'TEXTBOX',
                    DefaultValue: '',
                    IsRequired: false,
                    IsVisible: true,
                    SortOrder: null,
                    LookupProcedure: '',
                    Description: '',
                };
                setData(defaultData);
                form.setFieldsValue(defaultData);
            }
            clearErrors();
        }
    }, [open, editingParameter]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const options = {
                preserveScroll: true,
                onSuccess: () => handleClose(),
                onError: () => {},
            };

            if (isEdit) {
                put(`/reports-manage/${reportId}/parameters/${editingParameter!.ReportParameterID}`, options);
            } else {
                post(`/reports-manage/${reportId}/parameters`, options);
            }
        });
    };

    const handleClose = () => {
        form.resetFields();
        reset();
        clearErrors();
        onClose();
    };

    return (
        <Modal
            title={
                <div style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 10,
                    padding: '4px 0',
                }}>
                    <div style={{
                        width: 40,
                        height: 40,
                        borderRadius: 10,
                        background: 'linear-gradient(135deg, #334155 0%, #64748B 100%)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        boxShadow: '0 2px 4px rgba(51, 65, 85, 0.3)',
                    }}>
                        <SettingOutlined style={{ color: '#fff', fontSize: 18 }} />
                    </div>
                    <span style={{ fontSize: 16, fontWeight: 600, color: '#0F172A' }}>
                        {isEdit ? 'ویرایش پارامتر' : 'ایجاد پارامتر جدید'}
                    </span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={800}
            className="responsive-modal"
            footer={[
                <Button
                    key="cancel"
                    icon={<CloseOutlined />}
                    onClick={handleClose}
                    disabled={processing}
                    style={{ borderRadius: 6 }}
                >
                    انصراف
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<SaveOutlined />}
                    loading={processing}
                    onClick={handleSubmit}
                    style={{
                        background: 'linear-gradient(135deg, #334155 0%, #64748B 100%)',
                        border: 'none',
                        borderRadius: 6,
                        boxShadow: '0 2px 4px rgba(51, 65, 85, 0.3)',
                    }}
                >
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد پارامتر'}
                </Button>,
            ]}
            styles={{
                body: { paddingTop: 24 },
                header: {
                    borderBottom: '2px solid #E2E8F0',
                    paddingBottom: 12,
                }
            }}
        >
            <Form form={form} layout="vertical" requiredMark>
                {/* بخش ۱: اطلاعات اصلی */}
                <div style={{
                    background: '#F8FAFC',
                    padding: '16px',
                    borderRadius: 8,
                    marginBottom: 16,
                    border: '1px solid #E2E8F0',
                }}>
                    <Text strong style={{ color: '#334155', display: 'block', marginBottom: 12, fontSize: 13 }}>
                        📋 اطلاعات اصلی پارامتر
                    </Text>

                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item
                                label="نام پارامتر (در SP)"
                                name="ParameterName"
                                rules={[
                                    { required: true, message: 'نام پارامتر الزامی است' },
                                    { max: 100, message: 'حداکثر 100 کاراکتر' },
                                ]}
                                validateStatus={errors.ParameterName ? 'error' : ''}
                                help={errors.ParameterName}
                                extra="مثلاً: StartDate یا VisitorID (بدون @)"
                            >
                                <Input
                                    prefix={<DatabaseOutlined style={{ color: '#64748B' }} />}
                                    placeholder="ParameterName"
                                    value={data.ParameterName}
                                    onChange={(e) => setData('ParameterName', e.target.value)}
                                    size="large"
                                    dir="ltr"
                                    style={{ borderRadius: 6 }}
                                />
                            </Form.Item>
                        </Col>

                        <Col xs={24} md={12}>
                            <Form.Item
                                label="عنوان نمایشی"
                                name="ParameterCaption"
                                rules={[
                                    { required: true, message: 'عنوان الزامی است' },
                                    { max: 200, message: 'حداکثر 200 کاراکتر' },
                                ]}
                                validateStatus={errors.ParameterCaption ? 'error' : ''}
                                help={errors.ParameterCaption}
                                extra="این متن به کاربر نمایش داده می‌شود"
                            >
                                <Input
                                    prefix={<FontSizeOutlined style={{ color: '#64748B' }} />}
                                    placeholder="مثلاً: از تاریخ"
                                    value={data.ParameterCaption}
                                    onChange={(e) => setData('ParameterCaption', e.target.value)}
                                    size="large"
                                    style={{ borderRadius: 6 }}
                                />
                            </Form.Item>
                        </Col>
                    </Row>
                </div>

                {/* بخش ۲: نوع داده و کنترل */}
                <div style={{
                    background: '#EFF6FF',
                    padding: '16px',
                    borderRadius: 8,
                    marginBottom: 16,
                    border: '1px solid #BFDBFE',
                }}>
                    <Text strong style={{ color: '#1E40AF', display: 'block', marginBottom: 12, fontSize: 13 }}>
                        🔧 تنظیمات نوع داده و کنترل
                    </Text>

                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item
                                label="نوع داده (DataType)"
                                name="DataType"
                                rules={[{ required: true, message: 'نوع داده الزامی است' }]}
                                validateStatus={errors.DataType ? 'error' : ''}
                                help={errors.DataType}
                            >
                                <Select
                                    value={data.DataType}
                                    onChange={(value) => setData('DataType', value)}
                                    size="large"
                                    options={DATA_TYPES}
                                    style={{ borderRadius: 6 }}
                                />
                            </Form.Item>
                        </Col>

                        <Col xs={24} md={12}>
                            <Form.Item
                                label="نوع کنترل (Control)"
                                name="ControlType"
                                rules={[{ required: true, message: 'نوع کنترل الزامی است' }]}
                                validateStatus={errors.ControlType ? 'error' : ''}
                                help={errors.ControlType}
                            >
                                <Select
                                    value={data.ControlType}
                                    onChange={(value) => setData('ControlType', value)}
                                    size="large"
                                    options={CONTROL_TYPES}
                                    style={{ borderRadius: 6 }}
                                />
                            </Form.Item>
                        </Col>

                        {needsLookup && (
                            <Col span={24}>
                                <Form.Item
                                    label="Stored Procedure برای پر کردن لیست"
                                    name="LookupProcedure"
                                    rules={[
                                        { required: true, message: 'برای SELECT/MULTISELECT الزامی است' },
                                        { max: 200, message: 'حداکثر 200 کاراکتر' },
                                    ]}
                                    validateStatus={errors.LookupProcedure ? 'error' : ''}
                                    help={errors.LookupProcedure}
                                    extra="⚠️ SP باید ستون‌های Value و Text برگردونه"
                                >
                                    <Input
                                        prefix={<DatabaseOutlined style={{ color: '#64748B' }} />}
                                        placeholder="sp_GetVisitors"
                                        value={data.LookupProcedure}
                                        onChange={(e) => setData('LookupProcedure', e.target.value)}
                                        size="large"
                                        dir="ltr"
                                        style={{ borderRadius: 6 }}
                                    />
                                </Form.Item>
                            </Col>
                        )}
                    </Row>
                </div>

                {/* بخش ۳: تنظیمات پیشرفته */}
                <div style={{
                    background: '#FEF3C7',
                    padding: '16px',
                    borderRadius: 8,
                    marginBottom: 16,
                    border: '1px solid #FDE68A',
                }}>
                    <Text strong style={{ color: '#92400E', display: 'block', marginBottom: 12, fontSize: 13 }}>
                        ⚙️ تنظیمات پیشرفته
                    </Text>

                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item
                                label="مقدار پیش‌فرض"
                                name="DefaultValue"
                                rules={[{ max: 500, message: 'حداکثر 500 کاراکتر' }]}
                            >
                                <Input
                                    placeholder="اختیاری"
                                    value={data.DefaultValue}
                                    onChange={(e) => setData('DefaultValue', e.target.value)}
                                    size="large"
                                    style={{ borderRadius: 6 }}
                                />
                            </Form.Item>
                        </Col>

                        <Col xs={24} md={12}>
                            <Form.Item
                                label="ترتیب نمایش"
                                name="SortOrder"
                                extra="خالی = آخر لیست"
                            >
                                <InputNumber
                                    value={data.SortOrder}
                                    onChange={(value) => setData('SortOrder', value)}
                                    size="large"
                                    style={{ width: '100%', borderRadius: 6 }}
                                    min={1}
                                    placeholder="1"
                                />
                            </Form.Item>
                        </Col>
                    </Row>
                </div>

                {/* بخش ۴: وضعیت */}
                <div style={{
                    background: '#F0FDF4',
                    padding: '16px',
                    borderRadius: 8,
                    marginBottom: 16,
                    border: '1px solid #BBF7D0',
                }}>
                    <Text strong style={{ color: '#15803D', display: 'block', marginBottom: 12, fontSize: 13 }}>
                        🎯 وضعیت نمایش
                    </Text>

                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item label="پارامتر اجباری" name="IsRequired">
                                <Switch
                                    checked={data.IsRequired}
                                    onChange={(checked) => setData('IsRequired', checked)}
                                    checkedChildren="⚠️ اجباری"
                                    unCheckedChildren="اختیاری"
                                />
                            </Form.Item>
                        </Col>

                        <Col xs={24} md={12}>
                            <Form.Item label="نمایش در فرم" name="IsVisible">
                                <Switch
                                    checked={data.IsVisible}
                                    onChange={(checked) => setData('IsVisible', checked)}
                                    checkedChildren="👁 نمایش"
                                    unCheckedChildren="🔒 مخفی"
                                />
                            </Form.Item>
                        </Col>
                    </Row>
                </div>

                {/* توضیحات */}
                <Form.Item
                    label="توضیحات"
                    name="Description"
                    rules={[{ max: 500, message: 'حداکثر 500 کاراکتر' }]}
                >
                    <Input.TextArea
                        placeholder="توضیحات اختیاری..."
                        value={data.Description}
                        onChange={(e) => setData('Description', e.target.value)}
                        rows={3}
                        maxLength={500}
                        showCount
                        style={{ borderRadius: 6 }}
                    />
                </Form.Item>
            </Form>
        </Modal>
    );
}