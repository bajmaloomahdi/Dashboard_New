/**
 * fetch سبک‌وزنِ سازگار با CSRFِ Laravel برایِ مسیرهایِ crm/* — دقیقاً
 * هم‌الگو با wfApi (Components/Workflow/workflowApi.ts).
 */

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export interface CrmApiResult {
    ok: boolean;
    status: number;
    success: boolean;
    message: string;
    [key: string]: any;
}

/**
 * `signal` (اختیاری) برایِ لغوِ درخواستِ قدیمی (مثلاً Autocomplete)؛ در صورتِ لغو، AbortError پرتاب می‌شود.
 * اگر `body` یک FormData باشد (آپلودِ فایل) همان‌طور ارسال می‌شود و Content-Type را مرورگر (multipart + boundary) می‌گذارد.
 */
export async function crmApi(url: string, method: 'GET' | 'POST' | 'PUT' = 'GET', body?: any, signal?: AbortSignal): Promise<CrmApiResult> {
    const isForm = typeof FormData !== 'undefined' && body instanceof FormData;
    const res = await fetch(url, {
        signal,
        method,
        headers: {
            'X-XSRF-TOKEN': getXsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            ...(isForm ? {} : { 'Content-Type': 'application/json' }),
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: isForm ? body : body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));

    return {
        ok: res.ok,
        status: res.status,
        success: !!data.success,
        message: data.message || (res.ok ? 'عملیات انجام شد.' : 'خطا در ارتباط با سرور'),
        ...data,
    };
}
