import PivotFilter from './ReportValueFilter';
import { Confirmation } from '../../Components/Confirmation';
import './sales-pivot.css';
import { DndContext, useDraggable, useDroppable, PointerSensor, KeyboardSensor, useSensor, useSensors, pointerWithin, rectIntersection } from '@dnd-kit/core';
import type { ReactNode } from 'react';
import type { TableColumnsType } from 'antd';
import { useEffect, useState } from 'react';
import { Alert, Button, Card, ConfigProvider, Input, Modal, Pagination, Select, Space, Table } from 'antd';
import axios from 'axios';
import MainLayout from '../../Layouts/MainLayout';

const salesLabels: Record<string, string> = {
    tc_code: 'کد مرکز', tc_name: 'مرکز', iop_code: 'کد مشتری', iop_name: 'مشتری',
    YM: 'سال و ماه', tmp_code: 'کد تیپ', tmp_name: 'تیپ', i_code: 'کد کالا', i_name: 'کالا',
    hr_code: 'کد بازاریاب', Visitor: 'بازاریاب', AmountPrice: 'مبلغ فروش', QtyConfirmed: 'تعداد فروش',
};
const salesMeasures = ['AmountPrice', 'QtyConfirmed'];
const zones = { filters: 'فیلترها', rows: 'ردیف‌ها', columns: 'ستون‌ها', values: 'مقادیر' };
const pivotZones = ['columns', 'filters', 'values', 'rows'] as const;
type Zone = keyof typeof zones;
type Settings = Record<Zone, string[]> & { criteria: Record<string, string[] | string> };
type Drag = { field: string; from?: Zone };
type Sums = string[];
type Report = {
    rowItems: Record<string, (string | number | null)[]>;
    columnItems: Record<string, (string | number | null)[]>;
    cells: Record<string, Record<string, Sums>>;
    rowTotals: Record<string, Sums>;
    columnTotals: Record<string, Sums>;
    grand: Sums;
    totalRows: number;
    pageSize: number;
};
const tones = { filters: 'zone-filters', rows: 'zone-rows', columns: 'zone-columns', values: 'zone-values' };
const filterValues = (value: string[] | string | undefined) => Array.isArray(value) ? value : value ? [value] : [];

function Handle({ item, caption }: { item: Drag; caption: string }) {
    const { attributes, listeners, setNodeRef } = useDraggable({ id: (item.from ?? 'field') + ':' + item.field, data: item });
    return <button type="button" ref={setNodeRef} {...attributes} {...listeners} aria-label={'جابجایی ' + caption}
        className="sales-pivot-handle">⠿</button>;
}

function DropArea({ zone, before, children, disabled }: { zone: Zone; before?: string; children: ReactNode; disabled?: boolean }) {
    const { setNodeRef, isOver } = useDroppable({ id: zone + ':' + (before ?? ''), data: { zone, before }, disabled });
    return <div ref={setNodeRef} className={before ? (isOver ? 'sales-pivot-slot is-over' : 'sales-pivot-slot')
        : 'sales-pivot-zone ' + tones[zone] + (isOver ? ' is-over' : '')}>{children}</div>;
}

const initial: Settings = { filters: [], rows: ['YM'], columns: [], values: salesMeasures, criteria: {} };
const emptyLayout: Settings = { filters: [], rows: [], columns: [], values: [], criteria: {} };
type NamedLayout = { LayoutID: number; LayoutName: string; UpdatedAtUtc: string; IsDefault: boolean | number };
type LayoutResponse = { personal: Settings | null; default: Settings | null; invalid: boolean; layouts: NamedLayout[]; selectedId: number | null };
const decimalPlaces = 38;
const decimalBase = 10n ** BigInt(decimalPlaces);
function decimalUnits(value: string) {
    const [integer, fraction = ''] = value.replace(/^-/, '').split('.');
    const magnitude = BigInt(integer) * decimalBase + BigInt(fraction.padEnd(decimalPlaces, '0'));
    return value.startsWith('-') ? -magnitude : magnitude;
}
function formatUnits(value: bigint) {
    const magnitude = value < 0n ? -value : value;
    const fraction = (magnitude % decimalBase).toString().padStart(decimalPlaces, '0').replace(/0+$/, '');
    return (value < 0n ? '-' : '') + (magnitude / decimalBase).toString() + (fraction ? '.' + fraction : '');
}
const number = (value?: string) => value?.replace(/^[+-]?\d+/, (integer) => integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',')) ?? '—';
const label = (items: (string | number | null)[]) => items.map((item) => String(item ?? 'بدون مقدار').trim()).join(' / ');

