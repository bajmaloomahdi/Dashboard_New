import { useEffect } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography, Select, InputNumber, Switch, Divider } from 'antd';
import {
    BarChartOutlined,
    SaveOutlined,
    CloseOutlined,
    DatabaseOutlined,
    ClockCircleOutlined,
    FileExcelOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

const { Text } = Typography;

interface Report {
    ReportID: number;
    MenuID: number;
    ReportCode: string;
    ReportTitle: string;
    ProcedureName: string;
    CommandTimeout: number;
    AllowExcel: boolean | number;
    AllowPdf: boolean | number;
    AllowPrint: boolean | number;
    CacheDuration: number;
    Description: string | null;
}

interface MenuOption {
    MenuID: number;
    MenuTitle: string;
    MenuKind: string;
    Level: number;
}

interface ReportFormModalProps {
    open: boolean;
    onClose: () => void;
    editingReport: Report | null;
    availableMenus: MenuOption[];
}

const toBool = (value: any): boolean => Number(value) === 1;

export default function ReportFormModal({ open, onClose, editingReport, availableMenus }: ReportFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingReport;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        MenuID: null as number | null,
        ReportCode: '',
        ReportTitle: '',
        ProcedureName: '',
        CommandTimeout: 120,
        AllowExcel: true,
        AllowPdf: false,
        AllowPrint: false,
        CacheDuration: 0,
        Description: '',
    });

    const menuOptions = availableMenus.filter(
        (m) => m.MenuKind === 'REPORT' || m.MenuKind === 'PAGE' || m.MenuKind === 'FOLDER'
    );

    useEffect(() => {
        if (open) {
            if (editingReport) {
                const formData = {
                    MenuID: editingReport.MenuID,
                    ReportCode: editingReport.ReportCode || '',
                    ReportTitle: editingReport.ReportTitle || '',
                    ProcedureName: editingReport.ProcedureName || '',
                    CommandTimeout: editingReport.CommandTimeout || 120,
                    AllowExcel: toBool(editingReport.AllowExcel),
                    AllowPdf: toBool(editingReport.AllowPdf),
                    AllowPrint: toBool(editingReport.AllowPrint),
                    CacheDuration: editingReport.CacheDuration || 0,
                    Description: editingReport.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                const defaultData = {
                    MenuID: null,
                    ReportCode: '',
                    ReportTitle: '',
                    ProcedureName: '',
                    CommandTimeout: 120,
                    AllowExcel: true,
                    AllowPdf: false,
                    AllowPrint: false,
                    CacheDuration: 0,
                    Description: '',
                };
                setData(defaultData);
                form.setFieldsValue(defaultData);
            }
            clearErrors();
        }
    }, [open, editingReport]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const options = {
                preserveScroll: true,
                onSuccess: () => handleClose(),
                onError: () => {},
            };

            if (isEdit) {
                put(`/reports-manage/${editingReport!.ReportID}`, options);
            } else {
                post('/reports-manage', options);
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
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <BarChartOutlined style={{ color: '#722ed1', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش گزارش' : 'ایجاد گزارش جدید'}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={800}
            footer={[
                <Button
                    key="cancel"
                    icon={<CloseOutlined />}
                    onClick={handleClose}
                    disabled={processing}
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
                        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                        border: 'none',
                    }}
                >
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد گزارش'}
                </Button>,
            ]}
            styles={{ body: { paddingTop: 24 } }}
        >
            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col span={24}>
                        <Form.Item
                            label="منوی مربوطه"
                            name="MenuID"
                            rules={[{ required: true, message: 'انتخاب منو الزامی است' }]}
                            validateStatus={errors.MenuID ? 'error' : ''}
                            help={errors.MenuID}
                            extra="گزارش زیر این منو نمایش داده می‌شود"
                        >
                            <Select
                                value={data.MenuID}
                                onChange={(value) => setData('MenuID', value)}
                                size="large"
                                showSearch
                                placeholder="انتخاب منو..."
                                optionFilterProp="label"
                                options={menuOptions.map((m) => ({
                                    value: m.MenuID,
                                    label: `${'— '.repeat(m.Level - 1)}${m.MenuTitle}`,
                                }))}
                            />
                        </Form.Item>
                    </Col>

                    <Col span={12}>
                        <Form.Item
                            label="کد گزارش"
                            name="ReportCode"
                            rules={[
                                { required: true, message: 'کد گزارش الزامی است' },
                                { max: 50, message: 'حداکثر 50 کاراکتر' },
                            ]}
                            validateStatus={errors.ReportCode ? 'error' : ''}
                            help={errors.ReportCode}
                            extra="مثلاً: rpt_101"
                        >
                            <Input
                                placeholder="rpt_xxx"
                                value={data.ReportCode}
                                onChange={(e) => setData('ReportCode', e.target.value)}
                                size="large"
                                dir="ltr"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={12}>
                        <Form.Item
                            label="نام Stored Procedure"
                            name="ProcedureName"
                            rules={[
                                { required: true, message: 'نام SP الزامی است' },
                                { max: 200, message: 'حداکثر 200 کاراکتر' },
                            ]}
                            validateStatus={errors.ProcedureName ? 'error' : ''}
                            help={errors.ProcedureName}
                            extra="نام SP در دیتابیس"
                        >
                            <Input
                                prefix={<DatabaseOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="sp_report_name"
                                value={data.ProcedureName}
                                onChange={(e) => setData('ProcedureName', e.target.value)}
                                size="large"
                                dir="ltr"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={24}>
                        <Form.Item
                            label="عنوان گزارش"
                            name="ReportTitle"
                            rules={[
                                { required: true, message: 'عنوان گزارش الزامی است' },
                                { max: 200, message: 'حداکثر 200 کاراکتر' },
                            ]}
                            validateStatus={errors.ReportTitle ? 'error' : ''}
                            help={errors.ReportTitle}
                        >
                            <Input
                                prefix={<BarChartOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: گزارش فروش ماهانه"
                                value={data.ReportTitle}
                                onChange={(e) => setData('ReportTitle', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={12}>
                        <Form.Item
                            label="زمان انتظار (ثانیه)"
                            name="CommandTimeout"
                            extra="حداقل 30، حداکثر 600"
                        >
                            <InputNumber
                                prefix={<ClockCircleOutlined style={{ color: '#bfbfbf' }} />}
                                value={data.CommandTimeout}
                                onChange={(value) => setData('CommandTimeout', value ?? 120)}
                                size="large"
                                style={{ width: '100%' }}
                                min={30}
                                max={600}
                                placeholder="120"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={12}>
                        <Form.Item
                            label="مدت کش (دقیقه)"
                            name="CacheDuration"
                            extra="0 = بدون کش"
                        >
                            <InputNumber
                                value={data.CacheDuration}
                                onChange={(value) => setData('CacheDuration', value ?? 0)}
                                size="large"
                                style={{ width: '100%' }}
                                min={0}
                                placeholder="0"
                            />
                        </Form.Item>
                    </Col>
                </Row>

                <Divider style={{ margin: '8px 0 16px' }}>خروجی‌های مجاز</Divider>

                <Row gutter={16}>
                    <Col span={8}>
                        <Form.Item label="خروجی Excel" name="AllowExcel">
                            <Switch
                                checked={data.AllowExcel}
                                onChange={(checked) => setData('AllowExcel', checked)}
                                checkedChildren={<FileExcelOutlined />}
                                unCheckedChildren="خیر"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={8}>
                        <Form.Item label="خروجی PDF" name="AllowPdf">
                            <Switch
                                checked={data.AllowPdf}
                                onChange={(checked) => setData('AllowPdf', checked)}
                                checkedChildren="بله"
                                unCheckedChildren="خیر"
                                disabled
                            />
                        </Form.Item>
                    </Col>

                    <Col span={8}>
                        <Form.Item label="چاپ" name="AllowPrint">
                            <Switch
                                checked={data.AllowPrint}
                                onChange={(checked) => setData('AllowPrint', checked)}
                                checkedChildren="بله"
                                unCheckedChildren="خیر"
                                disabled
                            />
                        </Form.Item>
                    </Col>

                    <Col span={24}>
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
                            />
                        </Form.Item>
                    </Col>
                </Row>
            </Form>
        </Modal>
    );
}