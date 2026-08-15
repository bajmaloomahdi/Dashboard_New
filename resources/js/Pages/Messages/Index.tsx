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
    Select,
    Tabs,
} from 'antd';
import {
    SearchOutlined,
    ReloadOutlined,
    InboxOutlined,
    SendOutlined,
    MessageOutlined,
    CopyOutlined,
    LoadingOutlined,
    EyeOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Message {
    MessageID: number;
    MessageNumber: string | null;
    Subject: string;
    MessageText: string | null;
    MessageTypeID: number;
    MessageTypeName: string;
    SenderUserID: number;
    SenderName: string;
    CreateDate: string;
    MessageStatusID: number;
    MessageStatusName: string;
    IsCopy: boolean | number;
}

interface FilterItem {
    MessageTypeID: number;
    MessageTypeName: string;
}

interface StatusItem {
    MessageStatusID: number;
    MessageStatusName: string;
}

export default function MessagesIndex() {
    const { messages, mode, messageTypes, messageStatuses, filters, flash } = usePage().props as any;

    const [activeMode, setActiveMode] = useState<string>(mode === 'sent' ? 'sent' : 'inbox');
    const [searchText, setSearchText] = useState(filters?.search || '');
    const [typeFilter, setTypeFilter] = useState<string | null>(filters?.message_type_id || null);
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.message_status_id || null);
    const [searching, setSearching] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        router.visit(activeMode === 'sent' ? '/messages/sent' : '/messages', {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }, [activeMode]);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        setSearching(true);
        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current);
        }
        searchTimeoutRef.current = setTimeout(() => {
            const base = activeMode === 'sent' ? '/messages/sent' : '/messages';
            router.get(base, {
                search: searchText || undefined,
                message_type_id: typeFilter !== null && typeFilter !== '' ? typeFilter : undefined,
                message_status_id: statusFilter !== null && statusFilter !== '' ? statusFilter : undefined,
            }, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setSearching(false),
            });
        }, 300);
        return () => {
            if (searchTimeoutRef.current) {
                clearTimeout(searchTimeoutRef.current);
            }
        };
    }, [searchText, typeFilter, statusFilter]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    const handleReset = () => {
        setSearchText('');
        setTypeFilter(null);
        setStatusFilter(null);
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

    const customColumns: ColumnsType<Message> = [
        {
            title: 'شماره',
            dataIndex: 'MessageNumber',
            key: 'MessageNumber',
            width: 150,
            align: 'center',
            render: (number: string | null) =>
                number ? <span style={STYLES.codeBadge}>{number}</span> : <Text type="secondary">—</Text>,
        },
        {
            title: 'نوع',
            dataIndex: 'MessageTypeName',
            key: 'MessageTypeName',
            width: 110,
            align: 'center',
            render: (name: string) => (
                <Tag color={typeColor(name)} style={{ borderRadius: 6 }}>
                    {name}
                </Tag>
            ),
        },
        {
            title: 'موضوع',
            key: 'subject',
            align: 'center',
            render: (_, record: Message) => (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    minWidth: 250,
                    justifyContent: 'flex-start',
                }}>
                    <div style={STYLES.iconBox}>
                        <MessageOutlined style={{ color: '#fff', fontSize: 16 }} />
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Space size={6}>
                            <Text strong>{record.Subject}</Text>
                            {record.IsCopy === 1 || record.IsCopy === true ? (
                                <Tag
                                    icon={<CopyOutlined />}
                                    color="cyan"
                                    style={{ borderRadius: 6, marginInlineEnd: 0 }}
                                >
                                    رونوشت
                                </Tag>
                            ) : null}
                        </Space>
                        {record.MessageText && (
                            <Text type="secondary" style={{ fontSize: 11 }}>
                                {record.MessageText.length > 70
                                    ? record.MessageText.substring(0, 70) + '...'
                                    : record.MessageText}
                            </Text>
                        )}
                    </div>
                </div>
            ),
        },
        {
            title: 'فرستنده',
            dataIndex: 'SenderName',
            key: 'SenderName',
            width: 150,
            align: 'center',
        },
        {
            title: 'تاریخ',
            dataIndex: 'CreateDate',
            key: 'CreateDate',
            width: 150,
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
            title: 'وضعیت',
            dataIndex: 'MessageStatusName',
            key: 'MessageStatusName',
            width: 130,
            align: 'center',
            render: (name: string) => (
                <Tag color={statusColor(name)} style={{ borderRadius: 6 }}>
                    {name}
                </Tag>
            ),
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 90,
            align: 'center',
            render: (_, record: Message) => (
                <Tooltip title="مشاهده جزئیات">
                    <Button
                        type="text"
                        icon={<EyeOutlined />}
                        style={{ color: THEME.info }}
                        onClick={() => router.visit(`/messages/${record.MessageID}`)}
                    />
                </Tooltip>
            ),
        },
    ];

    return (
        <MainLayout>
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Title level={3} style={{ margin: 0 }}>
                        <InboxOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                        کارتابل پیام‌ها
                    </Title>
                    <Text type="secondary">
                        پیام‌های وارده و صادره
                    </Text>
                </Col>
            </Row>

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Tabs
                    activeKey={activeMode}
                    onChange={setActiveMode}
                    items={[
                        {
                            key: 'inbox',
                            label: (
                                <span>
                                    <InboxOutlined /> وارده
                                </span>
                            ),
                        },
                        {
                            key: 'sent',
                            label: (
                                <span>
                                    <SendOutlined /> صادره
                                </span>
                            ),
                        },
                    ]}
                />

                <Row gutter={[16, 16]} align="middle" style={{ marginTop: 8 }}>
                    <Col xs={24} sm={12} md={8}>
                        <Input
                            placeholder="جستجو در موضوع یا شماره..."
                            prefix={
                                searching
                                    ? <LoadingOutlined style={{ color: THEME.primary }} />
                                    : <SearchOutlined />
                            }
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Select
                            placeholder="نوع پیام"
                            style={{ width: '100%' }}
                            size="large"
                            value={typeFilter}
                            onChange={(value) => setTypeFilter(value)}
                            allowClear
                            options={(messageTypes || []).map((t: FilterItem) => ({
                                value: String(t.MessageTypeID),
                                label: t.MessageTypeName,
                            }))}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={6}>
                        <Select
                            placeholder="وضعیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={statusFilter}
                            onChange={(value) => setStatusFilter(value)}
                            allowClear
                            options={(messageStatuses || []).map((s: StatusItem) => ({
                                value: String(s.MessageStatusID),
                                label: s.MessageStatusName,
                            }))}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={4}>
                        <Button icon={<ReloadOutlined />} onClick={handleReset} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={messages}
                    loading={searching}
                    customColumns={customColumns}
                    rowKey="MessageID"
                    showColumnSearch={false}
                />
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