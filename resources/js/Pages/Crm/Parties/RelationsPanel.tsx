import { useState } from 'react';
import { Table, Button, Select, Checkbox, Space, Tag, Popconfirm, Typography, Alert, Empty, Input, Divider } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, StarFilled, UserAddOutlined, PhoneOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import PersianDateInput from '../../../Components/PersianDateInput';
import { toBool } from '../../../Utils/bool';
import CrmDeleteButton from '../../../Components/Crm/CrmDeleteButton';

const { Text } = Typography;

interface Relation {
    RelationID: number;
    PartyID: number;
    PersonID: number;
    PersonName: string;
    PositionID: number | null;
    PositionName: string | null;
    RoleNames: string | null;
    /** موبایل/تلفنِ اصلیِ این مخاطب (از تماس‌هایِ خودِ شخص یا تماس‌هایِ این طرف‌حساب که به او مرتبط‌اند) */
    PrimaryPhone?: string | null;
    PrimaryPhoneExtension?: string | null;
    PrimaryPhoneType?: string | null;
    IsPrimaryContact: boolean | number | string;
    IsActive: boolean | number | string;
}

interface Person { PersonID: number; DisplayName: string; }

interface RelationsPanelProps {
    partyId: number;
    items: Relation[];
    positions: { PositionID: number; DisplayName: string }[];
    contactRoles: { ContactRoleID: number; DisplayName: string }[];
    titles: { TitleID: number; DisplayName: string }[];
    canManage: boolean;
    /** دسترسیِ CRM_DELETE */
    canDelete?: boolean;
}

const emptyDraft = {
    relationId: null as number | null,
    personId: null as number | null,
    positionIds: [] as number[],
    roleIds: [] as number[],
    isPrimaryContact: false,
};

const emptyNewPerson = {
    firstName: '',
    lastName: '',
    titleId: null as number | null,
    identifierNumber: '',
    identifierDate: null as string | null,
};

/**
 * مخاطبینِ یک طرف‌حساب — مدلِ Party ↔ Relationship ↔ Person: شخص مستقل است
 * (می‌تواند به چند طرف‌حساب دیگر هم مرتبط باشد)، سمت و نقش هر دو چندمقداری،
 * مخاطبِ اصلی و وضعیتِ رابطه ویژگیِ خودِ رابطه‌اند نه شخص.
 */
