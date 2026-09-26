import { useState } from 'react';
import { Table, Button, Input, Select, Space, Tag, Popconfirm, Typography, Alert, Empty, Checkbox, TimePicker, Tooltip } from 'antd';
import {
    PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, CheckOutlined,
    RedoOutlined, PhoneOutlined, TeamOutlined, FileTextOutlined, ClockCircleOutlined,
} from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import dayjs, { Dayjs } from 'dayjs';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import PersianDateInput from '../../../Components/PersianDateInput';
import { toBool } from '../../../Utils/bool';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

type InteractionType = 'CALL' | 'MEETING' | 'NOTE' | 'FOLLOWUP';
type InteractionStatus = 'PLANNED' | 'DONE' | 'CANCELED';

interface Interaction {
    InteractionID: number;
    InteractionType: InteractionType;
    PartyID: number;
    PersonID: number | null;
    PersonName: string | null;
    OwnerUserID: number | null;
    OwnerName: string | null;
    Subject: string;
    Description: string | null;
    Outcome: string | null;
    InteractionDate: string;
    Status: InteractionStatus;
    FollowUpOfID: number | null;
    FollowUpOfSubject: string | null;
    IsActive: boolean | number | string;
}

interface InteractionsPanelProps {
    partyId: number;
    items: Interaction[];
    relatedPersons: { PersonID: number; DisplayName: string }[];
    users: { UserID: number; FullName: string }[];
    canManage: boolean;
}

const TYPE_META: Record<InteractionType, { label: string; color: string; icon: JSX.Element }> = {
    CALL: { label: 'تماس', color: 'blue', icon: <PhoneOutlined /> },
    MEETING: { label: 'جلسه', color: 'purple', icon: <TeamOutlined /> },
    NOTE: { label: 'یادداشت', color: 'default', icon: <FileTextOutlined /> },
    FOLLOWUP: { label: 'پیگیری', color: 'orange', icon: <ClockCircleOutlined /> },
};

const STATUS_META: Record<InteractionStatus, { label: string; color: string }> = {
    PLANNED: { label: 'برنامه‌ریزی‌شده', color: 'processing' },
    DONE: { label: 'انجام‌شده', color: 'success' },
    CANCELED: { label: 'لغوشده', color: 'default' },
};

const typeOptions = (Object.keys(TYPE_META) as InteractionType[]).map((k) => ({ value: k, label: TYPE_META[k].label }));

const todayIso = () => dayjs().format('YYYY-MM-DD');

const emptyDraft = () => ({
    id: null as number | null,
    type: 'CALL' as InteractionType,
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
});

const emptyFollowUp = () => ({ enabled: false, date: null as string | null, time: null as Dayjs | null, subject: '' });

const toApiDateTime = (date: string, time: Dayjs | null) => `${date} ${(time ?? dayjs().startOf('day')).format('HH:mm')}:00`;

const splitDateTime = (v: string) => {
    const [d, t] = (v || '').replace('T', ' ').split(' ');
    return { date: d || '', time: (t || '').slice(0, 5) };
};

/**
 * تعاملاتِ یک طرف‌حساب — تماس/جلسه/یادداشت/پیگیری. قواعدِ وضعیت در Backend اعمال
 * می‌شود؛ اینجا فقط گزینه‌هایِ مجاز نشان داده می‌شود و تغییرِ وضعیت فقط از
 * دکمه‌هایِ «انجام شد/لغو» (فقط برایِ تعاملِ برنامه‌ریزی‌شده) انجام می‌شود.
 */
