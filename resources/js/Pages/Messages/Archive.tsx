import { useState, useEffect, useRef, useMemo } from 'react';
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
} from 'antd';
import {
    SearchOutlined,
    ReloadOutlined,
    InboxOutlined,
    MessageOutlined,
    CopyOutlined,
    LoadingOutlined,
    EyeOutlined,
    ReadOutlined,
    ArrowLeftOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import PriorityTag, { getPriorityPalette } from '../../Components/PriorityTag';
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
    msgPriorityID: number | null;
    PriorityName: string | null;
    PrioritySortOrder: number | null;
    SenderUserID: number;
    SenderName: string;
    CreateDate: string;
    MessageStatusID: number;
    MessageStatusName: string;
    IsCopy: boolean | number;
    IsRead: boolean | number;
}

interface FilterItem {
    MessageTypeID: number;
    MessageTypeName: string;
}

interface StatusItem {
    MessageStatusID: number;
    MessageStatusName: string;
}

interface PriorityItem {
    msgPriorityID: number;
    Name: string;
    SortOrder: number;
    Description?: string | null;
}

export default function MessageArchive() {
    const { messages, messageTypes, messageStatuses, priorities, filters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [typeFilter, setTypeFilter] = useState<string | null>(filters?.message_type_id || null);
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.message_status_id || null);
    const [priorityFilter, setPriorityFilter] = useState<string | null>(filters?.msg_priority_id || null);
    const [searching, setSearching] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    /** بیشترین ترتیب نمایش اولویت — برای محاسبه رنگ نسبی */
    const maxPrioritySort = useMemo(
        () => Math.max(1, ...(priorities || []).map((p: PriorityItem) => p.SortOrder || 0)),
        [priorities]
    );

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

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
            router.get('/messages/archive', {
                search: searchText || undefined,
                message_type_id: typeFilter !== null && typeFilter !== '' ? typeFilter : undefined,
                message_status_id: statusFilter !== null && statusFilter !== '' ? statusFilter : undefined,
                msg_priority_id: priorityFilter !== null && priorityFilter !== '' ? priorityFilter : undefined,
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
    }, [searchText, typeFilter, statusFilter, priorityFilter]);

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
        setPriorityFilter(null);
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
            title: 'اولویت',
            dataIndex: 'PriorityName',
            key: 'PriorityName',
            width: 120,
            align: 'center',
            sorter: (a: Message, b: Message) =>
                (b.PrioritySortOrder || 0) - (a.PrioritySortOrder || 0),
            render: (name: string | null, record: Message) => (
                <PriorityTag
                    name={name}
                    sortOrder={record.PrioritySortOrder}
                    maxSortOrder={maxPrioritySort}
                />
            ),
        },
        {
            title: 'موضوع',
            key: 'subject',
            align: 'center',
            render: (_, record: Message) => {
                const isRead = record.IsRead === 1 || record.IsRead === true;
                const palette = getPriorityPalette(record.PrioritySortOrder, maxPrioritySort);

                return (
                    <div style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 10,
                        minWidth: 250,
                        justifyContent: 'flex-start',
                    }}>
                        {/* آیکون با رنگ اولویت */}
                        <div
                            style={{
                                ...STYLES.iconBox,
                                background: record.PriorityName ? palette.gradient : STYLES.iconBox.background,
                                boxShadow: record.PriorityName
                                    ? `0 2px 8px ${palette.shadow}`
                                    : STYLES.iconBox.boxShadow,
                            }}
                        >
                            <MessageOutlined style={{ color: '#fff', fontSize: 16 }} />
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                            <Space size={6}>
                                <Text strong style={{ fontWeight: isRead ? 400 : 700 }}>
                                    {record.Subject}
                                </Text>
                                {record.IsCopy === 1 || record.IsCopy === true ? (
                                    <Tag icon={<CopyOutlined />} color="cyan" style={{ borderRadius: 6, marginInlineEnd: 0 }}>
                                        رونوشت
                                    </Tag>
                                ) : null}
                                {!isRead && (
                                    <Tag icon={<ReadOutlined />} color="blue" style={{ borderRadius: 6, marginInlineEnd: 0 }}>
                                        نخوانده
                                    </Tag>
                                )}
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
                );
            },
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
                                <InboxOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                                آرشیو پیام‌ها
                            </Title>
                            <Text type="secondary">
                                تمام پیام‌های دریافتی (خوانده و نخوانده)
                            </Text>
                        </div>
                    </Space>
                </Col>
            </Row>

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={7}>
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
                    <Col xs={24} sm={12} md={5}>
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
                    <Col xs={24} sm={12} md={4}>
                        <Select
                            placeholder="اولویت"
                            style={{ width: '100%' }}
                            size="large"
                            value={priorityFilter}
                            onChange={(value) => setPriorityFilter(value)}
                            allowClear
                            options={(priorities || []).map((p: PriorityItem) => {
                                const palette = getPriorityPalette(p.SortOrder, maxPrioritySort);
                                return {
                                    value: String(p.msgPriorityID),
                                    label: (
                                        <Space size={6}>
                                            <span
                                                style={{
                                                    width: 9,
                                                    height: 9,
                                                    borderRadius: '50%',
                                                    background: palette.gradient,
                                                    display: 'inline-block',
                                                }}
                                            />
                                            <span style={{ color: palette.color, fontWeight: 600 }}>
                                                {p.Name}
                                            </span>
                                        </Space>
                                    ),
                                };
                            })}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={5}>
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
                    <Col xs={24} sm={12} md={3}>
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
