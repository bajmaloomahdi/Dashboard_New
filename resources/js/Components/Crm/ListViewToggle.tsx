import { useState } from 'react';
import { Segmented, Tooltip } from 'antd';
import { AppstoreOutlined, TableOutlined } from '@ant-design/icons';

export type ListViewMode = 'cards' | 'grid';

/**
 * نحوهٔ نمایشِ فهرست (کارت/جدول) — انتخابِ هر کاربر در مرورگرِ خودش (localStorage) به ازایِ هر صفحه
 * نگه داشته می‌شود؛ اگر ذخیره‌سازی در دسترس نباشد، پیش‌فرض «کارت» است.
 */
export function useListViewMode(storageKey: string): [ListViewMode, (mode: ListViewMode) => void] {
    const [mode, setMode] = useState<ListViewMode>(() => {
        try {
            return window.localStorage.getItem(storageKey) === 'grid' ? 'grid' : 'cards';
        } catch {
            return 'cards';
        }
    });

    const change = (next: ListViewMode) => {
        setMode(next);
        try {
            window.localStorage.setItem(storageKey, next);
        } catch {
            /* ذخیره‌نشدنِ انتخاب مشکلی ایجاد نمی‌کند */
        }
    };

    return [mode, change];
}

export default function ListViewToggle({ value, onChange }: { value: ListViewMode; onChange: (mode: ListViewMode) => void }) {
    return (
        <Segmented
            size="large"
            value={value}
            onChange={(v) => onChange(v as ListViewMode)}
            options={[
                { value: 'cards', label: <Tooltip title="نمایشِ کارتی"><span><AppstoreOutlined /> کارت</span></Tooltip> },
                { value: 'grid', label: <Tooltip title="نمایشِ جدولی"><span><TableOutlined /> جدول</span></Tooltip> },
            ]}
        />
    );
}
