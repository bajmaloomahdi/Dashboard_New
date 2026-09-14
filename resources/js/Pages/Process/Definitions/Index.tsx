import { useState, useEffect, useRef } from 'react';
import { Card, Button, Input, Space, Tag, Tooltip, Typography, Row, Col, Select, Popconfirm } from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    ReloadOutlined,
    CheckCircleOutlined,
    StopOutlined,
    ApartmentOutlined,
    EyeOutlined,
    LoadingOutlined,
    BranchesOutlined,
    PlayCircleOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import DataGrid from '../../../Components/DataGrid';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import DefinitionFormModal from './DefinitionFormModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { THEME, STYLES } from '../../../theme';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface Definition {
    DefinitionID: number;
    Code: string;
    Name: string;
    Description: string | null;
    EntityType: string;
    IsActive: boolean | number | string;
    Date_InsertFirst: string;
    CreatedByName: string | null;
    VersionCount: number;
    ActiveVersionNo: number | null;
    InstanceCount: number;
}

export default function ProcessDefinitionsIndex() {
    const { definitions, filters, permissions } = usePage().props as unknown as {
        definitions: Definition[];
        filters: { search: string | null; isActive: boolean | null };
        permissions: string[];
    };

    const canDesign = (permissions || []).includes('WORKFLOW_DESIGN');

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [statusFilter, setStatusFilter] = useState<string | null>(
        filters?.isActive === null || filters?.isActive === undefined ? null : filters.isActive ? '1' : '0'
    );
    const [searching, setSearching] = useState(false);

    const [modalOpen, setModalOpen] = useState(false);
    const [editingDefinition, setEditingDefinition] = useState<Definition | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const showNotification = (type: NotificationType, message: string) => setNotification({ open: true, type, message });
    const closeNotification = () => setNotification((prev) => ({ ...prev, open: false }));

    const searchTimeoutRef = useRef<NodeJS.Timeout | null>(null);
    const isFirstRender = useRef(true);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        setSearching(true);
        if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);

        searchTimeoutRef.current = setTimeout(() => {
            router.get(
                '/process/definitions',
                { search: searchText || undefined, isActive: statusFilter !== null ? statusFilter : undefined },
                { preserveState: true, preserveScroll: true, replace: true, only: ['definitions', 'filters'], onFinish: () => setSearching(false) }
            );
        }, 300);

        return () => {
            if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
        };
    }, [searchText, statusFilter]);

    const handleReset = () => {
        setSearchText('');
        setStatusFilter(null);
    };

    const handleCreate = () => {
        setEditingDefinition(null);
        setModalOpen(true);
    };

    const handleEdit = (def: Definition) => {
        setEditingDefinition(def);
        setModalOpen(true);
    };

    const handleModalSuccess = (message: string) => {
        setModalOpen(false);
        setEditingDefinition(null);
        showNotification('success', message);
        router.reload({ only: ['definitions'] });
    };

    const handleToggle = async (def: Definition) => {
        setTogglingId(def.DefinitionID);
        const res = await wfApi(`/workflow/definitions/${def.DefinitionID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            showNotification('error', res.message);
            return;
        }

        showNotification('success', res.message);
        router.reload({ only: ['definitions'] });
    };

    const customColumns: ColumnsType<Definition> = [
        {
            title: 'کد',
            dataIndex: 'Code',
            key: 'Code',
            width: 160,
            align: 'center',
            render: (code: string) => <span style={STYLES.codeBadge}>{code}</span>,
        },
        {
            title: 'نام',
            key: 'name',
            align: 'center',
            render: (_, record) => (
                <div style={{ display: 'inline-flex', flexDirection: 'column', minWidth: 200, textAlign: 'right' }}>
                    <Text strong style={{ color: THEME.textPrimary }}>{record.Name}</Text>
                    {record.Description ? <Text type="secondary" style={{ fontSize: 11 }}>{record.Description}</Text> : null}
                </div>
            ),
        },
        {
            title: 'نوعِ موجودیت',
            dataIndex: 'EntityType',
            key: 'EntityType',
            width: 140,
            align: 'center',
            render: (v: string) => <Tag color="purple" style={{ borderRadius: 6 }}>{v}</Tag>,
        },
        {
            title: 'نسخه‌ها',
            key: 'versions',
            width: 130,
            align: 'center',
            render: (_, record) => (
                <Tooltip title="تعدادِ کلِ نسخه‌ها / شمارهٔ نسخهٔ فعال">
                    <Tag icon={<BranchesOutlined />} color="blue" style={{ borderRadius: 6 }}>
                        {record.VersionCount} {record.ActiveVersionNo ? `(فعال: v${record.ActiveVersionNo})` : '(بدونِ نسخهٔ فعال)'}
                    </Tag>
                </Tooltip>
            ),
        },
        {
            title: 'نمونه‌ها',
            dataIndex: 'InstanceCount',
            key: 'InstanceCount',
            width: 100,
            align: 'center',
            render: (count: number) => (
                <Tag icon={<PlayCircleOutlined />} color={count > 0 ? 'green' : 'default'} style={{ borderRadius: 6 }}>
                    {count}
                </Tag>
            ),
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, record) => {
                const isActive = toBool(record.IsActive);
                return (
                    <Tag icon={isActive ? <CheckCircleOutlined /> : <StopOutlined />} color={isActive ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                        {isActive ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'تاریخِ ایجاد',
            dataIndex: 'Date_InsertFirst',
            key: 'Date_InsertFirst',
            width: 120,
            align: 'center',
            render: (v: string) => <Text style={{ fontSize: 12 }}>{gregorianToJalaliDisplay(v)}</Text>,
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 140,
            align: 'center',
            fixed: 'left',
            render: (_, record) => {
                const isActive = toBool(record.IsActive);
                return (
                    <Space>
                        <Tooltip title="مشاهده">
                            <Button type="text" icon={<EyeOutlined />} style={{ color: THEME.primary }} onClick={() => router.visit(`/process/definitions/${record.DefinitionID}`)} />
                        </Tooltip>
                        {canDesign && (
                            <>
                                <Tooltip title="ویرایش">
                                    <Button type="text" icon={<EditOutlined />} style={{ color: THEME.info }} onClick={() => handleEdit(record)} />
                                </Tooltip>
                                <Popconfirm
                                    title={isActive ? 'غیرفعال‌کردنِ فرایند' : 'فعال‌کردنِ فرایند'}
                                    description={isActive ? 'پس از غیرفعال‌شدن، Startِ نمونهٔ جدید برایِ این فرایند ممکن نخواهد بود؛ نمونه‌هایِ در‌حالِ‌اجرا تحتِ تأثیر قرار نمی‌گیرند.' : 'آیا مطمئن هستید؟'}
                                    onConfirm={() => handleToggle(record)}
                                    okText="بله"
                                    cancelText="خیر"
                                    okButtonProps={{ danger: isActive }}
                                >
                                    <Tooltip title={isActive ? 'غیرفعال کردن' : 'فعال کردن'}>
                                        <Button
                                            type="text"
                                            icon={isActive ? <StopOutlined /> : <CheckCircleOutlined />}
                                            loading={togglingId === record.DefinitionID}
                                            style={{ color: isActive ? THEME.error : THEME.success }}
                                        />
                                    </Tooltip>
                                </Popconfirm>
                            </>
                        )}
                    </Space>
                );
            },
        },
    ];

    const totalCount = definitions?.length || 0;
    const activeCount = definitions?.filter((d) => toBool(d.IsActive)).length || 0;
    const runningInstances = definitions?.reduce((sum, d) => sum + (d.InstanceCount || 0), 0) || 0;

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title="اتوماسیونِ فرایند"
                subtitle="مدیریتِ تعریف‌هایِ Workflow"
                stats={[
                    { icon: <ApartmentOutlined />, label: 'کلِ فرایندها', value: `${totalCount} فرایند` },
                    { icon: <CheckCircleOutlined />, label: 'فعال', value: `${activeCount} فرایند` },
                    { icon: <PlayCircleOutlined />, label: 'نمونه‌هایِ ساخته‌شده', value: `${runningInstances} نمونه` },
                ]}
                actions={
                    canDesign ? (
                        <Button type="primary" icon={<PlusOutlined />} size="large" style={STYLES.primaryButton} onClick={handleCreate}>
                            فرایندِ جدید
                        </Button>
                    ) : undefined
                }
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={12}>
                        <Input
                            placeholder="جستجو در نام، کد، نوعِ موجودیت..."
                            prefix={searching ? <LoadingOutlined style={{ color: THEME.primary }} /> : <SearchOutlined />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                            allowClear
                            size="large"
                        />
                    </Col>
                    <Col xs={24} sm={12} md={8}>
                        <Select
                            placeholder="فیلترِ وضعیت"
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

            <Card style={STYLES.card}>
                <DataGrid
                    columns={[]}
                    dataSource={definitions}
                    loading={searching}
                    customColumns={customColumns}
                    rowKey="DefinitionID"
                    showColumnSearch={false}
                    showRowNumber={false}
                    pageSize={15}
                />
            </Card>

            <DefinitionFormModal
                open={modalOpen}
                onClose={() => {
                    setModalOpen(false);
                    setEditingDefinition(null);
                }}
                editingDefinition={editingDefinition}
                onSuccess={handleModalSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={closeNotification} />
        </MainLayout>
    );
}
