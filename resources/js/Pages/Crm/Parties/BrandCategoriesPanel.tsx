import { useEffect, useMemo, useState } from 'react';
import { Table, Button, Select, TreeSelect, InputNumber, Space, Tag, Popconfirm, Typography, Alert, Empty, Tooltip, Switch } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, TagsOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { router } from '@inertiajs/react';
import dayjs from 'dayjs';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import PersianDateInput from '../../../Components/PersianDateInput';
import { ProductCategoryRow, toTreeSelectData } from '../../../Components/Crm/productCategoryTree';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import { THEME } from '../../../theme';

const { Text, Link } = Typography;

export interface BrandCategoryRow {
    PartyBrandCategoryID: number | string;
    PartyBrandID: number | string;
    PartyID: number | string;
    BrandID: number | string;
    ProductCategoryID: number | string | null;
    EntryDate: string | null;
    ExitDate: string | null;
    SharePercent: number | string;
    IsActive: boolean | number | string;
    BrandName: string;
    BrandIsActive: boolean | number | string;
    CategoryPath: string | null;
    CategoryIsActive: boolean | number | string | null;
}

interface BrandCategoriesPanelProps {
    partyId: number;
    items: BrandCategoryRow[];
    canManage: boolean;
    /** بعد از هر تغییر — ارتباطِ داخلیِ Party↔Brand هم ممکن است ساخته/فعال شده باشد */
    onChanged: () => void;
}

interface Draft {
    productCategoryId: number | null;
    brandId: number | null;
    sharePercent: number;
    entryDate: string | null;
    exitDate: string | null;
    isActive: boolean;
}

const emptyDraft = (): Draft => ({ productCategoryId: null, brandId: null, sharePercent: 0, entryDate: null, exitDate: null, isActive: true });

const day = (v: string | null) => (v ? v.slice(0, 10) : null);

/** ردیفِ فعالِ دارایِ دسته که امروز داخلِ بازه‌اش است (EntryDate خالی = از ابتدا، ExitDate خالی = بدونِ پایان). */
const coversToday = (r: BrandCategoryRow, today: string) =>
    toBool(r.IsActive) && r.ProductCategoryID !== null && (!r.EntryDate || day(r.EntryDate)! <= today) && (!r.ExitDate || day(r.ExitDate)! >= today);

/**
 * تعریفِ برند برایِ طرف‌حساب — تنها محلِ ثبت/ویرایشِ ارتباطِ برند با طرف‌حساب و دسته/سهم/تاریخ‌ها.
 * برند اجباری؛ دسته و تاریخ‌ها اختیاری؛ درصد پیش‌فرض ۰. هر ردیفِ گرید کامل قابلِ ویرایش است.
 * یکتاییِ فعال (Party+Brand+Category) و سقفِ ۱۰۰٪ (فقط ردیف‌هایِ دارایِ دسته) مرجعشان SP است؛ پیامِ آن نمایش داده می‌شود.
 */
