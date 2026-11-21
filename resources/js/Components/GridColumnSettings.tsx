import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Button, Checkbox, InputNumber, Modal, Space, Alert } from 'antd';
import { ArrowDownOutlined, ArrowUpOutlined, HolderOutlined, SettingOutlined } from '@ant-design/icons';
import { DndContext, PointerSensor, pointerWithin, useDraggable, useDroppable, useSensor, useSensors } from '@dnd-kit/core';
import type { ReactNode } from 'react';
import axios from 'axios';

type Visibility = Record<string, boolean>;
type Widths = Record<string, number>;
type Preferences = {
    columns: Record<string, string>;
    required: string[];
    visibility: Visibility;
    order: string[];
    widths: Widths;
    version: string | null;
};

function columnOrder(keys: readonly string[], saved: readonly string[]) {
    const available = new Set(keys);
    const ordered = saved.filter((key) => available.delete(key));
    return [...ordered, ...available];
}

function ColumnSettingsRow({ id, label, disabled, children }: { id: string; label: string; disabled: boolean; children: ReactNode }) {
    const drag = useDraggable({ id, disabled });
    const drop = useDroppable({ id, disabled });

    return <div ref={(node) => { drag.setNodeRef(node); drop.setNodeRef(node); }}
        style={{ display: 'flex', alignItems: 'center', gap: 8, paddingBlock: 4,
            borderBlockStart: drop.isOver && !drag.isDragging ? '2px solid #1677ff' : '2px solid transparent',
            opacity: drag.isDragging ? 0.5 : 1 }}>
        <Button size="small" type="text" icon={<HolderOutlined />} disabled={disabled}
            ref={drag.setActivatorNodeRef} {...drag.attributes} {...drag.listeners}
            aria-label={`کشیدن ستون ${label}`} style={{ touchAction: 'none', cursor: disabled ? undefined : 'grab' }} />
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flex: 1, minWidth: 0, gap: 12 }}>
            {children}
        </div>
    </div>;
}

export function applyGridColumnSettings<T extends { key?: React.Key; hidden?: boolean }>(
    columns: readonly T[], settings: { visibility?: Visibility; order?: string[]; widths?: Widths },
): T[] {
    if (!settings.visibility) return [...columns];
    const rank = new Map((settings.order ?? []).map((key, index) => [key, index]));
    return [...columns].sort((a, b) =>
        (rank.get(String(a.key)) ?? Infinity) - (rank.get(String(b.key)) ?? Infinity),
    ).map((column) => ({
        ...column,
        ...(settings.widths?.[String(column.key)] !== undefined ? { width: settings.widths[String(column.key)] } : {}),
        hidden: column.hidden || settings.visibility?.[String(column.key)] === false,
    }));
}

export function useGridColumnSettings(gridKey?: string, availableKeys?: readonly string[]) {
    const { auth } = usePage().props as { auth?: { user?: { UserID?: number } } };
    const userId = auth?.user?.UserID;
    const scope = gridKey && userId ? `${userId}:${gridKey}` : null;
    const url = `/grid-column-preferences/${encodeURIComponent(gridKey ?? '')}`;
    const [loaded, setLoaded] = useState<{ scope: string; preferences: Preferences }>();
    const [loadError, setLoadError] = useState<string | null>(null);
    const [revision, setRevision] = useState(0);

    useEffect(() => {
        if (!scope) return;
        const abort = new AbortController();
        setLoadError(null);
        axios.get<Preferences>(url, { signal: abort.signal, headers: { Accept: 'application/json' } })
            .then(({ data }) => { if (!abort.signal.aborted) setLoaded({ scope, preferences: data }); })
            .catch(() => { if (!abort.signal.aborted) setLoadError('تنظیمات ستون‌ها بارگذاری نشد.'); });
        return () => abort.abort();
    }, [scope, url, revision]);

    const preferences = loaded?.scope === scope ? loaded?.preferences : undefined;
    return {
        visibility: preferences?.visibility,
        order: preferences?.order,
        widths: preferences?.widths,
        control: scope && (
            <GridColumnSettings
                key={scope}
                preferences={preferences}
                availableKeys={availableKeys}
                loadError={loadError}
                reload={() => setRevision((n) => n + 1)}
                save={async (visibility: Visibility | null, order: string[], widths: Widths, version: string | null) => {
                    const config = { headers: { Accept: 'application/json' } };
                    const { data } = visibility === null
                        ? await axios.delete<Preferences>(url, { ...config, data: { version } })
                        : await axios.put<Preferences>(url, { visibility, order, widths, version }, config);
                    setLoaded((current) => current?.scope === scope ? { scope, preferences: data } : current);
                }}
            />
        ),
    };
}