type ReportParameter = { field: string; title: string; operator: string; kind: string; required: boolean; default?: string[]; options?: { label: string; value: string }[] };

export function SalesPivot({ fields, userId, fieldLabels, measureFields, reportCode, title, allowExcel = true, parameters = [] }: {
    fields: string[]; userId: string; fieldLabels?: Record<string, string>; measureFields?: string[];
    reportCode?: string; title?: string; allowExcel?: boolean; parameters?: ReportParameter[];
}) {
    const reportUrl = reportCode ? '/reports/' + encodeURIComponent(reportCode) : undefined;
    const labels = fieldLabels ?? salesLabels;
    const measures = measureFields ?? salesMeasures;
    const [parameterValues, setParameterValues] = useState<Record<string, string[]>>(() =>
        Object.fromEntries(parameters.map((item) => [item.field, item.default ?? []])));
    const missingRequired = parameters.some(({ field, required, operator }) => {
        const count = parameterValues[field]?.filter(Boolean).length ?? 0;
        return (required || count > 0) && count < (operator === 'between' ? 2 : 1);
    });
    const storageKey = 'sales-pivot:' + userId;
    const layoutUrl = reportUrl ? reportUrl + '/layout' : undefined;
    const [settings, setSettings] = useState<Settings>(() => reportCode ? emptyLayout : initial);
    const [layoutBusy, setLayoutBusy] = useState(false);
    const [navigation, setNavigation] = useState({ spec: initial, page: 1, parameterValues });
    const page = navigation.spec === settings && navigation.parameterValues === parameterValues ? navigation.page : 1;
    const [drag, setDrag] = useState<Drag>();
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }), useSensor(KeyboardSensor));
    const [report, setReport] = useState<{ data: Report; spec: Settings }>();
    const [busy, setBusy] = useState(true);
    const [search, setSearch] = useState('');
    const [notice, setNotice] = useState('');
    const [error, setError] = useState('');

    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [layouts, setLayouts] = useState<NamedLayout[]>([]);
    const [listOpen, setListOpen] = useState(false);
    const [layoutSearch, setLayoutSearch] = useState('');
    const normalizeLayoutName = (value: string) => value.trim().toLocaleLowerCase('fa-IR').replace(/ي/g, 'ی').replace(/ك/g, 'ک');
    const visibleLayouts = layouts.filter((item) => normalizeLayoutName(item.LayoutName).includes(normalizeLayoutName(layoutSearch)));
    const [editor, setEditor] = useState<{ id?: number; name: string }>();
    const [layoutError, setLayoutError] = useState('');

    const openLayout = async (url: string, useDefault = false, signal?: AbortSignal, id?: number, listOnly = false) => {
        setLayoutBusy(true);
        try {
            const { data } = await axios.get<LayoutResponse>(url, { signal, params: { id } });
            if (signal?.aborted) return;
            setLayouts(data.layouts);
            if (listOnly) return;
            if (id && !data.personal) { setLayoutError('این چیدمان با تعریف فعلی گزارش سازگار نیست.'); return; }
            if (id) setListOpen(false);
            setSelectedId(useDefault ? null : data.selectedId);
            setSettings(useDefault ? data.default ?? emptyLayout : data.personal ?? data.default ?? emptyLayout);
            setNotice(data.invalid ? 'برخی فیلدهای چیدمان ذخیره‌شده دیگر با تعریف گزارش سازگار نیستند.' : '');
        } catch {
            if (!signal?.aborted) setNotice('بازیابی چیدمان انجام نشد.');
        } finally {
            if (!signal?.aborted) setLayoutBusy(false);
        }
    };

    const mutateLayout = async (action: 'save' | 'rename' | 'delete' | 'default', id?: number, name?: string) => {
        if (!layoutUrl) return;
        setLayoutBusy(true);
        setLayoutError('');
        try {
            const criteria = Object.fromEntries(settings.filters.map((field) => [field,
                filterValues(settings.criteria[field]).map((value) => value.trim()).filter(Boolean)]));
            const { data } = await axios.put<{ id: number }>(layoutUrl, {
                action, id, name, layout: action === 'save' ? { ...settings, criteria, aggregation: 'SUM' } : undefined,
            });
            if (action === 'save') setSelectedId(data.id);
            if (action === 'delete' && id === selectedId) setSelectedId(null);
            setEditor(undefined);
            setNotice(action === 'delete' ? 'چیدمان حذف شد.' : 'چیدمان ذخیره شد.');
            if (listOpen) await openLayout(layoutUrl, false, undefined, undefined, true);
        } catch (failure) {
            const message = axios.isAxiosError(failure) ? failure.response?.data?.errors?.name?.[0] : undefined;
            setLayoutError(message ?? 'عملیات چیدمان انجام نشد.');
        } finally {
            setLayoutBusy(false);
        }
    };

    useEffect(() => {
        if (!layoutUrl) return;
        const request = new AbortController();
        setSettings(emptyLayout);
        void openLayout(layoutUrl, false, request.signal);
        return () => request.abort();
    }, [layoutUrl]);

    useEffect(() => {
        setError('');
        setReport(undefined);
        setBusy(Boolean(settings.values.length) && !missingRequired);
        if (!settings.values.length || missingRequired) return;
        const request = new AbortController();
        const timer = window.setTimeout(async () => {
            try {
                const submittedParameters = Object.fromEntries(Object.entries(parameterValues)
                    .map(([field, values]) => [field, values.every((value) => !value) ? [] : values]));
                const { data } = await axios.post<Report>((reportUrl ?? '/sales-report') + '/execute', { parameterValues: submittedParameters,
                    rows: settings.rows, columns: settings.columns, values: settings.values, page,
                    filters: settings.filters.flatMap((field) => {
                        const values = filterValues(settings.criteria[field]).map((value) => value.trim()).filter(Boolean);
                        return values.length ? [{ field, values }] : [];
                    }),
                }, { signal: request.signal });
                if (!request.signal.aborted) setReport({ data, spec: settings });
            } catch (failure) {
                if (!request.signal.aborted) {
                    setError(axios.isAxiosError(failure) && failure.response?.data?.message === 'Pivot column limit'
                        ? 'تعداد گروه‌های ستونی بیش از ۲۴ است. فیلتر اضافه کنید یا فیلد را به ردیف‌ها منتقل کنید.'
                        : 'گزارش ساخته نشد. فیلترها و دسترسی به منبع فروش را بررسی کنید.');
                }
            } finally {
                if (!request.signal.aborted) setBusy(false);
            }
        }, 350);
        return () => { window.clearTimeout(timer); request.abort(); };
    }, [settings, page, parameterValues, reportUrl, missingRequired]);

    const place = ({ field, from }: Drag, to: Zone, before?: string) => {
        if (!fields.includes(field) || (to === 'values' && !measures.includes(field)) || (from === to && before === field)) return;
        setSettings((current) => {
            const next = { ...current };
            if (from) next[from] = next[from].filter((item) => item !== field);
            if (to === 'rows' || to === 'columns') {
                const other = to === 'rows' ? 'columns' : 'rows';
                next[other] = next[other].filter((item) => item !== field);
            }
            const items = next[to].filter((item) => item !== field);
            if (items.length >= (to === 'values' ? 2 : to === 'filters' ? 13 : 5)) return current;
            const index = before ? items.indexOf(before) : -1;
            items.splice(index < 0 ? items.length : index, 0, field);
            next[to] = items;
            return next;
        });
    };
    const data = report?.data;
    const spec = report?.spec ?? settings;
    const pivotColumns = Object.entries(data?.columnItems ?? {});
    const hasRows = Object.keys(data?.rowItems ?? {}).length > 0;
    const headers = [
        ...(spec.rows.length ? spec.rows.map((field) => labels[field]) : ['سطر']),
        ...pivotColumns.flatMap(([, values]) => spec.values.map((field) => [label(values), labels[field]].filter(Boolean).join(' · '))),
        ...(spec.columns.length ? spec.values.map((field) => 'جمع · ' + labels[field]) : []),
    ];
    const output = Object.entries(data?.rowItems ?? {}).map(([id, values]) => ({
        id,
        values: [
            ...(spec.rows.length ? [...values.map((value) => String(value ?? 'بدون مقدار').trim()), ...Array(spec.rows.length - values.length).fill('')].map((value, index) => values.length < spec.rows.length && index === values.length - 1 ? 'جمع ' + value : value) : ['همه']),
            ...pivotColumns.flatMap(([key]) => spec.values.map((_, index) => data?.cells[id]?.[key]?.[index] ?? '0')),
            ...(spec.columns.length ? data?.rowTotals[id] ?? [] : []),
        ],
    }));
    if (hasRows && spec.rows.length) {
        const leafRows = output.filter(({ id }) => data?.rowItems[id].length === spec.rows.length);
        const dimensionCount = spec.rows.length;
        output.push({ id: 'page-total', values: [
            'جمع این صفحه', ...spec.rows.slice(1).map(() => ''),
            ...headers.slice(dimensionCount).map((_, index) => formatUnits(leafRows.reduce(
                (sum, row) => sum + decimalUnits(row.values[dimensionCount + index] ?? '0'), 0n,
            ))),
        ] });
        output.push({ id: 'total', values: [
            'جمع کل همهٔ صفحات', ...spec.rows.slice(1).map(() => ''),
            ...pivotColumns.flatMap(([key]) => data?.columnTotals[key] ?? []),
            ...(spec.columns.length ? data?.grand ?? [] : []),
        ] });
    }

    type OutputRow = (typeof output)[number];
    const rowNumber = (row: OutputRow, index: number) =>
        row.id === 'page-total' || row.id === 'total' ? undefined : (page - 1) * (data?.pageSize ?? 0) + index + 1;
    const dimensionCount = Math.max(1, spec.rows.length);
    const leaf = (title: string, index: number) => ({
        title, key: String(index),
        render: (_: unknown, row: OutputRow) => index < dimensionCount
            ? row.values[index] : <bdi className="sales-pivot-number">{number(row.values[index])}</bdi>,
    });
    const tableColumns: TableColumnsType<OutputRow> = [
        { title: 'ردیف', key: 'row-number', align: 'center', width: 64,
            render: (_: unknown, row, index) => rowNumber(row, index) },
        ...headers.slice(0, dimensionCount).map(leaf),
    ];
    const groups: TableColumnsType<OutputRow> = [];
    pivotColumns.forEach(([, path], columnIndex) => {
        let level = groups;
        path.forEach((value, depth) => {
            const key = JSON.stringify(path.slice(0, depth + 1));
            let group = level.find((entry) => entry.key === key);
            if (!group) {
                group = { key, title: label([value]), children: [] };
                level.push(group);
            }
            if ('children' in group) level = group.children;
        });
        level.push(...spec.values.map((field, index) => leaf(labels[field], dimensionCount + columnIndex * spec.values.length + index)));
    });
    tableColumns.push(...groups);
    if (spec.columns.length) {
        tableColumns.push({ title: 'جمع کل', key: 'totals', children: spec.values.map((field, index) =>
            leaf(labels[field], dimensionCount + pivotColumns.length * spec.values.length + index)) });
    }

    const exportExcel = async () => {
        try {
            const ExcelJS = (await import('exceljs')).default;
            const { saveAs } = await import('file-saver');
            const workbook = new ExcelJS.Workbook();
            const sheet = workbook.addWorksheet('فروش', { views: [{ rightToLeft: true }] });
            sheet.addRow(['ردیف', ...headers]).font = { bold: true };
            const dimensions = Math.max(1, spec.rows.length);
            for (const [rowIndex, row] of output.entries()) sheet.addRow([rowNumber(row, rowIndex) ?? '', ...row.values.map((value, index) => {
                // Excel preserves only 15 significant digits in numeric cells.
                return index >= dimensions && /^-?\d{1,15}$/.test(value) ? Number(value) : value;
            })]);
            saveAs(new Blob([new Uint8Array(await workbook.xlsx.writeBuffer())]), 'sales-pivot.xlsx');
        } catch {
            setNotice('ساخت فایل Excel انجام نشد.');
        }
    };

    return <ConfigProvider direction="rtl" theme={{ components: { Table: { headerBg: '#18365c', headerColor: '#fff' } } }}>
        <DndContext sensors={sensors} collisionDetection={(args) => {
            const hits = pointerWithin(args);
            return hits.length ? hits.sort((a, b) => Number(!a.id.toString().split(':')[1]) - Number(!b.id.toString().split(':')[1])) : rectIntersection(args);
        }} onDragStart={({ active }) => setDrag(active.data.current as Drag)}
            onDragCancel={() => setDrag(undefined)}
            onDragEnd={({ active, over }) => {
                const destination = over?.data.current as { zone: Zone; before?: string } | undefined;
                if (destination) place(active.data.current as Drag, destination.zone, destination.before);
                setDrag(undefined);
            }}>
            <div dir="rtl" className="sales-pivot">
                <header className="sales-pivot-header">
                    <h1 className="sales-pivot-title">{title ?? 'گزارش‌ساز فروش محوری'}</h1>
                    <Space wrap>
                        {layoutUrl ? <>
                            <Button disabled={layoutBusy} onClick={() => { setLayoutError(''); setListOpen(true); void openLayout(layoutUrl, false, undefined, undefined, true); }}>باز کردن چیدمان</Button>
                            <Button disabled={layoutBusy} onClick={() => selectedId ? void mutateLayout('save', selectedId) : setEditor({ name: '' })}>ذخیره</Button>
                            <Button disabled={layoutBusy} onClick={() => setEditor({ name: '' })}>ذخیره به‌عنوان جدید</Button>
                            <Button disabled={layoutBusy} onClick={() => void openLayout(layoutUrl, true)}>بازگشت به پیش‌فرض</Button>
                            <Modal title="چیدمان‌های من" open={listOpen} footer={null} onCancel={() => setListOpen(false)}>
                                {layoutError && <Alert type="error" message={layoutError} />}
                                <Input aria-label="جست‌وجوی چیدمان" placeholder="جست‌وجو بر اساس نام چیدمان" allowClear
                                    value={layoutSearch} onChange={(event) => setLayoutSearch(event.target.value)} />
                                <div style={{ maxHeight: visibleLayouts.length > 10 ? 'min(60vh, 640px)' : undefined, overflowY: 'auto' }}>
                                {!visibleLayouts.length && <p>{layouts.length ? 'چیدمانی با این نام پیدا نشد.' : 'چیدمانی ذخیره نشده است.'}</p>}
                                {visibleLayouts.map((item, index) => <div key={item.LayoutID} style={{ paddingBlock: 8 }}>
                                    <strong>{(index + 1).toLocaleString('fa-IR')}. {item.LayoutName}{item.IsDefault ? ' · پیش‌فرض' : ''}</strong>
                                    <div>{new Date(item.UpdatedAtUtc.replace(' ', 'T').replace(/Z?$/, 'Z')).toLocaleString('fa-IR')}</div>
                                    <Space wrap>
                                        <Button disabled={layoutBusy} onClick={async () => { setLayoutError(''); await openLayout(layoutUrl, false, undefined, item.LayoutID); }}>باز کردن</Button>
                                        <Button disabled={layoutBusy} onClick={() => { setLayoutError(''); setEditor({ id: item.LayoutID, name: item.LayoutName }); }}>تغییر نام</Button>
                                        <Button disabled={layoutBusy || !!item.IsDefault} onClick={() => void mutateLayout('default', item.LayoutID)}>پیش‌فرض شخصی</Button>
                                        <Confirmation title="این چیدمان حذف شود؟" okText="حذف" cancelText="لغو" onConfirm={() => mutateLayout('delete', item.LayoutID)}>
                                            <Button danger disabled={layoutBusy}>حذف</Button>
                                        </Confirmation>
                                    </Space>
                                </div>)}
                                </div>
                            </Modal>
                            <Modal title={editor?.id ? 'تغییر نام چیدمان' : 'نام چیدمان جدید'} open={!!editor}
                                confirmLoading={layoutBusy} onCancel={() => setEditor(undefined)} okText="ذخیره" cancelText="لغو"
                                okButtonProps={{ disabled: !editor?.name.trim() }}
                                onOk={() => editor && mutateLayout(editor.id ? 'rename' : 'save', editor.id, editor.name.trim())}>
                                {layoutError && <Alert type="error" message={layoutError} />}
                                <Input aria-label="نام چیدمان" maxLength={120} value={editor?.name ?? ''}
                                    onChange={(event) => editor && setEditor({ ...editor, name: event.target.value })} />
                            </Modal>
                        </> : <><Button onClick={() => {
                            try { const saved = localStorage.getItem(storageKey); if (saved) { setSettings(JSON.parse(saved)); setNotice(''); } else setNotice('تنظیمی ذخیره نشده است.'); }
                            catch { setNotice('بازیابی تنظیمات انجام نشد.'); }
                        }}>گزارش من</Button>
                        <Button onClick={() => {
                            try { localStorage.setItem(storageKey, JSON.stringify(settings)); setNotice('گزارش ذخیره شد.'); }
                            catch { setNotice('ذخیره گزارش انجام نشد.'); }
                        }}>ذخیره گزارش</Button></>}
                        {allowExcel && <Button type="primary" disabled={!output.length || busy} onClick={() => void exportExcel()}>Excel این صفحه</Button>}
                    </Space>
                </header>
                <div className="sales-pivot-layout">
                    <section className="sales-pivot-main">
                        {parameters.length > 0 && <Card title="پارامترهای گزارش" size="small">
                            <Space wrap>{parameters.map((parameter) => {
                                const values = parameterValues[parameter.field] ?? [];
                                const update = (next: string[]) => setParameterValues((current) => ({ ...current, [parameter.field]: next }));
                                return <div key={parameter.field}>
                                    <div>{parameter.title}{parameter.required ? ' *' : ''}</div>
                                    {parameter.operator === 'in' || parameter.options ? <Select
                                        aria-label={parameter.title} style={{ minWidth: 170 }}
                                        mode={parameter.operator === 'in' ? (parameter.options ? 'multiple' : 'tags') : undefined}
                                        value={parameter.operator === 'in' ? values : values[0]}
                                        options={parameter.options ?? []} allowClear
                                        onChange={(next) => update(Array.isArray(next) ? next : next ? [next] : [])} />
                                        : Array.from({ length: parameter.operator === 'between' ? 2 : 1 }, (_, index) =>
                                            <Input key={index} aria-label={parameter.title + (index ? ' تا' : '')}
                                                type={parameter.kind.startsWith('date') || parameter.kind === 'smalldatetime' ? 'date' : 'text'}
                                                value={values[index] ?? ''} onChange={(event) => {
                                                    const next = [...values]; next[index] = event.target.value; update(next);
                                                }} />)}
                                </div>;
                            })}</Space>
                        </Card>}
                        <Card title="طراحی گزارش محوری" size="small">
                            <p className="sales-pivot-hint">فیلدها را با دستگیره به ناحیهٔ دلخواه بکشید.</p>
                            <div className="sales-pivot-zones">
                                {pivotZones.map((zone) => {
                                    const disabled = zone === 'values' && !!drag && !measures.includes(drag.field);
                                    return <DropArea key={zone} zone={zone} disabled={disabled}>
                                        <h2 className="sales-pivot-zone-title">{zones[zone]}</h2>
                                        {!settings[zone].length && <span className="sales-pivot-empty">فیلد را اینجا رها کنید</span>}
                                        {settings[zone].map((field) => <DropArea key={field} zone={zone} before={field} disabled={disabled}>
                                            <div className="sales-pivot-chip">
                                                <Handle item={{ field, from: zone }} caption={labels[field]} />
                                                <span className="sales-pivot-chip-label">{labels[field]}{zone === 'values' ? ' (جمع)' : ''}</span>
                                                <Button type="text" size="small" aria-label={'حذف ' + labels[field]} onClick={() =>
                                                    setSettings((current) => ({ ...current, [zone]: current[zone].filter((item) => item !== field) }))}>×</Button>
                                            </div>
                                            {zone === 'filters' && <PivotFilter field={field} caption={labels[field]} reportUrl={reportUrl} parameterValues={parameterValues}
                                                value={filterValues(settings.criteria[field])}
                                                onChange={(values) => setSettings((current) => ({ ...current, criteria: { ...current.criteria, [field]: values } }))} />}
                                        </DropArea>)}
                                    </DropArea>;
                                })}
                            </div>
                        </Card>
                        {notice && <Alert message={notice} />}
                        {layoutError && !editor && !listOpen && <Alert type="error" message={layoutError} />}
                        {error && <Alert type="error" message={error} />}
                        <Card title="نتیجه گزارش" size="small" className="sales-pivot-results">
                            <Table loading={busy} rowKey="id" size="small" bordered scroll={{ x: 'max-content' }} pagination={false}
                                rowClassName={(row) => row.id === 'total' ? 'sales-pivot-total' : row.id === 'page-total' || (data?.rowItems[row.id]?.length ?? 0) < spec.rows.length ? 'sales-pivot-subtotal' : ''}
                                locale={{ emptyText: settings.values.length ? 'نتیجه‌ای برای نمایش وجود ندارد' : 'مبلغ یا تعداد فروش را به مقادیر اضافه کنید' }}
                                columns={tableColumns}
                                dataSource={output} />
                            {data && data.totalRows > data.pageSize && <Pagination current={page} pageSize={data.pageSize}
                                total={data.totalRows} showSizeChanger={false}
                                onChange={(nextPage) => setNavigation({ spec: settings, page: nextPage, parameterValues })} />}
                        </Card>
                    </section>
                    <aside className="sales-pivot-fields">
                        <Card title={'فیلدهای گزارش (' + fields.length.toLocaleString('fa-IR') + ')'} size="small">
                            <Input aria-label="جست‌وجوی فیلد" placeholder="جست‌وجوی فیلد…" allowClear value={search} onChange={(event) => setSearch(event.target.value)} />
                            <div className="sales-pivot-field-list">
                                {fields.filter((field) => labels[field].includes(search.trim()) || field.toLowerCase().includes(search.trim().toLowerCase())).map((field) =>
                                    <div key={field} className="sales-pivot-field">
                                        <span>{labels[field]}</span><Handle item={{ field }} caption={labels[field]} />
                                    </div>)}
                            </div>
                        </Card>
                    </aside>
                </div>
            </div>
        </DndContext>
    </ConfigProvider>;
}

export default function SalesReport(props: Parameters<typeof SalesPivot>[0]) {
    return <MainLayout><SalesPivot key={props.reportCode ?? 'sales'} {...props} /></MainLayout>;
}
