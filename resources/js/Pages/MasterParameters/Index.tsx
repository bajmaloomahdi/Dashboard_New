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
    SettingOutlined,
    DatabaseOutlined,
    LoadingOutlined,
    LinkOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import MasterParameterFormModal from './MasterParameterFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface MasterParameter {
    MasterParameterID: number;
    ParameterName: string;
    ParameterCaption: string;
    DataType: string;
    ControlType: string;
    LookupProcedure: string | null;
    Description: string | null;
    IsActive: boolean | number;
    CreateDate: string;
    UsageCount: number;
}

const getDataTypeColor = (type: string): string => {
    const colors: Record<string, string> = {
        STRING: 'blue',
        INT: 'green',
        BIGINT: 'green',
        DECIMAL: 'cyan',
        DATE: 'purple',
        DATETIME: 'purple',
        BIT: 'orange',
    };
    return colors[type] || 'default';
};

const getControlTypeLabel = (type: string): { emoji: string; label: string } => {
    const map: Record<string, { emoji: string; label: string }> = {
        TEXTBOX: { emoji: '📝', label: 'تکست باکس' },
        NUMBER: { emoji: '🔢', label: 'عدد' },
        DATE: { emoji: '📅', label: 'تاریخ' },
        SELECT: { emoji: '📋', label: 'لیست کشویی' },
        MULTISELECT: { emoji: '☑', label: 'چند انتخابی' },
        CHECKBOX: { emoji: '✅', label: 'چک باکس' },
    };
    return map[type] || { emoji: '❓', label: type };
};

export default function MasterParametersIndex() {
    const { masterParameters, filters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.is_active || null);
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingParameter, setEditingParameter] = useState<MasterParameter | null>(null);

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
            router.get('/master-parameters', {
                search: searchText || undefined,
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
    }, [searchText, statusFilter]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => {
        setNotification((prev) => ({ ...prev, open: false }));
    };

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
    };

    const handleToggleActive = (paramId: number) => {
        router.post(`/master-parameters/${paramId}/toggle`, {}, { preserveScroll: true });
    };

    const handleCreate = () => {
        setEditingParameter(null);
        setModalOpen(true);
    };

    const handleEdit = (param: MasterParameter) => {
        setEditingParameter(param);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingParameter(null);
    };

    // ستون‌های سفارشی - همه align: center
    const customColumns: ColumnsType<MasterParameter> = [
        {
            title: 'شناسه',
            dataIndex: 'MasterParameterID',
            key: 'MasterParameterID',
            width: 80,
            align: 'center',
            render: (id: number) => (
                <div style={STYLES.rowNumber}>
                    {id}
                </div>
            ),
        },
        {
            title: 'نام پارامتر',
            dataIndex: 'ParameterName',
            key: 'ParameterName',
            width: 180,
            align: 'center',
            render: (name: string) => (
                <span style={STYLES.codeBadge}>
                    {name}
                </span>
            ),
        },
        {
            title: 'عنوان نمایشی',
            key: 'caption',
            align: 'center',
            render: (_, record) => (
                <div style={{
                    display: 'inline-flex',
                    flexDirection: 'column',
                    minWidth: 200,
                    textAlign: 'right',
                }}>
                    <Text strong style={{ color: THEME.textPrimary }}>{record.ParameterCaption}</Text>
                    {record.Description && (
                        <Text type="secondary" style={{ fontSize: 11 }}>
                            {record.Description}
                        </Text>
                    )}
                </div>
            ),
        },
        {
            title: 'نوع داده',
            dataIndex: 'DataType',
            key: 'DataType',
            width: 120,
            align: 'center',
            render: (type: string) => (
                <Tag color={getDataTypeColor(type)} style={{ fontWeight: 600, borderRadius: 6 }}>
                    {type}
                </Tag>
            ),
        },
        {
            title: 'نوع کنترل',
            dataIndex: 'ControlType',
            key: 'ControlType',
            width: 160,
            align: 'center',
            render: (type: string) => {
                const info = getControlTypeLabel(type);
                return (
                    <Tooltip title={type}>
                        <Tag color="purple" style={{ padding: '4px 10px', fontSize: 12, borderRadius: 6 }}>
                            {info.emoji} {info.label}
                        </Tag>
                    </Tooltip>
                );
            },
        },
        {
            title: 'Lookup SP',
            dataIndex: 'LookupProcedure',
            key: 'LookupProcedure',
            width: 160,
            align: 'center',
            render: (sp: string | null) => sp ? (
                <Space size={4}>
                    <DatabaseOutlined style={{ color: '#8c8c8c', fontSize: 12 }} />
                    <Text code style={{ fontSize: 11 }} dir="ltr">{sp}</Text>
                </Space>
            ) : (
                <Text type="secondary">-</Text>
            ),
        },
        {
            title: 'استفاده',
            dataIndex: 'UsageCount',
            key: 'UsageCount',
            width: 100,
            align: 'center',
            render: (count: number) => (
                <Tooltip title="تعداد گزارشاتی که این پارامتر رو استفاده می‌کنن">
                    <Tag
                        icon={<LinkOutlined />}
                        color={count > 0 ? 'green' : 'default'}
                        style={{ fontWeight: 600, borderRadius: 6 }}
                    >
                        {count}
                    </Tag>
                </Tooltip>
            ),
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, record) => {
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
            width: 120,
            align: 'center',
            render: (_, record) => {
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
                            title={isActive ? 'غیرفعال کردن پارامتر' : 'فعال کردن پارامتر'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.MasterParameterID)}
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

    const totalCount = masterParameters?.length || 0;
    const activeCount = masterParameters?.filter((p: MasterParameter) => columnHelpers.toBool(p.IsActive)).length || 0;
    const usedCount = masterParameters?.filter((p: MasterParameter) => p.UsageCount > 0).length || 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<SettingOutlined />}
                title="مدیریت پارامترهای Master"
                subtitle="تعریف پارامترهای عمومی برای استفاده در گزارشات"
                stats={[
                    { icon: <SettingOutlined />, label: 'کل پارامترها', value: `${totalCount} پارامتر` },
                    { icon: <CheckCircleOutlined />, label: 'فعال', value: `${activeCount} پارامتر` },
                    { icon: <LinkOutlined />, label: 'در حال استفاده', value: `${usedCount} پارامتر` },
                ]}
                actions={
                    <Button
                        type="primary"
                        icon={<PlusOutlined />}
                        size="large"
                        style={STYLES.primaryButton}
                        onClick={handleCreate}
                    >
                        پارامتر جدید
                    </Button>
                }
            />

            {/* فیلترها - همرنگ هدر جدول */}
            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={12}>
                        <Input
                            placeholder="جستجو در نام، عنوان، توضیحات..."
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
                    <Col xs={24} sm={12} md={8}>
                        <Select
                            placeholder="فیلتر وضعیت"
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
                    <Col xs={24} sm={24} md={4}>
                        <Button icon={<ReloadOutlined />} onClick={handleReset} size="large" block>
                            بازنشانی
                        </Button>
                    </Col>
                </Row>
            </Card>

            {/* جدول با DataGrid یکپارچه */}
            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={masterParameters}
                    loading={searching}
                    customColumns={customColumns}
                    rowKey="MasterParameterID"
                    showColumnSearch={false}
                    showRowNumber={false}
                    pageSize={15}
                />
            </Card>

            {/* مودال ایجاد/ویرایش */}
            <MasterParameterFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingParameter={editingParameter}
            />

            {/* مودال اعلان */}
            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />
        </MainLayout>
    );
}