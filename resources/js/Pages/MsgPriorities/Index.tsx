import { useState, useEffect, useMemo } from 'react';
import {
    Card,
    Button,
    Input,
    Select,
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
    ThunderboltOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import MsgPriorityFormModal from './MsgPriorityFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { getPriorityPalette } from '../../Components/PriorityTag';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface MsgPriority {
    msgPriorityID: number;
    Code: number;
    Name: string;
    Description: string | null;
    SortOrder: number;
    IsActive: boolean | number;
    Date_InsertFirst?: string | null;
    UserID_InsertFirst?: number | null;
}

export default function MsgPrioritiesIndex() {
    const { priorities, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState('');
    const [activeFilter, setActiveFilter] = useState<string | undefined>(undefined);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingPriority, setEditingPriority] = useState<MsgPriority | null>(null);

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
        setEditingPriority(null);
        setModalOpen(true);
    };

    const handleEdit = (priority: MsgPriority) => {
        setEditingPriority(priority);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingPriority(null);
    };

    const handleToggleActive = (id: number) => {
        router.post(`/msg-priorities/${id}/toggle`, {}, { preserveScroll: true });
    };

    const handleReset = () => {
        setSearchText('');
        setActiveFilter(undefined);
    };

    const filteredPriorities = (priorities || []).filter((p: MsgPriority) => {
        const matchSearch = searchText
            ? String(p.Code).includes(searchText) ||
              (p.Name || '').toLowerCase().includes(searchText.toLowerCase()) ||
              (p.Description || '').toLowerCase().includes(searchText.toLowerCase())
            : true;

        const matchActive =
            activeFilter === undefined || activeFilter === ''
                ? true
                : columnHelpers.toBool(p.IsActive) === (activeFilter === '1');

        return matchSearch && matchActive;
    });

    /** بیشترین ترتیب نمایش — برای محاسبه رنگ نسبی اولویت‌ها */
    const maxPrioritySort = useMemo(
        () => Math.max(1, ...(priorities || []).map((p: MsgPriority) => p.SortOrder || 0)),
        [priorities]
    );

    const customColumns: ColumnsType<MsgPriority> = [
        {
            title: 'کد',
            dataIndex: 'Code',
            key: 'Code',
            width: 100,
            align: 'center',
            render: (code: number) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
        {
            title: 'اولویت',
            key: 'priority',
            align: 'center',
            render: (_, record: MsgPriority) => (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    minWidth: 220,
                    justifyContent: 'flex-start',
                }}>
                    <div
                        style={{
                            ...STYLES.iconBox,
                            background: getPriorityPalette(record.SortOrder, maxPrioritySort).gradient,
                            boxShadow: `0 2px 8px ${getPriorityPalette(record.SortOrder, maxPrioritySort).shadow}`,
                        }}
                    >
                        <ThunderboltOutlined style={{ color: '#fff', fontSize: 16 }} />
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.Name}</Text>
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
            title: 'ترتیب نمایش',
            dataIndex: 'SortOrder',
            key: 'SortOrder',
            width: 120,
            align: 'center',
            sorter: (a: MsgPriority, b: MsgPriority) => a.SortOrder - b.SortOrder,
            defaultSortOrder: 'ascend',
            render: (sortOrder: number) => {
                const palette = getPriorityPalette(sortOrder, maxPrioritySort);
                return (
                    <span
                        style={{
                            display: 'inline-block',
                            minWidth: 30,
                            padding: '2px 10px',
                            borderRadius: 8,
                            fontWeight: 700,
                            fontSize: 12,
                            color: palette.color,
                            background: palette.bg,
                            border: `1px solid ${palette.border}`,
                        }}
                    >
                        {sortOrder}
                    </span>
                );
            },
        },
        {
            title: 'وضعیت فعالیت',
            key: 'isActive',
            width: 120,
            align: 'center',
            render: (_, record: MsgPriority) => {
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
            render: (_, record: MsgPriority) => {
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
                            title={isActive ? 'غیرفعال کردن اولویت' : 'فعال کردن اولویت'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.msgPriorityID)}
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
                        <ThunderboltOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                        اولویت‌های پیام
                    </Title>
                    <Text type="secondary">
                        تعریف اولویت‌های پیام (کم، متوسط، زیاد، فوری، ...)
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
                        اولویت جدید
                    </Button>
                </Col>
            </Row>

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={14}>
                        <Input
                            placeholder="جستجو در کد، نام اولویت..."
                            prefix={<SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={6} md={5}>
                        <Select
                            placeholder="وضعیت فعالیت"
                            value={activeFilter}
                            onChange={(value) => setActiveFilter(value)}
                            allowClear
                            size="large"
                            style={{ width: '100%' }}
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
                        />
                    </Col>
                    <Col xs={24} sm={6} md={5}>
                        <Button icon={<ReloadOutlined />} onClick={handleReset} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={filteredPriorities}
                    customColumns={customColumns}
                    rowKey="msgPriorityID"
                    showColumnSearch={false}
                />
            </Card>

            <MsgPriorityFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingPriority={editingPriority}
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
