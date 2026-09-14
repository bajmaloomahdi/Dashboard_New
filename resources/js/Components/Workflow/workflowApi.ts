/**
 * fetch سبک‌وزنِ سازگار با CSRFِ Laravel — دقیقاً همان الگویی که در
 * Calendar/EventFormModal.tsx و Components/ProjectComments.tsx استفاده شده.
 *
 * چرا fetchِ خام (نه axios/Inertia router): مسیرهایِ /workflow/* یک API خالصِ
 * JSON هستند (bootstrap/app.php آن‌ها را همیشه JSON می‌کند، حتی روی خطا)، نه
 * پاسخِ Inertia — پس router.post/useForm().post این‌جا مناسب نیست.
 */

function getXsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export interface WorkflowApiResult {
    ok: boolean;
    status: number;
    success: boolean;
    message: string;
    concurrency?: boolean;
    [key: string]: any;
}

export async function wfApi(url: string, method: 'GET' | 'POST' | 'PUT' = 'GET', body?: any): Promise<WorkflowApiResult> {
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
