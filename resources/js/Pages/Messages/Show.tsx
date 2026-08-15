import { useState, useEffect } from 'react';
import {
    Card,
    Button,
    Space,
    Tag,
    Typography,
    Row,
    Col,
    Empty,
    Table,
    Avatar,
    Select,
    Input,
    Alert,
    Upload,
    Divider,
} from 'antd';
import {
    ArrowLeftOutlined,
    PaperClipOutlined,
    DownloadOutlined,
    MessageOutlined,
    CopyOutlined,
    UserOutlined,
    ClockCircleOutlined,
    SendOutlined,
    SaveOutlined,
    AuditOutlined,
    UploadOutlined,
    CommentOutlined,
    UserAddOutlined,
    PictureOutlined,
} from '@ant-design/icons';
import { router, usePage, useForm } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import { THEME, STYLES } from '../../theme';

const { Title, Text } = Typography;

const gradientHeadStyle = {
    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
    border: 'none',
    borderRadius: '12px 12px 0 0',
};

const whiteTitle = (text: any) => (
    <span style={{ color: '#fff', fontWeight: 600 }}>{text}</span>
);

interface MessageHeader {
    MessageID: number;
    MessageNumber: string | null;
    Subject: string;
    MessageText: string | null;
    MessageTypeName: string;
    SenderName: string;
    CreateDate: string;
}

interface MessageDetail {
    MessageDetailID: number;
    FromName: string;
    ToName: string;
    MessageStatusName: string;
    Description: string | null;
    CreateDate: string;
}

interface CopyItem {
    MessageCopyID: number;
    FullName: string;
    CreateDate: string;
    CreateUserName: string | null;
    Description: string | null;
}

interface Attachment {
    MessageAttachmentID: number;
    FileName: string;
    FileExtension: string | null;
    FileSize: number;
    FilePath: string;
    CreateDate: string;
    CreateUserName: string | null;
}

interface CommentItem {
    MessageCommentID: number;
    UserID: number;
    UserName: string;
    Comment: string;
    CreateDate: string;
}

interface StatusItem {
    MessageStatusID: number;
    MessageStatusName: string;
}

interface UserItem {
    UserID: number;
    FullName: string;
    UnitName: string | null;
    IsManager: boolean | number;
}