function GridColumnSettings({ preferences, availableKeys, loadError, reload, save }: {
    preferences?: Preferences;
    availableKeys?: readonly string[];
    loadError: string | null;
    reload: () => void;
    save: (visibility: Visibility | null, order: string[], widths: Widths, version: string | null) => Promise<void>;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [draft, setDraft] = useState<Visibility>({});
    const [draftOrder, setDraftOrder] = useState<string[]>([]);
    const [draftWidths, setDraftWidths] = useState<Widths>({});
    const [version, setVersion] = useState<string | null>(null);
    const sensors = useSensors(useSensor(PointerSensor, { activationConstraint: { distance: 5 } }));

    const submit = async (visibility: Visibility | null) => {
        if (!preferences || busy) return;
        setBusy(true);
        setError(null);
        try {
            await save(visibility, draftOrder, draftWidths, version);
            setOpen(false);
        } catch (failure) {
            const conflict = axios.isAxiosError(failure) && failure.response?.status === 409;
            setError(conflict ? 'تنظیمات در دستگاه دیگری تغییر کرده است. پنجره را ببندید و دوباره باز کنید.' : 'ذخیرهٔ تنظیمات انجام نشد.');
            if (conflict) reload();
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <Button icon={<SettingOutlined />} onClick={() => {
                if (!preferences) { if (loadError) reload(); return; }
                setDraft({ ...preferences.visibility });
                setDraftWidths({ ...preferences.widths });
                const keys = (availableKeys ?? Object.keys(preferences.columns)).filter((key) => Object.prototype.hasOwnProperty.call(preferences.columns, key));
                setDraftOrder(columnOrder(keys, preferences.order ?? []));
                setVersion(preferences.version);
                setError(null);
                setOpen(true);
            }} loading={!preferences && !loadError && !open}>
                {loadError ? 'تلاش دوباره برای تنظیم ستون‌ها' : 'تنظیم ستون‌ها'}
            </Button>
            <Modal title="تنظیم ستون‌ها" open={open} onCancel={() => { if (!busy) setOpen(false); }}
                footer={[
                    <Button key="reset" disabled={busy} onClick={() => void submit(null)}>بازنشانی به پیش‌فرض</Button>,
                    <Button key="save" type="primary" loading={busy} onClick={() => void submit(draft)}>ذخیره</Button>,
                ]}>
                {error && <Alert type="error" message={error} />}
                <DndContext sensors={sensors} collisionDetection={pointerWithin} onDragEnd={({ active, over }) => {
                    if (busy || !over || active.id === over.id) return;
                    setDraftOrder((current) => {
                        const from = current.indexOf(String(active.id));
                        const to = current.indexOf(String(over.id));
                        if (from < 0 || to < 0) return current;
                        const next = [...current];
                        const [column] = next.splice(from, 1);
                        next.splice(to, 0, column);
                        return next;
                    });
                }}>
                <div style={{ maxHeight: '55vh', overflowY: 'auto' }}>
                    {draftOrder.map((key, index) => {
                        const label = preferences?.columns[key] ?? key;
                        const required = preferences?.required.includes(key) ?? false;
                        const move = (offset: number) => setDraftOrder((current) => {
                            const next = [...current];
                            const position = next.indexOf(key);
                            const target = position + offset;
                            if (position < 0 || target < 0 || target >= next.length) return current;
                            [next[position], next[target]] = [next[target], next[position]];
                            return next;
                        });
                        return <ColumnSettingsRow key={key} id={key} label={label} disabled={busy}>
                            {required ? <span>{label} (الزامی)</span> : <Checkbox checked={draft[key] !== false} disabled={busy}
                            onChange={(event) => {
                                const checked = event.target.checked;
                                setDraft((current) => ({ ...current, [key]: checked }));
                            }}>
                            {label}
                            </Checkbox>}
                            <Space size={4}>
                                <InputNumber size="small" min={60} max={800} precision={0} disabled={busy}
                                    aria-label={`عرض ${label} به پیکسل`} placeholder="خودکار" suffix="px"
                                    value={draftWidths[key] ?? null}
                                    onChange={(width) => setDraftWidths((current) => {
                                        const next = { ...current };
                                        if (width === null) delete next[key];
                                        else next[key] = width;
                                        return next;
                                    })} />
                                <Button size="small" aria-label={`انتقال ${label} به بالا`} icon={<ArrowUpOutlined />} disabled={busy || index === 0} onClick={() => move(-1)} />
                                <Button size="small" aria-label={`انتقال ${label} به پایین`} icon={<ArrowDownOutlined />} disabled={busy || index === draftOrder.length - 1} onClick={() => move(1)} />
                            </Space>
                        </ColumnSettingsRow>;
                    })}
                </div>
                </DndContext>
            </Modal>
        </>
    );
}
