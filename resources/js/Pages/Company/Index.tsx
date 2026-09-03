import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Form,
    Input,
    Upload,
    Row,
    Col,
    Typography,
    Avatar,
    Space,
    Alert,
} from 'antd';
import {
    SaveOutlined,
    UploadOutlined,
    ShopOutlined,
    GlobalOutlined,
    PictureOutlined,
} from '@ant-design/icons';
import { router, usePage, useForm } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { THEME, STYLES } from '../../theme';

const { Title, Text } = Typography;

export default function CompanyIndex() {
    const { company, flash } = usePage().props as any;

    const [form] = Form.useForm();

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    const [notificationKey, setNotificationKey] = useState(0);

    const showNotification = (type: NotificationType, m: string) => {
        setNotificationKey((k) => k + 1);
        setNotification({ open: true, type, message: m });
    };

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        Code: company?.Code || '',
        Name: company?.Name || '',
        Description: company?.Description || '',
        SiteTitle: company?.SiteTitle || '',
        Logo: null as File | null,
        Favicon: null as File | null,
    });

    // پیش‌نمایش لوگو و فاوآیکون
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [faviconPreview, setFaviconPreview] = useState<string | null>(null);

    useEffect(() => {
        // وقتی صفحه لود شد، لوگو و فاوآیکون فعلی رو نشون بده (از روت جداگانه)
        setLogoPreview(`/company/logo?t=${Date.now()}`);
        setFaviconPreview(`/company/favicon?t=${Date.now()}`);
    }, []);

    useEffect(() => {
        if (company) {
            form.setFieldsValue({
                Code: company.Code || '',
                Name: company.Name || '',
                Description: company.Description || '',
                SiteTitle: company.SiteTitle || '',
            });
        }
    }, [company]);

    // آپلود لوگو — فقط پیش‌نمایش
    const beforeLogoUpload = (file: File) => {
        const reader = new FileReader();
        reader.onload = (e) => setLogoPreview(e.target?.result as string);
        reader.readAsDataURL(file);
        setData('Logo', file);
        return false;
    };

    const beforeFaviconUpload = (file: File) => {
        const reader = new FileReader();
        reader.onload = (e) => setFaviconPreview(e.target?.result as string);
        reader.readAsDataURL(file);
        setData('Favicon', file);
        return false;
    };

    const handleSubmit = () => {
        form.validateFields().then(() => {
            post('/company', {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => reset(),
            });
        });
    };

    return (
        <MainLayout>
            <PageHeader
                icon={<ShopOutlined />}
                title="تنظیمات شرکت"
                subtitle="مشخصات، لوگو و فاوآیکون شرکت"
            />

            <Card style={STYLES.card}>
                <Form form={form} layout="vertical" requiredMark>
                    <Row gutter={16}>
                        {/* کد شرکت */}
                        <Col xs={24} md={12}>
                            <Form.Item
                                label="کد شرکت"
                                name="Code"
                                rules={[
                                    { required: true, message: 'کد شرکت الزامی است' },
                                    { max: 50, message: 'حداکثر 50 کاراکتر' },
                                ]}
                            >
                                <Input
                                    prefix={<GlobalOutlined style={{ color: '#bfbfbf' }} />}
                                    placeholder="مثلاً: CO01"
                                    value={data.Code}
                                    onChange={(e) => setData('Code', e.target.value)}
                                    size="large"
                                />
                            </Form.Item>
                        </Col>

                        {/* نام شرکت */}
                        <Col xs={24} md={12}>
                            <Form.Item
                                label="نام شرکت"
                                name="Name"
                                rules={[
                                    { required: true, message: 'نام شرکت الزامی است' },
                                    { max: 200, message: 'حداکثر 200 کاراکتر' },
                                ]}
                                validateStatus={errors.Name ? 'error' : ''}
                                help={errors.Name}
                            >
                                <Input
                                    prefix={<ShopOutlined style={{ color: '#bfbfbf' }} />}
                                    placeholder="نام شرکت..."
                                    value={data.Name}
                                    onChange={(e) => setData('Name', e.target.value)}
                                    size="large"
                                />
                            </Form.Item>
                        </Col>

                        {/* توضیحات */}
                        <Col span={24}>
                            <Form.Item
                                label="توضیحات"
                                name="Description"
                                rules={[{ max: 1000, message: 'حداکثر 1000 کاراکتر' }]}
                            >
                                <Input.TextArea
                                    placeholder="توضیحات شرکت (اختیاری)..."
                                    value={data.Description}
                                    onChange={(e) => setData('Description', e.target.value)}
                                    rows={4}
                                    maxLength={1000}
                                    showCount
                                />
                            </Form.Item>
                        </Col>

                        {/* عنوان صفحه / نام روی صفحه اصلی موبایل */}
                        <Col span={24}>
                            <Form.Item
                                label="عنوان صفحه (کنار فاوآیکون و نام هنگام افزودن به صفحه اصلی موبایل)"
                                name="SiteTitle"
                                rules={[{ max: 100, message: 'حداکثر 100 کاراکتر' }]}
                                validateStatus={errors.SiteTitle ? 'error' : ''}
                                help={errors.SiteTitle || 'اگر خالی بماند، از «پنل مدیریت» استفاده می‌شود.'}
                            >
                                <Input
                                    placeholder="مثلاً: پنل مدیریت شرکت داروسازی..."
                                    value={data.SiteTitle}
                                    onChange={(e) => setData('SiteTitle', e.target.value)}
                                    size="large"
                                    maxLength={100}
                                />
                            </Form.Item>
                        </Col>

                        {/* لوگو */}
                        <Col xs={24} md={12}>
                            <Form.Item label="لوگو (حداکثر 2MB)">
                                <Space align="start" size={16}>
                                    <Avatar
                                        size={80}
                                        shape="square"
                                        style={{
                                            background: '#f0f5ff',
                                            border: '1px dashed #d9d9d9',
                                        }}
                                    >
                                        {logoPreview ? (
                                            <img
                                                src={logoPreview}
                                                alt="logo"
                                                style={{ width: '100%', height: '100%', objectFit: 'contain' }}
                                            />
                                        ) : (
                                            <PictureOutlined style={{ fontSize: 28, color: '#bfbfbf' }} />
                                        )}
                                    </Avatar>
                                    <Upload beforeUpload={beforeLogoUpload} showUploadList={false}>
                                        <Button icon={<UploadOutlined />}>انتخاب لوگو</Button>
                                    </Upload>
                                </Space>
                            </Form.Item>
                        </Col>

                        {/* فاوآیکون */}
                        <Col xs={24} md={12}>
                            <Form.Item label="فاوآیکون (حداکثر 512KB)">
                                <Space align="start" size={16}>
                                    <Avatar
                                        size={80}
                                        shape="square"
                                        style={{
                                            background: '#f0f5ff',
                                            border: '1px dashed #d9d9d9',
                                        }}
                                    >
                                        {faviconPreview ? (
                                            <img
                                                src={faviconPreview}
                                                alt="favicon"
                                                style={{ width: '100%', height: '100%', objectFit: 'contain' }}
                                            />
                                        ) : (
                                            <PictureOutlined style={{ fontSize: 28, color: '#bfbfbf' }} />
                                        )}
                                    </Avatar>
                                    <Upload beforeUpload={beforeFaviconUpload} showUploadList={false}>
                                        <Button icon={<UploadOutlined />}>انتخاب فاوآیکون</Button>
                                    </Upload>
                                </Space>
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row justify="end" style={{ marginTop: 16 }}>
                        <Button
                            type="primary"
                            icon={<SaveOutlined />}
                            size="large"
                            loading={processing}
                            onClick={handleSubmit}
                            style={STYLES.primaryButton}
                        >
                            ذخیره تنظیمات
                        </Button>
                    </Row>
                </Form>
            </Card>

            <NotificationModal
                key={notificationKey}
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}