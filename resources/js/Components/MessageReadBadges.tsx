import { Tag, Tooltip, Typography } from 'antd';
import { BellOutlined, EyeOutlined, EyeInvisibleOutlined, CheckCircleOutlined, CommentOutlined, PaperClipOutlined } from '@ant-design/icons';

const { Text } = Typography;

const truthy = (v: unknown) => v === 1 || v === true || v === '1';

/** پیامِ نوخوانده (هرگز باز نشده) یا پیامی که تغییرِ خوانده‌نشده دارد (توضیح/ضمیمهٔ تازه، ارجاع، تغییرِ وضعیت، رونوشت) */
export function messageAttention(record: { IsRead?: unknown; HasUnread?: unknown }): 'new' | 'updated' | null {
    if (record.IsRead !== undefined && !truthy(record.IsRead)) return 'new';
    if (truthy(record.HasUnread)) return 'updated';
    return null;
}

/** کلاسِ ردیفِ جدول برایِ متمایزکردنِ پیام‌هایِ جدید/تغییرکرده (CSS در MESSAGE_ROW_CSS) */
export const messageRowClass = (record: { IsRead?: unknown; HasUnread?: unknown }) => {
    const state = messageAttention(record);
    return state ? `msg-row-${state}` : '';
};

export const MESSAGE_ROW_CSS = `
    .msg-row-new > td { background: #eef3ff !important; }
    .msg-row-new > td:first-child { box-shadow: inset -4px 0 0 #667eea; }
    .msg-row-new:hover > td { background: #e2eaff !important; }
    .msg-row-updated > td { background: #fff4dc !important; }
    .msg-row-updated > td:first-child { box-shadow: inset -4px 0 0 #d48806; }
    .msg-row-updated:hover > td { background: #ffebbd !important; }
`;

export function MessageChangeTag({ record }: { record: { IsRead?: unknown; HasUnread?: unknown } }) {
    const state = messageAttention(record);
    if (state === 'new') {
        return <Tag color="blue" style={{ borderRadius: 6, marginInlineEnd: 0 }} icon={<EyeInvisibleOutlined />}>دیده نشده</Tag>;
    }
    if (state === 'updated') {
        return (
            <Tooltip title="از آخرین باری که پیام را دیده‌اید توضیح، ضمیمه، ارجاع یا وضعیتِ تازه‌ای ثبت شده است">
                <Tag icon={<BellOutlined />} color="orange" style={{ borderRadius: 6, marginInlineEnd: 0 }}>تغییرِ تازه</Tag>
            </Tooltip>
        );
    }
    return truthy(record.IsRead)
        ? <Tag icon={<EyeOutlined />} color="success" style={{ borderRadius: 6, marginInlineEnd: 0 }}>دیده شده</Tag>
        : <span aria-label="وضعیت نامشخص">—</span>;
}

export function MessageContentIndicators({ record }: { record: { HasDescription?: unknown; HasAttachment?: unknown } }) {
    const hasDescription = truthy(record.HasDescription);
    const hasAttachment = truthy(record.HasAttachment);
    if (!hasDescription && !hasAttachment) return null;

    return <span style={{ display: 'inline-flex', gap: 12, alignItems: 'center' }}>
        {hasDescription && <Tooltip title="دارای توضیحات"><CommentOutlined style={{ color: '#1677ff' }} aria-label="دارای توضیحات" /></Tooltip>}
        {hasAttachment && <Tooltip title="دارای ضمیمه"><PaperClipOutlined style={{ color: '#a06600' }} aria-label="دارای ضمیمه" /></Tooltip>}
    </span>;
}

/** صادره: چند نفر از گیرندگان/رونوشت‌ها پیام را دیده‌اند */
export function SeenCountTag({ seen, total }: { seen?: unknown; total?: unknown }) {
    const s = Number(seen) || 0;
    const t = Number(total) || 0;
    if (t === 0) return null;
    const all = s >= t;
    return (
        <Tooltip title={all ? 'همهٔ گیرندگان و رونوشت‌ها پیام را دیده‌اند' : `${t - s} نفر هنوز پیام را ندیده‌اند`}>
            <Tag icon={all ? <CheckCircleOutlined /> : <EyeOutlined />} color={all ? 'success' : s > 0 ? 'processing' : 'default'} style={{ borderRadius: 6, marginInlineEnd: 0 }}>
                دیده‌شده {s} از {t}
            </Tag>
        </Tooltip>
    );
}

const formatDateTime = (v: string) => {
    const d = new Date(String(v).replace(' ', 'T'));
    return Number.isNaN(d.getTime()) ? '' : `${d.toLocaleDateString('fa-IR')} ${d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' })}`;
};

/** رسیدِ مشاهدهٔ یک گیرنده/رونوشت در صفحهٔ پیام */
export function SeenReceipt({ seen, seenDate, lastSeenDate }: { seen?: unknown; seenDate?: string | null; lastSeenDate?: string | null }) {
    if (!truthy(seen)) {
        return (
            <Tag icon={<EyeInvisibleOutlined />} style={{ borderRadius: 6 }}>
                دیده نشده
            </Tag>
        );
    }
    const first = seenDate ? formatDateTime(seenDate) : '';
    const last = lastSeenDate ? formatDateTime(lastSeenDate) : '';
    return (
        <Tooltip title={last && last !== first ? `آخرین مشاهده: ${last}` : undefined}>
            <div>
                <Tag icon={<EyeOutlined />} color="success" style={{ borderRadius: 6, marginInlineEnd: 0 }}>
                    دیده شد
                </Tag>
                {first ? (
                    <Text type="secondary" style={{ display: 'block', fontSize: 11, marginTop: 2 }}>
                        {first}
                    </Text>
                ) : null}
            </div>
        </Tooltip>
    );
}
