import { useEffect, useRef, useState } from 'react';
import { Form, Select, Spin } from 'antd';
import { ApartmentOutlined, ProjectOutlined, TeamOutlined } from '@ant-design/icons';
import { toBool } from '../../Utils/bool';
import { normalizeForSearch } from '../../Utils/persianSearch';

/** ارتباطِ رویداد (پچِ ۱۳۸) — برگشتی از GET /calendar/events/{id} */
export interface EventLink {
    LinkType: 'PARTY' | 'PERSON' | 'PROJECT' | 'MESSAGE';
    EntityID: number;
    DisplayName: string | null;
}

type Option = { value: number; label: string };

/** جست‌وجویِ راه‌دور (با تأخیرِ کوتاه) در فهرستِ طرف‌حساب‌ها یا افرادِ مرتبط — همان Endpointهایِ CRM */
export function useCrmSearch(url: string, idKey: 'PartyID' | 'PersonID', enabled: boolean) {
    const [options, setOptions] = useState<Option[]>([]);
    const [loading, setLoading] = useState(false);
    const timer = useRef<number | undefined>(undefined);
    const search = (q: string) => {
        if (!enabled) return;
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(async () => {
            setLoading(true);
            try {
                const res = await fetch(`${url}?isActive=1${q.trim() ? `&search=${encodeURIComponent(q.trim())}` : ''}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = res.ok ? await res.json() : { items: [] };
                const found: Option[] = (data.items || []).slice(0, 50).map((r: any) => ({ value: Number(r[idKey]), label: String(r.DisplayName || r[idKey]) }));
                // گزینه‌هایِ انتخاب‌شده (برچسب) حفظ می‌شوند
                setOptions((prev) => [...prev.filter((p) => !found.some((f) => f.value === p.value)), ...found]);
            } finally {
                setLoading(false);
            }
        }, 300);
    };
    return { options, setOptions, loading, search };
}

/**
 * بخشِ «ارتباط‌ها» در فرمِ رویداد: پروژه (پروژه‌هایی که کاربر سازنده/عضوشان است)، طرف‌حساب و فردِ مرتبط (با CRM_VIEW).
 * links = ارتباط‌هایِ فعلیِ رویداد (ویرایش) برایِ برچسبِ گزینه‌هایِ انتخاب‌شده.
 */
export default function EventLinksFields({ open, canLinkCrm, links }: { open: boolean; canLinkCrm: boolean; links: EventLink[] }) {
    const [projects, setProjects] = useState<Option[]>([]);
    const [projectsLoading, setProjectsLoading] = useState(false);
    const parties = useCrmSearch('/crm/parties-list', 'PartyID', canLinkCrm);
    const persons = useCrmSearch('/crm/persons', 'PersonID', canLinkCrm);
    const selectedParties = Form.useWatch<number[]>('LinkedPartyIDs');
    const partyKey = [...new Set((selectedParties ?? []).map(Number))].sort((a, b) => a - b).join(',');
    const [related, setRelated] = useState<{ key: string; options: Option[]; failed?: boolean }>();
    const currentRelations = related?.key === partyKey ? related : undefined;

    useEffect(() => {
        setRelated(undefined);
        if (!open || !canLinkCrm || !partyKey) return;

        const request = new AbortController();
        Promise.all(partyKey.split(',').map(async (id) => {
            const response = await fetch(`/crm/relations?partyId=${encodeURIComponent(id)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            });
            if (!response.ok) throw new Error('Could not load party relations');
            const data = await response.json();
            if (!Array.isArray(data.items) || data.success === false) throw new Error('Invalid party relations response');
            return data.items as { PersonID: number | string; PersonName: string; IsActive: unknown }[];
        })).then((lists) => {
            const unique = new Map<number, Option>();
            for (const row of lists.flat()) {
                if (!toBool(row.IsActive)) continue;
                const value = Number(row.PersonID);
                unique.set(value, { value, label: row.PersonName || String(value) });
            }
            if (!request.signal.aborted) setRelated({ key: partyKey, options: [...unique.values()] });
        }).catch(() => {
            if (!request.signal.aborted) setRelated({ key: partyKey, options: [], failed: true });
        });
        return () => request.abort();
    }, [open, canLinkCrm, partyKey]);

    useEffect(() => {
        if (!open) return;
        setProjectsLoading(true);
        fetch('/calendar/link-projects', { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { projects: [] }))
            .then((data) => {
                const list: Option[] = (data.projects || []).map((p: any) => ({ value: Number(p.ProjectID), label: `${p.ProjectTitle}${p.ProjectCode ? ` (${p.ProjectCode})` : ''}` }));
                // پروژهٔ وصل‌شده‌ای که کاربر عضوش نیست هم با نامش نمایش داده شود
                setProjects(list);
            })
            .catch(() => setProjects([]))
            .finally(() => setProjectsLoading(false));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, links]);

    useEffect(() => {
        parties.setOptions(links.filter((l) => l.LinkType === 'PARTY').map((l) => ({ value: l.EntityID, label: l.DisplayName || String(l.EntityID) })));
        persons.setOptions(links.filter((l) => l.LinkType === 'PERSON').map((l) => ({ value: l.EntityID, label: l.DisplayName || String(l.EntityID) })));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [links]);

    return (
        <>
            <Form.Item name="LinkedProjectIDs" label={<><ProjectOutlined /> پروژه‌هایِ مرتبط</>}>
                <Select mode="multiple" allowClear size="large" placeholder="انتخابِ پروژه (اختیاری)" loading={projectsLoading} optionFilterProp="label"
                    options={[...projects, ...links.filter((link) => link.LinkType === 'PROJECT' && !projects.some((option) => option.value === Number(link.EntityID)))
                        .map((link) => ({ value: Number(link.EntityID), label: link.DisplayName || String(link.EntityID) }))]} />
            </Form.Item>
            {canLinkCrm ? (
                <>
                    <Form.Item name="LinkedPartyIDs" label={<><ApartmentOutlined /> طرف‌حساب‌هایِ مرتبط</>}>
                        <Select
                            mode="multiple"
                            allowClear
                            size="large"
                            placeholder="جست‌وجو و انتخابِ طرف‌حساب (اختیاری)"
                            showSearch
                            filterOption={false}
                            onSearch={parties.search}
                            onFocus={() => parties.search('')}
                            notFoundContent={parties.loading ? <Spin size="small" /> : 'موردی پیدا نشد'}
                            options={parties.options}
                        />
                    </Form.Item>
                    <Form.Item name="LinkedPersonIDs" label={<><TeamOutlined /> افرادِ مرتبط</>}>
                        <Select
                            mode="multiple"
                            allowClear
                            size="large"
                            placeholder="جست‌وجو و انتخابِ فرد (اختیاری)"
                            showSearch
                            filterOption={partyKey
                                ? (input, option) => normalizeForSearch(option?.label ?? '').includes(normalizeForSearch(input))
                                : false}
                            onSearch={partyKey ? undefined : persons.search}
                            onFocus={partyKey ? undefined : () => persons.search('')}
                            notFoundContent={partyKey
                                ? !currentRelations ? <Spin size="small" /> : currentRelations.failed ? 'بارگذاری افراد مرتبط انجام نشد' : 'فرد مرتبط فعالی پیدا نشد'
                                : persons.loading ? <Spin size="small" /> : 'موردی پیدا نشد'}
                            labelRender={({ value, label }) => label
                                ?? links.find((link) => link.LinkType === 'PERSON' && Number(link.EntityID) === Number(value))?.DisplayName
                                ?? persons.options.find((option) => option.value === Number(value))?.label
                                ?? String(value)}
                            options={partyKey ? currentRelations?.options ?? [] : persons.options}
                        />
                    </Form.Item>
                </>
            ) : null}
        </>
    );
}
