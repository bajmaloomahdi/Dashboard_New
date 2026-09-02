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
    Alert,
} from 'antd';
import {
    SaveOutlined,
    SettingOutlined,
    DatabaseOutlined,
    CheckCircleOutlined,
    StopOutlined,
    SearchOutlined,
    LinkOutlined,
    CheckSquareOutlined,
    BorderOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import MainLayout from '../../Layouts/MainLayout';
import PageHeader from '../../Components/PageHeader';
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
    MenuTitle: string;
}

interface MasterParameter {
    MasterParameterID: number;
    ParameterName: string;
    ParameterCaption: string;
    DataType: string;
    ControlType: string;
    LookupProcedure: string | null;
    Description: string | null;
    IsLinked: boolean | number;
    IsRequired: boolean | number;
    IsVisible: boolean | number;
    DefaultValue: string | null;
    SortOrder: number | null;
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

export default function Parameters() {
    const { report, masterParameters, flash } = usePage().props as any;

    const [searchText, setSearchText] = useState('');
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [initialIds, setInitialIds] = useState<Set<number>>(new Set());
    const [saving, setSaving] = useState(false);

    const [notification, setNotification] = useState<{
        open: boolean;
        type: NotificationType;
        message: string;
    }>({ open: false, type: 'success', message: '' });

    useEffect(() => {
        const linkedIds = new Set<number>(
            masterParameters
                .filter((p: MasterParameter) => columnHelpers.toBool(p.IsLinked))
                .map((p: MasterParameter) => p.MasterParameterID)
        );
        setSelectedIds(linkedIds);
        setInitialIds(linkedIds);
    }, [masterParameters]);

    useEffect(() => {
        if (flash?.success) showNotification('success', flash.success);
        if (flash?.error) showNotification('error', flash.error);
    }, [flash]);

    const showNotification = (type: NotificationType, message: string) => {
        setNotification({ open: true, type, message });
    };

    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const filteredParameters = useMemo(() => {
        if (!searchText) return masterParameters;
        const search = searchText.toLowerCase();
        return masterParameters.filter((p: MasterParameter) =>
            p.ParameterName?.toLowerCase().includes(search) ||
            p.ParameterCaption?.toLowerCase().includes(search) ||
            p.Description?.toLowerCase().includes(search)
        );
    }, [masterParameters, searchText]);

    const hasChanges = useMemo(() => {
        if (selectedIds.size !== initialIds.size) return true;
        for (const id of selectedIds) {
            if (!initialIds.has(id)) return true;
        }
        return false;
    }, [selectedIds, initialIds]);

    const handleToggle = (id: number, checked: boolean) => {
        const newSet = new Set(selectedIds);
        if (checked) newSet.add(id);
        else newSet.delete(id);
        setSelectedIds(newSet);
    };

    // انتخاب/عدم انتخاب همه
    const handleSelectAll = (selectAll: boolean) => {
        const newSet = new Set(selectedIds);
        filteredParameters.forEach((p: MasterParameter) => {
            if (selectAll) newSet.add(p.MasterParameterID);
            else newSet.delete(p.MasterParameterID);
        });
        setSelectedIds(newSet);
    };

    const handleSave = () => {
        setSaving(true);
        router.post(`/reports-manage/${report.ReportID}/parameters`, {
            master_ids: Array.from(selectedIds),
        }, {
            preserveScroll: true,
            preserveState: true,
            only: ['flash', 'masterParameters'],
            onFinish: () => setSaving(false),
        });
    };

    // بررسی وضعیت انتخاب
    const allSelected = filteredParameters.length > 0 &&
        filteredParameters.every((p: MasterParameter) => selectedIds.has(p.MasterParameterID));
    const someSelected = filteredParameters.some((p: MasterParameter) => selectedIds.has(p.MasterParameterID));

    // ستون‌های سفارشی
    const customColumns: ColumnsType<MasterParameter> = [
        {
            title: 'اتصال',
            key: 'link',
            width: 100,
            align: 'center',
            render: (_, record) => (
                <Switch
                    checked={selectedIds.has(record.MasterParameterID)}
                    onChange={(checked) => handleToggle(record.MasterParameterID, checked)}
                    checkedChildren={<CheckCircleOutlined />}
                    unCheckedChildren={<StopOutlined />}
                />
            ),
        },
        {
            title: 'نام پارامتر',
            dataIndex: 'ParameterName',
            key: 'ParameterName',
            width: 180,
            align: 'center',
            render: (name: string) => (
                <span style={STYLES.codeBadge}>{name}</span>
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
                    <Tag color="purple" style={{ padding: '4px 10px', fontSize: 12, borderRadius: 6 }}>
                        {info.emoji} {info.label}
                    </Tag>
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
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<LinkOutlined />}
                title="اتصال پارامترها به گزارش"
                subtitle={`گزارش: ${report.ReportTitle}`}
                backHref="/reports-manage"
                backLabel="بازگشت به لیست"
                tags={[
                    { label: `کد: ${report.ReportCode}` },
                    ...(report.MenuTitle ? [{ label: report.MenuTitle }] : []),
                ]}
                stats={[
                    { icon: <SettingOutlined />, label: 'کل پارامترها', value: `${masterParameters?.length || 0} پارامتر` },
                    { icon: <CheckCircleOutlined />, label: 'انتخاب‌شده', value: `${selectedIds.size} پارامتر` },
                ]}
                actions={
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
                }
            />

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

            {/* جدول */}
            <Card style={STYLES.card}>
                {/* نوار ابزار: سرچ + دکمه‌های انتخاب همه */}
                <Row gutter={[16, 16]} align="middle" style={{ marginBottom: 16 }}>
                    <Col xs={24} md={14}>
                        <Input
                            placeholder="جستجو در پارامترها..."
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
                                onClick={() => handleSelectAll(true)}
                                disabled={allSelected}
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
                                onClick={() => handleSelectAll(false)}
                                disabled={!someSelected}
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
                    dataSource={filteredParameters}
                    customColumns={customColumns}
                    rowKey="MasterParameterID"
                    showRowNumber={false}
                    showColumnSearch={false}
                    pageSize={15}
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