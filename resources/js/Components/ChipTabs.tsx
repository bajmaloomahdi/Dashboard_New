import { useEffect, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { HolderOutlined } from '@ant-design/icons';
import { DndContext, PointerSensor, pointerWithin, useDraggable, useDroppable, useSensor, useSensors } from '@dnd-kit/core';
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

function SortableChip<K extends string>({ item, active, onSelect, onMove }: {
    item: ChipTabItem<K>; active: boolean; onSelect: () => void; onMove: (offset: number) => void;
}) {
    const drag = useDraggable({ id: item.key });
    const drop = useDroppable({ id: item.key });

    return <div ref={(node) => { drag.setNodeRef(node); drop.setNodeRef(node); }}
        className={`chip-tab ${active ? 'active' : ''} ${drop.isOver && !drag.isDragging ? 'drop-target' : ''}`}
        style={{ opacity: drag.isDragging ? 0.45 : 1 }}>
        <button type="button" className="chip-tab-label" aria-pressed={active} onClick={onSelect}>
            {item.icon} {item.label}
            {item.count != null && <span className="chip-tab-count">{item.count}</span>}
        </button>
        <button type="button" className="chip-tab-handle" ref={drag.setActivatorNodeRef}
            {...drag.attributes} {...drag.listeners} aria-label={`جابه‌جایی تب ${item.label}`}
            title="بکشید یا از کلیدهای جهت‌دار استفاده کنید"
            onKeyDown={(event) => {
                if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
                event.preventDefault();
                const rtl = getComputedStyle(event.currentTarget).direction === 'rtl';
                onMove((event.key === 'ArrowLeft') === rtl ? 1 : -1);
            }}><HolderOutlined /></button>
    </div>;
}

/**
 * نوار تب به‌شکل چیپ‌های گرد — همون الگوی فیلتر اولویت در Projects/Index
 * و تب‌های Projects/Show، برای یکدست بودن ظاهر در همه‌ی صفحات.
 */
export default function ChipTabs<K extends string>({ items, activeKey, onChange }: ChipTabsProps<K>) {
    const page = usePage<{ auth?: { user?: { UserID?: number; id?: number } } }>();
    const user = page.props.auth?.user;
    const storageKey = `chip-tabs:${user?.UserID ?? user?.id ?? 'guest'}:${page.component}`;
    const [preference, setPreference] = useState<{ key: string; order: string[] }>();
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

    useEffect(() => {
        try {
            const saved: unknown = JSON.parse(localStorage.getItem(storageKey) ?? '[]');
            setPreference({ key: storageKey, order: Array.isArray(saved) ? saved.filter((key): key is string => typeof key === 'string') : [] });
        } catch {
            setPreference({ key: storageKey, order: [] });
        }
    }, [storageKey]);

    const savedOrder = preference?.key === storageKey ? preference.order : [];
    const remaining = new Map<string, ChipTabItem<K>>(items.map((item) => [item.key, item]));
    const orderedItems: ChipTabItem<K>[] = [];
    for (const key of savedOrder) {
        const item = remaining.get(key);
        if (item) orderedItems.push(item);
        remaining.delete(key);
    }
    orderedItems.push(...remaining.values());

    const move = (from: number, to: number) => {
        if (from < 0 || to < 0 || to >= orderedItems.length || from === to) return;
        const order: string[] = orderedItems.map(({ key }) => key);
        const [key] = order.splice(from, 1);
        order.splice(to, 0, key);
        setPreference({ key: storageKey, order });
        try { localStorage.setItem(storageKey, JSON.stringify(order)); } catch { /* current view retains the order */ }
    };

    return (
        <DndContext sensors={sensors} collisionDetection={pointerWithin} onDragEnd={({ active, over }) => {
            if (over) move(orderedItems.findIndex((item) => item.key === active.id), orderedItems.findIndex((item) => item.key === over.id));
        }}>
        <div className="chip-tabbar">
            {orderedItems.map((item, index) => (
                <SortableChip key={item.key} item={item} active={activeKey === item.key}
                    onSelect={() => onChange(item.key)} onMove={(offset) => move(index, index + offset)} />
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
                    padding: 0 8px 0 12px;
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
                .chip-tab-label, .chip-tab-handle {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    border: 0;
                    background: transparent;
                    color: inherit;
                    font: inherit;
                }
                .chip-tab-label { padding: 8px 4px; cursor: pointer; }
                .chip-tab-handle { padding: 6px 2px; cursor: grab; touch-action: none; }
                .chip-tab.drop-target { outline: 2px solid ${THEME.primary}; outline-offset: 1px; }
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
        </DndContext>
    );
}
