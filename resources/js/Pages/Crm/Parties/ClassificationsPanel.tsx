import { useState } from 'react';
import { Table, Button, Select, Space, Tag, Popconfirm, Typography, Alert, Empty, Divider } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { toBool } from '../../../Utils/bool';

const { Text, Title } = Typography;

interface Classification {
    ClassificationID: number;
    PartyID: number;
    DepartmentID: number | null;
    PartyTypeID: number | null;
    ActivityID: number | null;
    DepartmentName: string | null;
    PartyTypeName: string | null;
    ActivityName: string | null;
    IsActive: boolean | number | string;
}

interface ClassificationsPanelProps {
    partyId: number;
    items: Classification[];
    departments: { DepartmentID: number; DisplayName: string }[];
    partyTypes: { PartyTypeID: number; DisplayName: string }[];
    activities: { ActivityID: number; DisplayName: string; PartyTypeID: number }[];
    canManage: boolean;
}

/**
 * دسته‌بندی‌هایِ یک طرف‌حساب — دو بعدِ کاملاً مستقل، هرکدام چندگانه:
 * «دپارتمان» (طرف‌حساب می‌تواند در چند دپارتمان باشد) و «نوع/فعالیت»
 * (طرف‌حساب می‌تواند چند نوع داشته باشد). هر دو روی همان جدولِ
 * CrmPartyClassifications ذخیره می‌شوند، فقط با دو فرم/نمایشِ جداگانه؛
 * هنگامِ ویرایش از یک بخش، بعدِ دیگرِ همان ردیف (اگر مقداری داشته باشد)
 * دست‌نخورده می‌ماند.
 */
