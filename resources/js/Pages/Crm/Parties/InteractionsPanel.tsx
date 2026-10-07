import { useState } from 'react';
import { Table, Button, Input, Select, Space, Tag, Popconfirm, Typography, Alert, Empty, Checkbox, TimePicker, Tooltip, Upload } from 'antd';
import {
    PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, CheckOutlined,
    RedoOutlined, PhoneOutlined, TeamOutlined, FileTextOutlined, ClockCircleOutlined, PaperClipOutlined, DeleteOutlined, UndoOutlined,
} from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import dayjs, { Dayjs } from 'dayjs';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import PersianDateInput from '../../../Components/PersianDateInput';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

type InteractionStatus = 'PLANNED' | 'DONE' | 'CANCELED';

/** نوعِ تعامل اکنون Master Data است (CrmInteractionTypes)؛ این فقط شکلِ یک ردیف است. */
interface InteractionTypeOption {
    InteractionTypeID: number;
    Code: string;
    DisplayName: string;
}

interface InteractionAttachment {
    InteractionAttachmentID: number;
    FileName: string;
    FileExtension: string | null;
    FileSize: number | string;
    Description: string | null;
    CreatedByName: string | null;
    Date_InsertFirst: string;
    /** Routeِ دانلودِ دارایِ Permission — مسیرِ فیزیکیِ فایل هرگز به Frontend نمی‌آید */
    DownloadUrl: string;
}

/** فایلِ تازه‌انتخاب‌شده (هنوز ارسال نشده) + توضیحاتش */
interface PendingAttachment {
    uid: string;
    file: File;
    description: string;
}

interface Interaction {
    InteractionID: number;
    /** Code قدیمی (برایِ Fallbackِ رنگ/آیکن) — منبعِ اصلیِ عنوان اکنون InteractionTypeDisplayName است */
    InteractionType: string;
    InteractionTypeID: number;
    InteractionTypeDisplayName: string;
    PartyID: number;
    ProjectID: number | null;
    ProjectTitle: string | null;
    PersonID: number | null;
    PersonName: string | null;
    OwnerUserID: number | null;
    OwnerName: string | null;
    /** کاربرِ ثبت‌کنندهٔ رکورد (UserID_InsertFirst) — متمایز از «مسئول»/Owner */
    CreatedByName: string | null;
    Subject: string;
    Description: string | null;
    Outcome: string | null;
    InteractionDate: string;
    Status: InteractionStatus;
    FollowUpOfID: number | null;
    FollowUpOfSubject: string | null;
    IsActive: boolean | number | string;
    Attachments?: InteractionAttachment[];
}

interface InteractionsPanelProps {
    partyId: number;
    items: Interaction[];
    relatedPersons: { PersonID: number; DisplayName: string }[];
    users: { UserID: number; FullName: string }[];
    interactionTypes: InteractionTypeOption[];
    canManage: boolean;
}

/** پروژه‌ای که همین Party پیمانکارِ فعالِ آن است (GET crm/parties/{partyId}/projects — Stage B) */
interface PartyProject {
    ProjectID: number;
    ProjectTitle: string;
    ProjectCode?: string | null;
}

/** فقط ظاهر (رنگ/آیکن) برایِ چهار نوعِ شناخته‌شدهٔ قدیمی؛ عنوان همیشه از Master Data می‌آید.
 * نوعِ تازه‌ای که Admin بعداً اضافه کند، از DEFAULT استفاده می‌کند — بدونِ نیاز به تغییرِ کد. */
const TYPE_VISUALS: Record<string, { color: string; icon: JSX.Element }> = {
    CALL: { color: 'blue', icon: <PhoneOutlined /> },
    MEETING: { color: 'purple', icon: <TeamOutlined /> },
    NOTE: { color: 'default', icon: <FileTextOutlined /> },
    FOLLOWUP: { color: 'orange', icon: <ClockCircleOutlined /> },
};
const DEFAULT_TYPE_VISUAL = { color: 'default', icon: <FileTextOutlined /> };
const typeVisual = (code: string) => TYPE_VISUALS[code] || DEFAULT_TYPE_VISUAL;

const todayIso = () => dayjs().format('YYYY-MM-DD');

