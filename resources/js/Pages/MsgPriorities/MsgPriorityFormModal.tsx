import { useEffect, useState } from 'react';
import { Modal, Form, Input, InputNumber, Row, Col, Button } from 'antd';
import {
    SaveOutlined,
    CloseOutlined,
    ThunderboltOutlined,
    IdcardOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

interface MsgPriority {
    msgPriorityID: number;
    Code: number;
    Name: string;
    Description: string | null;
    SortOrder: number;
}

interface MsgPriorityFormModalProps {
    open: boolean;
    onClose: () => void;
    editingPriority: MsgPriority | null;
}

export default function MsgPriorityFormModal({ open, onClose, editingPriority }: MsgPriorityFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingPriority;

    const [sortOrderError, setSortOrderError] = useState<string>('');

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        Name: '',
        Description: '',
        SortOrder: 1,
    });

    useEffect(() => {
        if (open) {
            if (editingPriority) {
                const formData = {
                    Name: editingPriority.Name || '',
                    Description: editingPriority.Description || '',
                    SortOrder: editingPriority.SortOrder ?? 1,
                };
                setData(formData);
                form.setFieldsValue({
                    Name: formData.Name,
                    Description: formData.Description,
                });
            } else {
                reset();
                form.resetFields();
            }
            setSortOrderError('');
            clearErrors();
        }
    }, [open, editingPriority]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            // اعتبارسنجی دستی ترتیب نمایش (فقط در حالت ویرایش)
            if (isEdit) {
                const sortOrder = Number(data.SortOrder);
                if (!sortOrder || sortOrder < 1) {
                    setSortOrderError('ترتیب نمایش باید عددی بزرگ‌تر از صفر باشد');
                    return;
                }
                setSortOrderError('');
            }

            const options = {
                preserveScroll: true,
                onSuccess: () => onClose(),
            };

            if (isEdit) {
                put(`/msg-priorities/${editingPriority!.msgPriorityID}`, options);
            } else {
                post('/msg-priorities', options);
            }
        });
    };

    const handleClose = () => {
        form.resetFields();
        reset();
        clearErrors();
        setSortOrderError('');
        onClose();
    };

    return (
        <Modal
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <ThunderboltOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش اولویت' : 'ایجاد اولویت جدید'}</span>
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
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد اولویت'}
                </Button>,
            ]}
        >
            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col span={12}>
                        <Form.Item
                            label="کد اولویت"
                            extra="کد به صورت خودکار و از 101 ساخته می‌شود"
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="خودکار"
                                value={isEdit ? String(editingPriority?.Code) : 'خودکار'}
                                disabled
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={12}>
                        <Form.Item
                            label="نام اولویت"
                            name="Name"
                            rules={[
                                { required: true, message: 'نام اولویت الزامی است' },
                                { max: 100, message: 'حداکثر 100 کاراکتر' },
                            ]}
                            validateStatus={errors.Name ? 'error' : ''}
                            help={errors.Name}
                        >
                            <Input
                                prefix={<ThunderboltOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: فوری"
                                value={data.Name}
                                onChange={(e) => setData('Name', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {isEdit && (
                        <Col span={12}>
                            <Form.Item
                                label="ترتیب نمایش"
                                required
                                extra="عدد کوچک‌تر بالاتر نمایش داده می‌شود"
                                validateStatus={sortOrderError || errors.SortOrder ? 'error' : ''}
                                help={sortOrderError || errors.SortOrder}
                            >
                                <InputNumber
                                    placeholder="مثلاً: 1"
                                    value={data.SortOrder}
                                    onChange={(value) => {
                                        setData('SortOrder', Number(value) || 0);
                                        if (sortOrderError) setSortOrderError('');
                                    }}
                                    min={1}
                                    precision={0}
                                    controls={false}
                                    size="large"
                                    style={{ width: '100%' }}
                                />
                            </Form.Item>
                        </Col>
                    )}

                    <Col span={24}>
                        <Form.Item
                            label="توضیحات"
                            name="Description"
                            rules={[{ max: 500, message: 'حداکثر 500 کاراکتر' }]}
                            validateStatus={errors.Description ? 'error' : ''}
                            help={errors.Description}
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
