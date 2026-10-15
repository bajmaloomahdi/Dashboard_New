import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { normalizeForSearch } from '../../Utils/persianSearch';

/** یک نتیجهٔ سرور — همان قراردادِ POST /global-search (GlobalSearchController) */
export interface ServerItem {
    type: string;
    id: number;
    title: string;
    subtitle: string | null;
    url: string;
    badges: string[];
    match: string;
}

export interface ServerGroup {
    key: string;
    label: string;
    items: ServerItem[];
    hasMore: boolean;
    moreUrl: string | null;
}

export type ServerSearchState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'done'; groups: ServerGroup[]; complete: boolean }
    | { status: 'error'; message: string };

const DEBOUNCE_MS = 300;
/** همان حداقلِ سرور (global_search.query.min_length) — کوتاه‌تر اصلاً ارسال نمی‌شود */
const MIN_LENGTH = 2;

/**
 * جست‌وجویِ سمتِ سرور با تأخیر (debounce) و لغوِ درخواستِ قبلی.
 * - متن فقط در بدنهٔ POST ارسال می‌شود (هرگز در URL)؛ توکنِ CSRF را axios از کوکیِ XSRF-TOKEN می‌فرستد.
 * - مجوزها و محدودیتِ نرخ کاملاً سمتِ سرور اعمال می‌شوند؛ اینجا فقط پیامِ مناسب نمایش داده می‌شود.
 */
export function useServerSearch(query: string, enabled: boolean): ServerSearchState {
    const [state, setState] = useState<ServerSearchState>({ status: 'idle' });
    const controllerRef = useRef<AbortController | null>(null);

    useEffect(() => {
        controllerRef.current?.abort();
        const q = query.trim();
        if (!enabled || normalizeForSearch(q).length < MIN_LENGTH) {
            setState({ status: 'idle' });
            return;
        }

        setState({ status: 'loading' });
        const controller = new AbortController();
        controllerRef.current = controller;

        const timer = window.setTimeout(() => {
            axios
                .post('/global-search', { q }, { signal: controller.signal, headers: { Accept: 'application/json' } })
                .then((res) => {
                    if (controller.signal.aborted) return;
                    const groups = Array.isArray(res.data?.groups) ? (res.data.groups as ServerGroup[]) : [];
                    setState({ status: 'done', groups, complete: res.data?.complete !== false });
                })
                .catch((err) => {
                    if (controller.signal.aborted || axios.isCancel(err)) return;
                    setState({ status: 'error', message: errorMessage(err) });
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, enabled]);

    return state;
}

function errorMessage(err: unknown): string {
    if (!axios.isAxiosError(err) || !err.response) return 'ارتباط با سرور برقرار نشد.';
    const { status, data, headers } = err.response;
    switch (status) {
        case 422: {
            const first = data?.errors ? Object.values(data.errors as Record<string, string[]>)[0]?.[0] : null;
            return first || 'متن جست‌وجو نامعتبر است.';
        }
        case 429: {
            const retry = Number(headers?.['retry-after']);
            return retry > 0 ? `تعداد جست‌وجوها زیاد است؛ ${retry} ثانیه دیگر دوباره تلاش کنید.` : 'تعداد جست‌وجوها زیاد است؛ کمی صبر کنید.';
        }
        case 503:
            return 'جست‌وجو موقتاً در دسترس نیست؛ چند لحظه بعد دوباره تلاش کنید.';
        case 401:
        case 419:
            return 'نشست شما منقضی شده است؛ صفحه را بازخوانی کنید.';
        case 403:
            return 'دسترسی به جست‌وجو برای این حساب مجاز نیست.';
        case 404:
            return 'جست‌وجوی سراسری فعال نیست.';
        default:
            return 'خطا در جست‌وجو؛ دوباره تلاش کنید.';
    }
}
