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
    Popconfirm,
} from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    IdcardOutlined,
    CrownOutlined,
    LoadingOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import PositionFormModal from './PositionFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Position {
    PositionID: number;
    PositionCode: string;
    PositionName: string;
    UnitID: number;
    UnitName: string;
    IsUnitManager: boolean | number;
    ParentPositionID: number | null;
    ParentPositionDisplay: string | null;
    Description: string | null;
    IsActive: boolean | number;
}

interface Unit {
    UnitID: number;
    UnitName: string;
}

export default function PositionsIndex() {
    const { positions, units, filters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [unitFilter, setUnitFilter] = useState<string | null>(filters?.unit_id || null);
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.is_active || null);
    const [searching, setSearching] = useState(false);
    const [modalOpen, setModalOpen] = useState(false);
    const [editingPosition, setEditingPosition] = useState<Position | null>(null);

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
        setSearching(true);
        if (searchTimeoutRef.current) {
            clearTimeout(searchTimeoutRef.current);
        }
        searchTimeoutRef.current = setTimeout(() => {
            router.get('/positions', {
                search: searchText || undefined,
                unit_id: unitFilter !== null && unitFilter !== '' ? unitFilter : undefined,
                is_active: statusFilter !== null && statusFilter !== '' ? statusFilter : undefined,
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
    }, [searchText, unitFilter, statusFilter]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    const handleReset = () => {
        setSearchText('');
        setUnitFilter(null);
        setStatusFilter(null);
    };

    const handleToggleActive = (positionId: number) => {
        router.post(`/positions/${positionId}/toggle`, {}, { preserveScroll: true });
    };

    const handleCreate = () => {
        setEditingPosition(null);
        setModalOpen(true);
    };

    const handleEdit = (position: Position) => {
        setEditingPosition(position);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingPosition(null);
    };

    const customColumns: ColumnsType<Position> = [
        {
            title: 'کد',
            dataIndex: 'PositionCode',
            key: 'PositionCode',
            width: 100,
            align: 'center',
            render: (code: string) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
        {
            title: 'سمت',
            key: 'position',
            align: 'center',
            render: (_, record: Position) => {
                const isManager = columnHelpers.toBool(record.IsUnitManager);
                return (
                    <div style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 10,
                        minWidth: 220,
                        justifyContent: 'flex-start',
                    }}>
                        <div style={STYLES.iconBox}>
                            <IdcardOutlined style={{ color: '#fff', fontSize: 16 }} />
                        </div>
                        <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                            <Space size={6}>
                                <Text strong>{record.PositionName}</Text>
                                {isManager && (
                                    <Tag
                                        icon={<CrownOutlined />}
                                        color="gold"
                                        style={{ borderRadius: 6, marginInlineEnd: 0 }}
                                    >
                                        مدیر
                                    </Tag>
                                )}
                            </Space>
                            {record.Description && (
                                <Text type="secondary" style={{ fontSize: 11 }}>
                                    {record.Description.length > 60
                                        ? record.Description.substring(0, 60) + '...'
                                        : record.Description}
                                </Text>
                            )}
                        </div>
                    </div>
                );
            },
        },
        {
            title: 'واحد',
            key: 'unit',
            width: 180,
            align: 'center',
            render: (_, record: Position) =>
                record.UnitName ? (
                    <Tag color="geekblue" style={{ borderRadius: 6 }}>{record.UnitName}</Tag>
                ) : (
                    <Text type="secondary">—</Text>
                ),
        },
        {
            title: 'سمت والد',
            dataIndex: 'ParentPositionDisplay',
            key: 'ParentPositionDisplay',
            width: 200,
            align: 'center',
            render: (parent: string | null) =>
                parent ? <Text>{parent}</Text> : <Text type="secondary">—</Text>,
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, record: Position) => {
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
            render: (_, record: Position) => {
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
                            title={isActive ? 'غیرفعال کردن سمت' : 'فعال کردن سمت'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.PositionID)}
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
            <PageHeader
                icon={<IdcardOutlined />}
                title="سمت‌ها"
                subtitle="مدیریت سمت‌های سازمانی و جایگاه‌های شغلی"
                stats={[
                    { icon: <IdcardOutlined />, label: 'تعداد کل', value: `${(positions || []).length} سمت` },
                    {
                        icon: <CheckCircleOutlined />,
                        label: 'فعال',
                        value: `${(positions || []).filter((p: Position) => columnHelpers.toBool(p.IsActive)).length} سمت`,
                    },
                    {
                        icon: <CrownOutlined />,
                        label: 'مدیر واحد',
                        value: `${(positions || []).filter((p: Position) => columnHelpers.toBool(p.IsUnitManager)).length} سمت`,
                    },
                ]}
                actions={
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        size="large"
                        style={STYLES.primaryButton}
                        onClick={handleCreate}
                    >
                        سمت جدید
                    </Button>
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={10}>
                        <Input
                            placeholder="جستجوی زنده در کد، نام سمت، واحد..."
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
                            placeholder="فیلتر واحد"
                            style={{ width: '100%' }}
                            size="large"
                            value={unitFilter}
                            onChange={(value) => setUnitFilter(value)}
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            options={(units || []).map((u: Unit) => ({
                                value: String(u.UnitID),
                                label: u.UnitName,
                            }))}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={4}>
                        <Select
                            placeholder="وضعیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={statusFilter}
                            onChange={(value) => setStatusFilter(value)}
                            allowClear
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
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
                    dataSource={positions}
                    loading={searching}
                    customColumns={customColumns}
                    rowKey="PositionID"
                    showColumnSearch={false}
                />
            </Card>

            <PositionFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingPosition={editingPosition}
                positions={positions}
                units={units}
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