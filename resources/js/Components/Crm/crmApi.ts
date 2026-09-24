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

export async function crmApi(url: string, method: 'GET' | 'POST' | 'PUT' = 'GET', body?: any): Promise<CrmApiResult> {
    const res = await fetch(url, {
        method,
        headers: {
            'X-XSRF-TOKEN': getXsrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
        body: body ? JSON.stringify(body) : undefined,
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
