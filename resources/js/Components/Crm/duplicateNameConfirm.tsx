import { Modal } from 'antd';
import { crmApi, CrmApiResult } from './crmApi';

interface DuplicateItem {
    id: number;
    name: string;
    isActive: boolean | number;
}

/** پنجرهٔ هشدارِ نامِ تکراری؛ true = کاربر «بله، ثبت شود» را زد */
function confirmDuplicateName(res: CrmApiResult): Promise<boolean> {
    const items: DuplicateItem[] = Array.isArray(res.duplicates) ? res.duplicates : [];
    const more = Math.max(0, Number(res.duplicateCount || 0) - items.length);

    return new Promise((resolve) => {
        Modal.confirm({
            title: 'نامِ تکراری',
            centered: true,
            okText: 'بله، ثبت شود',
            cancelText: 'انصراف',
            content: (
                <div dir="rtl" style={{ textAlign: 'right' }}>
                    <p style={{ marginBottom: 8 }}>{res.message}</p>
                    {items.length > 0 && (
                        <>
                            <div style={{ fontSize: 12, color: '#6B7280', marginBottom: 4 }}>موارد با نامِ مشابه:</div>
                            <ul style={{ margin: 0, paddingInlineStart: 18 }}>
                                {items.map((d) => (
                                    <li key={d.id}>
                                        {d.name}
                                        {Number(d.isActive) === 1 || d.isActive === true ? '' : ' (غیرفعال)'}
                                    </li>
                                ))}
                            </ul>
                            {more > 0 && <div style={{ fontSize: 12, color: '#6B7280', marginTop: 4 }}>و {more} موردِ دیگر</div>}
                        </>
                    )}
                </div>
            ),
            onOk: () => resolve(true),
            onCancel: () => resolve(false),
        });
    });
}

/**
 * ذخیرهٔ طرف‌حساب/مخاطب با هشدارِ نامِ تکراری (پچِ ۱۱۴): اگر سرور «نامِ تکراری» (409) برگرداند، از کاربر تأیید گرفته می‌شود؛
 * با تأیید همان درخواست با allowDuplicateName دوباره فرستاده می‌شود. null = کاربر انصراف داد و هیچ چیزی ثبت نشد.
 */
export async function crmSaveWithDuplicateConfirm(url: string, body: Record<string, any>): Promise<CrmApiResult | null> {
    const res = await crmApi(url, 'POST', body);
    if (res.status !== 409 || !res.duplicateName) return res;
    if (!(await confirmDuplicateName(res))) return null;

    return crmApi(url, 'POST', { ...body, allowDuplicateName: true });
}
