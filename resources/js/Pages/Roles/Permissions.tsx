import { useState, useEffect, useMemo } from 'react';
import {
    Card,
    Button,
    Input,
    Space,
    Tag,
    Typography,
    Row,
    Col,
    Switch,
    Tabs,
    Statistic,
    Alert,
} from 'antd';
import {
    SearchOutlined,
    SaveOutlined,
    ArrowLeftOutlined,
    SafetyOutlined,
    AppstoreOutlined,
    BarChartOutlined,
    FolderOutlined,
    FileTextOutlined,
    CheckCircleOutlined,
    StopOutlined,
    CheckSquareOutlined,
    BorderOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import DataGrid from '../../Components/DataGrid';
import { THEME, STYLES, columnHelpers } from '../../theme';
import type { ColumnsType } from 'antd/es/table';

const { Title, Text } = Typography;

interface Role {
    RoleID: number;
    RoleCode: string;
    RoleName: string;
    Description: string | null;
}

interface Menu {
    MenuID: number;
    ParentID: number | null;
    MenuCode: string;
    MenuTitle: string;
    MenuKind: string;
    Url: string | null;
    Icon: string | null;
    Level: number;
    SortOrder: number;
    HasAccess: boolean | number;
    ParentTitle: string | null;
}

interface Report {
    ReportID: number;
    ReportCode: string;
    ReportTitle: string;
    ProcedureName: string;
    MenuID: number;
    MenuTitle: string;
    Description: string | null;
    HasAccess: boolean | number;
}

export default function RolePermissions() {
    const { role, roleMenus: menus, roleReports: reports, flash } = usePage().props as any;

    const [activeTab, setActiveTab] = useState('menus');
    const [searchText, setSearchText] = useState('');

    const [selectedMenuIds, setSelectedMenuIds] = useState<Set<number>>(new Set());
    const [selectedReportIds, setSelectedReportIds] = useState<Set<number>>(new Set());

    const [initialMenuIds, setInitialMenuIds] = useState<Set<number>>(new Set());
    const [initialReportIds, setInitialReportIds] = useState<Set<number>>(new Set());

    const [saving, setSaving] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        const menuIds = new Set<number>(
            menus.filter((m: Menu) => columnHelpers.toBool(m.HasAccess)).map((m: Menu) => m.MenuID)
        );
        const reportIds = new Set<number>(
            reports.filter((r: Report) => columnHelpers.toBool(r.HasAccess)).map((r: Report) => r.ReportID)
        );

        setSelectedMenuIds(menuIds);
        setSelectedReportIds(reportIds);
        setInitialMenuIds(menuIds);
        setInitialReportIds(reportIds);
    }, [menus, reports]);

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    /**
     * مرتب‌سازی درختی منوها
     */
    const sortedMenus = useMemo(() => {
        const result: Menu[] = [];

        const rootMenus = menus
            .filter((m: Menu) => m.ParentID === null)
            .sort((a: Menu, b: Menu) => a.SortOrder - b.SortOrder);

        rootMenus.forEach((root: Menu) => {
            result.push(root);
            const children = menus
                .filter((m: Menu) => m.ParentID === root.MenuID)
                .sort((a: Menu, b: Menu) => a.SortOrder - b.SortOrder);
            children.forEach((child: Menu) => {
                result.push(child);
            });
        });

        return result;
    }, [menus]);

    const filteredMenus = useMemo(() => {
        if (!searchText) return sortedMenus;
        const search = searchText.toLowerCase();
        return sortedMenus.filter((m: Menu) =>
            m.MenuTitle?.toLowerCase().includes(search) ||
            m.ParentTitle?.toLowerCase().includes(search)
        );
    }, [sortedMenus, searchText]);

    const filteredReports = useMemo(() => {
        if (!searchText) return reports;
        const search = searchText.toLowerCase();
        return reports.filter((r: Report) =>
            r.ReportTitle?.toLowerCase().includes(search) ||
            r.MenuTitle?.toLowerCase().includes(search)
        );
    }, [reports, searchText]);

    const hasChanges = useMemo(() => {
        if (activeTab === 'menus') {
            if (selectedMenuIds.size !== initialMenuIds.size) return true;
            for (const id of selectedMenuIds) {
                if (!initialMenuIds.has(id)) return true;
            }
            return false;
        } else {
            if (selectedReportIds.size !== initialReportIds.size) return true;
            for (const id of selectedReportIds) {
                if (!initialReportIds.has(id)) return true;
            }
            return false;
        }
    }, [activeTab, selectedMenuIds, selectedReportIds, initialMenuIds, initialReportIds]);

    const handleToggleMenu = (menuId: number, checked: boolean) => {
        const newSet = new Set(selectedMenuIds);
        if (checked) newSet.add(menuId);
        else newSet.delete(menuId);
        setSelectedMenuIds(newSet);
    };

    const handleToggleReport = (reportId: number, checked: boolean) => {
        const newSet = new Set(selectedReportIds);
        if (checked) newSet.add(reportId);
        else newSet.delete(reportId);
        setSelectedReportIds(newSet);
    };

    // انتخاب/عدم انتخاب همه منوها (بر اساس نتایج فیلتر شده)
    const handleSelectAllMenus = (selectAll: boolean) => {
        const newSet = new Set(selectedMenuIds);
        filteredMenus.forEach((m: Menu) => {
            if (selectAll) newSet.add(m.MenuID);
            else newSet.delete(m.MenuID);
        });
        setSelectedMenuIds(newSet);
    };

    // انتخاب/عدم انتخاب همه گزارشات
    const handleSelectAllReports = (selectAll: boolean) => {
        const newSet = new Set(selectedReportIds);
        filteredReports.forEach((r: Report) => {
            if (selectAll) newSet.add(r.ReportID);
            else newSet.delete(r.ReportID);
        });
        setSelectedReportIds(newSet);
    };

    const handleSave = () => {
        setSaving(true);

        if (activeTab === 'menus') {
            router.post(`/roles/${role.RoleID}/menus`, {
                menu_ids: Array.from(selectedMenuIds),
            }, {
                preserveScroll: true,
                preserveState: true,
                only: ['flash', 'roleMenus', 'roleReports', 'menus'],
                onFinish: () => setSaving(false),
            });
        } else {
            router.post(`/roles/${role.RoleID}/reports`, {
                report_ids: Array.from(selectedReportIds),
            }, {
                preserveScroll: true,
                preserveState: true,
                only: ['flash', 'roleMenus', 'roleReports', 'menus'],
                onFinish: () => setSaving(false),
            });
        }
    };

    // بررسی وضعیت انتخاب فعلی (برای دکمه‌های انتخاب همه)
    const allMenusSelected = filteredMenus.length > 0 &&
        filteredMenus.every((m: Menu) => selectedMenuIds.has(m.MenuID));
    const someMenusSelected = filteredMenus.some((m: Menu) => selectedMenuIds.has(m.MenuID));

    const allReportsSelected = filteredReports.length > 0 &&
        filteredReports.every((r: Report) => selectedReportIds.has(r.ReportID));
    const someReportsSelected = filteredReports.some((r: Report) => selectedReportIds.has(r.ReportID));

    // ستون‌های جدول منوها
    const menuColumns: ColumnsType<Menu> = [
        {
            title: 'دسترسی',
            key: 'access',
            width: 100,
            align: 'center',
            render: (_, record: Menu) => (
                <Switch
                    checked={selectedMenuIds.has(record.MenuID)}
                    onChange={(checked) => handleToggleMenu(record.MenuID, checked)}
                    checkedChildren={<CheckCircleOutlined />}
                    unCheckedChildren={<StopOutlined />}
                />
            ),
        },
        {
            title: 'نام منو',
            key: 'title',
            align: 'center',
            render: (_, record: Menu) => {
                const isFolder = record.MenuKind === 'FOLDER';
                const paddingRight = record.Level > 1 ? (record.Level - 1) * 24 : 0;

                return (
                    <div style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                        minWidth: 250,
                        justifyContent: 'flex-start',
                        paddingRight: paddingRight,
                    }}>
                        {isFolder ? (
                            <FolderOutlined style={{ color: '#faad14', fontSize: 16 }} />
                        ) : (
                            <FileTextOutlined style={{ color: THEME.info, fontSize: 16 }} />
                        )}
                        <Text strong={isFolder}>{record.MenuTitle}</Text>
                        {isFolder && (
                            <Tag color="orange" style={{ fontSize: 10, borderRadius: 6 }}>
                                پوشه
                            </Tag>
                        )}
                    </div>
                );
            },
        },
    ];

    // ستون‌های جدول گزارشات
    const reportColumns: ColumnsType<Report> = [
        {
            title: 'دسترسی',
            key: 'access',
            width: 100,
            align: 'center',
            render: (_, record: Report) => (
                <Switch
                    checked={selectedReportIds.has(record.ReportID)}
                    onChange={(checked) => handleToggleReport(record.ReportID, checked)}
                    checkedChildren={<CheckCircleOutlined />}
                    unCheckedChildren={<StopOutlined />}
                />
            ),
        },
        {
            title: 'نام گزارش',
            key: 'title',
            align: 'center',
            render: (_, record: Report) => (
                <div style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 8,
                    minWidth: 250,
                    justifyContent: 'flex-start',
                }}>
                    <BarChartOutlined style={{ color: '#722ed1', fontSize: 16 }} />
                    <div style={{ display: 'flex', flexDirection: 'column', textAlign: 'right' }}>
                        <Text strong>{record.ReportTitle}</Text>
                        {record.Description && (
                            <Text type="secondary" style={{ fontSize: 11 }}>
                                {record.Description}
                            </Text>
                        )}
                    </div>
                </div>
            ),
        },
        {
            title: 'منوی مربوطه',
            dataIndex: 'MenuTitle',
            key: 'MenuTitle',
            width: 200,
            align: 'center',
            render: (menu: string | null) => menu ? (
                <Tag color="cyan" style={{ borderRadius: 6 }}>{menu}</Tag>
            ) : (
                <Text type="secondary">-</Text>
            ),
        },
    ];

    return (
        <MainLayout>
            {/* هدر */}
            <Row justify="space-between" align="middle" style={{ marginBottom: 24 }}>
                <Col>
                    <Space>
                        <Button
                            icon={<ArrowLeftOutlined />}
                            onClick={() => router.visit('/roles')}
                            style={{ borderColor: THEME.primary, color: THEME.primary }}
                        >
                            بازگشت به لیست
                        </Button>
                        <div>
                            <Title level={3} style={{ margin: 0 }}>
                                <SafetyOutlined style={{ marginLeft: 8, color: THEME.primary }} />
                                مدیریت دسترسی‌های نقش
                            </Title>
                            <Text type="secondary">
                                نقش: <Text strong style={{ color: THEME.primary }}>{role.RoleName}</Text>
                                {' '}
                                (کد: {role.RoleCode})
                            </Text>
                        </div>
                    </Space>
                </Col>
                <Col>
                    <Button
                        type="primary"
                        icon={<SaveOutlined />}
                        size="large"
                        loading={saving}
                        disabled={!hasChanges}
                        onClick={handleSave}
                        style={hasChanges ? STYLES.primaryButton : undefined}
                    >
                        ذخیره تغییرات
                    </Button>
                </Col>
            </Row>

            {/* هشدار تغییرات */}
            {hasChanges && (
                <Alert
                    message="تغییرات ذخیره نشده‌اند"
                    description="برای اعمال تغییرات، دکمه ذخیره را کلیک کنید."
                    type="warning"
                    showIcon
                    style={{ marginBottom: 16, borderRadius: 8 }}
                />
            )}

            {/* آمار */}
            <Row gutter={16} style={{ marginBottom: 16 }}>
                <Col xs={12} sm={6}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.primary}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>کل منوها</span>}
                            value={menus.length}
                            prefix={<AppstoreOutlined style={{ color: THEME.primary }} />}
                            valueStyle={{ color: THEME.primary, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.success}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>منوهای فعال</span>}
                            value={selectedMenuIds.size}
                            prefix={<CheckCircleOutlined style={{ color: THEME.success }} />}
                            valueStyle={{ color: THEME.success, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card style={{ borderRadius: 8, borderTop: '3px solid #722ed1' }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>کل گزارشات</span>}
                            value={reports.length}
                            prefix={<BarChartOutlined style={{ color: '#722ed1' }} />}
                            valueStyle={{ color: '#722ed1', fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
                <Col xs={12} sm={6}>
                    <Card style={{ borderRadius: 8, borderTop: `3px solid ${THEME.success}` }}>
                        <Statistic
                            title={<span style={{ color: THEME.textSecondary }}>گزارشات فعال</span>}
                            value={selectedReportIds.size}
                            prefix={<CheckCircleOutlined style={{ color: THEME.success }} />}
                            valueStyle={{ color: THEME.success, fontSize: 20, fontWeight: 'bold' }}
                        />
                    </Card>
                </Col>
            </Row>

            {/* تب‌ها */}
            <Card style={STYLES.card}>
                <Tabs
                    activeKey={activeTab}
                    onChange={(key) => {
                        setActiveTab(key);
                        setSearchText('');
                    }}
                    items={[
                        {
                            key: 'menus',
                            label: (
                                <span>
                                    <AppstoreOutlined />
                                    منوها ({selectedMenuIds.size}/{menus.length})
                                </span>
                            ),
                            children: (
                                <>
                                    {/* نوار ابزار: سرچ + دکمه‌های انتخاب همه */}
                                    <Row gutter={[16, 16]} align="middle" style={{ marginBottom: 16 }}>
                                        <Col xs={24} md={14}>
                                            <Input
                                                placeholder="جستجوی زنده در منوها..."
                                                prefix={<SearchOutlined />}
                                                value={searchText}
                                                onChange={(e) => setSearchText(e.target.value)}
                                                allowClear
                                                size="large"
                                            />
                                        </Col>
                                        <Col xs={24} md={10}>
                                            <Space>
                                                <Button
                                                    icon={<CheckSquareOutlined />}
                                                    onClick={() => handleSelectAllMenus(true)}
                                                    disabled={allMenusSelected}
                                                    style={{
                                                        background: THEME.success,
                                                        borderColor: THEME.success,
                                                        color: '#fff',
                                                    }}
                                                    size="large"
                                                >
                                                    انتخاب همه
                                                </Button>
                                                <Button
                                                    icon={<BorderOutlined />}
                                                    onClick={() => handleSelectAllMenus(false)}
                                                    disabled={!someMenusSelected}
                                                    danger
                                                    size="large"
                                                >
                                                    برداشتن همه
                                                </Button>
                                            </Space>
                                        </Col>
                                    </Row>

                                    <DataGrid
                                        columns={[]}
                                        dataSource={filteredMenus}
                                        customColumns={menuColumns}
                                        rowKey="MenuID"
                                        showRowNumber={false}
                                        showColumnSearch={false}
                                        pageSize={15}
                                    />
                                </>
                            ),
                        },
                        {
                            key: 'reports',
                            label: (
                                <span>
                                    <BarChartOutlined />
                                    گزارشات ({selectedReportIds.size}/{reports.length})
                                </span>
                            ),
                            children: (
                                <>
                                    {/* نوار ابزار: سرچ + دکمه‌های انتخاب همه */}
                                    <Row gutter={[16, 16]} align="middle" style={{ marginBottom: 16 }}>
                                        <Col xs={24} md={14}>
                                            <Input
                                                placeholder="جستجوی زنده در گزارشات..."
                                                prefix={<SearchOutlined />}
                                                value={searchText}
                                                onChange={(e) => setSearchText(e.target.value)}
                                                allowClear
                                                size="large"
                                            />
                                        </Col>
                                        <Col xs={24} md={10}>
                                            <Space>
                                                <Button
                                                    icon={<CheckSquareOutlined />}
                                                    onClick={() => handleSelectAllReports(true)}
                                                    disabled={allReportsSelected}
                                                    style={{
                                                        background: THEME.success,
                                                        borderColor: THEME.success,
                                                        color: '#fff',
                                                    }}
                                                    size="large"
                                                >
                                                    انتخاب همه
                                                </Button>
                                                <Button
                                                    icon={<BorderOutlined />}
                                                    onClick={() => handleSelectAllReports(false)}
                                                    disabled={!someReportsSelected}
                                                    danger
                                                    size="large"
                                                >
                                                    برداشتن همه
                                                </Button>
                                            </Space>
                                        </Col>
                                    </Row>

                                    <DataGrid
                                        columns={[]}
                                        dataSource={filteredReports}
                                        customColumns={reportColumns}
                                        rowKey="ReportID"
                                        showRowNumber={false}
                                        showColumnSearch={false}
                                        pageSize={15}
                                    />
                                </>
                            ),
                        },
                    ]}
                />
            </Card>

            <NotificationModal
                open={notification.open}
                type={notification.type}
                message={notification.message}
                onClose={closeNotification}
            />

            {/* استایل‌های سفارشی برای هدر جدول در تب‌ها */}
            <style>{`
                .ant-tabs .unified-table .ant-table-thead > tr > th {
                    background: #EEEBFB !important;
                    color: #1F2937 !important;
                    font-weight: 600 !important;
                    border-bottom: 2px solid #C7BFEF !important;
                }
                .ant-tabs .unified-table .ant-table-thead > tr > th:not(:last-child) {
                    border-left: 1px solid #C7BFEF !important;
                }
            `}</style>
        </MainLayout>
    );
}