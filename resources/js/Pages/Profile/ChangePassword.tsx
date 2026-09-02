import { useState, useEffect } from 'react';
import {
    Card,
    Form,
    Input,
    Button,
    Typography,
    Row,
    Col,
    Space,
    Alert,
    Divider,
    Progress,
} from 'antd';
import {
    LockOutlined,
    SaveOutlined,
    KeyOutlined,
    SafetyOutlined,
    EyeInvisibleOutlined,
    EyeTwoTone,
    CheckCircleOutlined,
    CloseCircleOutlined,
} from '@ant-design/icons';
import { useForm, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';

const { Title, Text } = Typography;

export default function ChangePassword() {
    const { auth, flash } = usePage().props as any;
    const [form] = Form.useForm();

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        current_password: '',
        new_password: '',
        confirm_password: '',
    });

    // مدیریت مودال اعلان
    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    /**
     * نمایش پیام flash
     */
    useEffect(() => {
        if (flash?.success) {
            showNotification('success', flash.success);
            // پاک کردن فرم بعد از موفقیت
            reset();
            form.resetFields();
        }
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    /**
     * نمایش اعلان
     */
    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    /**
     * محاسبه قدرت رمز عبور
     */
    const calculatePasswordStrength = (password: string): { strength: number; label: string; color: string } => {
        if (!password) return { strength: 0, label: '', color: '' };

        let strength = 0;

        // طول
        if (password.length >= 6) strength += 20;
        if (password.length >= 10) strength += 20;
        if (password.length >= 14) strength += 10;

        // حروف بزرگ
        if (/[A-Z]/.test(password)) strength += 15;

        // حروف کوچک
        if (/[a-z]/.test(password)) strength += 10;

        // اعداد
        if (/[0-9]/.test(password)) strength += 15;

        // کاراکترهای خاص
        if (/[^A-Za-z0-9]/.test(password)) strength += 10;

        if (strength >= 80) return { strength, label: 'قوی', color: '#52c41a' };
        if (strength >= 60) return { strength, label: 'متوسط', color: '#1890ff' };
        if (strength >= 40) return { strength, label: 'ضعیف', color: '#faad14' };
        return { strength, label: 'خیلی ضعیف', color: '#ff4d4f' };
    };

    const passwordStrength = calculatePasswordStrength(data.new_password);

    /**
     * ارسال فرم
     */
    const handleSubmit = () => {
        form.validateFields().then(() => {
            post('/change-password', {
                preserveScroll: true,
            });
        });
    };

    /**
     * چک کردن قوانین رمز عبور
     */
    const passwordRules = [
        {
            label: 'حداقل 6 کاراکتر',
            valid: data.new_password.length >= 6,
        },
        {
            label: 'شامل حرف بزرگ (A-Z)',
            valid: /[A-Z]/.test(data.new_password),
        },
        {
            label: 'شامل حرف کوچک (a-z)',
            valid: /[a-z]/.test(data.new_password),
        },
        {
            label: 'شامل عدد (0-9)',
            valid: /[0-9]/.test(data.new_password),
        },
        {
            label: 'متفاوت با رمز فعلی',
            valid: data.new_password.length > 0 && data.new_password !== data.current_password,
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<KeyOutlined />}
                title="تغییر کلمه عبور"
                subtitle="برای امنیت بیشتر، کلمه عبور خود را به‌روز نگه دارید"
                stats={[
                    { icon: <SafetyOutlined />, label: 'کاربر', value: auth?.user?.FullName || '—' },
                    { icon: <LockOutlined />, label: 'نام کاربری', value: `@${auth?.user?.UserName || ''}` },
                ]}
            />

            <Row justify="center">
                <Col xs={24} md={20} lg={16} xl={14}>
                    <Row gutter={16}>
                        {/* فرم اصلی */}
                        <Col xs={24} lg={14}>
                            <Card
                                style={{
                                    borderRadius: 12,
                                    border: 'none',
                                    boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
                                }}
                            >
                                <Form
                                    form={form}
                                    layout="vertical"
                                    requiredMark
                                    onFinish={handleSubmit}
                                >
                                    {/* کلمه عبور فعلی */}
                                    <Form.Item
                                        label="کلمه عبور فعلی"
                                        name="current_password"
                                        rules={[
                                            { required: true, message: 'کلمه عبور فعلی الزامی است' },
                                        ]}
                                        validateStatus={errors.current_password ? 'error' : ''}
                                        help={errors.current_password}
                                    >
                                        <Input.Password
                                            prefix={<LockOutlined style={{ color: '#bfbfbf' }} />}
                                            placeholder="کلمه عبور فعلی خود را وارد کنید"
                                            value={data.current_password}
                                            onChange={(e) => {
                                                setData('current_password', e.target.value);
                                                clearErrors();
                                            }}
                                            size="large"
                                            autoComplete="current-password"
                                            iconRender={(visible) =>
                                                visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />
                                            }
                                        />
                                    </Form.Item>

                                    <Divider style={{ margin: '16px 0' }} />

                                    {/* کلمه عبور جدید */}
                                    <Form.Item
                                        label="کلمه عبور جدید"
                                        name="new_password"
                                        rules={[
                                            { required: true, message: 'کلمه عبور جدید الزامی است' },
                                            { min: 6, message: 'حداقل 6 کاراکتر' },
                                        ]}
                                        validateStatus={errors.new_password ? 'error' : ''}
                                        help={errors.new_password}
                                    >
                                        <Input.Password
                                            prefix={<KeyOutlined style={{ color: '#bfbfbf' }} />}
                                            placeholder="کلمه عبور جدید را وارد کنید"
                                            value={data.new_password}
                                            onChange={(e) => {
                                                setData('new_password', e.target.value);
                                                clearErrors();
                                            }}
                                            size="large"
                                            autoComplete="new-password"
                                            iconRender={(visible) =>
                                                visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />
                                            }
                                        />
                                    </Form.Item>

                                    {/* نمایش قدرت رمز */}
                                    {data.new_password && (
                                        <div style={{ marginTop: -12, marginBottom: 16 }}>
                                            <Space size={8} style={{ width: '100%', justifyContent: 'space-between' }}>
                                                <Text type="secondary" style={{ fontSize: 12 }}>
                                                    قدرت رمز:
                                                </Text>
                                                <Text strong style={{ fontSize: 12, color: passwordStrength.color }}>
                                                    {passwordStrength.label}
                                                </Text>
                                            </Space>
                                            <Progress
                                                percent={passwordStrength.strength}
                                                strokeColor={passwordStrength.color}
                                                showInfo={false}
                                                strokeWidth={6}
                                            />
                                        </div>
                                    )}

                                    {/* تکرار کلمه عبور */}
                                    <Form.Item
                                        label="تکرار کلمه عبور جدید"
                                        name="confirm_password"
                                        rules={[
                                            { required: true, message: 'تکرار کلمه عبور الزامی است' },
                                            ({ getFieldValue }) => ({
                                                validator(_, value) {
                                                    if (!value || getFieldValue('new_password') === value) {
                                                        return Promise.resolve();
                                                    }
                                                    return Promise.reject(new Error('تکرار کلمه عبور مطابقت ندارد'));
                                                },
                                            }),
                                        ]}
                                        validateStatus={errors.confirm_password ? 'error' : ''}
                                        help={errors.confirm_password}
                                        dependencies={['new_password']}
                                    >
                                        <Input.Password
                                            prefix={<LockOutlined style={{ color: '#bfbfbf' }} />}
                                            placeholder="کلمه عبور جدید را دوباره وارد کنید"
                                            value={data.confirm_password}
                                            onChange={(e) => {
                                                setData('confirm_password', e.target.value);
                                                clearErrors();
                                            }}
                                            size="large"
                                            autoComplete="new-password"
                                            iconRender={(visible) =>
                                                visible ? <EyeTwoTone /> : <EyeInvisibleOutlined />
                                            }
                                        />
                                    </Form.Item>

                                    <Divider style={{ margin: '16px 0' }} />

                                    {/* دکمه ذخیره */}
                                    <Form.Item style={{ marginBottom: 0 }}>
                                        <Button
                                            type="primary"
                                            htmlType="submit"
                                            icon={<SaveOutlined />}
                                            loading={processing}
                                            size="large"
                                            block
                                            style={{
                                                background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                                                border: 'none',
                                                height: 48,
                                                fontSize: 15,
                                            }}
                                        >
                                            تغییر کلمه عبور
                                        </Button>
                                    </Form.Item>
                                </Form>
                            </Card>
                        </Col>

                        {/* راهنما و قوانین */}
                        <Col xs={24} lg={10}>
                            <Card
                                title={
                                    <Space>
                                        <SafetyOutlined style={{ color: '#52c41a' }} />
                                        <span>راهنمای امنیتی</span>
                                    </Space>
                                }
                                style={{
                                    borderRadius: 12,
                                    border: 'none',
                                    boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
                                }}
                            >
                                <Alert
                                    message="امنیت حساب شما"
                                    description="کلمه عبور قوی از حساب کاربری شما در برابر دسترسی غیرمجاز محافظت می‌کند."
                                    type="info"
                                    showIcon
                                    style={{ marginBottom: 16 }}
                                />

                                <Title level={5} style={{ marginTop: 16 }}>
                                    قوانین کلمه عبور
                                </Title>

                                <Space direction="vertical" size={8} style={{ width: '100%' }}>
                                    {passwordRules.map((rule, index) => (
                                        <div
                                            key={index}
                                            style={{
                                                display: 'flex',
                                                alignItems: 'center',
                                                gap: 8,
                                                padding: 8,
                                                borderRadius: 6,
                                                background: rule.valid ? '#f6ffed' : '#fafafa',
                                                border: `1px solid ${rule.valid ? '#b7eb8f' : '#f0f0f0'}`,
                                                transition: 'all 0.3s',
                                            }}
                                        >
                                            {rule.valid ? (
                                                <CheckCircleOutlined style={{ color: '#52c41a', fontSize: 16 }} />
                                            ) : (
                                                <CloseCircleOutlined style={{ color: '#bfbfbf', fontSize: 16 }} />
                                            )}
                                            <Text
                                                style={{
                                                    fontSize: 13,
                                                    color: rule.valid ? '#52c41a' : '#8c8c8c',
                                                }}
                                            >
                                                {rule.label}
                                            </Text>
                                        </div>
                                    ))}
                                </Space>

                                <Divider />

                                <Title level={5}>نکات مهم</Title>
                                <ul style={{ paddingRight: 20, color: '#595959', fontSize: 13 }}>
                                    <li>از رمز عبور یکسان در سایت‌های دیگر استفاده نکنید</li>
                                    <li>رمز عبور خود را با کسی به اشتراک نگذارید</li>
                                    <li>هر چند ماه یکبار رمز خود را تغییر دهید</li>
                                    <li>از رمزهای پیچیده و طولانی استفاده کنید</li>
                                </ul>
                            </Card>
                        </Col>
                    </Row>
                </Col>
            </Row>

            {/* مودال اعلان */}
            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}