const emptyDraft = (defaultTypeId: number | null = null) => ({
    id: null as number | null,
    interactionTypeId: defaultTypeId as number | null,
    subject: '',
    date: todayIso() as string | null,
    time: dayjs() as Dayjs | null,
    status: 'DONE' as InteractionStatus,
    personId: null as number | null,
    ownerUserId: null as number | null,
    description: '',
    outcome: '',
    followUpOfId: null as number | null,
    followUpOfSubject: null as string | null,
    // «مرتبط با پروژه» — پیش‌فرض خاموش؛ لیستِ پروژه‌هایِ قابل‌انتخاب فقط هنگامِ روشن‌شدن بارگذاری می‌شود
    linkedToProject: false,
    projectId: null as number | null,
});

const emptyFollowUp = () => ({ enabled: false, date: null as string | null, time: null as Dayjs | null, subject: '' });

const toApiDateTime = (date: string, time: Dayjs | null) => `${date} ${(time ?? dayjs().startOf('day')).format('HH:mm')}:00`;

const splitDateTime = (v: string) => {
    const [d, t] = (v || '').replace('T', ' ').split(' ');
    return { date: d || '', time: (t || '').slice(0, 5) };
};

/** همان قیودِ Backend (CrmInteractionAttachmentFiles) — بررسیِ اولیه در مرورگر؛ بررسیِ قطعی در سرور */
const ATTACH_MAX_BYTES = 10240 * 1024;
const ATTACH_MAX_FILES = 10;
const ATTACH_MAX_DESCRIPTION = 1000;
const ATTACH_BLOCKED = new Set([
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps', 'pht', 'exe', 'com', 'bat', 'cmd', 'msi', 'msp', 'scr', 'dll', 'cpl', 'sys',
    'sh', 'bash', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'mjs', 'jse', 'wsf', 'wsh', 'hta', 'jar', 'html', 'htm', 'xhtml', 'shtml', 'svg', 'svgz', 'xml',
    'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'htaccess', 'htpasswd', 'lnk', 'reg',
]);

const checkAttachment = (file: File): string | null => {
    const parts = file.name.toLowerCase().split('.').slice(1);
    if (!file.name || file.name.startsWith('.') || parts.some((p) => ATTACH_BLOCKED.has(p))) return `نوعِ فایلِ «${file.name}» مجاز نیست (فایل‌هایِ اجرایی/اسکریپتی قابلِ پیوست نیستند).`;
    if (file.size === 0) return `فایلِ «${file.name}» خالی است.`;
    if (file.size > ATTACH_MAX_BYTES) return `حجمِ فایلِ «${file.name}» نباید بیشتر از ۱۰ مگابایت باشد.`;
    return null;
};

const formatSize = (bytes: number | string) => {
    const b = Number(bytes) || 0;
    if (b < 1024) return `${b} بایت`;
    if (b < 1024 * 1024) return `${(b / 1024).toFixed(0)} کیلوبایت`;
    return `${(b / 1024 / 1024).toFixed(1)} مگابایت`;
};

/**
 * تعاملاتِ یک طرف‌حساب — تماس/جلسه/یادداشت/پیگیری. قواعدِ وضعیت در Backend اعمال
 * می‌شود؛ اینجا فقط گزینه‌هایِ مجاز نشان داده می‌شود و تغییرِ وضعیت فقط از
 * دکمه‌هایِ «انجام شد/لغو» (فقط برایِ تعاملِ برنامه‌ریزی‌شده) انجام می‌شود.
 */