export default function RelationsPanel({ partyId, items: initialItems, positions, contactRoles, titles, canManage, canDelete = false }: RelationsPanelProps) {
    const [items, setItems] = useState<Relation[]>(initialItems || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft);
    const [personOptions, setPersonOptions] = useState<Person[]>([]);
    const [personSearching, setPersonSearching] = useState(false);
    const [creatingNewPerson, setCreatingNewPerson] = useState(false);
    const [newPerson, setNewPerson] = useState(emptyNewPerson);
    const [positionOptions, setPositionOptions] = useState(positions || []);
    const [creatingNewPosition, setCreatingNewPosition] = useState(false);
    const [newPositionName, setNewPositionName] = useState('');
    const [roleOptions, setRoleOptions] = useState(contactRoles || []);
    const [creatingNewRole, setCreatingNewRole] = useState(false);
    const [newRoleName, setNewRoleName] = useState('');
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const reload = async () => {
        const res = await crmApi(`/crm/relations?partyId=${partyId}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    const searchPersons = async (text: string) => {
        setPersonSearching(true);
        const res = await crmApi(`/crm/persons?search=${encodeURIComponent(text)}&isActive=1`);
        setPersonSearching(false);
        if (res.ok && res.success) setPersonOptions(res.items || []);
    };

    const openCreate = () => {
        setDraft(emptyDraft);
        setPersonOptions([]);
        setCreatingNewPerson(false);
        setNewPerson(emptyNewPerson);
        setCreatingNewPosition(false);
        setNewPositionName('');
        setCreatingNewRole(false);
        setNewRoleName('');
        setError(null);
        setFormOpen(true);
        searchPersons('');
    };

    const openEdit = async (row: Relation) => {
        const rolesRes = await crmApi(`/crm/relations/${row.RelationID}/roles`);
        setDraft({
            relationId: row.RelationID,
            personId: row.PersonID,
            positionIds: rolesRes.ok && rolesRes.success ? (rolesRes.positionIds || []).map(Number) : [],
            roleIds: rolesRes.ok && rolesRes.success ? (rolesRes.roleIds || []).map(Number) : [],
            isPrimaryContact: toBool(row.IsPrimaryContact),
        });
        setPersonOptions([{ PersonID: row.PersonID, DisplayName: row.PersonName }]);
        setCreatingNewPerson(false);
        setCreatingNewPosition(false);
        setNewPositionName('');
        setCreatingNewRole(false);
        setNewRoleName('');
        setError(null);
        setFormOpen(true);
    };

    const handleCreateNewPosition = async () => {
        if (!newPositionName.trim()) {
            setError('نامِ سمت الزامی است.');
            return;
        }
        setSaving(true);
        const res = await crmApi('/crm/directory/positions', 'POST', { displayName: newPositionName.trim() });
        setSaving(false);
        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        const created = { PositionID: Number(res.positionId), DisplayName: newPositionName.trim() };
        setPositionOptions((opts) => [created, ...opts]);
        setDraft((d) => ({ ...d, positionIds: [...d.positionIds, created.PositionID] }));
        setCreatingNewPosition(false);
        setNewPositionName('');
        setError(null);
    };

    const handleCreateNewRole = async () => {
        if (!newRoleName.trim()) {
            setError('نامِ نقش الزامی است.');
            return;
        }
        setSaving(true);
        const res = await crmApi('/crm/directory/contact-roles', 'POST', { displayName: newRoleName.trim() });
        setSaving(false);
        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        const created = { ContactRoleID: Number(res.contactRoleId), DisplayName: newRoleName.trim() };
        setRoleOptions((opts) => [created, ...opts]);
        setDraft((d) => ({ ...d, roleIds: [...d.roleIds, created.ContactRoleID] }));
        setCreatingNewRole(false);
        setNewRoleName('');
        setError(null);
    };

    const handleCreateNewPerson = async () => {
        if (!newPerson.titleId || !newPerson.lastName.trim()) {
            setError('عنوان و نامِ خانوادگیِ شخصِ جدید الزامی است.');
            return;
        }
        setSaving(true);
        const res = await crmApi('/crm/persons', 'POST', {
            firstName: newPerson.firstName.trim() || undefined,
            lastName: newPerson.lastName.trim(),
            titleId: newPerson.titleId,
            identifierNumber: newPerson.identifierNumber.trim() || undefined,
            identifierDate: newPerson.identifierDate || undefined,
        });
        setSaving(false);
        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        const created: Person = { PersonID: res.personId, DisplayName: [newPerson.firstName.trim(), newPerson.lastName.trim()].filter(Boolean).join(' ') };
        setPersonOptions((opts) => [created, ...opts]);
        setDraft((d) => ({ ...d, personId: created.PersonID }));
        setCreatingNewPerson(false);
        setNewPerson(emptyNewPerson);
        setError(null);
    };

    const handleSave = async () => {
        if (!draft.personId) {
            setError('انتخابِ شخص الزامی است.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/relations', 'POST', {
            relationId: draft.relationId ?? undefined,
            partyId,
            personId: draft.personId,
            positionIds: draft.positionIds,
            isPrimaryContact: draft.isPrimaryContact,
            roleIds: draft.roleIds,
        });
        setSaving(false);
        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        setFormOpen(false);
        setNotification({ open: true, type: 'success', message: res.message });
        reload();
    };

    // گزینه‌هایِ Multi-Select؛ موردِ انتخاب‌شده‌ای که در فهرستِ فعال نیست (غیرفعال‌شده) هم با برچسب نمایش داده می‌شود
    const withSelected = (opts: { value: number; label: string }[], selected: number[]) => [
        ...opts,
        ...selected.filter((id) => !opts.some((o) => o.value === id)).map((id) => ({ value: id, label: `(غیرفعال) #${id}` })),
    ];
    const positionSelectOptions = withSelected(positionOptions.map((p) => ({ value: Number(p.PositionID), label: p.DisplayName })), draft.positionIds);
    const roleSelectOptions = withSelected(roleOptions.map((r) => ({ value: Number(r.ContactRoleID), label: r.DisplayName })), draft.roleIds);

    const handleToggle = async (row: Relation) => {
        setTogglingId(row.RelationID);
        const res = await crmApi(`/crm/relations/${row.RelationID}/toggle`, 'POST');
        setTogglingId(null);
        setNotification({ open: true, type: res.success ? 'success' : 'error', message: res.message });
        if (res.success) reload();
    };

    const columns: ColumnsType<Relation> = [
        {
            title: 'شخص',
            key: 'person',
            render: (_, r) => (
                <Space size={4}>
                    {toBool(r.IsPrimaryContact) ? <StarFilled style={{ color: '#F59E0B', fontSize: 12 }} /> : null}
                    <Text>{r.PersonName}</Text>
                </Space>
            ),
        },
        {
            title: 'شمارهٔ تماسِ اصلی',
            key: 'primaryPhone',
            render: (_, r) => r.PrimaryPhone ? (
                <Space size={4}>
                    <PhoneOutlined style={{ color: '#6B7280', fontSize: 12 }} />
                    <a href={`tel:${r.PrimaryPhone}`} dir="ltr">{r.PrimaryPhone}</a>
                    {r.PrimaryPhoneExtension ? <Text type="secondary" style={{ fontSize: 12 }}>(داخلی: {r.PrimaryPhoneExtension})</Text> : null}
                    {r.PrimaryPhoneType ? <Text type="secondary" style={{ fontSize: 12 }}>{r.PrimaryPhoneType}</Text> : null}
                </Space>
            ) : <Text type="secondary">—</Text>,
        },
        { title: 'سمت‌ها', dataIndex: 'PositionName', key: 'PositionName', render: (v) => v || <Text type="secondary">—</Text> },
        { title: 'نقش‌ها', dataIndex: 'RoleNames', key: 'RoleNames', render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'وضعیتِ رابطه',
            key: 'status',
            width: 120,
            align: 'center',
            render: (_, r) => {
                const active = toBool(r.IsActive);
                return (
                    <Tag icon={active ? <CheckCircleOutlined /> : <StopOutlined />} color={active ? 'success' : 'default'} style={{ borderRadius: 6 }}>
                        {active ? 'فعال' : 'غیرفعال'}
                    </Tag>
                );
            },
        },
        {
            title: 'عملیات',
            key: 'actions',
            width: 130,
            align: 'center',
            render: (_, r) => {
                if (!canManage) return canDelete ? <CrmDeleteButton entity="relation" id={r.RelationID} label="مخاطب" onDone={(ok, msg) => { setNotification({ open: true, type: ok ? 'success' : 'error', message: msg }); if (ok) reload(); }} /> : null;
                const active = toBool(r.IsActive);
                return (
                    <Space>
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ رابطه' : 'فعال‌کردنِ رابطه'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                            <Button type="text" size="small" danger={active} loading={togglingId === r.RelationID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                        {canDelete ? <CrmDeleteButton entity="relation" id={r.RelationID} label="مخاطب" onDone={(ok, msg) => { setNotification({ open: true, type: ok ? 'success' : 'error', message: msg }); if (ok) reload(); }} /> : null}
                    </Space>
                );
            },
        },
    ];

    return (
        <div>
            {canManage ? (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                    <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>مخاطبِ جدید</Button>
                </div>
            ) : null}

            {error && !formOpen ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {formOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}

                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>شخص</Text>
                        <Space wrap>
                            <Select
                                showSearch
                                style={{ width: 260 }}
                                placeholder="جست‌وجویِ شخصِ موجود..."
                                value={draft.personId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, personId: v }))}
                                onSearch={searchPersons}
                                loading={personSearching}
                                filterOption={false}
                                notFoundContent={personSearching ? undefined : 'شخصی یافت نشد'}
                                options={personOptions.map((p) => ({ value: p.PersonID, label: p.DisplayName }))}
                                disabled={!!draft.relationId}
                            />
                            {!draft.relationId ? (
                                <Button icon={<UserAddOutlined />} onClick={() => setCreatingNewPerson((v) => !v)}>
                                    شخصِ جدید
                                </Button>
                            ) : null}
                        </Space>
                        {draft.relationId ? <Text type="secondary" style={{ fontSize: 12, display: 'block' }}>شخصِ این رابطه پس از ایجاد قابلِ‌تغییر نیست؛ برایِ تغییرِ شخص یک رابطهٔ جدید بسازید.</Text> : null}
                    </div>

                    {creatingNewPerson ? (
                        <Space direction="vertical" style={{ background: '#FAFAFA', padding: 12, borderRadius: 8, width: '100%' }} size={8}>
                            <Space wrap>
                                <Select
                                    placeholder="عنوان *"
                                    value={newPerson.titleId ?? undefined}
                                    onChange={(v) => setNewPerson((p) => ({ ...p, titleId: v ?? null }))}
                                    options={(titles || []).map((t) => ({ value: t.TitleID, label: t.DisplayName }))}
                                    style={{ width: 130 }}
                                />
                                <Input placeholder="نام (اختیاری)" value={newPerson.firstName} onChange={(e) => setNewPerson((p) => ({ ...p, firstName: e.target.value }))} style={{ width: 150 }} />
                                <Input placeholder="نامِ خانوادگی *" value={newPerson.lastName} onChange={(e) => setNewPerson((p) => ({ ...p, lastName: e.target.value }))} style={{ width: 150 }} />
                            </Space>
                            <Space wrap>
                                <Input
                                    dir="ltr"
                                    placeholder="کدِ ملی (۱۰ رقم)"
                                    maxLength={10}
                                    value={newPerson.identifierNumber}
                                    onChange={(e) => setNewPerson((p) => ({ ...p, identifierNumber: e.target.value.replace(/\D/g, '') }))}
                                    style={{ width: 160 }}
                                />
                                <PersianDateInput value={newPerson.identifierDate} onChange={(v) => setNewPerson((p) => ({ ...p, identifierDate: v }))} placeholder="تاریخِ تولد" />
                                <Button type="primary" size="small" loading={saving} onClick={handleCreateNewPerson}>ایجادِ شخص</Button>
                            </Space>
                        </Space>
                    ) : null}

                    <Divider style={{ margin: '4px 0' }} />

                    <Space style={{ width: '100%' }} wrap align="start">
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>سمت‌ها</Text>
                            <Space wrap>
                                <Select
                                    mode="multiple"
                                    allowClear
                                    style={{ minWidth: 240, maxWidth: '100%' }}
                                    placeholder="انتخابِ یک یا چند سمت"
                                    value={draft.positionIds}
                                    onChange={(v) => setDraft((d) => ({ ...d, positionIds: v }))}
                                    options={positionSelectOptions}
                                    optionFilterProp="label"
                                    notFoundContent="سمتی تعریف نشده است"
                                />
                                <Button icon={<UserAddOutlined />} onClick={() => setCreatingNewPosition((v) => !v)}>
                                    سمتِ جدید
                                </Button>
                            </Space>
                            {creatingNewPosition ? (
                                <Space style={{ marginTop: 8 }}>
                                    <Input
                                        placeholder="نامِ سمت"
                                        value={newPositionName}
                                        onChange={(e) => setNewPositionName(e.target.value)}
                                        style={{ width: 160 }}
                                    />
                                    <Button type="primary" size="small" loading={saving} onClick={handleCreateNewPosition}>ایجاد</Button>
                                </Space>
                            ) : null}
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نقش‌ها</Text>
                            <Space wrap>
                                <Select
                                    mode="multiple"
                                    allowClear
                                    style={{ minWidth: 240, maxWidth: '100%' }}
                                    placeholder="انتخابِ یک یا چند نقش"
                                    value={draft.roleIds}
                                    onChange={(v) => setDraft((d) => ({ ...d, roleIds: v }))}
                                    options={roleSelectOptions}
                                    optionFilterProp="label"
                                    notFoundContent="نقشی تعریف نشده است؛ «نقشِ جدید» را بزنید"
                                />
                                <Button icon={<PlusOutlined />} onClick={() => setCreatingNewRole((v) => !v)}>
                                    نقشِ جدید
                                </Button>
                            </Space>
                            {creatingNewRole ? (
                                <Space style={{ marginTop: 8 }}>
                                    <Input
                                        placeholder="نامِ نقش"
                                        value={newRoleName}
                                        onChange={(e) => setNewRoleName(e.target.value)}
                                        onPressEnter={handleCreateNewRole}
                                        style={{ width: 160 }}
                                    />
                                    <Button type="primary" size="small" loading={saving} onClick={handleCreateNewRole}>ایجاد</Button>
                                </Space>
                            ) : null}
                        </div>
                        <div style={{ paddingTop: 22 }}>
                            <Checkbox checked={draft.isPrimaryContact} onChange={(e) => setDraft((d) => ({ ...d, isPrimaryContact: e.target.checked }))}>
                                مخاطبِ اصلی
                            </Checkbox>
                        </div>
                    </Space>

                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="RelationID" columns={columns} dataSource={items} pagination={false} scroll={{ x: true }} locale={{ emptyText: <Empty description="مخاطبی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