export default function BrandCategoriesPanel({ partyId, items, canManage, onChanged }: BrandCategoriesPanelProps) {
    const [draft, setDraft] = useState<Draft>(emptyDraft());
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editDraft, setEditDraft] = useState<Draft>(emptyDraft());
    const [categories, setCategories] = useState<ProductCategoryRow[]>([]);
    const [activeBrands, setActiveBrands] = useState<{ value: number; label: string }[]>([]);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [rowError, setRowError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const today = dayjs().format('YYYY-MM-DD');
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    useEffect(() => {
        if (!canManage) return;
        (async () => {
            const [cats, brands] = await Promise.all([crmApi('/crm/product-categories-list'), crmApi('/crm/brands?isActive=1')]);
            if (cats.ok && cats.success) setCategories(cats.items || []);
            if (brands.ok && brands.success) setActiveBrands((brands.items || []).map((b: any) => ({ value: Number(b.BrandID), label: b.Name })));
        })();
    }, [canManage]);

    /** گزینه‌هایِ برند: برندهایِ فعال + برندِ فعلیِ ردیف (اگر غیرفعال شده) تا مقدارِ فعلی از دست نرود */
    const brandOptionsFor = (currentId: number | null, currentName?: string) =>
        currentId !== null && !activeBrands.some((b) => b.value === currentId)
            ? [...activeBrands, { value: currentId, label: `${currentName ?? currentId} (غیرفعال)` }]
            : activeBrands;

    const summary = useMemo(() => {
        const map = new Map<string, number>();
        (items || []).forEach((r) => {
            if (coversToday(r, today)) map.set(r.CategoryPath || '', (map.get(r.CategoryPath || '') || 0) + Number(r.SharePercent));
        });
        return Array.from(map, ([path, total]) => ({ path, total })).sort((a, b) => a.path.localeCompare(b.path, 'fa'));
    }, [items, today]);

    const validate = (d: Draft) => {
        if (!d.brandId) return 'برند الزامی است.';
        if (d.sharePercent === null || d.sharePercent === undefined || d.sharePercent < 0 || d.sharePercent > 100) return 'درصد باید بین ۰ تا ۱۰۰ باشد.';
        if (d.entryDate && d.exitDate && d.exitDate < d.entryDate) return 'تاریخِ خروج نمی‌تواند قبل از تاریخِ ورود باشد.';
        return null;
    };

    const payload = (d: Draft, id: number | null) => ({
        partyBrandCategoryId: id ?? undefined,
        partyId: id ? undefined : partyId,
        productCategoryId: d.productCategoryId ?? undefined,
        brandId: d.brandId,
        sharePercent: d.sharePercent ?? 0,
        entryDate: d.entryDate ?? undefined,
        exitDate: d.exitDate ?? undefined,
    });

    const handleCreate = async () => {
        const v = validate(draft);
        if (v) return setError(v);
        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/party-brand-categories', 'POST', payload(draft, null));
        setSaving(false);
        if (!res.ok || !res.success) return setError(res.message);
        setDraft(emptyDraft());
        notify('success', res.message);
        onChanged();
    };

    const startEdit = (r: BrandCategoryRow) => {
        setEditingId(Number(r.PartyBrandCategoryID));
        setEditDraft({
            productCategoryId: r.ProductCategoryID === null ? null : Number(r.ProductCategoryID),
            brandId: Number(r.BrandID),
            sharePercent: Number(r.SharePercent),
            entryDate: day(r.EntryDate),
            exitDate: day(r.ExitDate),
            isActive: toBool(r.IsActive),
        });
        setRowError(null);
    };

    const toggle = (id: number) => crmApi(`/crm/party-brand-categories/${id}/toggle`, 'POST');

    /**
     * ذخیرهٔ ویرایشِ ردیف. ترتیب با وضعیت: اگر ردیف غیرفعال می‌شود اول غیرفعال و بعد ذخیره (ردیفِ غیرفعال
     * بدونِ سقف ذخیره می‌شود)؛ اگر فعال می‌شود اول ذخیره و بعد فعال‌سازی (که همهٔ قواعد را دوباره اجرا می‌کند).
     */
    const saveEdit = async (r: BrandCategoryRow) => {
        const v = validate(editDraft);
        if (v) return setRowError(v);
        const id = Number(r.PartyBrandCategoryID);
        const wasActive = toBool(r.IsActive);
        setSaving(true);
        setRowError(null);

        if (wasActive && !editDraft.isActive) {
            const t = await toggle(id);
            if (!t.ok || !t.success) { setSaving(false); return setRowError(t.message); }
        }
        const res = await crmApi('/crm/party-brand-categories', 'POST', payload(editDraft, id));
        if (!res.ok || !res.success) {
            setSaving(false);
            setRowError(res.message);
            if (wasActive && !editDraft.isActive) onChanged(); // غیرفعال‌سازی انجام شده بود
            return;
        }
        if (!wasActive && editDraft.isActive) {
            const t = await toggle(id);
            if (!t.ok || !t.success) {
                setSaving(false);
                setRowError(`مقادیر ذخیره شد، اما فعال‌سازی انجام نشد: ${t.message}`);
                onChanged();
                return;
            }
        }
        setSaving(false);
        setEditingId(null);
        notify('success', res.message);
        onChanged();
    };

    const handleToggle = async (r: BrandCategoryRow) => {
        setTogglingId(Number(r.PartyBrandCategoryID));
        const res = await toggle(Number(r.PartyBrandCategoryID));
        setTogglingId(null);
        notify(res.ok && res.success ? 'success' : 'error', res.message);
        if (res.ok && res.success) onChanged();
    };

    const categoryTree = (currentId: number | null) => toTreeSelectData(categories, undefined, currentId);

    const isEditing = (r: BrandCategoryRow) => editingId === Number(r.PartyBrandCategoryID);

    const columns: ColumnsType<BrandCategoryRow> = [
        {
            title: 'دسته‌بندی محصول',
            key: 'category',
            render: (_, r) =>
                isEditing(r) ? (
                    <TreeSelect
                        style={{ width: '100%', minWidth: 180 }}
                        allowClear
                        showSearch
                        treeDefaultExpandAll
                        placeholder="بدونِ دسته‌بندی"
                        value={editDraft.productCategoryId ?? undefined}
                        onChange={(v) => setEditDraft((d) => ({ ...d, productCategoryId: v ?? null }))}
                        treeData={categoryTree(r.ProductCategoryID === null ? null : Number(r.ProductCategoryID))}
                        treeNodeFilterProp="title"
                    />
                ) : r.CategoryPath ? (
                    <Space size={4}>
                        <Text>{r.CategoryPath}</Text>
                        {r.CategoryIsActive !== null && !toBool(r.CategoryIsActive) ? <Tag style={{ borderRadius: 6 }}>غیرفعال</Tag> : null}
                    </Space>
                ) : (
                    <Text type="secondary">بدونِ دسته‌بندی</Text>
                ),
        },
        {
            title: 'برند',
            key: 'brand',
            width: 180,
            render: (_, r) =>
                isEditing(r) ? (
                    <Select
                        style={{ width: '100%' }}
                        showSearch
                        optionFilterProp="label"
                        value={editDraft.brandId ?? undefined}
                        onChange={(v) => setEditDraft((d) => ({ ...d, brandId: v }))}
                        options={brandOptionsFor(Number(r.BrandID), r.BrandName)}
                    />
                ) : (
                    <Space size={4}>
                        <Link onClick={() => router.visit(`/crm/brands/${r.BrandID}`)}>{r.BrandName}</Link>
                        {!toBool(r.BrandIsActive) ? <Tag style={{ borderRadius: 6 }}>غیرفعال</Tag> : null}
                    </Space>
                ),
        },
        {
            title: 'درصد سهم',
            key: 'percent',
            width: 120,
            align: 'right',
            render: (_, r) =>
                isEditing(r) ? (
                    <InputNumber
                        style={{ width: 100 }}
                        min={0}
                        max={100}
                        step={0.5}
                        precision={2}
                        value={editDraft.sharePercent}
                        onChange={(v) => setEditDraft((d) => ({ ...d, sharePercent: v === null || v === undefined ? 0 : Number(v) }))}
                    />
                ) : (
                    <Text strong>{Number(r.SharePercent)}٪</Text>
                ),
        },
        {
            title: 'تاریخ ورود',
            key: 'entry',
            width: 150,
            render: (_, r) =>
                isEditing(r) ? (
                    <PersianDateInput size="middle" value={editDraft.entryDate} onChange={(v) => setEditDraft((d) => ({ ...d, entryDate: v }))} />
                ) : r.EntryDate ? (
                    gregorianToJalaliDisplay(day(r.EntryDate)!)
                ) : (
                    <Text type="secondary">از ابتدا</Text>
                ),
        },
        {
            title: 'تاریخ خروج',
            key: 'exit',
            width: 150,
            render: (_, r) =>
                isEditing(r) ? (
                    <PersianDateInput size="middle" value={editDraft.exitDate} onChange={(v) => setEditDraft((d) => ({ ...d, exitDate: v }))} />
                ) : r.ExitDate ? (
                    gregorianToJalaliDisplay(day(r.ExitDate)!)
                ) : (
                    <Text type="secondary">ادامه‌دار</Text>
                ),
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
            align: 'center',
            render: (_, r) => {
                if (isEditing(r)) {
                    return <Switch checked={editDraft.isActive} checkedChildren="فعال" unCheckedChildren="غیرفعال" onChange={(v) => setEditDraft((d) => ({ ...d, isActive: v }))} />;
                }
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
                if (isEditing(r)) {
                    return (
                        <Space size={0}>
                            <Tooltip title="ذخیره">
                                <Button type="text" size="small" loading={saving} icon={<SaveOutlined />} style={{ color: THEME.success }} onClick={() => saveEdit(r)} />
                            </Tooltip>
                            <Tooltip title="انصراف">
                                <Button type="text" size="small" disabled={saving} icon={<CloseOutlined />} onClick={() => { setEditingId(null); setRowError(null); }} />
                            </Tooltip>
                        </Space>
                    );
                }
                const active = toBool(r.IsActive);
                return (
                    <Space size={0}>
                        <Tooltip title="ویرایش">
                            <Button type="text" size="small" disabled={editingId !== null} icon={<EditOutlined />} style={{ color: THEME.info }} onClick={() => startEdit(r)} />
                        </Tooltip>
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ ردیف' : 'فعال‌کردنِ ردیف'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر" disabled={editingId !== null}>
                            <Button
                                type="text"
                                size="small"
                                disabled={editingId !== null}
                                danger={active}
                                loading={togglingId === Number(r.PartyBrandCategoryID)}
                                icon={active ? <StopOutlined /> : <CheckCircleOutlined />}
                            />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <div>
            <Space size={6} style={{ marginBottom: 12 }}>
                <TagsOutlined style={{ color: THEME.primary }} />
                <Text strong>تعریفِ برند برایِ طرف‌حساب</Text>
            </Space>

            {canManage ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={8}>
                    {error ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ borderRadius: 8 }} /> : null}
                    <Space wrap align="end">
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>دسته‌بندی محصول (اختیاری)</Text>
                            <TreeSelect
                                style={{ width: 240 }}
                                allowClear
                                showSearch
                                treeDefaultExpandAll
                                placeholder="بدونِ دسته‌بندی"
                                value={draft.productCategoryId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, productCategoryId: v ?? null }))}
                                treeData={categoryTree(null)}
                                treeNodeFilterProp="title"
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>برند *</Text>
                            <Select
                                style={{ width: 200 }}
                                showSearch
                                optionFilterProp="label"
                                placeholder="انتخابِ برند"
                                value={draft.brandId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, brandId: v }))}
                                options={activeBrands}
                                notFoundContent="برندِ فعالی نیست — از صفحهٔ «برندها» تعریف کنید"
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>درصد سهم</Text>
                            <InputNumber
                                style={{ width: 110 }}
                                min={0}
                                max={100}
                                step={0.5}
                                precision={2}
                                addonAfter="٪"
                                value={draft.sharePercent}
                                onChange={(v) => setDraft((d) => ({ ...d, sharePercent: v === null || v === undefined ? 0 : Number(v) }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>تاریخ ورود (اختیاری)</Text>
                            <PersianDateInput size="middle" value={draft.entryDate} onChange={(v) => setDraft((d) => ({ ...d, entryDate: v }))} />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>تاریخ خروج (اختیاری)</Text>
                            <PersianDateInput size="middle" value={draft.exitDate} onChange={(v) => setDraft((d) => ({ ...d, exitDate: v }))} />
                        </div>
                        <Button type="primary" icon={<PlusOutlined />} loading={saving && editingId === null} onClick={handleCreate}>
                            ثبت
                        </Button>
                    </Space>
                    <Text type="secondary" style={{ fontSize: 12 }}>
                        تاریخِ ورودِ خالی یعنی از ابتدا و تاریخِ خروجِ خالی یعنی ادامه‌دار؛ تاریخِ خروج جزوِ بازه است. مجموعِ درصدِ ردیف‌هایِ فعالِ هم‌زمان در هر دسته‌بندی نباید از ۱۰۰٪ بیشتر شود (ردیفِ بدونِ دسته‌بندی در این سقف نیست).
                    </Text>
                </Space>
            ) : null}

            {summary.length ? (
                <Space wrap size={[8, 8]} style={{ marginBottom: 12 }}>
                    {summary.map((s) => (
                        <Tag key={s.path} color={s.total >= 100 ? 'green' : 'blue'} style={{ borderRadius: 6 }}>
                            {s.path}: امروز {Number(s.total.toFixed(2))}٪ — باقی‌مانده {Number(Math.max(0, 100 - s.total).toFixed(2))}٪
                        </Tag>
                    ))}
                </Space>
            ) : null}

            {rowError ? <Alert type="error" showIcon message={rowError} closable onClose={() => setRowError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            <Table
                rowKey="PartyBrandCategoryID"
                size="small"
                columns={columns}
                dataSource={items || []}
                pagination={false}
                scroll={{ x: 900 }}
                locale={{ emptyText: <Empty description="هنوز برندی برایِ این طرف‌حساب تعریف نشده است" /> }}
            />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
