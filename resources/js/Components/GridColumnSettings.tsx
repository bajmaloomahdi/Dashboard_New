import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Button, Checkbox, Modal, Space, Alert } from 'antd';
import { SettingOutlined } from '@ant-design/icons';
import axios from 'axios';

type Visibility = Record<string, boolean>;
type Preferences = {
    columns: Record<string, string>;
    required: string[];
    visibility: Visibility;
    version: string | null;
};

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
        control: scope && (
            <GridColumnSettings
                key={scope}
                preferences={preferences}
                availableKeys={availableKeys}
                loadError={loadError}
                reload={() => setRevision((n) => n + 1)}
                save={async (visibility: Visibility | null, version: string | null) => {
                    const config = { headers: { Accept: 'application/json' } };
                    const { data } = visibility === null
                        ? await axios.delete<Preferences>(url, { ...config, data: { version } })
                        : await axios.put<Preferences>(url, { visibility, version }, config);
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
    save: (visibility: Visibility | null, version: string | null) => Promise<void>;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [draft, setDraft] = useState<Visibility>({});
    const [version, setVersion] = useState<string | null>(null);

    const submit = async (visibility: Visibility | null) => {
        if (!preferences || busy) return;
        setBusy(true);
        setError(null);
        try {
            await save(visibility, version);
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
                <Space orientation="vertical">
                    {Object.entries(preferences?.columns ?? {}).filter(([key]) => !availableKeys || availableKeys.includes(key)).map(([key, label]) => {
                        const required = preferences?.required.includes(key) ?? false;
                        if (required) return <span key={key}>{label} (الزامی)</span>;
                        return <Checkbox key={key} checked={draft[key] !== false} disabled={busy}
                            onChange={(event) => {
                                const checked = event.target.checked;
                                setDraft((current) => ({ ...current, [key]: checked }));
                            }}>
                            {label}
                        </Checkbox>;
                    })}
                </Space>
            </Modal>
        </>
    );
}
