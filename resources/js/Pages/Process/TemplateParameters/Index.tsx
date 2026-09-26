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
const { TextArea } = Input;

interface TemplateParameter {
    TemplateParameterID: number;
    Code: string;
    Caption: string;
    EntityType: string | null;
    DataType: string;
    SourceType: string;
    SourceKey: string;
    Description: string | null;
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
    latinName: string;
    entityType: string | null;
    dataType: string | null;
    sourceType: string | null;
    sourceKey: string;
    description: string;
    sortOrder: number;
}

const emptyDraft: RowDraft = {
    templateParameterId: null,
    code: '',
    caption: '',
    latinName: '',
    entityType: null,
    dataType: null,
    sourceType: null,
    sourceKey: '',
    description: '',
    sortOrder: 0,
};

/** «منبعِ مقدار» — یک مفهومِ واحد در UI (در DB به GroupCode/SourceType نگاشت می‌شود، هردو همیشه یکسان). */
const SOURCE_OPTIONS = [
    { value: 'USER', label: 'کاربر' },
    { value: 'SYSTEM', label: 'سیستم' },
    { value: 'FORM', label: 'فرم' },
];

/** تنها فیلدهایِ کاربرِ شناخته‌شده (نگاه کن به TemplateRenderer::resolveUserField در Backend). */
const USER_FIELD_OPTIONS = [
    { value: 'FullName', label: 'نام و نام‌خانوادگی' },
    { value: 'UserCode', label: 'کدِ پرسنلی' },
    { value: 'PositionName', label: 'سمت' },
    { value: 'UnitName', label: 'واحدِ سازمانی' },
];

const DATA_TYPE_OPTIONS = [
    { value: 'STRING', label: 'متن (STRING)' },
    { value: 'DATE', label: 'تاریخ (DATE)' },
    { value: 'TIME', label: 'ساعت (TIME)' },
    { value: 'INTEGER', label: 'عددِ صحیح (INTEGER)' },
    { value: 'DECIMAL', label: 'عددِ اعشاری (DECIMAL)' },
];

