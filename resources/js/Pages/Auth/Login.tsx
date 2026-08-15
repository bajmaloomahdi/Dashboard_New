import { Button, Form, Input, Typography, Checkbox, Modal } from 'antd';
import { UserOutlined, LockOutlined, LoginOutlined, SafetyOutlined, CloseCircleFilled } from '@ant-design/icons';
import { useForm, usePage, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const { Title, Text } = Typography;

interface PageProps {
    errors: Record<string, string>;
    [key: string]: any;
}

/**
 * تشخیص وجود کاراکتر فارسی/عربی
 */
const hasPersianOrArabic = (text: string): boolean => {
    return /[\u0600-\u06FF\u0750-\u077F\uFB50-\uFDFF\uFE70-\uFEFF]/.test(text);
};

/**
 * تشخیص فقط کاراکترهای مجاز (انگلیسی + عدد + کاراکترهای خاص)
 */
const isValidInput = (text: string): boolean => {
    return /^[a-zA-Z0-9!@#$%^&*()_+\-=[\]{};':"\\|,.<>/?`~ ]*$/.test(text);
};

export default function Login() {
    const { errors } = usePage<PageProps>().props;
    const { data, setData, post, processing, reset, clearErrors } = useForm({
        UserName: '',
        Password: '',
        remember: false,
    });
    const [shake, setShake] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [modalMessage, setModalMessage] = useState('');
    const [validationErrors, setValidationErrors] = useState<{ UserName?: string; Password?: string }>({});

    useEffect(() => {
        if (errors && Object.keys(errors).length > 0) {
            const errorMsg = Object.values(errors)[0] as string;
            setModalMessage(errorMsg);
            setModalOpen(true);
            setShake(true);

            setTimeout(() => setShake(false), 500);

            const timer = setTimeout(() => {
                setModalOpen(false);
                router.reload({ only: ['errors'] });
                clearErrors();
            }, 3000);

            return () => clearTimeout(timer);
        }
    }, [errors]);

    useEffect(() => {
        return () => {
            reset('Password');
        };
    }, []);

    /**
     * تغییر نام کاربری - جلوگیری از فارسی
     */
    const handleUserNameChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;

        // اگر فارسی/عربی داره، اجازه تایپ نده
        if (hasPersianOrArabic(value)) {
            setValidationErrors(prev => ({
                ...prev,
                UserName: 'استفاده از حروف فارسی مجاز نیست'
            }));
            return;
        }

        // اگر کاراکتر غیرمجاز داره
        if (value && !isValidInput(value)) {
            setValidationErrors(prev => ({
                ...prev,
                UserName: 'فقط از حروف انگلیسی، اعداد و کاراکترهای خاص استفاده کنید'
            }));
            return;
        }

        setData('UserName', value);
        setValidationErrors(prev => ({ ...prev, UserName: undefined }));
        clearErrors();
    };

    /**
     * تغییر کلمه عبور - جلوگیری از فارسی
     */
    const handlePasswordChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const value = e.target.value;

        // اگر فارسی/عربی داره، اجازه تایپ نده
        if (hasPersianOrArabic(value)) {
            setValidationErrors(prev => ({
                ...prev,
                Password: 'استفاده از حروف فارسی مجاز نیست'
            }));
            return;
        }

        setData('Password', value);
        setValidationErrors(prev => ({ ...prev, Password: undefined }));
        clearErrors();
    };

    /**
     * ارسال فرم
     */
    const handleSubmit = () => {
        // اعتبارسنجی قبل از ارسال
        const errors: { UserName?: string; Password?: string } = {};

        if (!data.UserName || data.UserName.trim() === '') {
            errors.UserName = 'نام کاربری را وارد کنید';
        } else if (hasPersianOrArabic(data.UserName)) {
            errors.UserName = 'نام کاربری نباید شامل حروف فارسی باشد';
        } else if (!isValidInput(data.UserName)) {
            errors.UserName = 'نام کاربری معتبر نیست';
        }

        if (!data.Password || data.Password.trim() === '') {
            errors.Password = 'کلمه عبور را وارد کنید';
        } else if (hasPersianOrArabic(data.Password)) {
            errors.Password = 'کلمه عبور نباید شامل حروف فارسی باشد';
        }

        if (Object.keys(errors).length > 0) {
            setValidationErrors(errors);
            // نمایش پیام خطا
            const firstError = Object.values(errors)[0];
            setModalMessage(firstError as string);
            setModalOpen(true);
            setShake(true);
            setTimeout(() => setShake(false), 500);
            setTimeout(() => setModalOpen(false), 3000);
            return;
        }

        // پاک کردن خطاها و ارسال
        setValidationErrors({});
        post('/login', {
            preserveScroll: true,
            onError: () => {
                reset('Password');
            },
        });
    };

    return (
        <>
            {/* استایل انیمیشن‌ها */}
            <style>{`
                @keyframes shake {
                    0%, 100% { transform: translateX(0); }
                    25% { transform: translateX(-10px); }
                    75% { transform: translateX(10px); }
                }
                .shake {
                    animation: shake 0.5s ease-in-out;
                }
                @keyframes fadeInUp {
                    from {
                        opacity: 0;
                        transform: translateY(20px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }
                .fade-in-up {
                    animation: fadeInUp 0.6s ease-out;
                }
            `}</style>

            {/* Modal خطا */}
            <Modal
                open={modalOpen}
                footer={null}
                closable={false}
                centered
                width={400}
                styles={{
                    body: {
                        padding: '40px 24px',
                        textAlign: 'center',
                    },
                }}
            >
                <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                    <CloseCircleFilled style={{ fontSize: 64, color: '#ff4d4f' }} />
                    <div style={{ marginTop: 20 }}>
                        <Text style={{ fontSize: 16, color: '#262626', fontWeight: 500 }}>
                            {modalMessage}
                        </Text>
                    </div>
                </div>
            </Modal>

            <div style={{
                minHeight: '100vh',
                display: 'flex',
                background: '#f0f2f5',
            }}>
                {/* ستون چپ - فرم */}
                <div style={{
                    flex: 1,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: 40,
                }}>
                    <div className={`fade-in-up ${shake ? 'shake' : ''}`} style={{ width: '100%', maxWidth: 400 }}>
                        <div style={{ textAlign: 'center', marginBottom: 40 }}>
                            <div style={{
                                width: 70,
                                height: 70,
                                background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                                borderRadius: 20,
                                display: 'inline-flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                marginBottom: 20,
                                boxShadow: '0 10px 30px rgba(102, 126, 234, 0.3)',
                            }}>
                                <SafetyOutlined style={{ fontSize: 32, color: '#fff' }} />
                            </div>
                            <Title level={2} style={{ marginBottom: 8 }}>
                                خوش آمدید
                            </Title>
                            <Text type="secondary">
                                برای ورود، اطلاعات کاربری خود را وارد کنید
                            </Text>
                        </div>

                        <Form
                            name="login"
                            onFinish={handleSubmit}
                            layout="vertical"
                            size="large"
                        >
                            <Form.Item
                                label="نام کاربری"
                                validateStatus={validationErrors.UserName ? 'error' : ''}
                                help={validationErrors.UserName}
                            >
                                <Input
                                    prefix={<UserOutlined style={{ color: '#bfbfbf' }} />}
                                    placeholder="نام کاربری خود را وارد کنید"
                                    value={data.UserName}
                                    onChange={handleUserNameChange}
                                    autoComplete="username"
                                    style={{
                                        height: 48,
                                        borderRadius: 10,
                                        direction: 'ltr',
                                        textAlign: 'left',
                                    }}
                                    maxLength={100}
                                />
                            </Form.Item>

                            <Form.Item
                                label="کلمه عبور"
                                validateStatus={validationErrors.Password ? 'error' : ''}
                                help={validationErrors.Password}
                            >
                                <Input.Password
                                    prefix={<LockOutlined style={{ color: '#bfbfbf' }} />}
                                    placeholder="کلمه عبور خود را وارد کنید"
                                    value={data.Password}
                                    onChange={handlePasswordChange}
                                    autoComplete="current-password"
                                    style={{
                                        height: 48,
                                        borderRadius: 10,
                                    }}
                                    maxLength={100}
                                />
                            </Form.Item>

                            <Form.Item>
                                <Checkbox
                                    checked={data.remember}
                                    onChange={(e) => setData('remember', e.target.checked)}
                                >
                                    مرا به خاطر بسپار
                                </Checkbox>
                            </Form.Item>

                            <Form.Item>
                                <Button
                                    type="primary"
                                    htmlType="submit"
                                    loading={processing}
                                    block
                                    icon={<LoginOutlined />}
                                    style={{
                                        height: 48,
                                        borderRadius: 10,
                                        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                                        border: 'none',
                                        fontSize: 16,
                                        fontWeight: 500,
                                    }}
                                >
                                    ورود به حساب
                                </Button>
                            </Form.Item>
                        </Form>

                        <div style={{ textAlign: 'center', marginTop: 30 }}>
                            <Text type="secondary" style={{ fontSize: 13 }}>
                                © {new Date().getFullYear()} تمامی حقوق محفوظ است
                            </Text>
                        </div>
                    </div>
                </div>

                {/* ستون راست - برندینگ */}
                <div style={{
                    flex: 1,
                    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    padding: 40,
                    position: 'relative',
                    overflow: 'hidden',
                }}>
                    <div style={{
                        position: 'absolute',
                        top: -50,
                        right: -50,
                        width: 200,
                        height: 200,
                        background: 'rgba(255,255,255,0.1)',
                        borderRadius: '50%',
                    }} />
                    <div style={{
                        position: 'absolute',
                        bottom: -80,
                        left: -80,
                        width: 300,
                        height: 300,
                        background: 'rgba(255,255,255,0.1)',
                        borderRadius: '50%',
                    }} />
                    <div style={{ textAlign: 'center', color: '#fff', zIndex: 1, maxWidth: 400 }}>
                        <div style={{ fontSize: 80, marginBottom: 20 }}>🎯</div>
                        <Title level={1} style={{ color: '#fff', marginBottom: 16 }}>
                            داشبورد مدیریت
                        </Title>
                        <Text style={{ color: 'rgba(255,255,255,0.9)', fontSize: 16, lineHeight: 1.8 }}>
                            سیستم یکپارچه مدیریت کسب‌وکار شما
                            <br />
                            ساده، سریع و امن
                        </Text>
                    </div>
                </div>
            </div>
        </>
    );
}