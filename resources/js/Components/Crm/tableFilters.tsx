import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Input, Select } from 'antd';
import { SearchOutlined } from '@ant-design/icons';

/** یکسان‌سازیِ متن برایِ جست‌وجو: ي/ك عربی، ارقامِ فارسی/عربی، نیم‌فاصله، فاصلهٔ اضافه و حروفِ بزرگ */
export const normalizeForFilter = (value: unknown): string =>
    String(value ?? '')
        .replace(/[يى]/g, 'ی')
        .replace(/ك/g, 'ک')
        .replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
        .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
        .replace(/[‌‏‎]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
        .toLowerCase();

/** تعریفِ فیلترِ یک ستون: text = «شامل بودن» (یکسان‌شده)، exact = برابریِ کامل (برایِ فهرستِ کشویی) */
export type ColumnFilterSpec<T> = { get: (row: T) => unknown; mode: 'text' | 'exact' };

/**
 * فیلترِ ستونیِ شبیهِ اکسل: هر ستون یک مقدار؛ ردیف‌ها همان لحظهٔ تایپ فیلتر می‌شوند (سمتِ مرورگر، رویِ فهرستِ بارگذاری‌شده).
 */
export function useColumnFilters<T>(rows: T[], specs: Record<string, ColumnFilterSpec<T>>) {
    const [values, setValues] = useState<Record<string, string>>({});

    const filtered = useMemo(() => {
        const active = Object.entries(values).filter(([key, v]) => v && specs[key]);
        if (!active.length) return rows;
        return rows.filter((row) =>
            active.every(([key, v]) => {
                const spec = specs[key];
                const cell = spec.get(row);
                if (spec.mode === 'exact') {
                    const c = String(cell ?? '').trim();
                    return v === '\u0000' ? c === '' : c === v;
                }
                return normalizeForFilter(cell).includes(normalizeForFilter(v));
            })
        );
        // specs در هر رندر از نو ساخته می‌شود ولی منطقش ثابت است
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows, values]);

    const setValue = (key: string, value: string) => setValues((cur) => ({ ...cur, [key]: value }));
    const clear = () => setValues({});
    const hasAny = Object.values(values).some(Boolean);

    return { values, setValue, clear, hasAny, filtered };
}

/** سرستون: عنوان + کنترلِ فیلتر زیرِ آن (کلیک رویِ کنترل به ردیف/جدول نمی‌رسد) */
export function FilterHeader({ label, children }: { label: ReactNode; children: ReactNode }) {
    return (
        <div className="xl-filter-head">
            <div className="xl-filter-label">{label}</div>
            <div onClick={(e) => e.stopPropagation()} onKeyDown={(e) => e.stopPropagation()}>
                {children}
            </div>
        </div>
    );
}

export function TextFilterInput({ value, onChange, placeholder = 'جستجو…' }: { value?: string; onChange: (v: string) => void; placeholder?: string }) {
    return (
        <Input
            size="small"
            allowClear
            prefix={<SearchOutlined style={{ color: '#bfbfbf' }} />}
            placeholder={placeholder}
            value={value ?? ''}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}

export function SelectFilterInput({ value, onChange, options, placeholder = 'همه' }: { value?: string; onChange: (v: string) => void; options: { value: string; label: string }[]; placeholder?: string }) {
    return (
        <Select
            size="small"
            allowClear
            showSearch
            placeholder={placeholder}
            style={{ width: '100%', minWidth: 90 }}
            value={value || undefined}
            onChange={(v) => onChange(v ?? '')}
            options={options}
            filterOption={(input, option) => normalizeForFilter(option?.label).includes(normalizeForFilter(input))}
            popupMatchSelectWidth={false}
        />
    );
}

/** گزینه‌هایِ متمایزِ یک ستون از همین داده (برایِ فهرستِ کشویی) */
export function distinctOptions<T>(rows: T[], get: (row: T) => string | null | undefined, emptyLabel = '— (خالی)') {
    const values = Array.from(new Set(rows.map((r) => (get(r) || '').trim()))).sort((a, b) => a.localeCompare(b, 'fa'));
    // مقدارِ خالی با «\u0000» نمایندگی می‌شود تا با «بدونِ فیلتر» ('') قاطی نشود
    return values.map((v) => ({ value: v === '' ? '\u0000' : v, label: v || emptyLabel }));
}

/** CSSِ مشترکِ سرستون‌هایِ فیلتردار */
export const filterHeaderCss = `
    .xl-filter-head { display: flex; flex-direction: column; gap: 6px; min-width: 110px; }
    .xl-filter-label { font-weight: 600; white-space: nowrap; }
`;
