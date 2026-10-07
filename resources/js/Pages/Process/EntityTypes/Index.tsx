import { useState } from 'react';
import { Card, Table, Button, Input, InputNumber, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import {
    PlusOutlined,
    SaveOutlined,
    CloseOutlined,
    EditOutlined,
    CheckCircleOutlined,
    StopOutlined,
    ApartmentOutlined,
} from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { THEME, STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface WorkflowEntityType {
    EntityTypeID: number;
    Code: string;
    DisplayName: string;
    ResolverClass: string | null;
    SortOrder: number;
    IsActive: boolean | number | string;
    DefinitionCount: number;
}

interface RowDraft {
    entityTypeId: number | null;
    code: string;
    displayName: string;
    resolverClass: string;
    sortOrder: number;
}

const emptyDraft: RowDraft = { entityTypeId: null, code: '', displayName: '', resolverClass: '', sortOrder: 0 };

/**
 * Registryِ نوعِ موجودیت‌هایِ قابلِ‌استفاده در Workflow (فازِ ۳ — Phase A).
 *
 * این صفحه فقط لایهٔ «Business/UI Registry» را مدیریت می‌کند (Code/DisplayName/
 * IsActive) — نه Resolverِ واقعی (که همچنان در config/workflow.php است).
 * افزودنِ یک EntityTypeِ واقعاً جدید همچنان نیازمندِ یک کلاسِ Resolver + یک خط
 * Config است؛ این صفحه فقط UX تایپِ‌آزادِ EntityType را در فرمِ طراحیِ Workflow
 * حذف می‌کند.
 */
export default function ProcessEntityTypesIndex() {
    const { entityTypes, permissions } = usePage().props as unknown as {
        entityTypes: WorkflowEntityType[];
        permissions: string[];
    };

    const canManage = (permissions || []).includes('WORKFLOW_MANAGE_ENTITY_TYPES');

    const [items, setItems] = useState<WorkflowEntityType[]>(entityTypes || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState<RowDraft>(emptyDraft);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({
        open: false,
        type: 'success',
        message: '',
    });
    const [notificationKey, setNotificationKey] = useState(0);
    const notify = (type: NotificationType, message: string) => {
        setNotificationKey((k) => k + 1);
        setNotification({ open: true, type, message });
    };

    const reload = async () => {
        const res = await wfApi('/workflow/entity-types');
        if (res.ok && res.success) {
            setItems(res.items || []);
        }
    };

    const openCreate = () => {
        setDraft(emptyDraft);
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (row: WorkflowEntityType) => {
        setDraft({
            entityTypeId: row.EntityTypeID,
            code: row.Code,
            displayName: row.DisplayName,
            resolverClass: row.ResolverClass || '',
            sortOrder: row.SortOrder,
        });
        setError(null);
        setFormOpen(true);
    };

    const handleSave = async () => {
        if (!draft.code.trim() || !draft.displayName.trim()) {
            setError('کد و نامِ نمایشی الزامی است.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await wfApi('/workflow/entity-types', 'POST', {
            entityTypeId: draft.entityTypeId ?? undefined,
            code: draft.code.trim(),
            displayName: draft.displayName.trim(),
            resolverClass: draft.resolverClass.trim() || undefined,
            sortOrder: draft.sortOrder,
        });
        setSaving(false);

        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        setFormOpen(false);
        notify('success', res.message);
        reload();
    };

    const handleToggle = async (row: WorkflowEntityType) => {
        setTogglingId(row.EntityTypeID);
        const res = await wfApi(`/workflow/entity-types/${row.EntityTypeID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        reload();
    };

    const columns: ColumnsType<WorkflowEntityType> = [
        { title: 'کد', dataIndex: 'Code', key: 'Code', width: 140, render: (v: string) => <Text code dir="ltr">{v}</Text> },
        { title: 'نامِ نمایشی', dataIndex: 'DisplayName', key: 'DisplayName' },
        {
            title: 'Resolver',
            dataIndex: 'ResolverClass',
            key: 'ResolverClass',
            render: (v: string | null) => (v ? <Text dir="ltr" style={{ fontSize: 12 }} type="secondary">{v}</Text> : <Text type="secondary">—</Text>),
        },
        {
            title: 'تعدادِ فرایند',
            dataIndex: 'DefinitionCount',
            key: 'DefinitionCount',
            width: 110,
            align: 'center',
            render: (v: number) => <Tag style={{ borderRadius: 6 }}>{v}</Tag>,
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
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />
                        <Popconfirm
                            title={active ? 'غیرفعال‌کردنِ موجودیت' : 'فعال‌کردنِ موجودیت'}
                            description={active ? 'اگر فرایندِ فعالی از این نوع موجودیت استفاده کند، غیرفعال‌سازی مسدود می‌شود.' : 'آیا مطمئن هستید؟'}
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button type="text" size="small" danger={active} loading={togglingId === r.EntityTypeID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<ApartmentOutlined />}
                title="نوعِ موجودیت‌هایِ Workflow"
                subtitle="Registryِ موجودیت‌هایِ قابلِ‌استفاده در طراحیِ فرایندها"
                backHref="/process/definitions"
                backLabel="بازگشت به فرایندها"
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                            موجودیتِ جدید
                        </Button>
                    ) : undefined
                }
            />

            <Card style={STYLES.card}>
                {error && !formOpen ? (
                    <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} />
                ) : null}

                {formOpen ? (
                    <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                        {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                        <Space style={{ width: '100%' }} wrap>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>کد</Text>
                                <Input
                                    dir="ltr"
                                    style={{ width: 180 }}
                                    value={draft.code}
                                    onChange={(e) => setDraft((d) => ({ ...d, code: e.target.value.toUpperCase() }))}
                                    placeholder="LEAVE_REQUEST"
                                />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نامِ نمایشی</Text>
                                <Input style={{ width: 220 }} value={draft.displayName} onChange={(e) => setDraft((d) => ({ ...d, displayName: e.target.value }))} placeholder="درخواستِ مرخصی" />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ترتیب</Text>
                                <InputNumber style={{ width: 90 }} value={draft.sortOrder} onChange={(v) => setDraft((d) => ({ ...d, sortOrder: v ?? 0 }))} />
                            </div>
                        </Space>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
                                Resolver Class <Text type="secondary">(فقط مستندسازی — منبعِ واقعیِ اجرا همچنان config/workflow.php است)</Text>
                            </Text>
                            <Input dir="ltr" value={draft.resolverClass} onChange={(e) => setDraft((d) => ({ ...d, resolverClass: e.target.value }))} placeholder="App\Services\Workflow\Entity\...Resolver" />
                        </div>
                        <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                            <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>
                                انصراف
                            </Button>
                            <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>
                                ذخیره
                            </Button>
                        </Space>
                    </Space>
                ) : null}

                <Table
                    rowKey="EntityTypeID"
                    columns={columns}
                    dataSource={items}
                    pagination={false}
                    scroll={{ x: true }}
                    locale={{ emptyText: <Empty description="موجودیتی ثبت نشده است" /> }}
                />
            </Card>

            <NotificationModal key={notificationKey} open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
