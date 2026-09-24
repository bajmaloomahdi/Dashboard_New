import { useState } from 'react';
import { Table, Button, Input, Select, Checkbox, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, StarFilled } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface Contact {
    ContactID: number;
    PartyID: number | null;
    PersonID: number | null;
    ContactTypeID: number;
    ContactTypeName: string;
    ContactValue: string;
    Extension: string | null;
    Description: string | null;
    IsPrimary: boolean | number | string;
    IsActive: boolean | number | string;
}

interface ContactsPanelProps {
    /** دقیقاً یکی از partyId یا personId باید مشخص باشد — تماس یا به طرف‌حساب تعلق دارد یا مستقیماً به مخاطب. */
    partyId?: number;
    personId?: number;
    items: Contact[];
    contactTypes: { ContactTypeID: number; DisplayName: string }[];
    canManage: boolean;
}

const emptyDraft = { id: null as number | null, contactTypeId: null as number | null, value: '', extension: '', description: '', isPrimary: false };

/** اطلاعاتِ تماسِ یک طرف‌حساب یا یک مخاطب — «داخلی» فیلدی کنارِ مقدار است، نه نوعِ تماسِ جدا. */
export default function ContactsPanel({ partyId, personId, items: initialItems, contactTypes, canManage }: ContactsPanelProps) {
    const [items, setItems] = useState<Contact[]>(initialItems || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const ownerQuery = partyId ? `partyId=${partyId}` : `personId=${personId}`;

    const reload = async () => {
        const res = await crmApi(`/crm/contacts?${ownerQuery}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    const isPhoneType = (typeId: number | null) => {
        const t = contactTypes.find((c) => c.ContactTypeID === typeId);
        return t ? /تلفن|فکس/.test(t.DisplayName) : false;
    };

    const handleSave = async () => {
        if (!draft.contactTypeId) {
            setError('نوعِ تماس الزامی است.');
            return;
        }
        if (!draft.value.trim()) {
            setError('مقدارِ تماس الزامی است.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/contacts', 'POST', {
            contactId: draft.id ?? undefined,
            partyId,
            personId,
            contactTypeId: draft.contactTypeId,
            contactValue: draft.value.trim(),
            extension: draft.extension.trim() || undefined,
            description: draft.description.trim() || undefined,
            isPrimary: draft.isPrimary,
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

    const handleToggle = async (row: Contact) => {
        setTogglingId(row.ContactID);
        const res = await crmApi(`/crm/contacts/${row.ContactID}/toggle`, 'POST');
        setTogglingId(null);
        setNotification({ open: true, type: res.success ? 'success' : 'error', message: res.message });
        if (res.success) reload();
    };

    const columns: ColumnsType<Contact> = [
        { title: 'نوع', dataIndex: 'ContactTypeName', key: 'ContactTypeName', width: 100 },
        {
            title: 'مقدار',
            key: 'value',
            render: (_, r) => (
                <Space size={4}>
                    {toBool(r.IsPrimary) ? <StarFilled style={{ color: '#F59E0B', fontSize: 12 }} /> : null}
                    <Text dir="ltr">{r.ContactValue}</Text>
                    {r.Extension ? <Text type="secondary" style={{ fontSize: 12 }}>(داخلی: {r.Extension})</Text> : null}
                </Space>
            ),
        },
        { title: 'توضیحات', dataIndex: 'Description', key: 'Description', render: (v) => v || <Text type="secondary">—</Text> },
        {
            title: 'وضعیت',
            key: 'status',
            width: 110,
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
                        <Button
                            type="text"
                            size="small"
                            icon={<EditOutlined />}
                            onClick={() => {
                                setDraft({ id: r.ContactID, contactTypeId: r.ContactTypeID, value: r.ContactValue, extension: r.Extension || '', description: r.Description || '', isPrimary: toBool(r.IsPrimary) });
                                setError(null);
                                setFormOpen(true);
                            }}
                        />
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ تماس' : 'فعال‌کردنِ تماس'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                            <Button type="text" size="small" danger={active} loading={togglingId === r.ContactID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
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
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => { setDraft(emptyDraft); setError(null); setFormOpen(true); }}>
                        تماسِ جدید
                    </Button>
                </div>
            ) : null}

            {error && !formOpen ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {formOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    <Space style={{ width: '100%' }} wrap>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوعِ تماس</Text>
                            <Select
                                style={{ width: 160 }}
                                value={draft.contactTypeId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, contactTypeId: v }))}
                                options={contactTypes.map((c) => ({ value: c.ContactTypeID, label: c.DisplayName }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>مقدار</Text>
                            <Input dir="ltr" style={{ width: 220 }} value={draft.value} onChange={(e) => setDraft((d) => ({ ...d, value: e.target.value }))} />
                        </div>
                        {isPhoneType(draft.contactTypeId) ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>داخلی</Text>
                                <Input dir="ltr" style={{ width: 100 }} value={draft.extension} onChange={(e) => setDraft((d) => ({ ...d, extension: e.target.value }))} />
                            </div>
                        ) : null}
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات/کاربرد</Text>
                            <Input style={{ width: 200 }} value={draft.description} onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))} />
                        </div>
                        <div style={{ paddingTop: 22 }}>
                            <Checkbox checked={draft.isPrimary} onChange={(e) => setDraft((d) => ({ ...d, isPrimary: e.target.checked }))}>
                                تماسِ اصلی
                            </Checkbox>
                        </div>
                    </Space>
                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="ContactID" columns={columns} dataSource={items} pagination={false} locale={{ emptyText: <Empty description="اطلاعاتِ تماسی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
