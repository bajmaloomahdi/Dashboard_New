import { useEffect } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography } from 'antd';
import {
    UserOutlined,
    LockOutlined,
    MailOutlined,
    PhoneOutlined,
    IdcardOutlined,
    SaveOutlined,
    CloseOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

const { Text } = Typography;

interface User {
    UserID: number;
    UserCode: string;
    UserName: string;
    FirstName: string | null;
    LastName: string | null;
    Email: string | null;
    Mobile: string | null;
    Description: string | null;
}

interface UserFormModalProps {
    open: boolean;
    onClose: () => void;
    editingUser: User | null;
}

export default function UserFormModal({ open, onClose, editingUser }: UserFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingUser;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        UserName: '',
        Password: '',
        FirstName: '',
        LastName: '',
        Email: '',
        Mobile: '',
        Description: '',
    });

    // پر کردن فرم هنگام ویرایش
    useEffect(() => {
        if (open) {
            if (editingUser) {
                const formData = {
                    UserName: editingUser.UserName || '',
                    Password: '',
                    FirstName: editingUser.FirstName || '',
                    LastName: editingUser.LastName || '',
                    Email: editingUser.Email || '',
                    Mobile: editingUser.Mobile || '',
                    Description: editingUser.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                reset();
                form.resetFields();
            }
            clearErrors();
        }
    }, [open, editingUser]);

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
                    // خطاها توسط NotificationModal در Index نمایش داده میشن
                },
            };

            if (isEdit) {
                put(`/users/${editingUser!.UserID}`, options);
            } else {
                post('/users', options);
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
                    <UserOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش کاربر' : 'ایجاد کاربر جدید'}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={700}
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
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد کاربر'}
                </Button>,
            ]}
            styles={{ body: { paddingTop: 24 } }}
        >
            <Form
                form={form}
                layout="vertical"
                requiredMark
            >
                <Row gutter={16}>
                    {/* نام کاربری */}
                    <Col span={12}>
                        <Form.Item
                            label="نام کاربری"
                            name="UserName"
                            rules={[
                                { required: !isEdit, message: 'نام کاربری الزامی است' },
                                { min: 3, message: 'حداقل 3 کاراکتر' },
                                { max: 100, message: 'حداکثر 100 کاراکتر' },
                            ]}
                            validateStatus={errors.UserName ? 'error' : ''}
                            help={errors.UserName}
                            extra={isEdit ? 'نام کاربری قابل تغییر نیست' : undefined}
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: ali_ahmadi"
                                value={data.UserName}
                                onChange={(e) => setData('UserName', e.target.value)}
                                disabled={isEdit}
                                size="large"
                                autoComplete="off"
                            />
                        </Form.Item>
                    </Col>

                    {/* کلمه عبور */}
                    <Col span={12}>
                        <Form.Item
                            label="کلمه عبور"
                            name="Password"
                            rules={
                                !isEdit
                                    ? [
                                          { required: true, message: 'کلمه عبور الزامی است' },
                                          { min: 6, message: 'حداقل 6 کاراکتر' },
                                      ]
                                    : []
                            }
                            validateStatus={errors.Password ? 'error' : ''}
                            help={errors.Password}
                            extra={isEdit ? 'برای تغییر پسورد، بخش جداگانه‌ای وجود دارد' : undefined}
                        >
                            <Input.Password
                                prefix={<LockOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder={isEdit ? '••••••••' : 'حداقل 6 کاراکتر'}
                                value={data.Password}
                                onChange={(e) => setData('Password', e.target.value)}
                                disabled={isEdit}
                                size="large"
                                autoComplete="new-password"
                            />
                        </Form.Item>
                    </Col>

                    {/* نام */}
                    <Col span={12}>
                        <Form.Item
                            label="نام"
                            name="FirstName"
                            rules={[{ max: 100, message: 'حداکثر 100 کاراکتر' }]}
                            validateStatus={errors.FirstName ? 'error' : ''}
                            help={errors.FirstName}
                        >
                            <Input
                                prefix={<UserOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="نام"
                                value={data.FirstName}
                                onChange={(e) => setData('FirstName', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {/* نام خانوادگی */}
                    <Col span={12}>
                        <Form.Item
                            label="نام خانوادگی"
                            name="LastName"
                            rules={[{ max: 100, message: 'حداکثر 100 کاراکتر' }]}
                            validateStatus={errors.LastName ? 'error' : ''}
                            help={errors.LastName}
                        >
                            <Input
                                prefix={<UserOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="نام خانوادگی"
                                value={data.LastName}
                                onChange={(e) => setData('LastName', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {/* ایمیل */}
                    <Col span={12}>
                        <Form.Item
                            label="ایمیل"
                            name="Email"
                            rules={[
                                { type: 'email', message: 'فرمت ایمیل نامعتبر است' },
                                { max: 200, message: 'حداکثر 200 کاراکتر' },
                            ]}
                            validateStatus={errors.Email ? 'error' : ''}
                            help={errors.Email}
                        >
                            <Input
                                prefix={<MailOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="example@domain.com"
                                value={data.Email}
                                onChange={(e) => setData('Email', e.target.value)}
                                size="large"
                                type="email"
                            />
                        </Form.Item>
                    </Col>

                    {/* موبایل */}
                    <Col span={12}>
                        <Form.Item
                            label="موبایل"
                            name="Mobile"
                            rules={[
                                { max: 20, message: 'حداکثر 20 کاراکتر' },
                                {
                                    pattern: /^09\d{9}$/,
                                    message: 'شماره موبایل معتبر نیست (مثال: 09121234567)',
                                },
                            ]}
                            validateStatus={errors.Mobile ? 'error' : ''}
                            help={errors.Mobile}
                        >
                            <Input
                                prefix={<PhoneOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="09121234567"
                                value={data.Mobile}
                                onChange={(e) => setData('Mobile', e.target.value)}
                                size="large"
                                maxLength={11}
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