export default function InteractionsPanel({ partyId, items: initialItems, relatedPersons, users, interactionTypes, canManage }: InteractionsPanelProps) {
    const [items, setItems] = useState<Interaction[]>(initialItems || []);
    const [typeFilter, setTypeFilter] = useState<number | null>(null);
    const [projectFilter, setProjectFilter] = useState<number | null>(null);
    const [textFilter, setTextFilter] = useState('');
    const [partyProjects, setPartyProjects] = useState<PartyProject[]>([]);
    const [partyProjectsLoaded, setPartyProjectsLoaded] = useState(false);
    const [partyProjectsLoading, setPartyProjectsLoading] = useState(false);

    /** Codeِ نوعِ انتخاب‌شده در فرم — فقط برایِ قواعدِ نمایشی/وضعیتِ وابسته به نوع (NOTE/FOLLOWUP/CALL/MEETING) */
    const typeIdByCode = (code: string): number | null =>
        interactionTypes.find((t) => t.Code === code)?.InteractionTypeID ?? interactionTypes[0]?.InteractionTypeID ?? null;
    const codeByTypeId = (id: number | null): string | undefined =>
        interactionTypes.find((t) => t.InteractionTypeID === id)?.Code;
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft());
    const [followUp, setFollowUp] = useState(emptyFollowUp());
    const [saving, setSaving] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    // پیوست‌ها: فایل‌هایِ جدیدِ در انتظار + پیوست‌هایِ موجودِ علامت‌خورده برایِ حذف (هر دو فقط با «ذخیره» اعمال می‌شوند)
    const [pending, setPending] = useState<PendingAttachment[]>([]);
    const [existing, setExisting] = useState<InteractionAttachment[]>([]);
    const [removedIds, setRemovedIds] = useState<number[]>([]);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const isEdit = draft.id !== null;
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const reload = async () => {
        const res = await crmApi(`/crm/interactions?partyId=${partyId}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    /**
     * پروژه‌هایی که همین Party پیمانکارِ فعالِ آن‌هاست (Stage B: GET crm/parties/{partyId}/projects).
     * فقط یک‌بار و فقط هنگامِ نیازِ واقعی (تیک‌خوردنِ Checkbox یا ویرایشِ تعاملِ دارایِ Project) بارگذاری می‌شود.
     */
    const loadPartyProjects = async () => {
        if (partyProjectsLoaded) return;
        setPartyProjectsLoading(true);
        try {
            const res = await crmApi(`/crm/parties/${partyId}/projects`);
            if (res.ok && res.success) {
                setPartyProjects(res.items || []);
                setPartyProjectsLoaded(true);
            }
        } finally {
            setPartyProjectsLoading(false);
        }
    };

    const resetAttachments = (current: InteractionAttachment[]) => {
        setPending([]);
        setExisting(current);
        setRemovedIds([]);
    };

    // چند فایل پشتِ‌سرِهم از beforeUpload می‌رسند؛ خطایِ یک فایل بقیه را متوقف نمی‌کند (سقفِ تعداد هنگامِ ذخیره بررسی می‌شود)
    const addPending = (file: File) => {
        const problem = checkAttachment(file);
        if (problem) return setError(problem);
        setPending((list) => [...list, { uid: `${Date.now()}-${Math.random().toString(36).slice(2)}`, file, description: '' }]);
    };

    const openCreate = () => {
        setDraft(emptyDraft(typeIdByCode('CALL')));
        setFollowUp(emptyFollowUp());
        setError(null);
        resetAttachments([]);
        setFormOpen(true);
    };

    const openFollowUpOf = (row: Interaction) => {
        setDraft({
            ...emptyDraft(),
            interactionTypeId: typeIdByCode('FOLLOWUP'),
            status: 'PLANNED',
            personId: row.PersonID,
            followUpOfId: row.InteractionID,
            followUpOfSubject: row.Subject,
            subject: `پیگیریِ: ${row.Subject}`,
            linkedToProject: row.ProjectID !== null,
            projectId: row.ProjectID,
        });
        setFollowUp(emptyFollowUp());
        setError(null);
        resetAttachments([]);
        if (row.ProjectID !== null) loadPartyProjects();
        setFormOpen(true);
    };

    const openEdit = (row: Interaction) => {
        const { date, time } = splitDateTime(row.InteractionDate);
        setDraft({
            id: row.InteractionID,
            interactionTypeId: row.InteractionTypeID,
            subject: row.Subject,
            date,
            time: time ? dayjs(time, 'HH:mm') : null,
            status: row.Status,
            personId: row.PersonID,
            ownerUserId: row.OwnerUserID,
            description: row.Description || '',
            outcome: row.Outcome || '',
            followUpOfId: row.FollowUpOfID,
            followUpOfSubject: row.FollowUpOfSubject,
            linkedToProject: row.ProjectID !== null,
            projectId: row.ProjectID,
        });
        setFollowUp(emptyFollowUp());
        setError(null);
        resetAttachments(row.Attachments || []);
        if (row.ProjectID !== null) loadPartyProjects();
        setFormOpen(true);
    };

    /**
     * اعمالِ تغییراتِ پیوست پس از ذخیرهٔ موفقِ تعامل: اول حذفِ علامت‌خورده‌ها، بعد ارسالِ فایل‌هایِ جدید
     * (یک درخواستِ multipart؛ Backend همه یا هیچ‌کدام را ثبت می‌کند). خروجی: پیامِ خطا یا null.
     */
    const syncAttachments = async (interactionId: number): Promise<string | null> => {
        const problems: string[] = [];
        for (const id of removedIds) {
            const del = await crmApi(`/crm/interaction-attachments/${id}/delete`, 'POST');
            if (!del.ok || !del.success) problems.push(del.message);
        }
        if (pending.length) {
            const form = new FormData();
            pending.forEach((p, i) => {
                form.append(`attachments[${i}]`, p.file);
                form.append(`descriptions[${i}]`, p.description.trim());
            });
            const up = await crmApi(`/crm/interactions/${interactionId}/attachments`, 'POST', form);
            if (!up.ok || !up.success) {
                problems.push(up.status === 413 ? 'حجمِ مجموعِ فایل‌ها از سقفِ مجازِ سرور بیشتر است.' : up.message);
            }
        }
        return problems.length ? problems.join(' — ') : null;
    };

    const onTypeChange = (id: number) => {
        const code = codeByTypeId(id);
        setDraft((d) => ({ ...d, interactionTypeId: id, status: code === 'NOTE' ? 'DONE' : code === 'FOLLOWUP' ? 'PLANNED' : d.status === 'CANCELED' ? 'DONE' : d.status }));
    };

    const handleSave = async () => {
        if (!draft.subject.trim()) return setError('موضوع الزامی است.');
        if (!draft.date) return setError('تاریخ الزامی است.');
        if (followUp.enabled && (!followUp.date || !followUp.subject.trim())) return setError('برایِ پیگیری، تاریخ و موضوع الزامی است.');
        if (pending.length > ATTACH_MAX_FILES) return setError('در هر بار حداکثر ۱۰ فایل قابلِ پیوست است.');
        if (pending.some((p) => p.description.trim().length > ATTACH_MAX_DESCRIPTION)) return setError('توضیحاتِ هر پیوست حداکثر ۱۰۰۰ کاراکتر است.');

        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/interactions', 'POST', {
            interactionId: draft.id ?? undefined,
            interactionTypeId: draft.interactionTypeId,
            partyId,
            personId: draft.personId ?? undefined,
            projectId: draft.linkedToProject ? draft.projectId ?? undefined : undefined,
            subject: draft.subject.trim(),
            description: draft.description.trim() || undefined,
            outcome: draft.outcome.trim() || undefined,
            interactionDate: toApiDateTime(draft.date, draft.time),
            status: isEdit ? undefined : draft.status,
            followUpOfId: codeByTypeId(draft.interactionTypeId) === 'FOLLOWUP' ? draft.followUpOfId ?? undefined : undefined,
            ownerUserId: draft.ownerUserId ?? undefined,
        });

        if (!res.ok || !res.success) {
            setSaving(false);
            return setError(res.message);
        }

        // پیوست‌ها و پیگیری بعد از ذخیرهٔ موفقِ خودِ تعامل؛ خطایِ هرکدام گزارش می‌شود ولی تعاملِ ذخیره‌شده برنمی‌گردد
        const problems: string[] = [];
        const attachProblem = await syncAttachments(Number(res.interactionId));
        if (attachProblem) problems.push(`ثبت/حذفِ پیوست ناموفق بود: ${attachProblem}`);

        if (!isEdit && followUp.enabled && followUp.date) {
            const fu = await crmApi('/crm/interactions', 'POST', {
                interactionTypeId: typeIdByCode('FOLLOWUP'),
                partyId,
                personId: draft.personId ?? undefined,
                projectId: draft.linkedToProject ? draft.projectId ?? undefined : undefined,
                subject: followUp.subject.trim(),
                interactionDate: toApiDateTime(followUp.date, followUp.time),
                followUpOfId: res.interactionId,
                ownerUserId: draft.ownerUserId ?? undefined,
            });
            if (!fu.ok || !fu.success) problems.push(`ثبتِ پیگیری ناموفق بود: ${fu.message}`);
        }

        setSaving(false);
        setFormOpen(false);
        await reload();
        if (problems.length) notify('error', `تعامل ذخیره شد، اما ${problems.join(' | ')}`);
        else notify('success', res.message);
    };

    const changeStatus = async (row: Interaction, status: 'DONE' | 'CANCELED') => {
        setBusyId(row.InteractionID);
        const res = await crmApi(`/crm/interactions/${row.InteractionID}/status`, 'POST', { status });
        setBusyId(null);
        notify(res.success ? 'success' : 'error', res.message);
        if (res.success) reload();
    };

    const toggleActive = async (row: Interaction) => {
        setBusyId(row.InteractionID);
        const res = await crmApi(`/crm/interactions/${row.InteractionID}/toggle`, 'POST');
        setBusyId(null);
        notify(res.success ? 'success' : 'error', res.message);
        if (res.success) reload();
    };

    /** پروژه‌هایِ متمایزی که در همین Interactionهایِ بارگذاری‌شده دیده می‌شوند — منبعِ گزینه‌هایِ Filterِ پروژه؛
     * بدونِ Query اضافی (از همان دادهٔ موجود) و شاملِ پروژه‌هایِ تاریخی حتی اگر رابطهٔ پیمانکاری بعداً غیرفعال شده باشد. */
    const projectFilterOptions = Array.from(
        new Map(
            items.filter((i) => i.ProjectID !== null).map((i) => [i.ProjectID as number, { ProjectID: i.ProjectID as number, ProjectTitle: i.ProjectTitle || `#${i.ProjectID}` }])
        ).values()
    );

    const textFilterNormalized = textFilter.trim().toLowerCase();
    const shown = items.filter((i) => {
        if (typeFilter && i.InteractionTypeID !== typeFilter) return false;
        if (projectFilter && i.ProjectID !== projectFilter) return false;
        if (textFilterNormalized) {
            const haystack = `${i.Subject} ${i.Description || ''} ${i.Outcome || ''}`.toLowerCase();
            if (!haystack.includes(textFilterNormalized)) return false;
        }
        return true;
    });

    /** گزینه‌هایِ Selectِ پروژه در فرم: لیستِ زندهٔ پیمانکاریِ فعال + (هنگامِ ویرایش) پروژهٔ فعلیِ رکورد
     * حتی اگر رابطهٔ پیمانکاری از آن زمان غیرفعال شده باشد — تا ویرایش هیچ‌وقت گزینهٔ خالی نشان ندهد. */
    const formProjectOptions = (() => {
        const list = [...partyProjects];
        if (isEdit && draft.projectId !== null && !list.some((p) => p.ProjectID === draft.projectId)) {
            const fromRow = items.find((i) => i.ProjectID === draft.projectId);
            list.push({ ProjectID: draft.projectId, ProjectTitle: fromRow?.ProjectTitle || `#${draft.projectId}` });
        }
        return list;
    })();

    const columns: ColumnsType<Interaction> = [
        {
            title: 'تاریخ و ساعت',
            key: 'date',
            width: 150,
            render: (_, r) => {
                const { date, time } = splitDateTime(r.InteractionDate);
                return <Text>{date ? gregorianToJalaliDisplay(date) : '—'} <Text type="secondary" dir="ltr">{time}</Text></Text>;
            },
        },
        { title: 'کاربر ثبت‌کننده', dataIndex: 'CreatedByName', key: 'CreatedByName', width: 140, render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'نوع تعامل',
            key: 'type',
            width: 110,
            render: (_, r) => {
                const visual = typeVisual(r.InteractionType);
                return (
                    <Tag color={visual.color} icon={visual.icon} style={{ borderRadius: 6 }}>
                        {r.InteractionTypeDisplayName}
                    </Tag>
                );
            },
        },
        {
            title: 'موضوع',
            key: 'subject',
            render: (_, r) => (
                <div>
                    <Text strong>{r.Subject}</Text>
                    {r.FollowUpOfSubject ? <Text type="secondary" style={{ display: 'block', fontSize: 12 }}>ادامهٔ: {r.FollowUpOfSubject}</Text> : null}
                    {(r.Attachments || []).length ? (
                        <div className="crm-int-attach-list">
                            {(r.Attachments || []).map((a) => (
                                <div key={a.InteractionAttachmentID} className="crm-int-attach-item">
                                    <PaperClipOutlined />
                                    <a href={a.DownloadUrl} title={`دانلودِ ${a.FileName} (${formatSize(a.FileSize)})`}>{a.FileName}</a>
                                    {a.Description ? <Text type="secondary"> — {a.Description}</Text> : null}
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            title: 'پروژه',
            key: 'project',
            width: 160,
            render: (_, r) => r.ProjectTitle ? <Text>{r.ProjectTitle}</Text> : <Text type="secondary">—</Text>,
        },
        { title: 'توضیحات', dataIndex: 'Description', key: 'Description', render: (v) => v || <Text type="secondary">—</Text> },
        { title: 'نتیجه', dataIndex: 'Outcome', key: 'Outcome', render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'عملیات',
            key: 'actions',
            width: 190,
            align: 'center',
            render: (_, r) => {
                if (!canManage) return null;
                const active = toBool(r.IsActive);
                const planned = r.Status === 'PLANNED' && r.InteractionType !== 'NOTE';
                return (
                    <Space size={0}>
                        {planned ? (
                            <>
                                <Popconfirm title="انجام شد؟" onConfirm={() => changeStatus(r, 'DONE')} okText="بله" cancelText="خیر">
                                    <Tooltip title="انجام شد"><Button type="text" size="small" style={{ color: '#16a34a' }} loading={busyId === r.InteractionID} icon={<CheckOutlined />} /></Tooltip>
                                </Popconfirm>
                                <Popconfirm title="لغو شود؟" onConfirm={() => changeStatus(r, 'CANCELED')} okText="بله" cancelText="خیر">
                                    <Tooltip title="لغو"><Button type="text" size="small" danger icon={<CloseOutlined />} /></Tooltip>
                                </Popconfirm>
                            </>
                        ) : null}
                        {r.InteractionType !== 'NOTE' ? (
                            <Tooltip title="پیگیریِ جدید"><Button type="text" size="small" icon={<RedoOutlined />} onClick={() => openFollowUpOf(r)} /></Tooltip>
                        ) : null}
                        <Tooltip title="ویرایش"><Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} /></Tooltip>
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ تعامل' : 'فعال‌کردنِ تعامل'} onConfirm={() => toggleActive(r)} okText="بله" cancelText="خیر">
                            <Button type="text" size="small" danger={active} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    const draftTypeCode = codeByTypeId(draft.interactionTypeId);

    return (
        <div>
            <style>{`
                .crm-int-attach { width: 100%; max-width: 720px; }
                .crm-int-attach-row {
                    display: flex; align-items: center; gap: 8px; min-width: 0;
                    padding: 4px 8px; margin-bottom: 4px; border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa;
                }
                .crm-int-attach-row.is-new { background: #f6ffed; border-color: #d9f7be; }
                .crm-int-attach-row.is-removed .crm-int-attach-name { text-decoration: line-through; color: #bfbfbf; }
                .crm-int-attach-name { flex: 0 1 auto; min-width: 0; max-width: 45%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
                .crm-int-attach-size { flex: none; font-size: 12px; }
                .crm-int-attach-desc { flex: 1 1 auto; min-width: 0; font-size: 12px; }
                .crm-int-attach-input { flex: 1 1 160px; min-width: 0; }
                .crm-int-attach-list { margin-top: 4px; }
                .crm-int-attach-item { font-size: 12px; overflow-wrap: anywhere; }
                .crm-int-attach-item .anticon { margin-left: 4px; color: #8c8c8c; }
                @media (max-width: 575px) {
                    .crm-int-attach-row { flex-wrap: wrap; }
                    .crm-int-attach-name { max-width: calc(100% - 90px); }
                    .crm-int-attach-input { flex-basis: 100%; }
                }
            `}</style>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12, gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
                <Space wrap align="end">
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوع تعامل</Text>
                        <Select
                            allowClear
                            style={{ width: 180 }}
                            placeholder="همهٔ انواع"
                            value={typeFilter ?? undefined}
                            onChange={(v) => setTypeFilter(v ?? null)}
                            options={(interactionTypes || []).map((t) => ({ value: t.InteractionTypeID, label: t.DisplayName }))}
                        />
                    </div>
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>پروژه</Text>
                        <Select
                            allowClear
                            showSearch
                            style={{ width: 200 }}
                            placeholder="همهٔ پروژه‌ها"
                            value={projectFilter ?? undefined}
                            onChange={(v) => setProjectFilter(v ?? null)}
                            options={projectFilterOptions.map((p) => ({ value: p.ProjectID, label: p.ProjectTitle }))}
                            filterOption={(input, option) => (option?.label as string)?.toLowerCase().includes(input.toLowerCase())}
                            notFoundContent="تعاملی با پروژه ثبت نشده است"
                        />
                    </div>
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>جست‌وجو</Text>
                        <Input
                            allowClear
                            style={{ width: 220 }}
                            placeholder="موضوع، توضیحات یا نتیجه..."
                            value={textFilter}
                            onChange={(e) => setTextFilter(e.target.value)}
                        />
                    </div>
                </Space>
                {canManage ? (
                    <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>تعاملِ جدید</Button>
                ) : null}
            </div>

            {formOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    {draft.followUpOfSubject ? <Text type="secondary">پیگیریِ تعاملِ: {draft.followUpOfSubject}</Text> : null}
                    <Space style={{ width: '100%' }} wrap align="start">
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوع</Text>
                            <Select
                                style={{ width: 160 }}
                                disabled={isEdit || draft.followUpOfId !== null}
                                value={draft.interactionTypeId ?? undefined}
                                onChange={onTypeChange}
                                options={(interactionTypes || []).map((t) => ({ value: t.InteractionTypeID, label: t.DisplayName }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>مخاطب</Text>
                            <Select
                                allowClear
                                style={{ width: 190 }}
                                placeholder="با کدام مخاطب؟"
                                value={draft.personId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, personId: v ?? null }))}
                                options={(relatedPersons || []).map((p) => ({ value: p.PersonID, label: p.DisplayName }))}
                                notFoundContent="ابتدا از تبِ «مخاطبین» یک نفر را به این طرف‌حساب مرتبط کنید"
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>موضوع</Text>
                            <Input style={{ width: 240 }} value={draft.subject} onChange={(e) => setDraft((d) => ({ ...d, subject: e.target.value }))} />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>تاریخ</Text>
                            <PersianDateInput size="middle" value={draft.date} onChange={(v) => setDraft((d) => ({ ...d, date: v }))} />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ساعت</Text>
                            <TimePicker format="HH:mm" style={{ width: 100 }} value={draft.time} onChange={(v) => setDraft((d) => ({ ...d, time: v }))} />
                        </div>
                        {!isEdit && (draftTypeCode === 'CALL' || draftTypeCode === 'MEETING') ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>وضعیت</Text>
                                <Select
                                    style={{ width: 150 }}
                                    value={draft.status}
                                    onChange={(v) => setDraft((d) => ({ ...d, status: v }))}
                                    options={[{ value: 'DONE', label: 'انجام‌شده' }, { value: 'PLANNED', label: 'برنامه‌ریزی‌شده' }]}
                                />
                            </div>
                        ) : null}
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>مسئول</Text>
                            <Select
                                allowClear
                                showSearch
                                style={{ width: 180 }}
                                placeholder="پیش‌فرض: خودِ من"
                                value={draft.ownerUserId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, ownerUserId: v ?? null }))}
                                options={(users || []).map((u) => ({ value: u.UserID, label: u.FullName }))}
                                filterOption={(input, option) => (option?.label as string)?.includes(input)}
                            />
                        </div>
                    </Space>

                    <div>
                        <Checkbox
                            checked={draft.linkedToProject}
                            onChange={(e) => {
                                const checked = e.target.checked;
                                if (checked) {
                                    loadPartyProjects();
                                    setDraft((d) => ({ ...d, linkedToProject: true }));
                                } else {
                                    setDraft((d) => ({ ...d, linkedToProject: false, projectId: null }));
                                }
                            }}
                        >
                            مرتبط با پروژه
                        </Checkbox>
                        {draft.linkedToProject ? (
                            <div style={{ marginTop: 8 }}>
                                <Select
                                    allowClear
                                    showSearch
                                    loading={partyProjectsLoading}
                                    style={{ width: 280 }}
                                    placeholder="پروژه را انتخاب کنید"
                                    value={draft.projectId ?? undefined}
                                    onChange={(v) => setDraft((d) => ({ ...d, projectId: v ?? null }))}
                                    options={formProjectOptions.map((p) => ({ value: p.ProjectID, label: p.ProjectTitle }))}
                                    filterOption={(input, option) => (option?.label as string)?.toLowerCase().includes(input.toLowerCase())}
                                    notFoundContent={partyProjectsLoading ? 'در حالِ بارگذاری...' : 'این طرف‌حساب پیمانکارِ فعالِ هیچ پروژه‌ای نیست'}
                                />
                            </div>
                        ) : null}
                    </div>

                    {/* flex به‌جایِ Space تا در موبایل هر فیلد تا عرضِ صفحه کوچک شود (دسکتاپ همان 340px) */}
                    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'flex-start', gap: 8, width: '100%' }}>
                        <div style={{ flex: '0 1 340px', minWidth: 0 }}>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات</Text>
                            <Input.TextArea rows={2} style={{ width: '100%' }} value={draft.description} onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))} />
                        </div>
                        {draftTypeCode !== 'NOTE' ? (
                            <div style={{ flex: '0 1 340px', minWidth: 0 }}>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نتیجه</Text>
                                <Input.TextArea rows={2} style={{ width: '100%' }} value={draft.outcome} onChange={(e) => setDraft((d) => ({ ...d, outcome: e.target.value }))} />
                            </div>
                        ) : null}
                    </div>

                    <div className="crm-int-attach">
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>پیوست‌ها</Text>
                        {existing.map((a) => {
                            const removed = removedIds.includes(a.InteractionAttachmentID);
                            return (
                                <div key={a.InteractionAttachmentID} className={`crm-int-attach-row${removed ? ' is-removed' : ''}`}>
                                    <PaperClipOutlined />
                                    <a href={a.DownloadUrl} className="crm-int-attach-name" title={a.FileName}>{a.FileName}</a>
                                    <Text type="secondary" className="crm-int-attach-size">{formatSize(a.FileSize)}</Text>
                                    <Text type="secondary" className="crm-int-attach-desc" ellipsis={{ tooltip: a.Description || undefined }}>{a.Description || ''}</Text>
                                    {removed ? (
                                        <Tooltip title="بازگرداندن">
                                            <Button type="text" size="small" icon={<UndoOutlined />} disabled={saving} onClick={() => setRemovedIds((ids) => ids.filter((x) => x !== a.InteractionAttachmentID))} />
                                        </Tooltip>
                                    ) : (
                                        <Tooltip title="حذفِ پیوست (با ذخیره اعمال می‌شود)">
                                            <Button type="text" size="small" danger icon={<DeleteOutlined />} disabled={saving} onClick={() => setRemovedIds((ids) => [...ids, a.InteractionAttachmentID])} />
                                        </Tooltip>
                                    )}
                                </div>
                            );
                        })}
                        {pending.map((p) => (
                            <div key={p.uid} className="crm-int-attach-row is-new">
                                <PaperClipOutlined />
                                <span className="crm-int-attach-name" title={p.file.name}>{p.file.name}</span>
                                <Text type="secondary" className="crm-int-attach-size">{formatSize(p.file.size)}</Text>
                                <Input
                                    size="small"
                                    className="crm-int-attach-input"
                                    placeholder="توضیحاتِ پیوست (اختیاری)"
                                    maxLength={ATTACH_MAX_DESCRIPTION}
                                    value={p.description}
                                    disabled={saving}
                                    onChange={(e) => setPending((list) => list.map((x) => (x.uid === p.uid ? { ...x, description: e.target.value } : x)))}
                                />
                                <Tooltip title="حذف از فهرست">
                                    <Button type="text" size="small" danger icon={<CloseOutlined />} disabled={saving} onClick={() => setPending((list) => list.filter((x) => x.uid !== p.uid))} />
                                </Tooltip>
                            </div>
                        ))}
                        <Upload
                            multiple
                            showUploadList={false}
                            disabled={saving}
                            beforeUpload={(file) => {
                                addPending(file);
                                return false; // ارسال فقط همراهِ «ذخیره»
                            }}
                        >
                            <Button size="small" icon={<PaperClipOutlined />} disabled={saving}>افزودنِ فایل</Button>
                        </Upload>
                        <Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>
                            هر فایل حداکثر ۱۰ مگابایت، در هر بار حداکثر ۱۰ فایل؛ فایل‌هایِ اجرایی/اسکریپتی مجاز نیستند. تغییراتِ پیوست با «ذخیره» اعمال می‌شود.
                        </Text>
                    </div>

                    {!isEdit && (draftTypeCode === 'CALL' || draftTypeCode === 'MEETING') ? (
                        <div>
                            <Checkbox checked={followUp.enabled} onChange={(e) => setFollowUp((f) => ({ ...f, enabled: e.target.checked }))}>
                                ایجادِ پیگیریِ بعدی
                            </Checkbox>
                            {followUp.enabled ? (
                                <Space style={{ marginTop: 8 }} wrap>
                                    <Input placeholder="موضوعِ پیگیری" style={{ width: 240 }} value={followUp.subject} onChange={(e) => setFollowUp((f) => ({ ...f, subject: e.target.value }))} />
                                    <PersianDateInput size="middle" value={followUp.date} onChange={(v) => setFollowUp((f) => ({ ...f, date: v }))} />
                                    <TimePicker format="HH:mm" style={{ width: 100 }} value={followUp.time} onChange={(v) => setFollowUp((f) => ({ ...f, time: v }))} />
                                </Space>
                            ) : null}
                        </div>
                    ) : null}

                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="InteractionID" columns={columns} dataSource={shown} pagination={false} scroll={{ x: true }} locale={{ emptyText: <Empty description="تعاملی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
