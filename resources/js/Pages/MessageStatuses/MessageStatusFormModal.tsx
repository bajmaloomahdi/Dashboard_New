import { useEffect } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography } from 'antd';
import {
    SaveOutlined,
    CloseOutlined,
    FlagOutlined,
    IdcardOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

interface MessageStatus {
    MessageStatusID: number;
    MessageStatusCode: number;
    MessageStatusName: string;
    Description: string | null;
}

interface MessageStatusFormModalProps {
    open: boolean;
    onClose: () => void;
    editingStatus: MessageStatus | null;
}

export default function MessageStatusFormModal({ open, onClose, editingStatus }: MessageStatusFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingStatus;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        MessageStatusName: '',
        Description: '',
    });

    useEffect(() => {
        if (open) {
            if (editingStatus) {
                const formData = {
                    MessageStatusName: editingStatus.MessageStatusName || '',
                    Description: editingStatus.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                reset();
                form.resetFields();
            }
            clearErrors();
        }
    }, [open, editingStatus]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const options = {
                preserveScroll: true,
                onSuccess: () => onClose(),
            };
            if (isEdit) {
                put(`/message-statuses/${editingStatus!.MessageStatusID}`, options);
            } else {
                post('/message-statuses', options);
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
                    <FlagOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش وضعیت' : 'ایجاد وضعیت جدید'}</span>
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
                >
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد وضعیت'}
                </Button>,
            ]}
        >
            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col span={12}>
                        <Form.Item
                            label="کد وضعیت"
                            extra="کد به صورت خودکار و از 101 ساخته می‌شود"
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="خودکار"
                                value={isEdit ? String(editingStatus?.MessageStatusCode) : 'خودکار'}
                                disabled
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={12}>
                        <Form.Item
                            label="نام وضعیت"
                            name="MessageStatusName"
                            rules={[
                                { required: true, message: 'نام وضعیت الزامی است' },
                                { max: 100, message: 'حداکثر 100 کاراکتر' },
                            ]}
                            validateStatus={errors.MessageStatusName ? 'error' : ''}
                            help={errors.MessageStatusName}
                        >
                            <Input
                                prefix={<FlagOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: در حال انجام"
                                value={data.MessageStatusName}
                                onChange={(e) => setData('MessageStatusName', e.target.value)}
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