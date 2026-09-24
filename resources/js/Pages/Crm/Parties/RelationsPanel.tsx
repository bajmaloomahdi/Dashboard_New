import { useState } from 'react';
import { Table, Button, Select, Checkbox, Space, Tag, Popconfirm, Typography, Alert, Empty, Input, Divider } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, StarFilled, UserAddOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import PersianDateInput from '../../../Components/PersianDateInput';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface Relation {
    RelationID: number;
    PartyID: number;
    PersonID: number;
    PersonName: string;
    PositionID: number | null;
    PositionName: string | null;
    RoleNames: string | null;
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
}

const emptyDraft = {
    relationId: null as number | null,
    personId: null as number | null,
    positionId: null as number | null,
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
 * (می‌تواند به چند طرف‌حساب دیگر هم مرتبط باشد)، سمت تک‌مقداری، نقش چندمقداری،
 * مخاطبِ اصلی و وضعیتِ رابطه ویژگیِ خودِ رابطه‌اند نه شخص.
 */
export default function RelationsPanel({ partyId, items: initialItems, positions, contactRoles, titles, canManage }: RelationsPanelProps) {
    const [items, setItems] = useState<Relation[]>(initialItems || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft);
    const [personOptions, setPersonOptions] = useState<Person[]>([]);
    const [personSearching, setPersonSearching] = useState(false);
    const [creatingNewPerson, setCreatingNewPerson] = useState(false);
    const [newPerson, setNewPerson] = useState(emptyNewPerson);
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
        setError(null);
        setFormOpen(true);
    };

    const openEdit = async (row: Relation) => {
        const rolesRes = await crmApi(`/crm/relations/${row.RelationID}/roles`);
        setDraft({
            relationId: row.RelationID,
            personId: row.PersonID,
            positionId: row.PositionID,
            roleIds: rolesRes.ok && rolesRes.success ? rolesRes.roleIds || [] : [],
            isPrimaryContact: toBool(row.IsPrimaryContact),
        });
        setPersonOptions([{ PersonID: row.PersonID, DisplayName: row.PersonName }]);
        setCreatingNewPerson(false);
        setError(null);
        setFormOpen(true);
    };

    const handleCreateNewPerson = async () => {
        if (!newPerson.firstName.trim() || !newPerson.lastName.trim()) {
            setError('نام و نامِ خانوادگیِ شخصِ جدید الزامی است.');
            return;
        }
        setSaving(true);
        const res = await crmApi('/crm/persons', 'POST', {
            firstName: newPerson.firstName.trim(),
            lastName: newPerson.lastName.trim(),
            titleId: newPerson.titleId ?? undefined,
            identifierNumber: newPerson.identifierNumber.trim() || undefined,
            identifierDate: newPerson.identifierDate || undefined,
        });
        setSaving(false);
        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        const created: Person = { PersonID: res.personId, DisplayName: `${newPerson.firstName.trim()} ${newPerson.lastName.trim()}` };
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
            positionId: draft.positionId ?? undefined,
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
        { title: 'سمت', dataIndex: 'PositionName', key: 'PositionName', render: (v) => v || <Text type="secondary">—</Text> },
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
            width: 100,
            align: 'center',
            render: (_, r) => {
                if (!canManage) return null;
                const active = toBool(r.IsActive);
                return (
                    <Space>
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ رابطه' : 'فعال‌کردنِ رابطه'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                            <Button type="text" size="small" danger={active} loading={togglingId === r.RelationID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
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
                                    allowClear
                                    placeholder="عنوان"
                                    value={newPerson.titleId ?? undefined}
                                    onChange={(v) => setNewPerson((p) => ({ ...p, titleId: v ?? null }))}
                                    options={(titles || []).map((t) => ({ value: t.TitleID, label: t.DisplayName }))}
                                    style={{ width: 130 }}
                                />
                                <Input placeholder="نام" value={newPerson.firstName} onChange={(e) => setNewPerson((p) => ({ ...p, firstName: e.target.value }))} style={{ width: 150 }} />
                                <Input placeholder="نامِ خانوادگی" value={newPerson.lastName} onChange={(e) => setNewPerson((p) => ({ ...p, lastName: e.target.value }))} style={{ width: 150 }} />
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

                    <Space style={{ width: '100%' }} wrap>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>سمت</Text>
                            <Select
                                allowClear
                                style={{ width: 200 }}
                                placeholder="سمت"
                                value={draft.positionId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, positionId: v ?? null }))}
                                options={positions.map((p) => ({ value: p.PositionID, label: p.DisplayName }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نقش‌ها (چندتایی)</Text>
                            <Select
                                mode="multiple"
                                style={{ width: 320 }}
                                placeholder="انتخابِ نقش‌ها"
                                value={draft.roleIds}
                                onChange={(v) => setDraft((d) => ({ ...d, roleIds: v }))}
                                options={contactRoles.map((r) => ({ value: r.ContactRoleID, label: r.DisplayName }))}
                            />
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

            <Table rowKey="RelationID" columns={columns} dataSource={items} pagination={false} locale={{ emptyText: <Empty description="مخاطبی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
