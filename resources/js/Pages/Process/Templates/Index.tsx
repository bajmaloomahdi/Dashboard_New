import { useEffect, useRef, useState } from 'react';
import { Card, Table, Button, Input, Select, Space, Tag, Popconfirm, Typography, Alert, Empty, Row, Col } from 'antd';
import {
    PlusOutlined,
    EditOutlined,
    SearchOutlined,
    CheckCircleOutlined,
    StopOutlined,
    FileTextOutlined,
} from '@ant-design/icons';
import { router, usePage } from '@inertiajs/react';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import TemplateFormModal from './TemplateFormModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface LetterTemplate {
    LetterTemplateID: number;
    Code: string;
    Name: string;
    EntityType: string;
    DefinitionID: number | null;
    DefinitionName: string | null;
    SubjectTemplate: string;
    BodyTemplate: string;
    IsActive: boolean | number | string;
}

interface EntityTypeOption {
    Code: string;
    DisplayName: string;
}

interface WfDefinition {
    DefinitionID: number;
    Code: string;
    Name: string;
    EntityType: string;
}

/**
 * Template Management UI (فازِ ۳ — Phase C، Item 3). فقط UI/فیلتر را از سمتِ
 * Inertia (GET process/templates) مدیریت می‌کند؛ ایجاد/ویرایش/فعال‌سازی از
 * سمتِ کلاینت مستقیماً به endpointِ JSONِ موجود (/workflow/templates) می‌رود.
 */
