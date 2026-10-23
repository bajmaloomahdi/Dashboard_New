import { Tag, Tooltip, Typography } from 'antd';

const { Text } = Typography;

/**
 * سلولِ ستونِ چندمقداری: فقط اولین مقدار نمایش داده می‌شود؛ اگر بیشتر بود، نشانِ «+N» کنارش می‌آید و
 * با بردنِ موس رویِ سلول بقیهٔ مقدارها در تولتیپ دیده می‌شوند. خالی → «—».
 */
export default function FirstWithTooltipCell({ items }: { items: string[] }) {
    if (!items.length) return <Text type="secondary">—</Text>;
    const [first, ...rest] = items;
    if (!rest.length) return <span>{first}</span>;

    return (
        <Tooltip
            title={
                <div dir="rtl">
                    {rest.map((x) => (
                        <div key={x}>{x}</div>
                    ))}
                </div>
            }
        >
            <span style={{ cursor: 'default', display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                <span>{first}</span>
                <Tag style={{ margin: 0, borderRadius: 10, fontSize: 11, lineHeight: '18px', paddingInline: 6 }}>+{rest.length}</Tag>
            </span>
        </Tooltip>
    );
}

/** آرایهٔ JSONِ نام‌ها از سرور (مثلاً PartyTypeNames) → string[]؛ اگر خالی بود از مقدارِ قدیمیِ تکی استفاده می‌شود */
export function parseNameList(json: string | null | undefined, fallback?: string | null): string[] {
    if (json) {
        try {
            const list = JSON.parse(json);
            if (Array.isArray(list)) {
                const clean = list.map((x) => String(x ?? '').trim()).filter(Boolean);
                if (clean.length) return clean;
            }
        } catch {
            /* قالبِ نامعتبر → مقدارِ قدیمی */
        }
    }

    return fallback && fallback.trim() ? [fallback.trim()] : [];
}
