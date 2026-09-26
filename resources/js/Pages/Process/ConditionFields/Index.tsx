import { useState } from 'react';
import { Card, Table, Button, Input, InputNumber, Select, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import {
    PlusOutlined,
    SaveOutlined,
    CloseOutlined,
    EditOutlined,
    CheckCircleOutlined,
    StopOutlined,
    FilterOutlined,
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
const { TextArea } = Input;

interface ConditionFieldRow {
    FieldID: number;
    Code: string;
    DisplayName: string;
    DataType: string;
    SourceType: string;
    SourceKey: string;
    AllowedValuesJson: string | null;
    Description: string | null;
    SortOrder: number;
    IsActive: boolean | number | string;
}

interface RowDraft {
    fieldId: number | null;
    code: string;
    displayName: string;
    latinName: string;
    dataType: string | null;
    description: string;
    sortOrder: number;
}

const emptyDraft: RowDraft = {
    fieldId: null,
    code: '',
    displayName: '',
    latinName: '',
    dataType: null,
    description: '',
    sortOrder: 0,
};

/** همان ۵ DataType پشتیبانی‌شدهٔ Condition Engine (ConditionFieldService::DATA_TYPES). */
const DATA_TYPE_OPTIONS = [
    { value: 'STRING', label: 'متن (STRING)' },
    { value: 'DATE', label: 'تاریخ (DATE)' },
    { value: 'TIME', label: 'ساعت (TIME)' },
    { value: 'INTEGER', label: 'عددِ صحیح (INTEGER)' },
    { value: 'DECIMAL', label: 'عددِ اعشاری (DECIMAL)' },
];

/**
 * تولیدِ Code — فقط حروف/عددِ لاتین و Underscore را نگه می‌دارد (حروفِ فارسی/علائم
 * حذف می‌شوند). این فقط یک پیش‌نمایشِ سمتِ کلاینت است؛ Backend همین الگوریتم را
 * دوباره و مستقلاً روی caption/latinName اجرا می‌کند و تنها منبعِ حقیقتِ Code است
 * (به مقدارِ این پیش‌نمایش اعتماد نمی‌کند).
 */
function suggestCode(title: string): string {
    return title
        .toUpperCase()
        .replace(/[^A-Z0-9\s_]/g, '')
        .trim()
        .replace(/\s+/g, '_')
        .replace(/_+/g, '_')
        .replace(/^[^A-Z]+/, '')
        .slice(0, 50);
}

/** الگویِ معتبرِ Code — هم‌ارزِ CODE_PATTERN در Backend. */
const CODE_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;

function isUsableCode(code: string): boolean {
    return CODE_PATTERN.test(code);
}

/**
 * Registryِ سراسریِ فیلدهایِ شرط (WorkflowConditionFields — Phase 2، Global Registry).
 *
 * تنها محلِ مدیریتِ Field (ایجاد/ویرایش/فعال‌سازی) — Designer/RuleBuilder فقط از
 * همین Registry مصرف می‌کنند و هیچ فیلدِ جدیدی از آن‌جا ساخته نمی‌شود.
 *
 * Code هرگز مستقیماً توسطِ کاربر تایپ نمی‌شود: Backend آن را از رویِ عنوان می‌سازد
 * (Read-only در UI، فقط برایِ نمایش). اگر عنوان حرفِ لاتینِ کافی نداشته باشد (مثلاً
 * کاملاً فارسی)، یک فیلدِ کوچکِ «نامِ لاتین» فقط در همان لحظه ظاهر می‌شود تا کاربر
 * یک نامِ کوتاهِ لاتین بدهد و Code از رویِ آن ساخته شود. SourceType/SourceKey هم
 * جزئیاتِ فنیِ صرفِ Backend‌اند و هرگز در UI نمایش داده نمی‌شوند.
 */
export default function ProcessConditionFieldsIndex() {
    const { conditionFields, permissions } = usePage().props as unknown as {
        conditionFields: ConditionFieldRow[];
        permissions: string[];
    };

    const canManage = (permissions || []).includes('WORKFLOW_MANAGE_CONDITION_FIELDS');

    const [items, setItems] = useState<ConditionFieldRow[]>(conditionFields || []);
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
        const res = await wfApi('/workflow/condition-fields?includeInactive=1');
        if (res.ok && res.success) {
            setItems(res.items || []);
        }
    };

    const openCreate = () => {
        setDraft(emptyDraft);
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (row: ConditionFieldRow) => {
        // در ویرایش، Code همان مقدارِ فعلی و ثابت است — نه از عنوان دوباره ساخته می‌شود،
        // نه فیلدِ «نامِ لاتین» لازم/نمایش داده می‌شود.
        setDraft({
            fieldId: row.FieldID,
            code: row.Code,
            displayName: row.DisplayName,
            latinName: '',
            dataType: row.DataType,
            description: row.Description ?? '',
            sortOrder: row.SortOrder,
        });
        setError(null);
        setFormOpen(true);
    };

    const isEditing = draft.fieldId !== null;
    const codeFromCaption = suggestCode(draft.displayName);
    const needsLatinName = !isEditing && draft.displayName.trim() !== '' && !isUsableCode(codeFromCaption);
    const previewCode = isEditing ? draft.code : (needsLatinName ? suggestCode(draft.latinName) : codeFromCaption);

    const handleSave = async () => {
        if (!draft.displayName.trim() || !draft.dataType) {
            setError('عنوان و نوعِ داده الزامی‌اند.');
            return;
        }
        if (needsLatinName && !isUsableCode(suggestCode(draft.latinName))) {
            setError('عنوان شامل حروفِ لاتینِ کافی نیست — یک «نامِ لاتین» کوتاه و معتبر وارد کنید (مثلاً AMOUNT).');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await wfApi('/workflow/condition-fields', 'POST', {
            fieldId: draft.fieldId ?? undefined,
            displayName: draft.displayName.trim(),
            latinName: !isEditing && needsLatinName ? draft.latinName.trim() : undefined,
            dataType: draft.dataType,
            description: draft.description.trim() || undefined,
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

    const handleToggle = async (row: ConditionFieldRow) => {
        setTogglingId(row.FieldID);
        const res = await wfApi(`/workflow/condition-fields/${row.FieldID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        reload();
    };

    const columns: ColumnsType<ConditionFieldRow> = [
        { title: 'کد', dataIndex: 'Code', key: 'Code', width: 170, render: (v: string) => <Text code dir="ltr">{v}</Text> },
        { title: 'عنوان', dataIndex: 'DisplayName', key: 'DisplayName' },
        { title: 'نوعِ داده', dataIndex: 'DataType', key: 'DataType', width: 110, render: (v: string) => <Tag style={{ borderRadius: 6 }}>{DATA_TYPE_OPTIONS.find((o) => o.value === v)?.label ?? v}</Tag> },
        {
            title: 'توضیح',
            dataIndex: 'Description',
            key: 'Description',
            ellipsis: true,
            render: (v: string | null) => (v ? <Text type="secondary" style={{ fontSize: 12 }}>{v}</Text> : null),
        },
        { title: 'ترتیب', dataIndex: 'SortOrder', key: 'SortOrder', width: 80, align: 'center' },
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
                            title={active ? 'غیرفعال‌کردنِ فیلد' : 'فعال‌کردنِ فیلد'}
                            description={active ? 'این فیلد دیگر برایِ ساختِ Ruleِ جدید در دسترس نخواهد بود.' : 'آیا مطمئن هستید؟'}
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button type="text" size="small" danger={active} loading={togglingId === r.FieldID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <MainLayout>
            <PageHeader
                icon={<FilterOutlined />}
                title="فیلدهایِ شروط"
                subtitle="Registryِ سراسریِ فیلدهایِ قابلِ‌استفاده در شرط‌هایِ Workflow"
                backHref="/process/definitions"
                backLabel="بازگشت به فرایندها"
                actions={
                    canManage ? (
                        <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                            فیلدِ جدید
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
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>عنوانِ فیلد</Text>
                                <Input
                                    style={{ width: 240 }}
                                    value={draft.displayName}
                                    onChange={(e) => setDraft((d) => ({ ...d, displayName: e.target.value }))}
                                    placeholder="مبلغ"
                                />
                            </div>
                            {needsLatinName && (
                                <div>
                                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
                                        نامِ لاتین <Text type="secondary">(چون عنوان حرفِ لاتین ندارد)</Text>
                                    </Text>
                                    <Input
                                        dir="ltr"
                                        style={{ width: 180, fontFamily: 'monospace' }}
                                        value={draft.latinName}
                                        onChange={(e) => setDraft((d) => ({ ...d, latinName: e.target.value.toUpperCase() }))}
                                        placeholder="AMOUNT"
                                    />
                                </div>
                            )}
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>کد</Text>
                                <Input
                                    dir="ltr"
                                    disabled
                                    style={{ width: 200, fontFamily: 'monospace' }}
                                    value={previewCode}
                                    placeholder="AMOUNT"
                                />
                                <Text type="secondary" style={{ fontSize: 11, display: 'block' }}>
                                    {isEditing ? 'Code پس از ایجاد قابلِ‌تغییر نیست.' : 'Code به‌صورتِ خودکار ساخته می‌شود.'}
                                </Text>
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوعِ داده</Text>
                                <Select
                                    style={{ width: 180 }}
                                    placeholder="انتخاب..."
                                    value={draft.dataType ?? undefined}
                                    onChange={(v) => setDraft((d) => ({ ...d, dataType: v }))}
                                    options={DATA_TYPE_OPTIONS}
                                />
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ترتیب</Text>
                                <InputNumber style={{ width: 90 }} value={draft.sortOrder} onChange={(v) => setDraft((d) => ({ ...d, sortOrder: v ?? 0 }))} />
                            </div>
                        </Space>

                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
                                توضیح <Text type="secondary">(اختیاری)</Text>
                            </Text>
                            <TextArea
                                style={{ maxWidth: 480 }}
                                rows={2}
                                value={draft.description}
                                onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))}
                                placeholder="توضیحِ کوتاهی برایِ راهنماییِ کاربرانِ دیگر..."
                            />
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
                    rowKey="FieldID"
                    columns={columns}
                    dataSource={items}
                    pagination={false}
                    scroll={{ x: 'max-content' }}
                    locale={{ emptyText: <Empty description="فیلدی ثبت نشده است" /> }}
                />
            </Card>

            <NotificationModal key={notificationKey} open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </MainLayout>
    );
}
