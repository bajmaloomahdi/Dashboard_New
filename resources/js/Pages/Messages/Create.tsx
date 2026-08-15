import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Form,
    Input,
    Select,
    Radio,
    Space,
    Tag,
    Typography,
    Row,
    Col,
    Alert,
    Upload,
} from 'antd';
import {
    SendOutlined,
    ArrowLeftOutlined,
    MessageOutlined,
    UserOutlined,
    TeamOutlined,
    CrownOutlined,
    CopyOutlined,
    UploadOutlined,
    PaperClipOutlined,
} from '@ant-design/icons';
import { router, usePage, useForm } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { THEME, STYLES } from '../../theme';

const { Title, Text } = Typography;

interface MessageType {
    MessageTypeID: number;
    MessageTypeName: string;
}

interface TargetUser {
    UserID: number;
    FullName: string;
    UserName: string;
    UnitName: string | null;
    IsManager: boolean | number;
}

interface TaskUnit {
    UnitID: number;
    UnitCode: string;
    UnitName: string;
    ManagerUserID: number | null;
    ManagerName: string | null;
}

export default function MessageCreate() {
    const { messageTypes, targets, taskUnits, flash } = usePage().props as any;

    const [form] = Form.useForm();
    const [fileList, setFileList] = useState<any[]>([]);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    const { data, setData, post, processing, errors, reset, clearErrors } = useForm({
        MessageTypeID: null as number | null,
        Subject: '',
        MessageText: '',
        RecipientType: 1,
        RecipientUserIDs: [] as number[],
        CopyUserIDs: [] as number[],
        CopyDescription: '',
        attachments: [] as File[],
    });

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    const selectedType = messageTypes?.find(
        (t: MessageType) => t.MessageTypeID === data.MessageTypeID
    );
    const isTask = selectedType?.MessageTypeName === 'وظیفه';

    const userOptions = (targets || []).map((t: TargetUser) => ({
        value: t.UserID,
        label: (
            <Space>
                <Text>{t.FullName}</Text>
                {t.IsManager === 1 || t.IsManager === true ? (
                    <Tag icon={<CrownOutlined />} color="gold" style={{ borderRadius: 6, marginInlineEnd: 0 }}>
                        مدیر
                    </Tag>
                ) : null}
                {t.UnitName ? <Text type="secondary" style={{ fontSize: 12 }}>{t.UnitName}</Text> : null}
            </Space>
        ),
    }));

    const unitOptions = (taskUnits || []).map((t: TaskUnit) => ({
        value: t.UnitID,
        label: `${t.UnitName} (${t.ManagerName || 'بدون مدیر'})`,
        disabled: !t.ManagerUserID,
    }));

    const handleUnitChange = (unitId: number) => {
        const unit = (taskUnits || []).find((t: TaskUnit) => t.UnitID === unitId);
        if (unit?.ManagerUserID) {
            setData('RecipientUserIDs', [unit.ManagerUserID]);
        }
    };

    const handleTypeChange = (typeId: number) => {
        setData('MessageTypeID', typeId);
        setData('RecipientUserIDs', []);
    };

    const beforeUpload = () => false;

    const handleUploadChange = ({ fileList: newList }: any) => {
        setFileList(newList);
        const files = newList
            .map((f: any) => f.originFileObj)
            .filter(Boolean);
        setData('attachments', files);
    };

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const files = fileList
                .map((f: any) => f.originFileObj)
                .filter(Boolean);
            setData('attachments', files);

            post('/messages', {
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    setFileList([]);
                    reset();
                },
            });
        });
    };

    return (
        <MainLayout>
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Space>
                        <Button
                            icon={<ArrowLeftOutlined />}
                            onClick={() => router.visit('/messages')}
                            style={{ borderColor: THEME.primary, color: THEME.primary }}
                        >
                            بازگشت به کارتابل
                        </Button>
                        <div>
                            <Title level={3} style={{ margin: 0 }}>
                                <MessageOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                                ارسال پیام جدید
                            </Title>
                            <Text type="secondary">
                                ارسال پیام به همکاران و مدیران
                            </Text>
                        </div>
                    </Space>
                </Col>
            </Row>

            <Card style={STYLES.card}>
                <Form form={form} layout="vertical" requiredMark>
                    <Row gutter={16}>
                        <Col span={12}>
                            <Form.Item
                                label="نوع پیام"
                                name="MessageTypeID"
                                rules={[{ required: true, message: 'انتخاب نوع پیام الزامی است' }]}
                            >
                                <Select
                                    size="large"
                                    placeholder="انتخاب نوع پیام"
                                    value={data.MessageTypeID ?? undefined}
                                    onChange={handleTypeChange}
                                    options={(messageTypes || []).map((t: MessageType) => ({
                                        value: t.MessageTypeID,
                                        label: t.MessageTypeName,
                                    }))}
                                />
                            </Form.Item>
                        </Col>

                        {isTask ? (
                            <Col span={12}>
                                <Form.Item
                                    label="واحد (مدیر آن به‌صورت خودکار انتخاب می‌شود)"
                                    name="TaskUnitID"
                                    rules={[{ required: true, message: 'انتخاب واحد الزامی است' }]}
                                >
                                    <Select
                                        size="large"
                                        placeholder="انتخاب واحد"
                                        options={unitOptions}
                                        onChange={handleUnitChange}
                                    />
                                </Form.Item>
                                {data.RecipientUserIDs.length > 0 ? (
                                    <Alert
                                        type="info"
                                        showIcon
                                        message="مدیر واحد به‌عنوان گیرنده انتخاب شد"
                                        style={{ marginBottom: 16, borderRadius: 8 }}
                                    />
                                ) : null}
                            </Col>
                        ) : (
                            <Col span={12}>
                                <Form.Item label="گیرنده‌ها" required>
                                    <Radio.Group
                                        value={data.RecipientType}
                                        onChange={(e) => setData('RecipientType', e.target.value)}
                                    >
                                        <Space direction="vertical">
                                            <Radio value={2}>
                                                <TeamOutlined /> همه کاربران
                                            </Radio>
                                            <Radio value={3}>
                                                <CrownOutlined /> همه مدیران
                                            </Radio>
                                            <Radio value={1}>
                                                <UserOutlined /> کاربر یا کاربران مشخص
                                            </Radio>
                                        </Space>
                                    </Radio.Group>
                                </Form.Item>
                            </Col>
                        )}

                        {!isTask && data.RecipientType === 1 ? (
                            <Col span={24}>
                                <Form.Item
                                    label="انتخاب کاربران"
                                    name="RecipientUsers"
                                    rules={[{ required: true, message: 'حداقل یک گیرنده انتخاب کنید' }]}
                                >
                                    <Select
                                        mode="multiple"
                                        size="large"
                                        placeholder="جستجو و انتخاب کاربران..."
                                        value={data.RecipientUserIDs}
                                        onChange={(values: number[]) => setData('RecipientUserIDs', values)}
                                        options={userOptions}
                                        optionFilterProp="label"
                                        showSearch
                                        style={{ width: '100%' }}
                                    />
                                </Form.Item>
                            </Col>
                        ) : null}

                        <Col span={24}>
                            <Form.Item
                                label="موضوع"
                                name="Subject"
                                rules={[
                                    { required: true, message: 'موضوع الزامی است' },
                                    { max: 500, message: 'حداکثر 500 کاراکتر' },
                                ]}
                                validateStatus={errors.Subject ? 'error' : ''}
                                help={errors.Subject}
                            >
                                <Input
                                    size="large"
                                    placeholder="موضوع پیام..."
                                    value={data.Subject}
                                    onChange={(e) => setData('Subject', e.target.value)}
                                />
                            </Form.Item>
                        </Col>

                        <Col span={24}>
                            <Form.Item label="متن پیام" name="MessageText">
                                <Input.TextArea
                                    rows={6}
                                    placeholder="متن پیام..."
                                    value={data.MessageText}
                                    onChange={(e) => setData('MessageText', e.target.value)}
                                    showCount
                                    maxLength={5000}
                                />
                            </Form.Item>
                        </Col>

                        <Col span={24}>
                            <Form.Item label="رونوشت (اختیاری)" name="CopyUsers">
                                <Select
                                    mode="multiple"
                                    size="large"
                                    placeholder="انتخاب کاربران رونوشت..."
                                    value={data.CopyUserIDs}
                                    onChange={(values: number[]) => setData('CopyUserIDs', values)}
                                    options={userOptions}
                                    optionFilterProp="label"
                                    showSearch
                                    style={{ width: '100%' }}
                                    prefix={<CopyOutlined />}
                                />
                            </Form.Item>
                        </Col>

                        <Col span={24}>
                            <Form.Item label="توضیح رونوشت (اختیاری)" name="CopyDescription">
                                <Input.TextArea
                                    rows={2}
                                    placeholder="توضیح رونوشت..."
                                    value={data.CopyDescription}
                                    onChange={(e) => setData('CopyDescription', e.target.value)}
                                    maxLength={1000}
                                    showCount
                                />
                            </Form.Item>
                        </Col>

                        <Col span={24}>
                            <Form.Item label="ضمیمه (اختیاری)">
                                <Upload
                                    beforeUpload={beforeUpload}
                                    fileList={fileList}
                                    onChange={handleUploadChange}
                                    multiple
                                >
                                    <Button icon={<UploadOutlined />}>انتخاب فایل</Button>
                                </Upload>
                                {fileList.length > 0 ? (
                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                        <PaperClipOutlined /> {fileList.length} فایل انتخاب شده
                                    </Text>
                                ) : null}
                            </Form.Item>
                        </Col>
                    </Row>

                    <Row justify="end" style={{ marginTop: 8 }}>
                        <Button
                            type="primary"
                            icon={<SendOutlined />}
                            size="large"
                            loading={processing}
                            onClick={handleSubmit}
                            style={STYLES.primaryButton}
                        >
                            ارسال پیام
                        </Button>
                    </Row>
                </Form>
            </Card>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}