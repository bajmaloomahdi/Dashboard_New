import { useEffect } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography } from 'antd';
import {
    SafetyOutlined,
    FileTextOutlined,
    SaveOutlined,
    CloseOutlined,
    IdcardOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

const { Text } = Typography;

interface Role {
    RoleID: number;
    RoleCode: string;
    RoleName: string;
    Description: string | null;
}

interface RoleFormModalProps {
    open: boolean;
    onClose: () => void;
    editingRole: Role | null;
}

export default function RoleFormModal({ open, onClose, editingRole }: RoleFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingRole;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        RoleName: '',
        Description: '',
    });

    // پر کردن فرم هنگام ویرایش
    useEffect(() => {
        if (open) {
            if (editingRole) {
                const formData = {
                    RoleName: editingRole.RoleName || '',
                    Description: editingRole.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                reset();
                form.resetFields();
            }
            clearErrors();
        }
    }, [open, editingRole]);

    /**
     * ارسال فرم
     */
    const handleSubmit = () => {
        form.validateFields().then(() => {
            const options = {
                preserveScroll: true,
                onSuccess: () => {
                    handleClose();
                },
                onError: () => {
                    // خطاها توسط NotificationModal نمایش داده میشن
                },
            };

            if (isEdit) {
                put(`/roles/${editingRole!.RoleID}`, options);
            } else {
                post('/roles', options);
            }
        });
    };

    /**
     * بستن مودال
     */
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
                    <SafetyOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش نقش' : 'ایجاد نقش جدید'}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={600}
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
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد نقش'}
                </Button>,
            ]}
            styles={{ body: { paddingTop: 24 } }}
        >
            <Form
                form={form}
                layout="vertical"
                requiredMark
            >
                {/* نمایش کد در حالت ویرایش */}
                {isEdit && (
                    <div
                        style={{
                            background: '#f0f5ff',
                            border: '1px solid #d6e4ff',
                            borderRadius: 8,
                            padding: 12,
                            marginBottom: 20,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                        }}
                    >
                        <IdcardOutlined style={{ color: '#1890ff' }} />
                        <Text>
                            کد نقش: <Text strong>{editingRole?.RoleCode}</Text>
                        </Text>
                    </div>
                )}

                <Row gutter={16}>
                    {/* نام نقش */}
                    <Col span={24}>
                        <Form.Item
                            label="نام نقش"
                            name="RoleName"
                            rules={[
                                { required: true, message: 'نام نقش الزامی است' },
                                { min: 3, message: 'حداقل 3 کاراکتر' },
                                { max: 100, message: 'حداکثر 100 کاراکتر' },
                            ]}
                            validateStatus={errors.RoleName ? 'error' : ''}
                            help={errors.RoleName}
                        >
                            <Input
                                prefix={<SafetyOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: مدیر انبار"
                                value={data.RoleName}
                                onChange={(e) => setData('RoleName', e.target.value)}
                                size="large"
                                autoComplete="off"
                            />
                        </Form.Item>
                    </Col>

                    {/* توضیحات */}
                    <Col span={24}>
                        <Form.Item
                            label="توضیحات"
                            name="Description"
                            rules={[{ max: 500, message: 'حداکثر 500 کاراکتر' }]}
                            validateStatus={errors.Description ? 'error' : ''}
                            help={errors.Description}
                        >
                            <Input.TextArea
                                placeholder="توضیحات اختیاری درباره این نقش..."
                                value={data.Description}
                                onChange={(e) => setData('Description', e.target.value)}
                                rows={4}
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