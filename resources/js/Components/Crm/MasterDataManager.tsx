import { useEffect, useState } from 'react';
import { Table, Button, Input, InputNumber, Select, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, ReloadOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from './crmApi';
import { useColumnFilters, FilterHeader, TextFilterInput, SelectFilterInput, filterHeaderCss } from './tableFilters';
import CrmDeleteButton, { type CrmDeleteEntity } from './CrmDeleteButton';
import NotificationModal, { NotificationType } from '../NotificationModal';
import { toBool } from '../../Utils/bool';

const { Text } = Typography;

export interface MasterDataRow {
    [key: string]: any;
    Code: string;
    DisplayName: string;
    SortOrder: number;
    IsActive: boolean | number | string;
}

export interface ParentSelectConfig {
    /** برچسبِ فیلدِ والد، مثلاً «نوع» */
    label: string;
    /** نامِ پارامتر در بدنهٔ Save، مثلاً 'partyTypeId' */
    paramName: string;
    /** نامِ فیلدِ PKِ والد در ردیف‌هایِ برگشتی، مثلاً 'PartyTypeID' — همین فیلد
     * رویِ خودِ ردیف‌هایِ فرزند هم با همین نام برمی‌گردد (چون FK هم‌نام است)،
     * پس برایِ فیلترِ گرید هم از همین idField استفاده می‌شود. */
    idField: string;
    /**
     * مسیرِ index API والد، مثلاً '/crm/classification/party-types' — گزینه‌ها
     * زنده از همین‌جا خوانده می‌شوند (نه از Propsِ اولیهٔ صفحه)، چون تبِ والد
     * ممکن است همین حالا یک ردیفِ تازه اضافه کرده باشد که در Propsِ اولیهٔ
     * صفحه (بارگذاری‌شده در لحظهٔ Renderِ سرور) نبوده است.
     */
    apiBase: string;
    /** نامِ ستونِ نمایشیِ والد که از سرور برمی‌گردد، مثلاً 'PartyTypeName' */
    displayColumn: { title: string; dataIndex: string };
    /** اختیاری — فیلدی رویِ ردیف‌هایِ والد که کنارِ نامِ هر گزینه نمایش داده می‌شود
     * تا والدهایِ هم‌نام قابلِ‌تشخیص باشند، مثلاً 'ProvinceName' برایِ گزینه‌هایِ شهرستان. */
    optionContextField?: string;
}

export interface GrandParentSelectConfig {
    /** برچسبِ فیلدِ جدِّ بزرگ‌تر، مثلاً «استان» */
    label: string;
    /** مسیرِ index API جدِّ بزرگ‌تر، مثلاً '/crm/geography/provinces' */
    apiBase: string;
    /** نامِ فیلدِ PKِ جدِّ بزرگ‌تر — هم رویِ ردیف‌هایِ والد (برایِ فیلترکردنِ
     * گزینه‌هایِ Selectِ والد) و هم رویِ خودِ ردیف‌هایِ این جدول (برایِ فیلترِ
     * مستقیمِ گرید) با همین نام برمی‌گردد، مثلاً 'ProvinceID'. */
    idField: string;
    /** نامِ ستونِ نمایشیِ جدِّ بزرگ‌تر که از سرور برمی‌گردد، مثلاً 'ProvinceName' */
    displayColumn?: { title: string; dataIndex: string };
}

interface MasterDataManagerProps {
    /** نامِ فیلدِ PK ردیف‌ها، مثلاً 'DepartmentID' */
    idField: string;
    items: MasterDataRow[];
    /** مسیرِ پایهٔ API، مثلاً '/crm/classification/departments' — index این آدرس، save همین با POST، toggle با `${id}/toggle` */
    apiBase: string;
    canManage: boolean;
    /** دسترسیِ CRM_DELETE + slugِ موجودیت برایِ دکمهٔ حذف (بدونِ deleteEntity دکمه نمایش داده نمی‌شود) */
    canDelete?: boolean;
    deleteEntity?: CrmDeleteEntity;
    /** برایِ پیام‌ها/Placeholder، مثلاً 'دپارتمان' */
    entityLabel: string;
    parentSelect?: ParentSelectConfig;
    /** فقط برایِ نوارِ فیلترِ بالایِ گرید: یک سطحِ فیلترِ اضافه (جدِّ بزرگ‌تر، مثلاً
     * استان برایِ شهر) که گزینه‌هایِ فیلترِ والد را محدود می‌کند. فرمِ ایجاد/ویرایش
     * عمداً فقط والدِ مستقیم را می‌پرسد و این سطح را نشان نمی‌دهد. */
    grandParentSelect?: GrandParentSelectConfig;
    namePlaceholder?: string;
}

interface RowDraft {
    id: number | null;
    displayName: string;
    sortOrder: number;
    parentId: number | null;
}

const emptyDraft: RowDraft = { id: null, displayName: '', sortOrder: 0, parentId: null };

/**
 * مدیرِ عمومیِ Master Data — برایِ همهٔ فهرست‌هایِ سادهٔ CRM (دپارتمان/نوع/فعالیت/
 * عنوان/سمت/نقش/نوعِ تماس/عنوانِ آدرس/استان/شهرستان/شهر/محله/منطقهٔ شهرداری) به‌جایِ
 * ۱۲ صفحهٔ تکراری — هم‌الگو با Process/EntityTypes/Index.tsx (Table + فرمِ درون‌خطی
 * + Popconfirm)، فقط پارامتری‌شده تا یک بار نوشته و ۱۲ بار استفاده شود.
 */
export default function MasterDataManager({
    idField,
    items: initialItems,
    apiBase,
    canManage,
    canDelete = false,
    deleteEntity,
    entityLabel,
    parentSelect,
    grandParentSelect,
    namePlaceholder,
}: MasterDataManagerProps) {
    const [items, setItems] = useState<MasterDataRow[]>(initialItems || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState<RowDraft>(emptyDraft);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [parentRows, setParentRows] = useState<any[]>([]);

    const loadParentOptions = async () => {
        if (!parentSelect) return;
        const res = await crmApi(parentSelect.apiBase);
        if (res.ok && res.success) {
            setParentRows(res.items || []);
        }
    };

    useEffect(() => {
        loadParentOptions();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** گزینه‌هایِ Selectِ والد در فرم (فقط والدِ مستقیم پرسیده می‌شود) */
    const contextField = parentSelect?.optionContextField;
    const formParentOptions = parentRows.map((row: any) => ({
        value: row[parentSelect?.idField ?? ''],
        label: contextField && row[contextField] ? `${row.DisplayName} — ${row[contextField]}` : row.DisplayName,
    }));

    // فیلترِ ستونیِ شبیهِ اکسل (با تایپ، همان لحظه) — جدِّ بزرگ‌تر/والد/کد/نام/وضعیت
    const grandCol = grandParentSelect?.displayColumn;
    const parentCol = parentSelect?.displayColumn;
    const colF = useColumnFilters<MasterDataRow>(items, {
        ...(grandCol ? { grand: { get: (r: MasterDataRow) => r[grandCol.dataIndex], mode: 'text' as const } } : {}),
        ...(parentCol ? { parent: { get: (r: MasterDataRow) => r[parentCol.dataIndex], mode: 'text' as const } } : {}),
        code: { get: (r) => r.Code, mode: 'text' },
        name: { get: (r) => r.DisplayName, mode: 'text' },
        status: { get: (r) => (toBool(r.IsActive) ? '1' : '0'), mode: 'exact' },
    });
    const displayedItems = colF.filtered;

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
        const res = await crmApi(apiBase);
        if (res.ok && res.success) {
            setItems(res.items || []);
        }
    };

    const openCreate = () => {
        loadParentOptions();
        setDraft({ ...emptyDraft });
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (row: MasterDataRow) => {
        loadParentOptions();
        setDraft({
            id: row[idField],
            displayName: row.DisplayName,
            sortOrder: row.SortOrder,
            parentId: parentSelect ? row[parentSelect.idField] ?? null : null,
        });
        setError(null);
        setFormOpen(true);
    };

    const handleSave = async () => {
        if (!draft.displayName.trim()) {
            setError('نام الزامی است.');
            return;
        }
        if (parentSelect && !draft.parentId) {
            setError(`${parentSelect.label} الزامی است.`);
            return;
        }
        setSaving(true);
        setError(null);
        const body: Record<string, any> = {
            displayName: draft.displayName.trim(),
            sortOrder: draft.sortOrder,
        };
        if (draft.id != null) {
            // 'CityID' -> 'cityId' — نه صرفاً هم‌حروفِ کوچکِ اولین حرف (که 'cityID'
            // می‌داد و با نامِ پارامترِ موردِ انتظارِ بک‌اند مغایرت داشت، در نتیجه
            // شناسه هیچ‌وقت ارسال نمی‌شد و ذخیره همیشه به‌جایِ ویرایش، رکوردِ جدید می‌ساخت).
            const idParam = idField.replace(/ID$/, 'Id').replace(/^./, (c) => c.toLowerCase());
            body[idParam] = draft.id;
        }
        if (parentSelect) {
            body[parentSelect.paramName] = draft.parentId;
        }
        const res = await crmApi(apiBase, 'POST', body);
        setSaving(false);

        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        setFormOpen(false);
        notify('success', res.message);
        reload();
    };

    const handleToggle = async (row: MasterDataRow) => {
        const id = row[idField];
        setTogglingId(id);
        const res = await crmApi(`${apiBase}/${id}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            notify('error', res.message);
            return;
        }
        notify('success', res.message);
        reload();
    };

    // در گریدهایِ سلسله‌مراتبی (فعالیت/شهر/شهرستان) ستون‌هایِ سطوحِ بالادستی اول می‌آیند، بعد کد، بعد نام؛
    // عنوانِ هر ستون همان موجودیتِ تعریف‌شده است (مثلاً «نامِ شهر»، «استان») و زیرِ آن کادرِ فیلتر.
    const columns: ColumnsType<MasterDataRow> = [
        { title: 'ردیف', key: 'row', width: 64, align: 'center', render: (_, __, i) => i + 1 },
        ...(grandCol
            ? [{
                  title: <FilterHeader label={grandCol.title}><TextFilterInput value={colF.values.grand} onChange={(v) => colF.setValue('grand', v)} /></FilterHeader>,
                  dataIndex: grandCol.dataIndex,
                  key: grandCol.dataIndex,
              }]
            : []),
        ...(parentCol
            ? [{
                  title: <FilterHeader label={parentCol.title}><TextFilterInput value={colF.values.parent} onChange={(v) => colF.setValue('parent', v)} /></FilterHeader>,
                  dataIndex: parentCol.dataIndex,
                  key: parentCol.dataIndex,
              }]
            : []),
        {
            title: <FilterHeader label={`کدِ ${entityLabel}`}><TextFilterInput value={colF.values.code} onChange={(v) => colF.setValue('code', v)} /></FilterHeader>,
            dataIndex: 'Code',
            key: 'Code',
            width: 150,
            render: (v: string) => <Text code dir="ltr" style={{ whiteSpace: 'nowrap' }}>{v}</Text>,
        },
        {
            title: <FilterHeader label={`نامِ ${entityLabel}`}><TextFilterInput value={colF.values.name} onChange={(v) => colF.setValue('name', v)} /></FilterHeader>,
            dataIndex: 'DisplayName',
            key: 'DisplayName',
        },
        {
            title: (
                <FilterHeader label="وضعیت">
                    <SelectFilterInput value={colF.values.status} onChange={(v) => colF.setValue('status', v)} options={[{ value: '1', label: 'فعال' }, { value: '0', label: 'غیرفعال' }]} />
                </FilterHeader>
            ),
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
            width: 140,
            align: 'center',
            render: (_, r) => {
                const showDelete = canDelete && !!deleteEntity;
                if (!canManage && !showDelete) return null;
                const active = toBool(r.IsActive);
                const id = r[idField];
                return (
                    <Space>
                        {canManage ? <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} /> : null}
                        {canManage ? (
                        <Popconfirm
                            title={active ? `غیرفعال‌کردنِ ${entityLabel}` : `فعال‌کردنِ ${entityLabel}`}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button type="text" size="small" danger={active} loading={togglingId === id} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                        ) : null}
                        {showDelete ? (
                            <CrmDeleteButton
                                entity={deleteEntity!}
                                id={id}
                                label={entityLabel}
                                onDone={(ok, msg) => {
                                    notify(ok ? 'success' : 'error', msg);
                                    if (ok) reload();
                                }}
                            />
                        ) : null}
                    </Space>
                );
            },
        },
    ];

    return (
        <div>
            <style>{filterHeaderCss}</style>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8, flexWrap: 'wrap', marginBottom: 12 }}>
                <Space size={8} wrap>
                    <Text type="secondary">
                        {colF.hasAny ? `${displayedItems.length} از ${items.length} ردیف` : `${items.length} ردیف`}
                    </Text>
                    {colF.hasAny ? (
                        <Button size="small" icon={<ReloadOutlined />} onClick={colF.clear}>پاک‌کردنِ فیلترها</Button>
                    ) : null}
                </Space>
                {canManage ? (
                    <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                        {entityLabel} جدید
                    </Button>
                ) : null}
            </div>

            {error && !formOpen ? (
                <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} />
            ) : null}

            {formOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    <Space style={{ width: '100%' }} wrap>
                        {parentSelect ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>{parentSelect.label}</Text>
                                <Select
                                    style={{ width: 260 }}
                                    value={draft.parentId ?? undefined}
                                    onChange={(v) => setDraft((d) => ({ ...d, parentId: v }))}
                                    options={formParentOptions}
                                    placeholder={parentSelect.label}
                                    showSearch
                                    filterOption={(input, option) => (option?.label as string)?.toLowerCase().includes(input.toLowerCase())}
                                />
                            </div>
                        ) : null}
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نام</Text>
                            <Input
                                style={{ width: 220 }}
                                value={draft.displayName}
                                onChange={(e) => setDraft((d) => ({ ...d, displayName: e.target.value }))}
                                placeholder={namePlaceholder || entityLabel}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ترتیب</Text>
                            <InputNumber style={{ width: 90 }} value={draft.sortOrder} onChange={(v) => setDraft((d) => ({ ...d, sortOrder: v ?? 0 }))} />
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
                rowKey={idField}
                columns={columns}
                dataSource={displayedItems}
                pagination={false}
                scroll={{ x: true }}
                locale={{ emptyText: <Empty description={colF.hasAny ? 'ردیفی با این فیلترها یافت نشد' : `${entityLabel}ی ثبت نشده است`} /> }}
            />

            <NotificationModal key={notificationKey} open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
