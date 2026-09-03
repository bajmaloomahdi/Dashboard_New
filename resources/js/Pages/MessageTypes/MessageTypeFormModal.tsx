
import { useEffect } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography } from 'antd';
import {
    SaveOutlined,
    CloseOutlined,
    MessageOutlined,
    IdcardOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

interface MessageType {
    MessageTypeID: number;
    MessageTypeCode: number;
    MessageTypeName: string;
    Description: string | null;
}

interface MessageTypeFormModalProps {
    open: boolean;
    onClose: () => void;
    editingType: MessageType | null;
}

export default function MessageTypeFormModal({ open, onClose, editingType }: MessageTypeFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingType;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        MessageTypeName: '',
        Description: '',
    });

    useEffect(() => {
        if (open) {
            if (editingType) {
                const formData = {
                    MessageTypeName: editingType.MessageTypeName || '',
                    Description: editingType.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                reset();
                form.resetFields();
            }
            clearErrors();
        }
    }, [open, editingType]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const options = {
                preserveScroll: true,
                onSuccess: () => onClose(),
            };
            if (isEdit) {
                put(`/message-types/${editingType!.MessageTypeID}`, options);
            } else {
                post('/message-types', options);
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
                    <MessageOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش نوع پیام' : 'ایجاد نوع پیام جدید'}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={600}
            className="responsive-modal"
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
                >
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد نوع پیام'}
                </Button>,
            ]}
        >
            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="کد نوع"
                            extra="کد به صورت خودکار و از 101 ساخته می‌شود"
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="خودکار"
                                value={isEdit ? String(editingType?.MessageTypeCode) : 'خودکار'}
                                disabled
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نام نوع پیام"
                            name="MessageTypeName"
                            rules={[
                                { required: true, message: 'نام نوع پیام الزامی است' },
                                { max: 100, message: 'حداکثر 100 کاراکتر' },
                            ]}
                            validateStatus={errors.MessageTypeName ? 'error' : ''}
                            help={errors.MessageTypeName}
                        >
                            <Input
                                prefix={<MessageOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: اطلاع‌رسانی"
                                value={data.MessageTypeName}
                                onChange={(e) => setData('MessageTypeName', e.target.value)}
                                size="large"
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