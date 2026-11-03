import { useEffect, useRef, useState } from 'react';
import { Form, Select, Spin } from 'antd';
import { ApartmentOutlined, ProjectOutlined, TeamOutlined } from '@ant-design/icons';

/** ارتباطِ رویداد (پچِ ۱۳۸) — برگشتی از GET /calendar/events/{id} */
export interface EventLink {
    LinkType: 'PARTY' | 'PERSON' | 'PROJECT' | 'MESSAGE';
    EntityID: number;
    DisplayName: string | null;
}

type Option = { value: number; label: string };

/** جست‌وجویِ راه‌دور (با تأخیرِ کوتاه) در فهرستِ طرف‌حساب‌ها یا افرادِ مرتبط — همان Endpointهایِ CRM */
function useCrmSearch(url: string, idKey: 'PartyID' | 'PersonID', enabled: boolean) {
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

    useEffect(() => {
        if (!open) return;
        setProjectsLoading(true);
        fetch('/calendar/link-projects', { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : { projects: [] }))
            .then((data) => {
                const list: Option[] = (data.projects || []).map((p: any) => ({ value: Number(p.ProjectID), label: `${p.ProjectTitle}${p.ProjectCode ? ` (${p.ProjectCode})` : ''}` }));
                // پروژهٔ وصل‌شده‌ای که کاربر عضوش نیست هم با نامش نمایش داده شود
                links.filter((l) => l.LinkType === 'PROJECT' && !list.some((o) => o.value === l.EntityID)).forEach((l) => list.push({ value: l.EntityID, label: l.DisplayName || String(l.EntityID) }));
                setProjects(list);
            })
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
                <Select mode="multiple" allowClear size="large" placeholder="انتخابِ پروژه (اختیاری)" loading={projectsLoading} optionFilterProp="label" options={projects} />
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
                            filterOption={false}
                            onSearch={persons.search}
                            onFocus={() => persons.search('')}
                            notFoundContent={persons.loading ? <Spin size="small" /> : 'موردی پیدا نشد'}
                            options={persons.options}
                        />
                    </Form.Item>
                </>
            ) : null}
        </>
    );
}
