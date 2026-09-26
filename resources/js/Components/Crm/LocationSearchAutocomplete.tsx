import { useEffect, useRef, useState } from 'react';
import { Alert, AutoComplete, Button, Input, Space, Tooltip, Typography } from 'antd';
import { EnvironmentOutlined, LoadingOutlined, SearchOutlined } from '@ant-design/icons';
import { crmApi } from './crmApi';

const { Text } = Typography;

/** حداقلِ طولِ عبارت برایِ جست‌وجویِ خودکار (ذره‌بین از ۱ نویسه هم جست‌وجو می‌کند) */
const MIN_CHARS = 2;
/** تأخیرِ پس از آخرین نویسه تا ارسالِ خودکارِ درخواست */
const DEBOUNCE_MS = 400;

export interface LocationSearchResult {
    title: string;
    address: string | null;
    region: string | null;
    neighbourhood: string | null;
    /** بخشی از عبارت که نشان نتوانست تطبیق دهد (نتیجه تقریبی است) */
    unmatched: string | null;
    latitude: number;
    longitude: number;
}

interface LocationSearchAutocompleteProps {
    /** نامِ شهرِ انتخاب‌شده در فرمِ CRM — همراهِ هر درخواست ارسال می‌شود */
    cityName: string | null;
    onSelect: (result: LocationSearchResult) => void;
}

const labelOf = (r: LocationSearchResult) => (r.address ? `${r.title}، ${r.address}` : r.title);

const isAbort = (e: unknown) => e instanceof DOMException && e.name === 'AbortError';

/**
 * جست‌وجویِ خودکارِ محل (Autocomplete) رویِ proxyِ سرورِ «تبدیل آدرس به نقطه» نشان — مشابهِ خودِ نشان:
 * با تایپ (حداقل MIN_CHARS نویسه) و مکثِ DEBOUNCE_MS درخواست خودکار ارسال و پیشنهادها زیرِ کادر نمایش داده
 * می‌شوند. هر درخواستِ تازه درخواستِ قبلی را Abort می‌کند و پاسخِ دیررسِ قدیمی هم نادیده گرفته می‌شود.
 * Enter = انتخابِ پیشنهادِ فعال (پیش‌فرض: اولی)؛ اگر پیشنهادی آماده نیست، فوراً جست‌وجو و اولین نتیجه انتخاب می‌شود.
 * ذره‌بین فقط جست‌وجویِ فوری/اجباری است (بدونِ انتخابِ خودکار). کلیدِ نشان هیچ‌جا در مرورگر نیست.
 */
