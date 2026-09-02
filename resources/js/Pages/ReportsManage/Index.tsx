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
    BarChartOutlined,
    DatabaseOutlined,
    LoadingOutlined,
    FileExcelOutlined,
    SettingOutlined,
    TeamOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
import ReportFormModal from './ReportFormModal';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Report {
    ReportID: number;
    ReportCode: string;
    ReportTitle: string;
    ProcedureName: string;
    MenuID: number;
    MenuTitle: string;
    CommandTimeout: number;
    AllowExcel: boolean | number;
    AllowPdf: boolean | number;
    AllowPrint: boolean | number;
    CacheDuration: number;
    Description: string | null;
    IsActive: boolean | number;
    CreateDate: string;
    ParametersCount: number;
    RolesCount: number;
}

export default function ReportsManageIndex() {
    const { reports, availableMenus, filters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(filters?.is_active || null);
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingReport, setEditingReport] = useState<Report | null>(null);

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
            router.get('/reports-manage', {
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

    const handleToggleActive = (reportId: number) => {
        router.post(`/reports-manage/${reportId}/toggle`, {}, { preserveScroll: true });
    };

    const handleCreate = () => {
        setEditingReport(null);
        setModalOpen(true);
    };

    const handleEdit = (report: Report) => {
        setEditingReport(report);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditingReport(null);
    };

    // ستون‌های سفارشی - همه align: center
    const customColumns: ColumnsType<Report> = [
        {
            title: 'کد',
            dataIndex: 'ReportCode',
            key: 'ReportCode',
            width: 100,
            align: 'center',
            render: (code: string) => (
                <span style={STYLES.codeBadge}>{code}</span>
            ),
        },
        {
            title: 'عنوان گزارش',
            key: 'title',
            align: 'center',
            render: (_, record: Report) => (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 10,
                    minWidth: 250,
                    justifyContent: 'flex-start',
                }}>
                    <div style={STYLES.iconBox}>
                        <BarChartOutlined style={{ color: '#fff', fontSize: 16 }} />
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.ReportTitle}</Text>
                        {record.Description && (
                            <Text type="secondary" style={{ fontSize: 11 }}>
                                {record.Description.length > 50
                                    ? record.Description.substring(0, 50) + '...'
                                    : record.Description}
                            </Text>
                        )}
                    </div>
                </div>
            ),
        },
        {
            title: 'Stored Procedure',
            dataIndex: 'ProcedureName',
            key: 'ProcedureName',
            width: 180,
            align: 'center',
            render: (name: string) => (
                <Space size={4}>
                    <DatabaseOutlined style={{ color: '#8c8c8c', fontSize: 12 }} />
                    <Text code style={{ fontSize: 11 }} dir="ltr">
                        {name}
                    </Text>
                </Space>
            ),
        },
        {
            title: 'منوی مربوطه',
            dataIndex: 'MenuTitle',
            key: 'MenuTitle',
            width: 180,
            align: 'center',
            render: (menu: string | null) => menu ? (
                <Tag color="cyan" style={{ borderRadius: 6 }}>{menu}</Tag>
            ) : (
                <Text type="secondary">-</Text>
            ),
        },
        {
            title: 'پارامترها',
            dataIndex: 'ParametersCount',
            key: 'ParametersCount',
            width: 100,
            align: 'center',
            render: (count: number) => (
                <Tag color={count > 0 ? 'blue' : 'default'} style={{ borderRadius: 6 }}>
                    {count} پارامتر
                </Tag>
            ),
        },
        {
            title: 'نقش‌ها',
            dataIndex: 'RolesCount',
            key: 'RolesCount',
            width: 100,
            align: 'center',
            render: (count: number) => (
                <Tooltip title="تعداد نقش‌های دارای دسترسی">
                    <Tag icon={<TeamOutlined />} color={count > 0 ? 'green' : 'default'} style={{ borderRadius: 6 }}>
                        {count}
                    </Tag>
                </Tooltip>
            ),
        },
        {
            title: 'خروجی',
            key: 'outputs',
            width: 100,
            align: 'center',
            render: (_, record: Report) => (
                <Space size={4}>
                    {columnHelpers.toBool(record.AllowExcel) && (
                        <Tooltip title="Excel">
                            <FileExcelOutlined style={{ color: THEME.success, fontSize: 16 }} />
                        </Tooltip>
                    )}
                </Space>
            ),
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, record: Report) => {
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
            width: 160,
            align: 'center',
            render: (_, record: Report) => {
                const isActive = columnHelpers.toBool(record.IsActive);
                return (
                    <Space>
                        <Tooltip title="مدیریت پارامترها">
                            <Button
                                type="text"
                                icon={<SettingOutlined />}
                                style={{ color: THEME.warning }}
                                onClick={() => router.visit(`/reports-manage/${record.ReportID}/parameters`)}
                            />
                        </Tooltip>
                        <Tooltip title="ویرایش">
                            <Button
                                type="text"
                                icon={<EditOutlined />}
                                style={{ color: THEME.info }}
                                onClick={() => handleEdit(record)}
                            />
                        </Tooltip>
                        <Popconfirm
                            title={isActive ? 'غیرفعال کردن گزارش' : 'فعال کردن گزارش'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggleActive(record.ReportID)}
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
                icon={<BarChartOutlined />}
                title="مدیریت گزارشات"
                subtitle="تعریف و مدیریت گزارشات سیستم"
                stats={[
                    { icon: <BarChartOutlined />, label: 'تعداد کل', value: `${(reports || []).length} گزارش` },
                    {
                        icon: <CheckCircleOutlined />,
                        label: 'فعال',
                        value: `${(reports || []).filter((r: Report) => columnHelpers.toBool(r.IsActive)).length} گزارش`,
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
                        گزارش جدید
                    </Button>
                }
            />

            {/* فیلترها - همرنگ هدر جدول */}
            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={12}>
                        <Input
                            placeholder="جستجوی زنده در عنوان، کد، SP..."
                            prefix={
                                searching
                                    ? <LoadingOutlined style={{ color: THEME.primary }} />
                                    : <SearchOutlined />
                            }
                            suffix={
                                searching ? (
                                    <Text type="secondary" style={{ fontSize: 12 }}>
                                        در حال جستجو...
                                    </Text>
                                ) : null
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
                    dataSource={reports}
                    loading={searching}
                    customColumns={customColumns}
                    rowKey="ReportID"
                    showColumnSearch={false}
                />
            </Card>

            {/* مودال ایجاد/ویرایش */}
            <ReportFormModal
                open={modalOpen}
                onClose={handleCloseModal}
                editingReport={editingReport}
                availableMenus={availableMenus || []}
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