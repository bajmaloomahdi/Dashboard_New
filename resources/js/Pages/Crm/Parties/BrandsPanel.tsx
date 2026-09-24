import { useState } from 'react';
import { Table, Button, Input, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { toBool } from '../../../Utils/bool';

const { Text } = Typography;

interface Brand {
    BrandID: number;
    PartyID: number;
    Name: string;
    Description: string | null;
    IsActive: boolean | number | string;
}

interface BrandsPanelProps {
    partyId: number;
    items: Brand[];
    canManage: boolean;
}

const emptyDraft = { id: null as number | null, name: '', description: '' };

/** برندهایِ یک طرف‌حساب — Entityِ مستقل (نه Brand1/Brand2). */
export default function BrandsPanel({ partyId, items: initialItems, canManage }: BrandsPanelProps) {
    const [items, setItems] = useState<Brand[]>(initialItems || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const reload = async () => {
        const res = await crmApi(`/crm/brands?partyId=${partyId}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    const handleSave = async () => {
        if (!draft.name.trim()) {
            setError('نامِ برند الزامی است.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/brands', 'POST', {
            brandId: draft.id ?? undefined,
            partyId,
            name: draft.name.trim(),
            description: draft.description.trim() || undefined,
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

    const handleToggle = async (row: Brand) => {
        setTogglingId(row.BrandID);
        const res = await crmApi(`/crm/brands/${row.BrandID}/toggle`, 'POST');
        setTogglingId(null);
        setNotification({ open: true, type: res.success ? 'success' : 'error', message: res.message });
        if (res.success) reload();
    };

    const columns: ColumnsType<Brand> = [
        { title: 'نام', dataIndex: 'Name', key: 'Name' },
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
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => { setDraft({ id: r.BrandID, name: r.Name, description: r.Description || '' }); setError(null); setFormOpen(true); }} />
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ برند' : 'فعال‌کردنِ برند'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                            <Button type="text" size="small" danger={active} loading={togglingId === r.BrandID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
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
                        برندِ جدید
                    </Button>
                </div>
            ) : null}

            {error && !formOpen ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {formOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    <Space style={{ width: '100%' }} wrap>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نام</Text>
                            <Input style={{ width: 240 }} value={draft.name} onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))} />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات</Text>
                            <Input style={{ width: 320 }} value={draft.description} onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))} />
                        </div>
                    </Space>
                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="BrandID" columns={columns} dataSource={items} pagination={false} locale={{ emptyText: <Empty description="برندی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
