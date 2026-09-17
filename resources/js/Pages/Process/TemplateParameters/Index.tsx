import { useEffect, useState } from 'react';
import { Card, Table, Button, Input, InputNumber, Select, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import {
    PlusOutlined,
    SaveOutlined,
    CloseOutlined,
    EditOutlined,
    CheckCircleOutlined,
    StopOutlined,
    ProfileOutlined,
} from '@ant-design/icons';
import { usePage } from '@inertiajs/react';
import type { ColumnsType } from 'antd/es/table';
import MainLayout from '../../../Layouts/MainLayout';
import PageHeader from '../../../Components/PageHeader';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { STYLES } from '../../../theme';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface TemplateParameter {
    TemplateParameterID: number;
    Code: string;
    Caption: string;
    GroupCode: string;
    EntityType: string | null;
    DataType: string;
    SourceType: string;
    SourceKey: string;
    IsActive: boolean | number | string;
    SortOrder: number;
}

interface EntityTypeOption {
    Code: string;
    DisplayName: string;
}

interface RowDraft {
    templateParameterId: number | null;
    code: string;
    caption: string;
    groupCode: string | null;
    entityType: string | null;
    dataType: string | null;
    sourceType: string | null;
    sourceKey: string;
    sortOrder: number;
}

const emptyDraft: RowDraft = {
    templateParameterId: null,
    code: '',
    caption: '',
    groupCode: null,
    entityType: null,
    dataType: null,
    sourceType: null,
    sourceKey: '',
    sortOrder: 0,
};

const GROUP_OPTIONS = [
    { value: 'USER', label: 'کاربر (USER)' },
    { value: 'SYSTEM', label: 'سیستم (SYSTEM)' },
    { value: 'FORM', label: 'فرم (FORM)' },
];

const SOURCE_TYPE_OPTIONS = GROUP_OPTIONS; // Phase B: همان سه‌مقداری با GroupCode

const DATA_TYPE_OPTIONS = [
    { value: 'STRING', label: 'متن (STRING)' },
    { value: 'DATE', label: 'تاریخ (DATE)' },
    { value: 'INTEGER', label: 'عددِ صحیح (INTEGER)' },
    { value: 'DECIMAL', label: 'عددِ اعشاری (DECIMAL)' },
];

/**
 * Registryِ پارامترهایِ Letter Template (فازِ ۳ — Phase B).
 *
 * کاملاً مستقل از Condition Engine (WorkflowConditionFields/ConditionContextBuilder/
 * ConditionDataTypeCaster) و از MasterParametersِ گزارش‌سازی — این صفحه فقط
 * Registryِ خودِ TemplateParameters را مدیریت می‌کند. AllowedValuesJson در UI
 * نمایش داده نمی‌شود چون در Phase B مقدارِ DataType هرگز SELECT نیست (تصمیمِ
 * ساده‌سازیِ عمدی، طبقِ گزارش).
 */
export default function ProcessTemplateParametersIndex() {
    const { templateParameters, permissions } = usePage().props as unknown as {
        templateParameters: TemplateParameter[];
        permissions: string[];
    };

    const canManage = (permissions || []).includes('WORKFLOW_MANAGE_TEMPLATE_PARAMETERS');

    const [items, setItems] = useState<TemplateParameter[]>(templateParameters || []);
    const [entityTypes, setEntityTypes] = useState<EntityTypeOption[]>([]);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState<RowDraft>(emptyDraft);
    /** تا وقتی کاربر خودش SourceKey را دستی تغییر نداده، برایِ گروهِ FORM با Code همگام می‌ماند. */
    const [sourceKeyAuto, setSourceKeyAuto] = useState(true);
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

    useEffect(() => {
        // فقط EntityTypeهایِ فعال — از Registryِ واقعیِ WorkflowEntityTypes، نه Config
        wfApi('/workflow/entity-types?isActive=1').then((res) => {
            if (res.ok && res.success) {
                setEntityTypes((res.items || []).map((e: any) => ({ Code: e.Code, DisplayName: e.DisplayName })));
            }
        });
    }, []);

    const reload = async () => {
        const res = await wfApi('/workflow/template-parameters');
        if (res.ok && res.success) {
            setItems(res.items || []);
        }
    };

    const openCreate = () => {
        setDraft(emptyDraft);
        setSourceKeyAuto(true);
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (row: TemplateParameter) => {
        setDraft({
            templateParameterId: row.TemplateParameterID,
            code: row.Code,
            caption: row.Caption,
            groupCode: row.GroupCode,
            entityType: row.EntityType,
            dataType: row.DataType,
            sourceType: row.SourceType,
            sourceKey: row.SourceKey,
            sortOrder: row.SortOrder,
        });
        setSourceKeyAuto(false);
        setError(null);
        setFormOpen(true);
    };

    const handleSave = async () => {
        if (!draft.code.trim() || !draft.caption.trim() || !draft.groupCode || !draft.dataType || !draft.sourceType || !draft.sourceKey.trim()) {
            setError('کد، عنوان، گروه، نوعِ داده، منبع، و کلیدِ منبع الزامی‌اند.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await wfApi('/workflow/template-parameters', 'POST', {
            templateParameterId: draft.templateParameterId ?? undefined,
            code: draft.code.trim(),
            caption: draft.caption.trim(),
            groupCode: draft.groupCode,
            entityType: draft.entityType ?? undefined,
            dataType: draft.dataType,
            sourceType: draft.sourceType,
            sourceKey: draft.sourceKey.trim(),
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

    const handleToggle = async (row: TemplateParameter) => {
        setTogglingId(row.TemplateParameterID);
        const res = await wfApi(`/workflow/template-parameters/${row.TemplateParameterID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        reload();
    };

    const columns: ColumnsType<TemplateParameter> = [
        { title: 'کد', dataIndex: 'Code', key: 'Code', width: 170, render: (v: string) => <Text code dir="ltr">{v}</Text> },
        { title: 'عنوان', dataIndex: 'Caption', key: 'Caption' },
        { title: 'گروه', dataIndex: 'GroupCode', key: 'GroupCode', width: 90, render: (v: string) => <Tag style={{ borderRadius: 6 }}>{v}</Tag> },
        {
            title: 'نوعِ موجودیت',
            dataIndex: 'EntityType',
            key: 'EntityType',
            width: 130,
            render: (v: string | null) => (v ? <Text dir="ltr" style={{ fontSize: 12 }}>{v}</Text> : <Tag style={{ borderRadius: 6 }}>سراسری</Tag>),
        },
        { title: 'نوعِ داده', dataIndex: 'DataType', key: 'DataType', width: 90 },
        { title: 'منبع', dataIndex: 'SourceType', key: 'SourceType', width: 90 },
        { title: 'کلیدِ منبع', dataIndex: 'SourceKey', key: 'SourceKey', render: (v: string) => <Text dir="ltr" style={{ fontSize: 12 }}>{v}</Text> },
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
                            title={active ? 'غیرفعال‌کردنِ پارامتر' : 'فعال‌کردنِ پارامتر'}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button type="text" size="small" danger={active} loading={togglingId === r.TemplateParameterID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<ProfileOutlined />}
                title="پارامترهایِ Template"
                subtitle="Registryِ پارامترهایِ مجاز برایِ استفاده در Letter Template"
                backHref="/process/definitions"
                backLabel="بازگشت به فرایندها"
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                            پارامترِ جدید
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
                                    style={{ width: 220 }}
                                    value={draft.code}
                                    onChange={(e) => {
                                        const code = e.target.value.toUpperCase();
                                        setDraft((d) => ({
                                            ...d,
                                            code,
                                            sourceKey: d.groupCode === 'FORM' && sourceKeyAuto ? code : d.sourceKey,
                                        }));
                                    }}
                                    placeholder="USER_FULL_NAME"
                                />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>عنوانِ نمایشی</Text>
                                <Input style={{ width: 220 }} value={draft.caption} onChange={(e) => setDraft((d) => ({ ...d, caption: e.target.value }))} placeholder="نام و نام‌خانوادگیِ کاربر" />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ترتیب</Text>
                                <InputNumber style={{ width: 90 }} value={draft.sortOrder} onChange={(v) => setDraft((d) => ({ ...d, sortOrder: v ?? 0 }))} />
                            </div>
                        </Space>
                        <Space style={{ width: '100%' }} wrap>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>گروه (GroupCode)</Text>
                                <Select
                                    style={{ width: 180 }}
                                    placeholder="انتخاب..."
                                    value={draft.groupCode ?? undefined}
                                    onChange={(v) =>
                                        setDraft((d) => ({
                                            ...d,
                                            groupCode: v,
                                            sourceType: v === 'FORM' ? 'FORM' : d.sourceType,
                                            sourceKey: v === 'FORM' && sourceKeyAuto ? d.code : d.sourceKey,
                                        }))
                                    }
                                    options={GROUP_OPTIONS}
                                />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>منبع (SourceType)</Text>
                                <Select
                                    style={{ width: 180 }}
                                    placeholder="انتخاب..."
                                    value={draft.sourceType ?? undefined}
                                    disabled={draft.groupCode === 'FORM'}
                                    onChange={(v) => setDraft((d) => ({ ...d, sourceType: v }))}
                                    options={SOURCE_TYPE_OPTIONS}
                                />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوعِ داده (DataType)</Text>
                                <Select style={{ width: 180 }} placeholder="انتخاب..." value={draft.dataType ?? undefined} onChange={(v) => setDraft((d) => ({ ...d, dataType: v }))} options={DATA_TYPE_OPTIONS} />
                            </div>
                        </Space>
                        <Space style={{ width: '100%' }} wrap>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
                                    کلیدِ منبع (SourceKey)
                                    {draft.groupCode === 'FORM' ? <Text type="secondary"> (پیش‌فرض = کد؛ در صورتِ نیاز قابلِ تغییر)</Text> : null}
                                </Text>
                                <Input
                                    dir="ltr"
                                    style={{ width: 200 }}
                                    value={draft.sourceKey}
                                    onChange={(e) => {
                                        setSourceKeyAuto(false);
                                        setDraft((d) => ({ ...d, sourceKey: e.target.value }));
                                    }}
                                    placeholder="FullName"
                                />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
                                    نوعِ موجودیت <Text type="secondary">(اختیاری — خالی = سراسری)</Text>
                                </Text>
                                <Select
                                    allowClear
                                    showSearch
                                    style={{ width: 220 }}
                                    placeholder="سراسری (همهٔ Entityها)"
                                    value={draft.entityType ?? undefined}
                                    onChange={(v) => setDraft((d) => ({ ...d, entityType: v ?? null }))}
                                    optionFilterProp="label"
                                    options={entityTypes.map((e) => ({ value: e.Code, label: `${e.DisplayName} (${e.Code})` }))}
                                />
                            </div>
                        </Space>
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
                    rowKey="TemplateParameterID"
                    columns={columns}
                    dataSource={items}
                    pagination={false}
                    scroll={{ x: 'max-content' }}
                    locale={{ emptyText: <Empty description="پارامتری ثبت نشده است" /> }}
                />
            </Card>

            <NotificationModal key={notificationKey} open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