export default function InteractionsPanel({ partyId, items: initialItems, relatedPersons, users, canManage }: InteractionsPanelProps) {
    const [items, setItems] = useState<Interaction[]>(initialItems || []);
    const [typeFilter, setTypeFilter] = useState<InteractionType | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft());
    const [followUp, setFollowUp] = useState(emptyFollowUp());
    const [saving, setSaving] = useState(false);
    const [busyId, setBusyId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const isEdit = draft.id !== null;
    const notify = (type: NotificationType, message: string) => setNotification({ open: true, type, message });

    const reload = async () => {
        const res = await crmApi(`/crm/interactions?partyId=${partyId}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    const openCreate = () => {
        setDraft(emptyDraft());
        setFollowUp(emptyFollowUp());
        setError(null);
        setFormOpen(true);
    };

    const openFollowUpOf = (row: Interaction) => {
        setDraft({
            ...emptyDraft(),
            type: 'FOLLOWUP',
            status: 'PLANNED',
            personId: row.PersonID,
            followUpOfId: row.InteractionID,
            followUpOfSubject: row.Subject,
            subject: `پیگیریِ: ${row.Subject}`,
        });
        setFollowUp(emptyFollowUp());
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (row: Interaction) => {
        const { date, time } = splitDateTime(row.InteractionDate);
        setDraft({
            id: row.InteractionID,
            type: row.InteractionType,
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
        });
        setFollowUp(emptyFollowUp());
        setError(null);
        setFormOpen(true);
    };

    const onTypeChange = (t: InteractionType) =>
        setDraft((d) => ({ ...d, type: t, status: t === 'NOTE' ? 'DONE' : t === 'FOLLOWUP' ? 'PLANNED' : d.status === 'CANCELED' ? 'DONE' : d.status }));

    const handleSave = async () => {
        if (!draft.subject.trim()) return setError('موضوع الزامی است.');
        if (!draft.date) return setError('تاریخ الزامی است.');
        if (followUp.enabled && (!followUp.date || !followUp.subject.trim())) return setError('برایِ پیگیری، تاریخ و موضوع الزامی است.');

        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/interactions', 'POST', {
            interactionId: draft.id ?? undefined,
            interactionType: draft.type,
            partyId,
            personId: draft.personId ?? undefined,
            subject: draft.subject.trim(),
            description: draft.description.trim() || undefined,
            outcome: draft.outcome.trim() || undefined,
            interactionDate: toApiDateTime(draft.date, draft.time),
            status: isEdit ? undefined : draft.status,
            followUpOfId: draft.type === 'FOLLOWUP' ? draft.followUpOfId ?? undefined : undefined,
            ownerUserId: draft.ownerUserId ?? undefined,
        });

        if (!res.ok || !res.success) {
            setSaving(false);
            return setError(res.message);
        }

        if (!isEdit && followUp.enabled && followUp.date) {
            const fu = await crmApi('/crm/interactions', 'POST', {
                interactionType: 'FOLLOWUP',
                partyId,
                personId: draft.personId ?? undefined,
                subject: followUp.subject.trim(),
                interactionDate: toApiDateTime(followUp.date, followUp.time),
                followUpOfId: res.interactionId,
                ownerUserId: draft.ownerUserId ?? undefined,
            });
            if (!fu.ok || !fu.success) {
                setSaving(false);
                setFormOpen(false);
                await reload();
                return notify('error', `تعامل ثبت شد، اما ثبتِ پیگیری ناموفق بود: ${fu.message}`);
            }
        }

        setSaving(false);
        setFormOpen(false);
        notify('success', res.message);
        reload();
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

    const shown = typeFilter ? items.filter((i) => i.InteractionType === typeFilter) : items;

    const columns: ColumnsType<Interaction> = [
        {
            title: 'نوع',
            key: 'type',
            width: 110,
            render: (_, r) => (
                <Tag color={TYPE_META[r.InteractionType].color} icon={TYPE_META[r.InteractionType].icon} style={{ borderRadius: 6 }}>
                    {TYPE_META[r.InteractionType].label}
                </Tag>
            ),
        },
        {
            title: 'تاریخ و ساعت',
            key: 'date',
            width: 150,
            render: (_, r) => {
                const { date, time } = splitDateTime(r.InteractionDate);
                return <Text>{date ? gregorianToJalaliDisplay(date) : '—'} <Text type="secondary" dir="ltr">{time}</Text></Text>;
            },
        },
        {
            title: 'موضوع',
            key: 'subject',
            render: (_, r) => (
                <div>
                    <Text strong>{r.Subject}</Text>
                    {r.FollowUpOfSubject ? <Text type="secondary" style={{ display: 'block', fontSize: 12 }}>ادامهٔ: {r.FollowUpOfSubject}</Text> : null}
                    {r.Outcome ? <Text type="secondary" style={{ display: 'block', fontSize: 12 }}>نتیجه: {r.Outcome}</Text> : null}
                </div>
            ),
        },
        { title: 'مخاطب', dataIndex: 'PersonName', key: 'PersonName', render: (v) => v || <Text type="secondary">—</Text> },
        { title: 'مسئول', dataIndex: 'OwnerName', key: 'OwnerName', render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'وضعیت',
            key: 'status',
            width: 130,
            align: 'center',
            render: (_, r) => (
                <Space direction="vertical" size={2}>
                    <Tag color={STATUS_META[r.Status].color} style={{ borderRadius: 6, margin: 0 }}>{STATUS_META[r.Status].label}</Tag>
                    {!toBool(r.IsActive) ? <Tag style={{ borderRadius: 6, margin: 0 }} icon={<StopOutlined />}>غیرفعال</Tag> : null}
                </Space>
            ),
        },
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

    return (
        <div>
            <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 12, gap: 8, flexWrap: 'wrap' }}>
                <Select
                    allowClear
                    style={{ width: 160 }}
                    placeholder="همهٔ انواع"
                    value={typeFilter ?? undefined}
                    onChange={(v) => setTypeFilter(v ?? null)}
                    options={typeOptions}
                />
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
                            <Select style={{ width: 140 }} disabled={isEdit || draft.followUpOfId !== null} value={draft.type} onChange={onTypeChange} options={typeOptions} />
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
                        {!isEdit && (draft.type === 'CALL' || draft.type === 'MEETING') ? (
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
                    <Space style={{ width: '100%' }} wrap align="start">
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات</Text>
                            <Input.TextArea rows={2} style={{ width: 340 }} value={draft.description} onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))} />
                        </div>
                        {draft.type !== 'NOTE' ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نتیجه</Text>
                                <Input.TextArea rows={2} style={{ width: 340 }} value={draft.outcome} onChange={(e) => setDraft((d) => ({ ...d, outcome: e.target.value }))} />
                            </div>
                        ) : null}
                    </Space>

                    {!isEdit && (draft.type === 'CALL' || draft.type === 'MEETING') ? (
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

            <Table rowKey="InteractionID" columns={columns} dataSource={shown} pagination={false} locale={{ emptyText: <Empty description="تعاملی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
