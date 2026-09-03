import { useEffect, useMemo } from 'react';
import { Modal, Form, Input, Row, Col, Button, Typography, Select, InputNumber, Switch, Divider } from 'antd';
import {
    AppstoreOutlined,
    LinkOutlined,
    SaveOutlined,
    CloseOutlined,
    FolderOutlined,
    FileOutlined,
    BarChartOutlined,
    LayoutOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

const { Text } = Typography;

interface Menu {
    MenuID: number;
    ParentID: number | null;
    MenuCode: string;
    MenuTitle: string;
    MenuKind: string;
    Url: string | null;
    Icon: string | null;
    SortOrder: number;
    OpenInNewTab: boolean | number;
    IsVisible: boolean | number;
    IsHomeTab: boolean | number;
    Description: string | null;
}

interface ParentOption {
    MenuID: number;
    MenuTitle: string;
    Level: number;
    DisplayTitle: string;
}

interface MenuFormModalProps {
    open: boolean;
    onClose: () => void;
    editingMenu: Menu | null;
    parentOptions: ParentOption[];
}

// لیست آیکون‌های Ant Design (پرکاربردترین‌ها)
const ICON_OPTIONS = [
    { value: 'DashboardOutlined', label: '📊 Dashboard' },
    { value: 'AppstoreOutlined', label: '⬛ Appstore' },
    { value: 'FolderOutlined', label: '📁 Folder' },
    { value: 'FileTextOutlined', label: '📄 FileText' },
    { value: 'BarChartOutlined', label: '📊 BarChart' },
    { value: 'LineChartOutlined', label: '📈 LineChart' },
    { value: 'PieChartOutlined', label: '🥧 PieChart' },
    { value: 'TeamOutlined', label: '👥 Team' },
    { value: 'UserOutlined', label: '👤 User' },
    { value: 'SafetyOutlined', label: '🛡 Safety' },
    { value: 'SettingOutlined', label: '⚙ Setting' },
    { value: 'ToolOutlined', label: '🔧 Tool' },
    { value: 'DatabaseOutlined', label: '💾 Database' },
    { value: 'HomeOutlined', label: '🏠 Home' },
    { value: 'ShoppingCartOutlined', label: '🛒 ShoppingCart' },
    { value: 'DollarOutlined', label: '💰 Dollar' },
    { value: 'CalendarOutlined', label: '📅 Calendar' },
    { value: 'MailOutlined', label: '✉ Mail' },
    { value: 'BellOutlined', label: '🔔 Bell' },
    { value: 'GlobalOutlined', label: '🌐 Global' },
];

const toBool = (value: any): boolean => Number(value) === 1;

export default function MenuFormModal({ open, onClose, editingMenu, parentOptions }: MenuFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingMenu;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        ParentID: null as number | null,
        MenuTitle: '',
        MenuKind: 'PAGE',
        Url: '',
        Icon: '',
        SortOrder: null as number | null,
        OpenInNewTab: false,
        IsVisible: true,
        IsHomeTab: false,
        Description: '',
    });

    const isTab = data.MenuKind === 'TAB' || data.IsHomeTab;

    // فیلتر Parent options (حذف خود منو در ویرایش)
    const filteredParents = useMemo(() => {
        if (!editingMenu) return parentOptions;
        return parentOptions.filter((p) => p.MenuID !== editingMenu.MenuID);
    }, [parentOptions, editingMenu]);

    // پر کردن فرم هنگام ویرایش
    useEffect(() => {
        if (open) {
            if (editingMenu) {
                const formData = {
                    ParentID: editingMenu.ParentID,
                    MenuTitle: editingMenu.MenuTitle || '',
                    MenuKind: editingMenu.MenuKind || 'PAGE',
                    Url: editingMenu.Url || '',
                    Icon: editingMenu.Icon || '',
                    SortOrder: editingMenu.SortOrder,
                    OpenInNewTab: toBool(editingMenu.OpenInNewTab),
                    IsVisible: toBool(editingMenu.IsVisible),
                    IsHomeTab: toBool(editingMenu.IsHomeTab),
                    Description: editingMenu.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                const defaultData = {
                    ParentID: null,
                    MenuTitle: '',
                    MenuKind: 'PAGE',
                    Url: '',
                    Icon: '',
                    SortOrder: null,
                    OpenInNewTab: false,
                    IsVisible: true,
                    IsHomeTab: false,
                    Description: '',
                };
                setData(defaultData);
                form.setFieldsValue(defaultData);
            }
            clearErrors();
        }
    }, [open, editingMenu]);

    /**
     * تغییر نوع منو
     * نوع TAB ⇒ خودکار تب صفحه اصلی می‌شود و از سایدبار حذف می‌شود
     */
    const handleKindChange = (value: string) => {
        if (value === 'TAB') {
            setData((prev: any) => ({
                ...prev,
                MenuKind: value,
                IsHomeTab: true,
                IsVisible: false,
                OpenInNewTab: false,
            }));
            form.setFieldsValue({ IsHomeTab: true, IsVisible: false, OpenInNewTab: false });
        } else {
            setData('MenuKind', value);
        }
    };

    /**
     * تغییر سوییچ «تب صفحه اصلی»
     * روشن شدن ⇒ نمایش در سایدبار خاموش و قفل می‌شود
     */
    const handleHomeTabChange = (checked: boolean) => {
        if (checked) {
            setData((prev: any) => ({ ...prev, IsHomeTab: true, IsVisible: false }));
            form.setFieldsValue({ IsHomeTab: true, IsVisible: false });
        } else {
            setData((prev: any) => ({ ...prev, IsHomeTab: false, IsVisible: true }));
            form.setFieldsValue({ IsHomeTab: false, IsVisible: true });
        }
    };

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
                onError: () => {},
            };

            if (isEdit) {
                put(`/menus/${editingMenu!.MenuID}`, options);
            } else {
                post('/menus', options);
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
                    <AppstoreOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش منو' : 'ایجاد منوی جدید'}</span>
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
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد منو'}
                </Button>,
            ]}
            styles={{ body: { paddingTop: 24 } }}
        >
            <Form form={form} layout="vertical" requiredMark>
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
                        <Text>
                            کد منو: <Text strong>{editingMenu?.MenuCode}</Text>
                        </Text>
                    </div>
                )}

                {/* راهنمای حالت تب */}
                {isTab && (
                    <div
                        style={{
                            background: '#f6f4ff',
                            border: '1px solid #d3c9ff',
                            borderRadius: 8,
                            padding: 12,
                            marginBottom: 20,
                            display: 'flex',
                            alignItems: 'center',
                            gap: 8,
                        }}
                    >
                        <LayoutOutlined style={{ color: '#667eea', fontSize: 18 }} />
                        <Text type="secondary" style={{ fontSize: 13 }}>
                            این منو به‌عنوان <Text strong>تب صفحه اصلی</Text> نمایش داده می‌شود و در سایدبار دیده نمی‌شود.
                        </Text>
                    </div>
                )}

                <Row gutter={16}>
                    {/* عنوان منو */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="عنوان منو"
                            name="MenuTitle"
                            rules={[
                                { required: true, message: 'عنوان منو الزامی است' },
                                { max: 200, message: 'حداکثر 200 کاراکتر' },
                            ]}
                            validateStatus={errors.MenuTitle ? 'error' : ''}
                            help={errors.MenuTitle}
                        >
                            <Input
                                prefix={<AppstoreOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: مدیریت انبار"
                                value={data.MenuTitle}
                                onChange={(e) => setData('MenuTitle', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    {/* نوع منو */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نوع منو"
                            name="MenuKind"
                            rules={[{ required: true, message: 'نوع منو الزامی است' }]}
                            validateStatus={errors.MenuKind ? 'error' : ''}
                            help={errors.MenuKind}
                        >
                            <Select
                                value={data.MenuKind}
                                onChange={handleKindChange}
                                size="large"
                                options={[
                                    { value: 'PAGE', label: <span><FileOutlined style={{ color: '#1890ff' }} /> صفحه (PAGE)</span> },
                                    { value: 'FOLDER', label: <span><FolderOutlined style={{ color: '#faad14' }} /> پوشه (FOLDER)</span> },
                                    { value: 'REPORT', label: <span><BarChartOutlined style={{ color: '#722ed1' }} /> گزارش (REPORT)</span> },
                                    { value: 'TAB', label: <span><LayoutOutlined style={{ color: '#667eea' }} /> تب صفحه اصلی (TAB)</span> },
                                ]}
                            />
                        </Form.Item>
                    </Col>

                    {/* منوی والد */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="منوی والد"
                            name="ParentID"
                            extra={isTab ? 'تب‌ها معمولاً بدون والد تعریف می‌شوند' : 'خالی = منوی اصلی (Level 1)'}
                        >
                            <Select
                                value={data.ParentID}
                                onChange={(value) => setData('ParentID', value)}
                                size="large"
                                allowClear
                                placeholder="بدون والد (منوی اصلی)"
                                showSearch
                                optionFilterProp="label"
                                options={filteredParents.map((p) => ({
                                    value: p.MenuID,
                                    label: p.DisplayTitle,
                                }))}
                            />
                        </Form.Item>
                    </Col>

                    {/* ترتیب نمایش */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="ترتیب نمایش"
                            name="SortOrder"
                            extra={isTab ? 'ترتیب قرارگیری تب در صفحه اصلی' : 'خالی = آخر لیست'}
                        >
                            <InputNumber
                                value={data.SortOrder}
                                onChange={(value) => setData('SortOrder', value)}
                                size="large"
                                style={{ width: '100%' }}
                                min={1}
                                controls={false}
                                placeholder="مثلاً: 1"
                            />
                        </Form.Item>
                    </Col>

                    {/* آدرس (URL) */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="آدرس (URL)"
                            name="Url"
                            extra={isTab ? 'برای TAB معمولاً خالی است' : 'برای FOLDER معمولاً خالی است'}
                        >
                            <Input
                                prefix={<LinkOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="/example/path"
                                value={data.Url}
                                onChange={(e) => setData('Url', e.target.value)}
                                size="large"
                                dir="ltr"
                            />
                        </Form.Item>
                    </Col>

                    {/* آیکون */}
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="آیکون"
                            name="Icon"
                        >
                            <Select
                                value={data.Icon}
                                onChange={(value) => setData('Icon', value)}
                                size="large"
                                allowClear
                                showSearch
                                placeholder="انتخاب آیکون"
                                options={ICON_OPTIONS}
                            />
                        </Form.Item>
                    </Col>
                </Row>

                <Divider style={{ margin: '8px 0 16px' }} />

                <Row gutter={16}>
                    {/* تب صفحه اصلی */}
                    <Col xs={24} md={8}>
                        <Form.Item
                            label={
                                <span>
                                    <LayoutOutlined style={{ color: '#667eea', marginLeft: 6 }} />
                                    تب صفحه اصلی
                                </span>
                            }
                            name="IsHomeTab"
                            extra="در داشبورد به‌صورت تب نمایش داده می‌شود"
                        >
                            <Switch
                                checked={data.IsHomeTab}
                                onChange={handleHomeTabChange}
                                disabled={data.MenuKind === 'TAB'}
                                checkedChildren="بله"
                                unCheckedChildren="خیر"
                            />
                        </Form.Item>
                    </Col>

                    {/* قابل مشاهده */}
                    <Col xs={24} md={8}>
                        <Form.Item
                            label="نمایش در سایدبار"
                            name="IsVisible"
                            extra={data.IsHomeTab ? 'تب‌ها در سایدبار نمایش داده نمی‌شوند' : undefined}
                        >
                            <Switch
                                checked={data.IsHomeTab ? false : data.IsVisible}
                                onChange={(checked) => setData('IsVisible', checked)}
                                disabled={data.IsHomeTab}
                                checkedChildren="فعال"
                                unCheckedChildren="مخفی"
                            />
                        </Form.Item>
                    </Col>

                    {/* باز شدن در تب جدید */}
                    <Col xs={24} md={8}>
                        <Form.Item label="باز شدن در تب جدید مرورگر" name="OpenInNewTab">
                            <Switch
                                checked={data.OpenInNewTab}
                                onChange={(checked) => setData('OpenInNewTab', checked)}
                                disabled={data.IsHomeTab}
                                checkedChildren="بله"
                                unCheckedChildren="خیر"
                            />
                        </Form.Item>
                    </Col>

                    {/* توضیحات */}
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
