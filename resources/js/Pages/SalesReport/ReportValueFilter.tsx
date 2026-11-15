import { useEffect, useState } from 'react';
import { Select } from 'antd';
import axios from 'axios';

export default function ReportValueFilter({ field, caption, value, onChange, reportUrl, parameterValues }: { field: string; caption: string; value: string[]; onChange: (values: string[]) => void; reportUrl?: string; parameterValues: Record<string, string[]> }) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!open) return;
        const request = new AbortController();
        setLoading(true);
        setFailed(false);
        setOptions([]);
        const timer = window.setTimeout(async () => {
            try {
                const payload = { field, search };
                const { data } = reportUrl
                    ? await axios.post<string[]>(reportUrl + '/pivot-options', { ...payload, parameterValues }, { signal: request.signal })
                    : await axios.get<string[]>('/sales-report/filter-options', { params: payload, signal: request.signal });
                if (!request.signal.aborted) setOptions(data);
            } catch {
                if (!request.signal.aborted) setFailed(true);
            } finally {
                if (!request.signal.aborted) setLoading(false);
            }
        }, 300);
        return () => { window.clearTimeout(timer); request.abort(); };
    }, [field, open, search, reportUrl, parameterValues]);

    return <Select mode="multiple" allowClear size="small" className="sales-pivot-filter" maxCount={20}
        aria-label={caption} placeholder="همه · جست‌وجو و انتخاب"
        showSearch={{ filterOption: false, onSearch: setSearch, searchValue: search }}
        onOpenChange={(visible) => { setOpen(visible); if (!visible) setSearch(''); }}
        value={value} onChange={(values) => { onChange(values); setSearch(''); }}
        options={options.map((item) => ({ label: item, value: item }))} loading={loading}
        notFoundContent={loading ? 'در حال بارگذاری…' : failed ? 'دریافت گزینه‌ها ناموفق بود' : 'موردی پیدا نشد'} />;
}
