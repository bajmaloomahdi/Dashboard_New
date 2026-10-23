import { Tag, Tooltip } from 'antd';
import { CheckCircleFilled, ClockCircleFilled, CloseCircleFilled, QuestionCircleFilled } from '@ant-design/icons';
import type { ReactNode } from 'react';

/** وضعیت‌هایِ رویداد: برچسب، رنگِ Tag، رنگ و آیکونِ نشانه (پچِ ۱۱۶) */
export const EVENT_STATUS_META: Record<string, { label: string; tagColor: string; color: string; icon: ReactNode }> = {
    CONFIRMED: { label: 'ثبت شده', tagColor: 'processing', color: '#1677ff', icon: <ClockCircleFilled /> },
    DONE: { label: 'انجام شده', tagColor: 'success', color: '#52c41a', icon: <CheckCircleFilled /> },
    CANCELLED: { label: 'لغو شده', tagColor: 'default', color: '#8c8c8c', icon: <CloseCircleFilled /> },
    TENTATIVE: { label: 'موقت', tagColor: 'warning', color: '#faad14', icon: <QuestionCircleFilled /> },
};

export const eventStatusLabel = (status: string | null | undefined) => EVENT_STATUS_META[status ?? '']?.label ?? '';

/**
 * آیکونِ وضعیت با تولتیپ (مثلاً «وضعیت: انجام شده»). onColored = رویِ پس‌زمینهٔ رنگیِ رویداد (سفید با هالهٔ روشن).
 */
export function EventStatusIcon({ status, onColored = false, noTooltip = false }: { status: string | null | undefined; onColored?: boolean; noTooltip?: boolean }) {
    const meta = EVENT_STATUS_META[status ?? ''];
    if (!meta) return null;

    const icon = (
            <span
                className="cal-status-icon"
                aria-label={meta.label}
                style={{
                    color: onColored ? '#fff' : meta.color,
                    display: 'inline-flex',
                    alignItems: 'center',
                    fontSize: onColored ? 11 : 14,
                    marginInlineEnd: 4,
                    filter: onColored ? 'drop-shadow(0 0 1px rgba(0,0,0,.35))' : undefined,
                    flexShrink: 0,
                }}
            >
                {meta.icon}
            </span>
    );

    return noTooltip ? icon : <Tooltip title={`وضعیت: ${meta.label}`}>{icon}</Tooltip>;
}

/** برچسبِ متنیِ وضعیت (همهٔ وضعیت‌ها، حتی «ثبت شده») */
export function EventStatusTag({ status }: { status: string | null | undefined }) {
    const meta = EVENT_STATUS_META[status ?? ''];
    if (!meta) return null;

    return (
        <Tag color={meta.tagColor} icon={meta.icon} style={{ marginInlineEnd: 0 }}>
            {meta.label}
        </Tag>
    );
}
