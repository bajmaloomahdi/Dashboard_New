import { ReactNode } from 'react';
import { THEME } from '../theme';

export interface ChipTabItem<K extends string> {
    key: K;
    label: string;
    icon?: ReactNode;
    count?: number | null;
}

interface ChipTabsProps<K extends string> {
    items: ChipTabItem<K>[];
    activeKey: K;
    onChange: (key: K) => void;
}

/**
 * نوار تب به‌شکل چیپ‌های گرد — همون الگوی فیلتر اولویت در Projects/Index
 * و تب‌های Projects/Show، برای یکدست بودن ظاهر در همه‌ی صفحات.
 */
export default function ChipTabs<K extends string>({ items, activeKey, onChange }: ChipTabsProps<K>) {
    return (
        <div className="chip-tabbar">
            {items.map((t) => (
                <span
                    key={t.key}
                    className={`chip-tab ${activeKey === t.key ? 'active' : ''}`}
                    onClick={() => onChange(t.key)}
                >
                    {t.icon} {t.label}
                    {t.count !== null && t.count !== undefined ? <span className="chip-tab-count">{t.count}</span> : null}
                </span>
            ))}

            <style>{`
                .chip-tabbar {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 8px;
                    margin-bottom: 16px;
                }
                .chip-tab {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    padding: 8px 16px;
                    border-radius: 999px;
                    border: 1.5px solid ${THEME.border};
                    background: #fff;
                    color: ${THEME.textSecondary};
                    font-size: 13px;
                    font-weight: 600;
                    cursor: pointer;
                    transition: all 0.18s ease;
                    user-select: none;
                }
                .chip-tab:hover {
                    transform: translateY(-2px);
                    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
                }
                .chip-tab.active {
                    background: ${THEME.primaryGradient};
                    color: #fff;
                    border-color: transparent;
                    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.35);
                }
                .chip-tab-count {
                    background: rgba(255,255,255,0.3);
                    border-radius: 999px;
                    padding: 0 7px;
                    font-size: 11px;
                }
                .chip-tab:not(.active) .chip-tab-count {
                    background: ${THEME.primaryLight};
                    color: ${THEME.primary};
                }
            `}</style>
        </div>
    );
}