/**
 * تولیدِ Code — فقط حروف/عددِ لاتین و Underscore را نگه می‌دارد. این فقط یک
 * پیش‌نمایشِ سمتِ کلاینت است؛ Backend همین الگوریتم را دوباره و مستقلاً روی
 * caption/latinName اجرا می‌کند و تنها منبعِ حقیقتِ Code است.
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

function sourceLabel(sourceType: string): string {
    return SOURCE_OPTIONS.find((o) => o.value === sourceType)?.label ?? sourceType;
}

/**
 * Registryِ پارامترهایِ Letter Template (فازِ ۳ — Phase B، ساده‌سازیِ UX).
 *
 * فرم عمداً حداقلی است: عنوان/Code/منبع/نوعِ داده/توضیحِ اختیاری. GroupCode و
 * SourceType دو مفهومِ جداگانه نیستند — در UI فقط یک «منبعِ مقدار» دیده می‌شود
 * (Backend این دو ستون را همیشه یکسان نگه می‌دارد). SourceKey هرگز به‌صورتِ خام
 * نمایش داده نمی‌شود: برایِ «کاربر» از یک انتخابِ محدود و آشنا (نام/کدِ پرسنلی/
 * سمت/واحد) گرفته می‌شود، برایِ «سیستم» کاملاً خودکار است (Backend تعیین می‌کند)،
 * و فقط برایِ «فرم» یک نامِ فیلد از کاربر گرفته می‌شود. EntityType هم فقط برایِ
 * Source=FORM نمایش داده می‌شود چون تنها Sourceای است که در عمل به یک نوعِ
 * موجودیتِ خاص محدود می‌شود.
 *
 * Code هرگز مستقیماً توسطِ کاربر تایپ نمی‌شود: Backend آن را از رویِ عنوان می‌سازد
 * (Read-only در UI، فقط برایِ نمایش). اگر عنوان حرفِ لاتینِ کافی نداشته باشد (مثلاً
 * کاملاً فارسی)، یک فیلدِ کوچکِ «نامِ لاتین» فقط در همان لحظه ظاهر می‌شود.
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
    /** تا وقتی کاربر خودش «نامِ فیلدِ فرم» را دستی تغییر نداده، با Codeِ پیش‌نمایش همگام می‌ماند (فقط برایِ Source=FORM). */
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
        // در ویرایش، Code همان مقدارِ فعلی و ثابت است — نه از عنوان دوباره ساخته می‌شود،
        // نه فیلدِ «نامِ لاتین» لازم/نمایش داده می‌شود.
        setDraft({
            templateParameterId: row.TemplateParameterID,
            code: row.Code,
            caption: row.Caption,
            latinName: '',
            entityType: row.EntityType,
            dataType: row.DataType,
            sourceType: row.SourceType,
            sourceKey: row.SourceType === 'FORM' ? row.SourceKey : row.SourceType === 'USER' ? row.SourceKey : '',
            description: row.Description ?? '',
            sortOrder: row.SortOrder,
        });
        setSourceKeyAuto(false);
        setError(null);
        setFormOpen(true);
    };

    const isEditing = draft.templateParameterId !== null;
    const codeFromCaption = suggestCode(draft.caption);
    const needsLatinName = !isEditing && draft.caption.trim() !== '' && !isUsableCode(codeFromCaption);
    const previewCode = isEditing ? draft.code : (needsLatinName ? suggestCode(draft.latinName) : codeFromCaption);

    const handleSave = async () => {
        if (!draft.caption.trim() || !draft.sourceType || !draft.dataType) {
            setError('عنوان، منبعِ مقدار، و نوعِ داده الزامی‌اند.');
            return;
        }
        if (needsLatinName && !isUsableCode(suggestCode(draft.latinName))) {
            setError('عنوان شامل حروفِ لاتینِ کافی نیست — یک «نامِ لاتین» کوتاه و معتبر وارد کنید (مثلاً AMOUNT).');
            return;
        }
        if (draft.sourceType === 'USER' && !draft.sourceKey) {
            setError('انتخابِ فیلدِ کاربر الزامی است.');
            return;
        }
        if (draft.sourceType === 'FORM' && !draft.sourceKey.trim()) {
            setError('نامِ فیلدِ فرم الزامی است.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await wfApi('/workflow/template-parameters', 'POST', {
            templateParameterId: draft.templateParameterId ?? undefined,
            caption: draft.caption.trim(),
            latinName: !isEditing && needsLatinName ? draft.latinName.trim() : undefined,
            entityType: draft.sourceType === 'FORM' ? draft.entityType ?? undefined : undefined,
            dataType: draft.dataType,
            sourceType: draft.sourceType,
            sourceKey: draft.sourceType === 'SYSTEM' ? undefined : draft.sourceKey.trim(),
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
        { title: 'منبع', dataIndex: 'SourceType', key: 'SourceType', width: 90, render: (v: string) => <Tag style={{ borderRadius: 6 }}>{sourceLabel(v)}</Tag> },
        { title: 'نوعِ داده', dataIndex: 'DataType', key: 'DataType', width: 90 },
        {
            title: 'نوعِ موجودیت',
            dataIndex: 'EntityType',
            key: 'EntityType',
            width: 130,
            render: (v: string | null) => (v ? <Text dir="ltr" style={{ fontSize: 12 }}>{v}</Text> : <Tag style={{ borderRadius: 6 }}>سراسری</Tag>),
        },
        {
            title: 'توضیح',
            dataIndex: 'Description',
            key: 'Description',
            ellipsis: true,
            render: (v: string | null) => (v ? <Text type="secondary" style={{ fontSize: 12 }}>{v}</Text> : null),
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
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>عنوان</Text>
                                <Input
                                    style={{ width: 220 }}
                                    value={draft.caption}
                                    onChange={(e) => {
                                        const caption = e.target.value;
                                        setDraft((d) => {
                                            const nextPreview = needsLatinName ? suggestCode(d.latinName) : suggestCode(caption);
                                            return {
                                                ...d,
                                                caption,
                                                sourceKey: d.sourceType === 'FORM' && sourceKeyAuto ? nextPreview : d.sourceKey,
                                            };
                                        });
                                    }}
                                    placeholder="نام و نام‌خانوادگیِ کاربر"
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
                                        onChange={(e) => {
                                            const latinName = e.target.value.toUpperCase();
                                            setDraft((d) => {
                                                const nextPreview = suggestCode(latinName);
                                                return {
                                                    ...d,
                                                    latinName,
                                                    sourceKey: d.sourceType === 'FORM' && sourceKeyAuto ? nextPreview : d.sourceKey,
                                                };
                                            });
                                        }}
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
                                    placeholder="USER_FULL_NAME"
                                />
                                <Text type="secondary" style={{ fontSize: 11, display: 'block' }}>
                                    {isEditing ? 'Code پس از ایجاد قابلِ‌تغییر نیست.' : 'Code به‌صورتِ خودکار ساخته می‌شود.'}
                                </Text>
                            </div>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوعِ داده</Text>
                                <Select style={{ width: 180 }} placeholder="انتخاب..." value={draft.dataType ?? undefined} onChange={(v) => setDraft((d) => ({ ...d, dataType: v }))} options={DATA_TYPE_OPTIONS} />
                            </div>
                        </Space>

                        <Space style={{ width: '100%' }} wrap>
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>منبعِ مقدار</Text>
                                <Select
                                    style={{ width: 160 }}
                                    placeholder="انتخاب..."
                                    value={draft.sourceType ?? undefined}
                                    onChange={(v) => {
                                        setSourceKeyAuto(true);
                                        setDraft((d) => ({
                                            ...d,
                                            sourceType: v,
                                            sourceKey: v === 'FORM' ? previewCode : '',
                                            entityType: v === 'FORM' ? d.entityType : null,
                                        }));
                                    }}
                                    options={SOURCE_OPTIONS}
                                />
                            </div>

                            {/* فقط زمانی که واقعاً لازم است — دقیقاً همان فیلدی که Sourceِ انتخاب‌شده به آن نیاز دارد. */}
                            {draft.sourceType === 'USER' && (
                                <div>
                                    <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>فیلدِ کاربر</Text>
                                    <Select
                                        style={{ width: 200 }}
                                        placeholder="انتخاب..."
                                        value={draft.sourceKey || undefined}
                                        onChange={(v) => setDraft((d) => ({ ...d, sourceKey: v }))}
                                        options={USER_FIELD_OPTIONS}
                                    />
                                </div>
                            )}

                            {draft.sourceType === 'FORM' && (
                                <>
                                    <div>
                                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>
                                            نامِ فیلدِ فرم <Text type="secondary">(پیش‌فرض = کد؛ در صورتِ نیاز قابلِ تغییر)</Text>
                                        </Text>
                                        <Input
                                            dir="ltr"
                                            style={{ width: 200 }}
                                            value={draft.sourceKey}
                                            onChange={(e) => {
                                                setSourceKeyAuto(false);
                                                setDraft((d) => ({ ...d, sourceKey: e.target.value }));
                                            }}
                                            placeholder="description"
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
                                </>
                            )}

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