export default function ClassificationsPanel({ partyId, items: initialItems, departments, partyTypes, activities, canManage }: ClassificationsPanelProps) {
    const [items, setItems] = useState<Classification[]>(initialItems || []);

    const [deptFormOpen, setDeptFormOpen] = useState(false);
    const [deptDraft, setDeptDraft] = useState<{ id: number | null; departmentId: number | null }>({ id: null, departmentId: null });
    const [deptSaving, setDeptSaving] = useState(false);
    const [deptError, setDeptError] = useState<string | null>(null);

    const [typeFormOpen, setTypeFormOpen] = useState(false);
    const [typeDraft, setTypeDraft] = useState<{ id: number | null; partyTypeId: number | null; activityId: number | null }>({ id: null, partyTypeId: null, activityId: null });
    const [typeSaving, setTypeSaving] = useState(false);
    const [typeError, setTypeError] = useState<string | null>(null);

    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const departmentRows = items.filter((i) => i.DepartmentID);
    const typeActivityRows = items.filter((i) => i.PartyTypeID || i.ActivityID);
    const filteredActivities = (activities || []).filter((a) => !typeDraft.partyTypeId || a.PartyTypeID === typeDraft.partyTypeId);

    const reload = async () => {
        const res = await crmApi(`/crm/classifications?partyId=${partyId}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    const handleSaveDept = async () => {
        if (!deptDraft.departmentId) {
            setDeptError('انتخابِ دپارتمان الزامی است.');
            return;
        }
        setDeptSaving(true);
        setDeptError(null);
        const editingRow = deptDraft.id ? items.find((i) => i.ClassificationID === deptDraft.id) : null;
        const res = await crmApi('/crm/classifications', 'POST', {
            classificationId: deptDraft.id ?? undefined,
            partyId,
            departmentId: deptDraft.departmentId,
            partyTypeId: editingRow?.PartyTypeID ?? undefined,
            activityId: editingRow?.ActivityID ?? undefined,
        });
        setDeptSaving(false);
        if (!res.ok || !res.success) {
            setDeptError(res.message);
            return;
        }
        setDeptFormOpen(false);
        setNotification({ open: true, type: 'success', message: res.message });
        reload();
    };

    const handleSaveType = async () => {
        if (!typeDraft.partyTypeId && !typeDraft.activityId) {
            setTypeError('حداقل انتخابِ نوع الزامی است.');
            return;
        }
        setTypeSaving(true);
        setTypeError(null);
        const editingRow = typeDraft.id ? items.find((i) => i.ClassificationID === typeDraft.id) : null;
        const res = await crmApi('/crm/classifications', 'POST', {
            classificationId: typeDraft.id ?? undefined,
            partyId,
            departmentId: editingRow?.DepartmentID ?? undefined,
            partyTypeId: typeDraft.partyTypeId ?? undefined,
            activityId: typeDraft.activityId ?? undefined,
        });
        setTypeSaving(false);
        if (!res.ok || !res.success) {
            setTypeError(res.message);
            return;
        }
        setTypeFormOpen(false);
        setNotification({ open: true, type: 'success', message: res.message });
        reload();
    };

    const handleToggle = async (row: Classification) => {
        setTogglingId(row.ClassificationID);
        const res = await crmApi(`/crm/classifications/${row.ClassificationID}/toggle`, 'POST');
        setTogglingId(null);
        setNotification({ open: true, type: res.success ? 'success' : 'error', message: res.message });
        if (res.success) reload();
    };

    const statusColumn: ColumnsType<Classification>[number] = {
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
    };

    const actionsColumn = (onEdit: (r: Classification) => void): ColumnsType<Classification>[number] => ({
        title: 'عملیات',
        key: 'actions',
        width: 100,
        align: 'center',
        render: (_, r) => {
            if (!canManage) return null;
            const active = toBool(r.IsActive);
            return (
                <Space>
                    <Button type="text" size="small" icon={<EditOutlined />} onClick={() => onEdit(r)} />
                    <Popconfirm title={active ? 'غیرفعال‌کردن' : 'فعال‌کردن'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                        <Button type="text" size="small" danger={active} loading={togglingId === r.ClassificationID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                    </Popconfirm>
                </Space>
            );
        },
    });

    const deptColumns: ColumnsType<Classification> = [
        { title: 'دپارتمان', dataIndex: 'DepartmentName', key: 'DepartmentName', render: (v) => v || <Text type="secondary">—</Text> },
        statusColumn,
        actionsColumn((r) => {
            setDeptDraft({ id: r.ClassificationID, departmentId: r.DepartmentID });
            setDeptError(null);
            setDeptFormOpen(true);
        }),
    ];

    const typeColumns: ColumnsType<Classification> = [
        { title: 'نوع', dataIndex: 'PartyTypeName', key: 'PartyTypeName', render: (v) => v || <Text type="secondary">—</Text> },
        { title: 'فعالیت', dataIndex: 'ActivityName', key: 'ActivityName', render: (v) => v || <Text type="secondary">—</Text> },
        statusColumn,
        actionsColumn((r) => {
            setTypeDraft({ id: r.ClassificationID, partyTypeId: r.PartyTypeID, activityId: r.ActivityID });
            setTypeError(null);
            setTypeFormOpen(true);
        }),
    ];

    return (
        <div>
            <Title level={5} style={{ marginTop: 0 }}>دپارتمان</Title>
            {canManage ? (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => { setDeptDraft({ id: null, departmentId: null }); setDeptError(null); setDeptFormOpen(true); }}>
                        دپارتمانِ جدید
                    </Button>
                </div>
            ) : null}

            {deptError && !deptFormOpen ? <Alert type="error" showIcon message={deptError} closable onClose={() => setDeptError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {deptFormOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {deptError ? <Alert type="error" showIcon message={deptError} style={{ borderRadius: 8 }} /> : null}
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>دپارتمان</Text>
                        <Select
                            style={{ width: 240 }}
                            placeholder="دپارتمان"
                            value={deptDraft.departmentId ?? undefined}
                            onChange={(v) => setDeptDraft((d) => ({ ...d, departmentId: v }))}
                            options={(departments || []).map((d) => ({ value: d.DepartmentID, label: d.DisplayName }))}
                        />
                    </div>
                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setDeptFormOpen(false)} disabled={deptSaving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={deptSaving} onClick={handleSaveDept}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="ClassificationID" columns={deptColumns} dataSource={departmentRows} pagination={false} locale={{ emptyText: <Empty description="دپارتمانی ثبت نشده است" /> }} />

            <Divider />

            <Title level={5}>نوع و فعالیت</Title>
            {canManage ? (
                <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                    <Button type="primary" icon={<PlusOutlined />} onClick={() => { setTypeDraft({ id: null, partyTypeId: null, activityId: null }); setTypeError(null); setTypeFormOpen(true); }}>
                        نوعِ جدید
                    </Button>
                </div>
            ) : null}

            {typeError && !typeFormOpen ? <Alert type="error" showIcon message={typeError} closable onClose={() => setTypeError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {typeFormOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {typeError ? <Alert type="error" showIcon message={typeError} style={{ borderRadius: 8 }} /> : null}
                    <Space style={{ width: '100%' }} wrap>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوع</Text>
                            <Select
                                style={{ width: 200 }}
                                placeholder="نوع"
                                value={typeDraft.partyTypeId ?? undefined}
                                onChange={(v) => setTypeDraft((d) => ({ ...d, partyTypeId: v, activityId: null }))}
                                options={(partyTypes || []).map((t) => ({ value: t.PartyTypeID, label: t.DisplayName }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>فعالیت</Text>
                            <Select
                                allowClear
                                disabled={!typeDraft.partyTypeId}
                                style={{ width: 200 }}
                                placeholder="فعالیت"
                                value={typeDraft.activityId ?? undefined}
                                onChange={(v) => setTypeDraft((d) => ({ ...d, activityId: v ?? null }))}
                                options={filteredActivities.map((a) => ({ value: a.ActivityID, label: a.DisplayName }))}
                            />
                        </div>
                    </Space>
                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setTypeFormOpen(false)} disabled={typeSaving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={typeSaving} onClick={handleSaveType}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="ClassificationID" columns={typeColumns} dataSource={typeActivityRows} pagination={false} locale={{ emptyText: <Empty description="نوعی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