export default function ProcessTemplatesIndex() {
    const { letterTemplates, filters, permissions } = usePage().props as unknown as {
        letterTemplates: LetterTemplate[];
        filters: { search: string | null; entityType: string | null; definitionId: number | null; isActive: boolean | null };
        permissions: string[];
    };

    const canManage = (permissions || []).includes('WORKFLOW_MANAGE_TEMPLATES');

    const [searchText, setSearchText] = useState(filters?.search || '');
    const [entityTypeFilter, setEntityTypeFilter] = useState<string | null>(filters?.entityType ?? null);
    const [definitionFilter, setDefinitionFilter] = useState<number | null>(filters?.definitionId ?? null);
    const [statusFilter, setStatusFilter] = useState<string | null>(
        filters?.isActive === null || filters?.isActive === undefined ? null : filters.isActive ? '1' : '0'
    );
    const [searching, setSearching] = useState(false);

    const [entityTypes, setEntityTypes] = useState<EntityTypeOption[]>([]);
    const [definitions, setDefinitions] = useState<WfDefinition[]>([]);

    const [formOpen, setFormOpen] = useState(false);
    const [editingTemplate, setEditingTemplate] = useState<LetterTemplate | null>(null);
    const [togglingId, setTogglingId] = useState<number | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const searchTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const isFirstRender = useRef(true);

    useEffect(() => {
        wfApi('/workflow/entity-types?isActive=1').then((res) => {
            if (res.ok && res.success) setEntityTypes((res.items || []).map((e: any) => ({ Code: e.Code, DisplayName: e.DisplayName })));
        });
        wfApi('/workflow/definitions?isActive=1').then((res) => {
            if (res.ok && res.success) setDefinitions(res.items || []);
        });
    }, []);

    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }

        setSearching(true);
        if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);

        searchTimeoutRef.current = setTimeout(() => {
            router.get(
                '/process/templates',
                {
                    search: searchText || undefined,
                    entityType: entityTypeFilter || undefined,
                    definitionId: definitionFilter || undefined,
                    isActive: statusFilter !== null ? statusFilter : undefined,
                },
                { preserveState: true, preserveScroll: true, replace: true, only: ['letterTemplates', 'filters'], onFinish: () => setSearching(false) }
            );
        }, 300);

        return () => {
            if (searchTimeoutRef.current) clearTimeout(searchTimeoutRef.current);
        };
    }, [searchText, entityTypeFilter, definitionFilter, statusFilter]);

    const handleReset = () => {
        setSearchText('');
        setEntityTypeFilter(null);
        setDefinitionFilter(null);
        setStatusFilter(null);
    };

    const reload = () => router.reload({ only: ['letterTemplates'] });

    const handleCreate = () => {
        setEditingTemplate(null);
        setFormOpen(true);
    };

    const handleEdit = (row: LetterTemplate) => {
        setEditingTemplate(row);
        setFormOpen(true);
    };

    const handleFormSuccess = (message: string) => {
        setFormOpen(false);
        notify('success', message);
        reload();
    };

    const handleToggle = async (row: LetterTemplate) => {
        setTogglingId(row.LetterTemplateID);
        const res = await wfApi(`/workflow/templates/${row.LetterTemplateID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        reload();
    };

    const definitionsForFilter = entityTypeFilter ? definitions.filter((d) => d.EntityType === entityTypeFilter) : definitions;

    const columns: ColumnsType<LetterTemplate> = [
        { title: 'کد', dataIndex: 'Code', key: 'Code', width: 200, render: (v: string) => <Text code dir="ltr">{v}</Text> },
        { title: 'نام', dataIndex: 'Name', key: 'Name' },
        { title: 'نوعِ موجودیت', dataIndex: 'EntityType', key: 'EntityType', width: 140, render: (v: string) => <Text dir="ltr" style={{ fontSize: 12 }}>{v}</Text> },
        {
            title: 'Workflow Definition',
            dataIndex: 'DefinitionName',
            key: 'DefinitionName',
            width: 200,
            render: (v: string | null) => (v ? <Text>{v}</Text> : <Tag style={{ borderRadius: 6 }}>عمومی</Tag>),
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, r) => {
                const active = toBool(r.IsActive);
                return (
                    <Tag icon={active ? <CheckCircleOutlined /> : <StopOutlined />} color={active ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                        {active ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 110,
            align: 'center',
            render: (_, r) => {
                if (!canManage) return null;
                const active = toBool(r.IsActive);
                return (
                    <Space>
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => handleEdit(r)} />
                        <Popconfirm
                            title={active ? 'غیرفعال‌کردنِ قالب' : 'فعال‌کردنِ قالب'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button type="text" size="small" danger={active} loading={togglingId === r.LetterTemplateID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<FileTextOutlined />}
                title="قالب‌هایِ نامه"
                subtitle="مدیریتِ Letter Templateها برایِ ساختِ پیام از رویِ الگو"
                backHref="/process/definitions"
                backLabel="بازگشت به فرایندها"
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} onClick={handleCreate}>
                            قالبِ جدید
                        </Button>
                    ) : undefined
                }
            />

            <Card style={STYLES.card}>
                <Row gutter={[12, 12]} style={{ marginBottom: 16 }}>
                    <Col xs={24} md={8}>
                        <Input
                            allowClear
                            placeholder="جستجو بر اساسِ کد یا نام..."
                            prefix={<SearchOutlined style={{ color: '#8c8c8c' }} />}
                            value={searchText}
                            onChange={(e) => setSearchText(e.target.value)}
                        />
                    </Col>
                    <Col xs={24} md={5}>
                        <Select
                            allowClear
                            showSearch
                            style={{ width: '100%' }}
                            placeholder="نوعِ موجودیت"
                            value={entityTypeFilter ?? undefined}
                            onChange={(v) => {
                                setEntityTypeFilter(v ?? null);
                                if (definitionFilter && !definitions.find((d) => d.DefinitionID === definitionFilter && d.EntityType === v)) {
                                    setDefinitionFilter(null);
                                }
                            }}
                            optionFilterProp="label"
                            options={entityTypes.map((e) => ({ value: e.Code, label: `${e.DisplayName} (${e.Code})` }))}
                        />
                    </Col>
                    <Col xs={24} md={5}>
                        <Select
                            allowClear
                            showSearch
                            style={{ width: '100%' }}
                            placeholder="Workflow Definition"
                            value={definitionFilter ?? undefined}
                            onChange={(v) => setDefinitionFilter(v ?? null)}
                            optionFilterProp="label"
                            options={definitionsForFilter.map((d) => ({ value: d.DefinitionID, label: `${d.Name} (${d.Code})` }))}
                        />
                    </Col>
                    <Col xs={24} md={4}>
                        <Select
                            allowClear
                            style={{ width: '100%' }}
                            placeholder="وضعیت"
                            value={statusFilter ?? undefined}
                            onChange={(v) => setStatusFilter(v ?? null)}
                            options={[
                                { value: '1', label: 'فعال' },
                                { value: '0', label: 'غیرفعال' },
                            ]}
                        />
                    </Col>
                    <Col xs={24} md={2}>
                        <Button onClick={handleReset} style={{ width: '100%' }}>
                            پاک‌کردن
                        </Button>
                    </Col>
                </Row>

                <Table
                    rowKey="LetterTemplateID"
                    columns={columns}
                    dataSource={letterTemplates || []}
                    loading={searching}
                    pagination={false}
                    scroll={{ x: 'max-content' }}
                    locale={{ emptyText: <Empty description="قالبی ثبت نشده است" /> }}
                />
            </Card>

            <TemplateFormModal
                open={formOpen}
                onClose={() => setFormOpen(false)}
                editingTemplate={editingTemplate}
                onSuccess={handleFormSuccess}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
