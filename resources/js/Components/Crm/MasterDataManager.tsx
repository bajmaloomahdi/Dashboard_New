import { useEffect, useState } from 'react';
import { Table, Button, Input, InputNumber, Select, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from './crmApi';
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
    const [grandParentOptions, setGrandParentOptions] = useState<{ value: number; label: string }[]>([]);
    const [gridParentFilterId, setGridParentFilterId] = useState<number | null>(null);
    const [gridGrandParentFilterId, setGridGrandParentFilterId] = useState<number | null>(null);

    const loadParentOptions = async () => {
        if (!parentSelect) return;
        const res = await crmApi(parentSelect.apiBase);
        if (res.ok && res.success) {
            setParentRows(res.items || []);
        }
    };

    const loadGrandParentOptions = async () => {
        if (!grandParentSelect) return;
        const res = await crmApi(grandParentSelect.apiBase);
        if (res.ok && res.success) {
            setGrandParentOptions((res.items || []).map((row: any) => ({ value: row[grandParentSelect.idField], label: row.DisplayName })));
        }
    };

    useEffect(() => {
        loadParentOptions();
        loadGrandParentOptions();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** گزینه‌هایِ Selectِ والد — فرم همهٔ والدها را نشان می‌دهد (فقط والدِ مستقیم پرسیده
     * می‌شود)؛ نوارِ فیلترِ گرید اگر جدِّ بزرگ‌تری انتخاب شده باشد، فقط والدهایِ زیرمجموعهٔ
     * همان جد را نشان می‌دهد. */
    const contextField = parentSelect?.optionContextField;
    const toParentOptions = (rows: any[]) =>
        rows.map((row: any) => ({
            value: row[parentSelect?.idField ?? ''],
            label: contextField && row[contextField] ? `${row.DisplayName} — ${row[contextField]}` : row.DisplayName,
        }));
    const formParentOptions = toParentOptions(parentRows);
    const gridParentOptions = toParentOptions(
        grandParentSelect && gridGrandParentFilterId
            ? parentRows.filter((r) => r[grandParentSelect.idField] === gridGrandParentFilterId)
            : parentRows
    );

    const displayedItems = items.filter((row) => {
        if (parentSelect && gridParentFilterId && row[parentSelect.idField] !== gridParentFilterId) return false;
        if (grandParentSelect && gridGrandParentFilterId && row[grandParentSelect.idField] !== gridGrandParentFilterId) return false;
        return true;
    });

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
        loadGrandParentOptions();
        setDraft({ ...emptyDraft });
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (row: MasterDataRow) => {
        loadParentOptions();
        loadGrandParentOptions();
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

    const codeColumn = { title: 'کد', dataIndex: 'Code', key: 'Code', width: 140, render: (v: string) => <Text code dir="ltr" style={{ whiteSpace: 'nowrap' }}>{v}</Text> };
    const nameColumn = { title: 'نام', dataIndex: 'DisplayName', key: 'DisplayName' };

    // در گریدهایِ سلسله‌مراتبی (فعالیت/شهر/شهرستان) ستون‌هایِ سطوحِ بالادستی اول می‌آیند، بعد کد، بعد نام
    const columns: ColumnsType<MasterDataRow> = [
        ...(grandParentSelect?.displayColumn
            ? [{ title: grandParentSelect.displayColumn.title, dataIndex: grandParentSelect.displayColumn.dataIndex, key: grandParentSelect.displayColumn.dataIndex } as const]
            : []),
        ...(parentSelect
            ? [{ title: parentSelect.displayColumn.title, dataIndex: parentSelect.displayColumn.dataIndex, key: parentSelect.displayColumn.dataIndex } as const]
            : []),
        codeColumn,
        nameColumn,
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
                const id = r[idField];
                return (
                    <Space>
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />
                        <Popconfirm
                            title={active ? `غیرفعال‌کردنِ ${entityLabel}` : `فعال‌کردنِ ${entityLabel}`}
                            description="آیا مطمئن هستید؟"
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button type="text" size="small" danger={active} loading={togglingId === id} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <div>
            {canManage ? (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                    <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                        {entityLabel} جدید
                    </Button>
                </div>
            ) : null}

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

            {parentSelect ? (
                <Space style={{ marginBottom: 12 }} wrap>
                    {grandParentSelect ? (
                        <Select
                            style={{ width: 200 }}
                            placeholder={`فیلترِ ${grandParentSelect.label}`}
                            value={gridGrandParentFilterId ?? undefined}
                            onChange={(v) => {
                                setGridGrandParentFilterId(v ?? null);
                                setGridParentFilterId(null);
                            }}
                            options={grandParentOptions}
                            allowClear
                            showSearch
                            filterOption={(input, option) => (option?.label as string)?.toLowerCase().includes(input.toLowerCase())}
                        />
                    ) : null}
                    <Select
                        style={{ width: 200 }}
                        placeholder={`فیلترِ ${parentSelect.label}`}
                        value={gridParentFilterId ?? undefined}
                        onChange={(v) => setGridParentFilterId(v ?? null)}
                        options={gridParentOptions}
                        allowClear
                        showSearch
                        filterOption={(input, option) => (option?.label as string)?.toLowerCase().includes(input.toLowerCase())}
                    />
                </Space>
            ) : null}

            <Table
                rowKey={idField}
                columns={columns}
                dataSource={displayedItems}
                pagination={false}
                scroll={{ x: true }}
                locale={{ emptyText: <Empty description={`${entityLabel}ی ثبت نشده است`} /> }}
            />

            <NotificationModal key={notificationKey} open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
