import { Tag, Tooltip } from 'antd';
import { ThunderboltOutlined } from '@ant-design/icons';

/* ------------------------------------------------------------------ */
/* کامپوننت مشترک نمایش اولویت پیام                                   */
/* ─────────────────────────────────────────────────────────────────── */
/* رنگ بر اساس SortOrder نسبت به بالاترین اولویت محاسبه می‌شود:        */
/*   پایین‌ترین ⇒ سبز   →   زرد   →   نارنجی   →   قرمز (بالاترین)     */
/* ------------------------------------------------------------------ */

export interface PriorityPalette {
    /** رنگ اصلی (متن و نقطه) */
    color: string;
    /** رنگ پس‌زمینه ملایم */
    bg: string;
    /** رنگ حاشیه */
    border: string;
    /** گرادیان برای کارت‌ها */
    gradient: string;
    /** سایه هم‌رنگ */
    shadow: string;
    /** نام رنگ برای Tag انت‌دیزاین */
    tagColor: string;
}

const PALETTES: PriorityPalette[] = [
    // سطح ۱ — کمترین
    {
        color: '#059669', bg: '#ECFDF5', border: '#A7F3D0',
        gradient: 'linear-gradient(135deg, #34D399 0%, #059669 100%)',
        shadow: 'rgba(5,150,105,.35)', tagColor: 'green',
    },
    // سطح ۲
    {
        color: '#D97706', bg: '#FFFBEB', border: '#FDE68A',
        gradient: 'linear-gradient(135deg, #FBBF24 0%, #D97706 100%)',
        shadow: 'rgba(217,119,6,.35)', tagColor: 'gold',
    },
    // سطح ۳
    {
        color: '#EA580C', bg: '#FFF7ED', border: '#FED7AA',
        gradient: 'linear-gradient(135deg, #FB923C 0%, #EA580C 100%)',
        shadow: 'rgba(234,88,12,.35)', tagColor: 'orange',
    },
    // سطح ۴ — بیشترین
    {
        color: '#DC2626', bg: '#FEF2F2', border: '#FECACA',
        gradient: 'linear-gradient(135deg, #F87171 0%, #DC2626 100%)',
        shadow: 'rgba(220,38,38,.35)', tagColor: 'red',
    },
];

/** پالت خنثی برای «بدون اولویت» */
export const NEUTRAL_PALETTE: PriorityPalette = {
    color: '#6B7280', bg: '#F9FAFB', border: '#E5E7EB',
    gradient: 'linear-gradient(135deg, #9CA3AF 0%, #6B7280 100%)',
    shadow: 'rgba(107,114,128,.3)', tagColor: 'default',
};

/**
 * محاسبه پالت رنگ یک اولویت
 * @param sortOrder ترتیب نمایش اولویت
 * @param maxSortOrder بیشترین ترتیب نمایش بین اولویت‌های فعال
 */
export const getPriorityPalette = (
    sortOrder?: number | null,
    maxSortOrder?: number | null
): PriorityPalette => {
    if (!sortOrder || sortOrder < 1) return NEUTRAL_PALETTE;

    const max = Math.max(1, maxSortOrder || sortOrder);
    if (max <= 1) return PALETTES[PALETTES.length - 1];

    const ratio = (sortOrder - 1) / (max - 1);
    const index = Math.min(PALETTES.length - 1, Math.round(ratio * (PALETTES.length - 1)));
    return PALETTES[index];
};

/* ------------------------------------------------------------------ */

interface PriorityTagProps {
    /** نام اولویت */
    name?: string | null;
    /** ترتیب نمایش اولویت */
    sortOrder?: number | null;
    /** بیشترین ترتیب نمایش (برای محاسبه نسبی رنگ) */
    maxSortOrder?: number | null;
    /** توضیحات اولویت (روی Tooltip) */
    description?: string | null;
    /** نمایش آیکون رعد */
    showIcon?: boolean;
    /** فقط نقطه رنگی + متن (بدون تگ) */
    dotOnly?: boolean;
    /** اندازه */
    size?: 'small' | 'default';
    style?: React.CSSProperties;
}

export default function PriorityTag({
    name,
    sortOrder,
    maxSortOrder,
    description,
    showIcon = true,
    dotOnly = false,
    size = 'default',
    style,
}: PriorityTagProps) {
    if (!name) {
        return <span style={{ color: '#9CA3AF' }}>—</span>;
    }

    const p = getPriorityPalette(sortOrder, maxSortOrder);
    const fontSize = size === 'small' ? 11 : 12;

    /* حالت نقطه‌ای — برای جاهای فشرده مثل داخل جدول شلوغ */
    if (dotOnly) {
        const content = (
            <span
                style={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 6,
                    fontSize,
                    fontWeight: 600,
                    color: p.color,
                    ...style,
                }}
            >
                <span
                    style={{
                        width: 9,
                        height: 9,
                        borderRadius: '50%',
                        background: p.gradient,
                        boxShadow: `0 0 0 3px ${p.bg}`,
                        flexShrink: 0,
                    }}
                />
                {name}
            </span>
        );

        return description ? <Tooltip title={description}>{content}</Tooltip> : content;
    }

    /* حالت تگ — پیش‌فرض */
    const tag = (
        <Tag
            icon={showIcon ? <ThunderboltOutlined /> : undefined}
            style={{
                borderRadius: 8,
                margin: 0,
                fontSize,
                fontWeight: 600,
                color: p.color,
                background: p.bg,
                border: `1px solid ${p.border}`,
                padding: size === 'small' ? '0 8px' : '2px 10px',
                display: 'inline-flex',
                alignItems: 'center',
                gap: 4,
                ...style,
            }}
        >
            {name}
        </Tag>
    );

    return description ? <Tooltip title={description}>{tag}</Tooltip> : tag;
}