export default function LocationSearchAutocomplete({ cityName, onSelect }: LocationSearchAutocompleteProps) {
    const [text, setText] = useState('');
    const [results, setResults] = useState<LocationSearchResult[]>([]);
    const [open, setOpen] = useState(false);
    const [loading, setLoading] = useState(false);
    const [searchedTerm, setSearchedTerm] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const abortRef = useRef<AbortController | null>(null);
    const seqRef = useRef(0);

    const cancelPending = () => {
        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = null;
        abortRef.current?.abort();
        abortRef.current = null;
    };

    useEffect(() => cancelPending, []);

    const choose = (r: LocationSearchResult) => {
        cancelPending();
        setText(labelOf(r));
        setOpen(false);
        setResults([]);
        setSearchedTerm(null);
        onSelect(r);
    };

    /** درخواستِ تازه؛ قبلی Abort می‌شود. selectFirst = رفتارِ Enter وقتی پیشنهادی آماده نبود. */
    const fetchResults = async (term: string, selectFirst = false) => {
        cancelPending();
        const controller = new AbortController();
        abortRef.current = controller;
        const seq = ++seqRef.current;
        setLoading(true);
        setError(null);

        try {
            const res = await crmApi(
                `/crm/addresses/location-search?${new URLSearchParams({ term, ...(cityName ? { city: cityName } : {}) })}`,
                'GET',
                undefined,
                controller.signal
            );
            if (seq !== seqRef.current) return; // پاسخِ قدیمی
            setLoading(false);
            abortRef.current = null;
            if (!res.ok || !res.success) {
                setResults([]);
                setOpen(false);
                setError(res.message);
                return;
            }
            const items: LocationSearchResult[] = res.items || [];
            if (selectFirst && items.length > 0) {
                choose(items[0]);
                return;
            }
            setResults(items);
            setSearchedTerm(term);
            setOpen(true);
        } catch (e) {
            if (isAbort(e) || seq !== seqRef.current) return;
            setLoading(false);
            setResults([]);
            setOpen(false);
            setError('ارتباط با سرور برقرار نشد.');
        }
    };

    /** تایپِ کاربر: پیشنهادهایِ قبلی فوراً پنهان (نتیجهٔ کهنه نمایش داده نشود) و پس از مکث، جست‌وجویِ خودکار */
    const handleType = (value: string) => {
        setText(value);
        setError(null);
        cancelPending();
        seqRef.current++; // پاسخِ درحالِ‌انتظار دیگر معتبر نیست
        setLoading(false);
        setOpen(false);
        setResults([]);
        setSearchedTerm(null);
        const term = value.trim();
        if (term.length < MIN_CHARS) return; // خالی/کوتاه: رفتارِ فعلیِ نقشه دست‌نخورده
        timerRef.current = setTimeout(() => fetchResults(term), DEBOUNCE_MS);
    };

    /** ذره‌بین: جست‌وجویِ فوری/اجباری (از ۱ نویسه)، فقط نمایشِ پیشنهادها */
    const forceSearch = () => {
        const term = text.trim();
        if (term) fetchResults(term);
    };

    /** Enter وقتی پیشنهادی باز نیست (هنوز در مکث/درحالِ بارگذاری): فوراً جست‌وجو و اولین نتیجه انتخاب شود */
    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key !== 'Enter') return;
        if (open && results.length > 0) return; // خودِ AutoComplete پیشنهادِ فعال (اولی) را انتخاب می‌کند
        e.preventDefault();
        const term = text.trim();
        if (term) fetchResults(term, true);
    };

    const options = results.map((r, i) => ({
        value: String(i),
        label: (
            <div style={{ display: 'flex', gap: 8, alignItems: 'flex-start', padding: '2px 0', whiteSpace: 'normal' }}>
                <EnvironmentOutlined style={{ color: '#667eea', marginTop: 4 }} />
                <div style={{ minWidth: 0 }}>
                    <Text strong>{r.title}</Text>
                    {r.address ? <Text type="secondary" style={{ fontSize: 12 }}>{`، ${r.address}`}</Text> : null}
                    <Text type="secondary" style={{ display: 'block', fontSize: 11 }} dir="ltr">
                        {r.latitude}, {r.longitude}
                    </Text>
                    {r.unmatched ? (
                        <Text type="warning" style={{ display: 'block', fontSize: 11 }}>
                            تقریبی — «{r.unmatched}» پیدا نشد
                        </Text>
                    ) : null}
                </div>
            </div>
        ),
    }));

    return (
        <div>
            <Space.Compact style={{ width: '100%' }}>
                <AutoComplete
                    style={{ width: '100%' }}
                    value={text}
                    options={options}
                    open={open}
                    onOpenChange={(visible) => setOpen(visible && (results.length > 0 || searchedTerm !== null))}
                    onSearch={handleType}
                    onSelect={(value: string) => {
                        const r = results[Number(value)];
                        if (r) choose(r);
                    }}
                    notFoundContent={searchedTerm !== null ? <Text type="secondary">نتیجه‌ای پیدا نشد.</Text> : null}
                    defaultActiveFirstOption
                >
                    <Input
                        allowClear
                        onKeyDown={handleKeyDown}
                        suffix={loading ? <LoadingOutlined style={{ color: '#667eea' }} /> : <span />}
                        placeholder={cityName ? `آدرس یا نامِ مکان در ${cityName}…` : 'آدرس، خیابان، میدان یا نامِ مکان…'}
                    />
                </AutoComplete>
                <Tooltip title="جست‌وجو">
                    <Button type="primary" icon={<SearchOutlined />} onClick={forceSearch} />
                </Tooltip>
            </Space.Compact>
            {error ? <Alert type="error" showIcon style={{ marginTop: 6, borderRadius: 8 }} message={error} /> : null}
        </div>
    );
}