export default function MessageShow() {
    const { message, details, copies, attachments, statuses, users, comments, isLastRecipient, isTask, canComment, flash } = usePage().props as any;

    const msg: MessageHeader = message;
    const detailList: MessageDetail[] = details || [];
    const copyList: CopyItem[] = copies || [];
    const attachmentList: Attachment[] = attachments || [];
    const statusList: StatusItem[] = statuses || [];
    const userList: UserItem[] = users || [];
    const commentList: CommentItem[] = comments || [];

    // فیلتر ضمیمه‌های عکس
    const imageAttachments = attachmentList.filter((a: Attachment) => {
        const ext = (a.FileExtension || '').toLowerCase();
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(ext);
    });

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

    // فرم تغییر وضعیت / ارجاع
    const { data, setData, post, processing, errors } = useForm({
        MessageStatusID: null as number | null,
        ToUserID: null as number | null,
        Description: '',
    });

    // فرم نظر
    const { data: commentData, setData: setCommentData, post: postComment, processing: commentProcessing, errors: commentErrors, reset: resetComment } = useForm({
        Comment: '',
        comment_attachments: [] as File[],
    });

    // فرم افزودن رونوشت
    const { data: copyData, setData: setCopyData, post: postCopy, processing: copyProcessing, errors: copyErrors, reset: resetCopy } = useForm({
        CopyUserID: null as number | null,
        Description: '',
    });

    const [commentFileList, setCommentFileList] = useState<any[]>([]);

    const selectedStatus = statusList.find((s) => s.MessageStatusID === data.MessageStatusID);
    const isReferral = selectedStatus?.MessageStatusName === 'ارجاع';

    // کاربرانی که هنوز رونوشت نیستند
    const copyUserOptions = userList
        .filter((u) => !copyList.some((c) => c.UserID === u.UserID))
        .map((u: UserItem) => ({
            value: u.UserID,
            label: (
                <Space>
                    <Text>{u.FullName}</Text>
                    {u.IsManager === 1 || u.IsManager === true ? (
                        <Tag color="gold" style={{ borderRadius: 6, marginInlineEnd: 0 }}>مدیر</Tag>
                    ) : null}
                    {u.UnitName ? <Text type="secondary" style={{ fontSize: 12 }}>{u.UnitName}</Text> : null}
                </Space>
            ),
        }));

    const userOptions = userList.map((u: UserItem) => ({
        value: u.UserID,
        label: (
            <Space>
                <Text>{u.FullName}</Text>
                {u.IsManager === 1 || u.IsManager === true ? (
                    <Tag color="gold" style={{ borderRadius: 6, marginInlineEnd: 0 }}>مدیر</Tag>
                ) : null}
                {u.UnitName ? <Text type="secondary" style={{ fontSize: 12 }}>{u.UnitName}</Text> : null}
            </Space>
        ),
    }));

    const handleSubmitStatus = () => {
        if (!data.MessageStatusID) {
            showNotification('warning', 'لطفاً وضعیت را انتخاب کنید.');
            return;
        }

        if (isReferral) {
            if (!data.ToUserID) {
                showNotification('warning', 'لطفاً کاربر مقصد ارجاع را انتخاب کنید.');
                return;
            }
            post(`/messages/${msg.MessageID}/forward`, { preserveScroll: true });
        } else {
            post(`/messages/${msg.MessageID}/status`, { preserveScroll: true });
        }
    };

    // آپلود ضمیمه نظر — فقط لیست آنتد
    const beforeCommentUpload = () => false;

    const handleCommentUploadChange = ({ fileList: newList }: any) => {
        setCommentFileList(newList);
        const files = newList.map((f: any) => f.originFileObj).filter(Boolean);
        setCommentData('comment_attachments', files);
    };

    // دکمه هوشمند نظر
    const getSubmitButtonText = () => {
        const hasComment = commentData.Comment.trim() !== '';
        const hasFiles = commentFileList.length > 0;
        if (hasComment && hasFiles) return 'ثبت';
        if (hasComment) return 'ثبت توضیح';
        if (hasFiles) return 'ثبت ضمیمه';
        return 'ثبت';
    };

    const handleSubmitComment = () => {
        const hasComment = commentData.Comment.trim() !== '';
        const hasFiles = commentFileList.length > 0;

        if (!hasComment && !hasFiles) {
            showNotification('warning', 'توضیح یا ضمیمه را وارد کنید.');
            return;
        }
        postComment(`/messages/${msg.MessageID}/comment`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                resetComment();
                setCommentFileList([]);
            },
        });
    };

    // ثبت رونوشت جدید
    const handleSubmitCopy = () => {
        if (!copyData.CopyUserID) {
            showNotification('warning', 'لطفاً کاربر رونوشت را انتخاب کنید.');
            return;
        }
        postCopy(`/messages/${msg.MessageID}/copy`, {
            preserveScroll: true,
            onSuccess: () => resetCopy(),
        });
    };

    const typeColor = (typeName: string) =>
        typeName === 'وظیفه' ? 'orange' : 'geekblue';

    const statusColor = (statusName: string) => {
        switch (statusName) {
            case 'ارسال شده': return 'default';
            case 'ارجاع': return 'purple';
            case 'در حال انجام': return 'processing';
            case 'انجام شده': return 'success';
            case 'عودت': return 'warning';
            case 'انجام نخواهد شد': return 'error';
            default: return 'default';
        }
    };

    const formatSize = (bytes: number) => {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
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
                            بازگشت
                        </Button>
                        <div>
                            <Title level={3} style={{ margin: 0 }}>
                                <MessageOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                                موضوع پیام
                            </Title>
                        </div>
                    </Space>
                </Col>
                <Col>
                    <Tag color={typeColor(msg.MessageTypeName)} style={{ borderRadius: 8, padding: '4px 16px', fontSize: 14 }}>
                        {msg.MessageTypeName}
                    </Tag>
                </Col>
            </Row>

            {/* سربرگ */}
            <Card
                style={{
                    marginBottom: 16,
                    borderRadius: 12,
                    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                    border: 'none',
                }}
            >
                <Row align="middle" gutter={16}>
                    <Col>
                        <Avatar size={56} style={{ background: 'rgba(255,255,255,0.25)', color: '#fff', fontSize: 24 }}>
                            <MessageOutlined />
                        </Avatar>
                    </Col>
                    <Col flex="auto">
                        <Title level={4} style={{ margin: 0, color: '#fff' }}>
                            {msg.Subject}
                            {msg.MessageNumber ? (
                                <Tag style={{ background: 'rgba(255,255,255,0.25)', color: '#fff', borderRadius: 6, border: 'none', marginRight: 8, fontSize: 13 }}>
                                    {msg.MessageNumber}
                                </Tag>
                            ) : null}
                        </Title>
                        <Space split={<Text style={{ color: 'rgba(255,255,255,0.7)' }}>|</Text>} style={{ marginTop: 8, flexWrap: 'wrap' }}>
                            <Text style={{ color: 'rgba(255,255,255,0.95)' }}>
                                <UserOutlined /> {msg.SenderName}
                            </Text>
                            <Text style={{ color: 'rgba(255,255,255,0.95)' }}>
                                <ClockCircleOutlined /> {new Date(msg.CreateDate).toLocaleString('fa-IR')}
                            </Text>
                        </Space>
                    </Col>
                </Row>
            </Card>

            {/* تغییر وضعیت وظیفه — فقط برای آخرین گیرنده */}
            {isTask && isLastRecipient ? (
                <Card
                    title={whiteTitle(
                        <Space>
                            <AuditOutlined />
                            <span>تغییر وضعیت وظیفه</span>
                        </Space>
                    )}
                    headStyle={gradientHeadStyle}
                    style={{ ...STYLES.card, marginBottom: 16 }}
                >
                    {errors?.status ? (
                        <Alert type="error" message={errors.status} showIcon style={{ marginBottom: 12, borderRadius: 8 }} />
                    ) : null}
                    <Row gutter={16}>
                        <Col xs={24} md={8}>
                            <Select
                                style={{ width: '100%' }}
                                size="large"
                                placeholder="انتخاب وضعیت جدید"
                                value={data.MessageStatusID ?? undefined}
                                onChange={(v: number) => {
                                    setData('MessageStatusID', v);
                                    if (v !== data.MessageStatusID) setData('ToUserID', null);
                                }}
                                options={(statusList || []).map((s: StatusItem) => ({
                                    value: s.MessageStatusID,
                                    label: s.MessageStatusName,
                                }))}
                            />
                        </Col>

                        {isReferral ? (
                            <Col xs={24} md={8}>
                                <Select
                                    style={{ width: '100%' }}
                                    size="large"
                                    placeholder="جستجو و انتخاب کاربر مقصد..."
                                    value={data.ToUserID ?? undefined}
                                    onChange={(v: number) => setData('ToUserID', v)}
                                    options={userOptions}
                                    optionFilterProp="label"
                                    showSearch
                                />
                            </Col>
                        ) : null}

                        <Col xs={24} md={8}>
                            <Input.TextArea
                                rows={2}
                                placeholder="توضیح (اختیاری)..."
                                value={data.Description}
                                onChange={(e) => setData('Description', e.target.value)}
                                maxLength={1000}
                                showCount
                            />
                        </Col>
                    </Row>

                    <Row justify="end" style={{ marginTop: 24 }}>
                        <Button
                            type="primary"
                            icon={<SaveOutlined />}
                            size="large"
                            loading={processing}
                            onClick={handleSubmitStatus}
                        >
                            ثبت
                        </Button>
                    </Row>
                </Card>
            ) : null}

            {/* متن پیام */}
            <Card
                title={whiteTitle(
                    <Space>
                        <MessageOutlined />
                        <span>متن پیام</span>
                    </Space>
                )}
                headStyle={gradientHeadStyle}
                style={{ ...STYLES.card, marginBottom: 16 }}
            >
                <div style={{ background: '#fafafa', borderRadius: 10, padding: '16px 20px', minHeight: 80 }}>
                    <Text style={{ whiteSpace: 'pre-wrap', lineHeight: 2, fontSize: 15 }}>
                        {msg.MessageText || '—'}
                    </Text>
                </div>
            </Card>

            {/* گردش پیام */}
            <Card
                title={whiteTitle(
                    <Space>
                        <SendOutlined />
                        <span>گردش پیام</span>
                    </Space>
                )}
                headStyle={gradientHeadStyle}
                style={{ ...STYLES.card, marginBottom: 16 }}
            >
                {detailList.length === 0 ? (
                    <Empty description="گردشی ثبت نشده است" />
                ) : (
                    <Table
                        rowKey="MessageDetailID"
                        dataSource={detailList}
                        pagination={false}
                        size="middle"
                        columns={[
                            {
                                title: 'فرستنده',
                                dataIndex: 'FromName',
                                key: 'FromName',
                                align: 'center',
                                render: (name: string) => (
                                    <Space>
                                        <Avatar size={26} style={{ background: '#667eea', fontSize: 12 }}>
                                            {name ? name.charAt(0) : <UserOutlined />}
                                        </Avatar>
                                        <Text>{name}</Text>
                                    </Space>
                                ),
                            },
                            {
                                title: 'گیرنده',
                                dataIndex: 'ToName',
                                key: 'ToName',
                                align: 'center',
                                render: (name: string) => (
                                    <Space>
                                        <Avatar size={26} style={{ background: '#764ba2', fontSize: 12 }}>
                                            {name ? name.charAt(0) : <UserOutlined />}
                                        </Avatar>
                                        <Text>{name}</Text>
                                    </Space>
                                ),
                            },
                            {
                                title: 'وضعیت',
                                dataIndex: 'MessageStatusName',
                                key: 'MessageStatusName',
                                align: 'center',
                                width: 130,
                                render: (name: string) => (
                                    <Tag color={statusColor(name)} style={{ borderRadius: 6 }}>
                                        {name}
                                    </Tag>
                                ),
                            },
                            {
                                title: 'توضیحات',
                                dataIndex: 'Description',
                                key: 'Description',
                                align: 'center',
                                render: (desc: string | null) =>
                                    desc ? <Text>{desc}</Text> : <Text type="secondary">—</Text>,
                            },
                            {
                                title: 'تاریخ و ساعت',
                                dataIndex: 'CreateDate',
                                key: 'CreateDate',
                                align: 'center',
                                width: 180,
                                render: (date: string) => (
                                    <div>
                                        <Text style={{ fontSize: 12 }}>
                                            {new Date(date).toLocaleDateString('fa-IR')}
                                        </Text>
                                        <br />
                                        <Text type="secondary" style={{ fontSize: 11 }}>
                                            {new Date(date).toLocaleTimeString('fa-IR')}
                                        </Text>
                                    </div>
                                ),
                            },
                        ]}
                    />
                )}
            </Card>

            {/* نظرات / توضیحات */}
            <Card
                title={whiteTitle(
                    <Space>
                        <CommentOutlined />
                        <span>نظرات و توضیحات</span>
                    </Space>
                )}
                headStyle={gradientHeadStyle}
                style={{ ...STYLES.card, marginBottom: 16 }}
            >
                {commentList.length === 0 ? (
                    <Empty description="هنوز توضیحی ثبت نشده است" />
                ) : (
                    <Space direction="vertical" size={16} style={{ width: '100%' }}>
                        {commentList.map((c) => (
                            <div
                                key={c.MessageCommentID}
                                style={{
                                    background: '#fafafa',
                                    borderRadius: 10,
                                    padding: '12px 16px',
                                    width: '100%',
                                }}
                            >
                                <Space>
                                    <Avatar size={28} style={{ background: '#667eea', fontSize: 13 }}>
                                        {c.UserName ? c.UserName.charAt(0) : <UserOutlined />}
                                    </Avatar>
                                    <Text strong>{c.UserName}</Text>
                                    <Text type="secondary" style={{ fontSize: 11 }}>
                                        <ClockCircleOutlined /> {new Date(c.CreateDate).toLocaleString('fa-IR')}
                                    </Text>
                                </Space>
                                <div style={{ marginTop: 8 }}>
                                    <Text style={{ whiteSpace: 'pre-wrap', lineHeight: 1.8 }}>
                                        {c.Comment || ''}
                                    </Text>
                                </div>
                            </div>
                        ))}
                    </Space>
                )}

                {/* فرم افزودن نظر — فقط اگه مجاز باشه */}
                {canComment ? (
                    <>
                        <Divider style={{ margin: '20px 0 16px' }} />
                        {commentErrors?.comment ? (
                            <Alert type="error" message={commentErrors.comment} showIcon style={{ marginBottom: 12, borderRadius: 8 }} />
                        ) : null}
                        <Row gutter={16}>
                            <Col xs={24}>
                                <Input.TextArea
                                    rows={3}
                                    placeholder="توضیح یا نظر خود را بنویسید..."
                                    value={commentData.Comment}
                                    onChange={(e) => setCommentData('Comment', e.target.value)}
                                    maxLength={2000}
                                    showCount
                                />
                            </Col>
                            <Col xs={24} style={{ marginTop: 12 }}>
                                <Upload
                                    beforeUpload={beforeCommentUpload}
                                    fileList={commentFileList}
                                    onChange={handleCommentUploadChange}
                                    multiple
                                >
                                    <Button icon={<UploadOutlined />}>ضمیمه فایل</Button>
                                </Upload>
                            </Col>
                        </Row>
                        <Row justify="end" style={{ marginTop: 12 }}>
                            <Button
                                type="primary"
                                icon={<SaveOutlined />}
                                size="large"
                                loading={commentProcessing}
                                onClick={handleSubmitComment}
                            >
                                {getSubmitButtonText()}
                            </Button>
                        </Row>
                    </>
                ) : null}
            </Card>

            {/* رونوشت‌ها + افزودن رونوشت */}
            <Card
                title={whiteTitle(
                    <Space>
                        <CopyOutlined />
                        <span>رونوشت‌ها</span>
                    </Space>
                )}
                headStyle={gradientHeadStyle}
                style={{ ...STYLES.card, marginBottom: 16 }}
            >
                {copyList.length === 0 ? (
                    <Empty description="رونوشتی ثبت نشده است" />
                ) : (
                    <Table
                        rowKey="MessageCopyID"
                        dataSource={copyList}
                        pagination={false}
                        size="middle"
                        style={{ marginBottom: 16 }}
                        columns={[
                            {
                                title: 'نام',
                                dataIndex: 'FullName',
                                key: 'FullName',
                                align: 'center',
                                render: (name: string) => (
                                    <Space>
                                        <Avatar size={28} style={{ background: '#13c2c2', fontSize: 13 }}>
                                            {name ? name.charAt(0) : <UserOutlined />}
                                        </Avatar>
                                        <Text>{name}</Text>
                                    </Space>
                                ),
                            },
                            {
                                title: 'توضیحات',
                                dataIndex: 'Description',
                                key: 'Description',
                                align: 'center',
                                render: (desc: string | null) =>
                                    desc ? <Text>{desc}</Text> : <Text type="secondary">—</Text>,
                            },
                            {
                                title: 'تاریخ و ساعت',
                                dataIndex: 'CreateDate',
                                key: 'CreateDate',
                                align: 'center',
                                width: 180,
                                render: (date: string) => (
                                    <div>
                                        <Text style={{ fontSize: 12 }}>
                                            {new Date(date).toLocaleDateString('fa-IR')}
                                        </Text>
                                        <br />
                                        <Text type="secondary" style={{ fontSize: 11 }}>
                                            {new Date(date).toLocaleTimeString('fa-IR')}
                                        </Text>
                                    </div>
                                ),
                            },
                            {
                                title: 'ایجادکننده',
                                dataIndex: 'CreateUserName',
                                key: 'CreateUserName',
                                align: 'center',
                                width: 160,
                                render: (name: string | null) =>
                                    name ? (
                                        <Space>
                                            <UserOutlined style={{ color: THEME.info }} />
                                            <Text>{name}</Text>
                                        </Space>
                                    ) : (
                                        <Text type="secondary">—</Text>
                                    ),
                            },
                        ]}
                    />
                )}

                {/* فرم افزودن رونوشت */}
                <Divider style={{ margin: '16px 0' }} />
                <Row gutter={16} align="bottom">
                    <Col xs={24} md={10}>
                        <Text type="secondary" style={{ display: 'block', marginBottom: 6 }}>
                            افزودن رونوشت جدید
                        </Text>
                        <Select
                            style={{ width: '100%' }}
                            size="large"
                            placeholder="جستجو و انتخاب کاربر..."
                            value={copyData.CopyUserID ?? undefined}
                            onChange={(v: number) => setCopyData('CopyUserID', v)}
                            options={copyUserOptions}
                            optionFilterProp="label"
                            showSearch
                        />
                    </Col>
                    <Col xs={24} md={10}>
                        <Input
                            size="large"
                            placeholder="توضیح (اختیاری)..."
                            value={copyData.Description}
                            onChange={(e) => setCopyData('Description', e.target.value)}
                            maxLength={1000}
                        />
                    </Col>
                    <Col xs={24} md={4}>
                        <Button
                            type="primary"
                            icon={<UserAddOutlined />}
                            size="large"
                            block
                            loading={copyProcessing}
                            onClick={handleSubmitCopy}
                        >
                            افزودن
                        </Button>
                    </Col>
                </Row>
                {copyErrors?.copy ? (
                    <Alert type="error" message={copyErrors.copy} showIcon style={{ marginTop: 12, borderRadius: 8 }} />
                ) : null}
            </Card>

            {/* ضمیمه‌ها */}
            <Card
                title={whiteTitle(
                    <Space>
                        <PaperClipOutlined />
                        <span>ضمیمه‌ها</span>
                    </Space>
                )}
                headStyle={gradientHeadStyle}
                style={{ ...STYLES.card, marginBottom: 16 }}
            >
                {attachmentList.length === 0 ? (
                    <Empty description="ضمیمه‌ای ثبت نشده است" />
                ) : (
                    <Table
                        rowKey="MessageAttachmentID"
                        dataSource={attachmentList}
                        pagination={false}
                        size="middle"
                        columns={[
                            {
                                title: 'نام فایل',
                                dataIndex: 'FileName',
                                key: 'FileName',
                                render: (name: string, record: Attachment) => {
                                    const ext = (record.FileExtension || '').toLowerCase();
                                    const isImage = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'].includes(ext);

                                    return isImage ? (
                                        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                            <a
                                                href={`/storage/${record.FilePath}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                style={{
                                                    display: 'block',
                                                    border: '1px solid #e8e8e8',
                                                    borderRadius: 10,
                                                    padding: 6,
                                                    background: '#fafafa',
                                                    boxShadow: '0 2px 8px rgba(0,0,0,0.08)',
                                                    transition: 'all 0.3s',
                                                }}
                                            >
                                                <img
                                                    src={`/storage/${record.FilePath}`}
                                                    alt={name}
                                                    style={{
                                                        width: 80,
                                                        height: 60,
                                                        objectFit: 'cover',
                                                        borderRadius: 6,
                                                        display: 'block',
                                                    }}
                                                />
                                            </a>
                                            <div>
                                                <Text strong>{name}</Text>
                                                {record.FileExtension ? (
                                                    <Tag color="geekblue" style={{ borderRadius: 6, marginTop: 2 }}>
                                                        {record.FileExtension}
                                                    </Tag>
                                                ) : null}
                                            </div>
                                        </div>
                                    ) : (
                                        <Space>
                                            <Avatar size={32} style={{ background: '#f0f5ff', color: '#667eea' }}>
                                                <PaperClipOutlined />
                                            </Avatar>
                                            <div>
                                                <Text strong>{name}</Text>
                                                {record.FileExtension ? (
                                                    <Tag color="geekblue" style={{ borderRadius: 6, marginTop: 2 }}>
                                                        {record.FileExtension}
                                                    </Tag>
                                                ) : null}
                                            </div>
                                        </Space>
                                    );
                                },
                            },
                            {
                                title: 'حجم',
                                dataIndex: 'FileSize',
                                key: 'FileSize',
                                width: 90,
                                align: 'center',
                                render: (size: number) => (
                                    <Text type="secondary">{formatSize(size)}</Text>
                                ),
                            },
                            {
                                title: 'کاربر',
                                dataIndex: 'CreateUserName',
                                key: 'CreateUserName',
                                width: 150,
                                align: 'center',
                                render: (name: string | null) =>
                                    name ? (
                                        <Space>
                                            <UserOutlined style={{ color: THEME.info }} />
                                            <Text>{name}</Text>
                                        </Space>
                                    ) : (
                                        <Text type="secondary">—</Text>
                                    ),
                            },
                            {
                                title: 'تاریخ و ساعت',
                                dataIndex: 'CreateDate',
                                key: 'CreateDate',
                                width: 170,
                                align: 'center',
                                render: (date: string) => (
                                    <div>
                                        <Text style={{ fontSize: 12 }}>
                                            {new Date(date).toLocaleDateString('fa-IR')}
                                        </Text>
                                        <br />
                                        <Text type="secondary" style={{ fontSize: 11 }}>
                                            {new Date(date).toLocaleTimeString('fa-IR')}
                                        </Text>
                                    </div>
                                ),
                            },
                            {
                                title: 'دانلود',
                                key: 'download',
                                width: 110,
                                align: 'center',
                                render: (_, record: Attachment) => (
                                    <a href={`/storage/${record.FilePath}`} target="_blank" rel="noopener noreferrer">
                                        <Button type="primary" ghost icon={<DownloadOutlined />} size="small">
                                            دانلود
                                        </Button>
                                    </a>
                                ),
                            },
                        ]}
                    />
                )}
            </Card>

            {/* تصاویر پیام — آخر صفحه */}
            {imageAttachments.length > 0 ? (
                <Card
                    title={whiteTitle(
                        <Space>
                            <PictureOutlined />
                            <span>تصاویر</span>
                        </Space>
                    )}
                    headStyle={gradientHeadStyle}
                    style={{ ...STYLES.card, marginTop: 16 }}
                >
                    <Row gutter={[16, 16]}>
                        {imageAttachments.map((img) => (
                            <Col key={img.MessageAttachmentID} xs={12} sm={8} md={6} lg={4}>
                                <a
                                    href={`/storage/${img.FilePath}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{ display: 'block' }}
                                >
                                    <div
                                        style={{
                                            border: '1px solid #e8e8e8',
                                            borderRadius: 12,
                                            padding: 8,
                                            background: '#fafafa',
                                            boxShadow: '0 2px 8px rgba(0,0,0,0.08)',
                                            transition: 'all 0.3s',
                                            textAlign: 'center',
                                        }}
                                        onMouseEnter={(e) => {
                                            e.currentTarget.style.transform = 'translateY(-3px)';
                                            e.currentTarget.style.boxShadow = '0 6px 20px rgba(102,126,234,0.3)';
                                        }}
                                        onMouseLeave={(e) => {
                                            e.currentTarget.style.transform = 'translateY(0)';
                                            e.currentTarget.style.boxShadow = '0 2px 8px rgba(0,0,0,0.08)';
                                        }}
                                    >
                                        <img
                                            src={`/storage/${img.FilePath}`}
                                            alt={img.FileName}
                                            style={{
                                                width: '100%',
                                                height: 110,
                                                objectFit: 'cover',
                                                borderRadius: 8,
                                                display: 'block',
                                            }}
                                        />
                                        <Text
                                            type="secondary"
                                            style={{
                                                fontSize: 11,
                                                display: 'block',
                                                marginTop: 6,
                                                overflow: 'hidden',
                                                textOverflow: 'ellipsis',
                                                whiteSpace: 'nowrap',
                                            }}
                                        >
                                            {img.FileName}
                                        </Text>
                                    </div>
                                </a>
                            </Col>
                        ))}
                    </Row>
                </Card>
            ) : null}

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