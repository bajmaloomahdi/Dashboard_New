import { useEffect, useRef, useState } from 'react';
import { Card, Button, Space, Tag, Typography, Row, Col, Select, InputNumber, Input, Pagination, Empty } from 'antd';
import { ApartmentOutlined, ReloadOutlined, EyeOutlined, LoadingOutlined, NodeIndexOutlined } from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import DataGrid from '../../../Components/DataGrid';
import PersianDateInput from '../../../Components/PersianDateInput';
import { THEME, STYLES } from '../../../theme';
import { gregorianToJalaliDateTimeDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

interface InstanceRow {
    InstanceID: number;
    InstanceNumber: string;
    DefinitionID: number;
    DefinitionCode: string;
    DefinitionName: string;
    VersionID: number;
    EntityType: string;
    EntityID: number;
    Status: string;
    StartedByUserID: number | null;
    StartedByName: string | null;
    StartedAt: string;
    CompletedAt: string | null;
    CurrentStepID: number | null;
    CurrentStepCode: string | null;
    CurrentStepName: string | null;
}

interface DefinitionOption {
    DefinitionID: number;
    Code: string;
    Name: string;
}

interface UserOption {
    UserID: number;
    FullName: string;
}

interface Filters {
    definitionId: number | null;
    status: string | null;
    entityType: string | null;
    entityId: number | null;
    startedByUserId: number | null;
    dateFrom: string | null;
    dateTo: string | null;
}

const instanceStatusTag: Record<string, { color: string; label: string }> = {
    RUNNING: { color: THEME.primary, label: 'در جریان' },
    COMPLETED: { color: THEME.success, label: 'تکمیل‌شده' },
    CANCELLED: { color: THEME.textLight, label: 'لغوشده' },
    SUSPENDED: { color: THEME.warning, label: 'معلق' },
    FAILED: { color: THEME.error, label: 'ناموفق' },
};

const statusOptions = [
    { value: 'RUNNING', label: 'در جریان' },
    { value: 'COMPLETED', label: 'تکمیل‌شده' },
    { value: 'CANCELLED', label: 'لغوشده' },
    { value: 'SUSPENDED', label: 'معلق' },
    { value: 'FAILED', label: 'ناموفق' },
];

export default function ProcessInstancesIndex() {
    const props = usePage().props as unknown as {
        instances: InstanceRow[];
        totalCount: number;
        filters: Filters;
        page: number;
        pageSize: number;
        permissions: string[];
        definitionOptions: DefinitionOption[];
        users: UserOption[];
    };

    const [filters, setFilters] = useState<Filters>(props.filters);
    const [page, setPage] = useState(props.page);
    const [pageSize, setPageSize] = useState(props.pageSize);
    const [loading, setLoading] = useState(false);

    const isFirstRender = useRef(true);
    const debounceRef = useRef<NodeJS.Timeout | null>(null);

    const applyQuery = (nextFilters: Filters, nextPage: number, nextPageSize: number) => {
        setLoading(true);
        router.get(
            '/process/instances',
            {
                definitionId: nextFilters.definitionId ?? undefined,
                status: nextFilters.status ?? undefined,
                entityType: nextFilters.entityType ?? undefined,
                entityId: nextFilters.entityId ?? undefined,
                startedByUserId: nextFilters.startedByUserId ?? undefined,
                dateFrom: nextFilters.dateFrom ?? undefined,
                dateTo: nextFilters.dateTo ?? undefined,
                page: nextPage,
                pageSize: nextPageSize,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['instances', 'totalCount', 'filters', 'page', 'pageSize'],
                onFinish: () => setLoading(false),
            }
        );
    };

    // تغییرِ فیلترها → بازگشت به صفحهٔ ۱ + Requestِ Debounceشده
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            setPage(1);
            applyQuery(filters, 1, pageSize);
        }, 300);

        return () => {
            if (debounceRef.current) clearTimeout(debounceRef.current);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters]);

    const handlePageChange = (nextPage: number, nextPageSize: number) => {
        setPage(nextPage);
        setPageSize(nextPageSize);
        applyQuery(filters, nextPage, nextPageSize);
    };

    const handleReset = () => {
        setFilters({
            definitionId: null,
            status: null,
            entityType: null,
            entityId: null,
            startedByUserId: null,
            dateFrom: null,
            dateTo: null,
        });
    };

    const columns: ColumnsType<InstanceRow> = [
        {
            title: 'شمارهٔ نمونه',
            dataIndex: 'InstanceNumber',
            key: 'InstanceNumber',
            width: 150,
            align: 'center',
            render: (v: string) => <span style={STYLES.codeBadge}>{v}</span>,
        },
        {
            title: 'فرایند',
            key: 'definition',
            align: 'center',
            render: (_, r) => (
                <div style={{ display: 'inline-flex', flexDirection: 'column', minWidth: 160, textAlign: 'right' }}>
                    <Text strong style={{ color: THEME.textPrimary }}>{r.DefinitionName}</Text>
                    <Text type="secondary" style={{ fontSize: 11 }}>{r.DefinitionCode}</Text>
                </div>
            ),
        },
        {
            title: 'موجودیت',
            key: 'entity',
            width: 150,
            align: 'center',
            render: (_, r) => <Tag color="purple" style={{ borderRadius: 6 }}>{r.EntityType} #{r.EntityID}</Tag>,
        },
        {
            title: 'وضعیت',
            dataIndex: 'Status',
            key: 'Status',
            width: 110,
            align: 'center',
            render: (v: string) => {
                const s = instanceStatusTag[v] ?? { color: THEME.textLight, label: v };
                return <Tag color={s.color} style={{ borderRadius: 6 }}>{s.label}</Tag>;
            },
        },
        {
            title: 'مرحلهٔ جاری',
            key: 'currentStep',
            width: 150,
            align: 'center',
            render: (_, r) =>
                r.CurrentStepName ? (
                    <Tag icon={<NodeIndexOutlined />} color="blue" style={{ borderRadius: 6 }}>{r.CurrentStepName}</Tag>
                ) : (
                    <Text type="secondary">—</Text>
                ),
        },
        {
            title: 'آغازگر',
            dataIndex: 'StartedByName',
            key: 'StartedByName',
            width: 130,
            align: 'center',
            render: (v: string | null) => v || <Text type="secondary">—</Text>,
        },
        {
            title: 'تاریخِ شروع',
            dataIndex: 'StartedAt',
            key: 'StartedAt',
            width: 160,
            align: 'center',
            render: (v: string) => <Text style={{ fontSize: 12 }}>{gregorianToJalaliDateTimeDisplay(v)}</Text>,
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 90,
            align: 'center',
            fixed: 'left',
            render: (_, r) => (
                <Button type="text" icon={<EyeOutlined />} style={{ color: THEME.primary }} onClick={() => router.visit(`/process/instances/${r.InstanceID}`)} />
            ),
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title="نمونه‌هایِ فرایند"
                subtitle="پیگیریِ اجرایِ Instanceهایِ Workflow"
                stats={[{ icon: <ApartmentOutlined />, label: 'کلِ نمونه‌ها (با فیلترِ فعلی)', value: `${props.totalCount} نمونه` }]}
            />

            <Card style={{ marginBottom: 16, ...STYLES.filterCard }}>
                <Row gutter={[16, 16]} align="middle">
                    <Col xs={24} sm={12} md={6}>
                        <Select
                            placeholder="فرایند"
                            style={{ width: '100%' }}
                            size="large"
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            value={filters.definitionId ?? undefined}
                            onChange={(v) => setFilters((f) => ({ ...f, definitionId: v ?? null }))}
                            options={props.definitionOptions.map((d) => ({ value: d.DefinitionID, label: `${d.Name} (${d.Code})` }))}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={5}>
                        <Select
                            placeholder="وضعیت"
                            style={{ width: '100%' }}
                            size="large"
                            allowClear
                            value={filters.status ?? undefined}
                            onChange={(v) => setFilters((f) => ({ ...f, status: v ?? null }))}
                            options={statusOptions}
                        />
                    </Col>
                    <Col xs={24} sm={12} md={5}>
                        <Select
                            placeholder="آغازگر"
                            style={{ width: '100%' }}
                            size="large"
                            allowClear
                            showSearch
                            optionFilterProp="label"
                            value={filters.startedByUserId ?? undefined}
                            onChange={(v) => setFilters((f) => ({ ...f, startedByUserId: v ?? null }))}
                            options={props.users.map((u) => ({ value: u.UserID, label: u.FullName }))}
                        />
                    </Col>
                    <Col xs={12} sm={12} md={4}>
                        <Input
                            placeholder="نوعِ موجودیت"
                            size="large"
                            allowClear
                            value={filters.entityType ?? ''}
                            onChange={(e) => setFilters((f) => ({ ...f, entityType: e.target.value || null }))}
                        />
                    </Col>
                    <Col xs={12} sm={12} md={4}>
                        <InputNumber
                            placeholder="شناسهٔ موجودیت"
                            style={{ width: '100%' }}
                            size="large"
                            value={filters.entityId ?? undefined}
                            onChange={(v) => setFilters((f) => ({ ...f, entityId: (v as number) ?? null }))}
                        />
                    </Col>
                    <Col xs={12} sm={12} md={5}>
                        <PersianDateInput
                            value={filters.dateFrom}
                            onChange={(v) => setFilters((f) => ({ ...f, dateFrom: v }))}
                            placeholder="از تاریخ"
                            size="large"
                        />
                    </Col>
                    <Col xs={12} sm={12} md={5}>
                        <PersianDateInput
                            value={filters.dateTo}
                            onChange={(v) => setFilters((f) => ({ ...f, dateTo: v }))}
                            placeholder="تا تاریخ"
                            size="large"
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
                {props.instances.length === 0 ? (
                    <Empty description="نمونه‌ای یافت نشد" />
                ) : (
                    <DataGrid
                        columns={[]}
                        dataSource={props.instances}
                        loading={loading}
                        customColumns={columns}
                        rowKey="InstanceID"
                        showColumnSearch={false}
                        showRowNumber={false}
                        pageSize={pageSize}
                    />
                )}

                <div style={{ display: 'flex', justifyContent: 'center', marginTop: 16 }}>
                    <Pagination
                        current={page}
                        pageSize={pageSize}
                        total={props.totalCount}
                        showSizeChanger
                        pageSizeOptions={['10', '20', '50', '100']}
                        onChange={handlePageChange}
                        showTotal={(total) => `${total} نمونه`}
                        disabled={loading}
                    />
                </div>
            </Card>
        </MainLayout>
    );
}
