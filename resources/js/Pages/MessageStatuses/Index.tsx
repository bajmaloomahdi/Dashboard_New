import { useState, useEffect, useRef } from 'react';
import {
    Card,
    Button,
    Input,
    Space,
    Tag,
    Tooltip,
    Typography,
    Row,
    Col,
    Popconfirm,
} from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    FlagOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import MessageStatusFormModal from './MessageStatusFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface MessageStatus {
    MessageStatusID: number;
    MessageStatusCode: number;
    MessageStatusName: string;
    Description: string | null;
    IsActive: boolean | number;
}

export default function MessageStatusesIndex() {
    const { messageStatuses, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState('');
    const [modalOpen, setModalOpen] = useState(false);
    const [editingStatus, setEditingStatus] = useState<MessageStatus | null>(null);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

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

    const handleCreate = () => {
        setEditingStatus(null);
        setModalOpen(true);
    };

    const handleEdit = (status: MessageStatus) => {
        setEditingStatus(status);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingStatus(null);
    };

    const handleToggleActive = (id: number) => {
        router.post(`/message-statuses/${id}/toggle`, {}, { preserveScroll: true });
    };

    const filteredStatuses = searchText
        ? messageStatuses.filter((s: MessageStatus) =>
            String(s.MessageStatusCode).includes(searchText) ||
            (s.MessageStatusName || '').toLowerCase().includes(searchText.toLowerCase()) ||
            (s.Description || '').toLowerCase().includes(searchText.toLowerCase())
        )
        : messageStatuses;

    const customColumns: ColumnsType<MessageStatus> = [
        {
            title: 'کد',
            dataIndex: 'MessageStatusCode',
            key: 'MessageStatusCode',
            width: 100,
            align: 'center',
            render: (code: number) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
        {
            title: 'وضعیت',
            key: 'status',
            align: 'center',
            render: (_, record: MessageStatus) => (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    minWidth: 220,
                    justifyContent: 'flex-start',
                }}>
                    <div style={STYLES.iconBox}>
                        <FlagOutlined style={{ color: '#fff', fontSize: 16 }} />
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.MessageStatusName}</Text>
                        {record.Description && (
                            <Text type="secondary" style={{ fontSize: 11 }}>
                                {record.Description.length > 60
                                    ? record.Description.substring(0, 60) + '...'
                                    : record.Description}
                            </Text>
                        )}
                    </div>
                </div>
            ),
        },
        {
            title: 'وضعیت فعالیت',
            key: 'isActive',
            width: 120,
            align: 'center',
            render: (_, record: MessageStatus) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                return (
                    <Tag
                        icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />}
                        color={isActive ? 'success' : 'default'}
                        style={{ borderRadius: 6 }}
                    >
                        {isActive ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 130,
            align: 'center',
            render: (_, record: MessageStatus) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                return (
                    <Space>
                        <Tooltip title="ویرایش">
                            <Button
                                type="text"
                                icon={<EditOutlined />}
                                style={{ color: THEME.info }}
                                onClick={() => handleEdit(record)}
                            />
                        </Tooltip>
                        <Popconfirm
                            title={isActive ? 'غیرفعال کردن وضعیت' : 'فعال کردن وضعیت'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.MessageStatusID)}
                            okText="بله"
                            cancelText="خیر"
                            okButtonProps={{ danger: isActive }}
                        >
                            <Tooltip title={isActive ? 'غیرفعال کردن' : 'فعال کردن'}>
                                <Button
                                    type="text"
                                    icon={isActive ? <StopOutlined /> : <CheckCircleOutlined />}
                                    style={{ color: isActive ? THEME.error : THEME.success }}
                                />
                            </Tooltip>
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <MainLayout>
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Title level={3} style={{ margin: 0 }}>
                        <FlagOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                        وضعیت‌های پیام
                    </Title>
                    <Text type="secondary">
                        تعریف وضعیت‌های پیام (ارسال شده، در حال انجام، انجام شده، ...)
                    </Text>
                </Col>
                <Col>
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        size="large"
                        style={STYLES.primaryButton}
                        onClick={handleCreate}
                    >
                        وضعیت جدید
                    </Button>
                </Col>
            </Row>

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={16} md={16}>
                        <Input
                            placeholder="جستجو در کد، نام وضعیت..."
                            prefix={<SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={8} md={8}>
                        <Button icon={<ReloadOutlined />} onClick={() => setSearchText('')} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={filteredStatuses}
                    customColumns={customColumns}
                    rowKey="MessageStatusID"
                    showColumnSearch={false}
                />
            </Card>

            <MessageStatusFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingStatus={editingStatus}
            />

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}