import { useEffect, useState } from 'react';
import { Modal, Table, Button, Input, InputNumber, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, TagsOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { wfApi } from '../../../Components/Workflow/workflowApi';
import { toBool } from '../../../Utils/bool';
import { THEME } from '../../../theme';

const { Text } = Typography;

export interface WorkflowCategory {
    CategoryID: number;
    Code: string;
    Name: string;
    Description: string | null;
    SortOrder: number;
    IsActive: boolean | number | string;
    DefinitionCount: number;
}

interface CategoryManagerModalProps {
    open: boolean;
    onClose: () => void;
    categories: WorkflowCategory[];
    /** بعد از هر تغییرِ موفق (ایجاد/ویرایش/تاگل) — والد لیستِ دسته‌ها را Refresh می‌کند */
    onChanged: () => void;
}

interface RowDraft {
    categoryId: number | null;
    code: string;
    name: string;
    description: string;
    sortOrder: number;
}

const emptyDraft: RowDraft = { categoryId: null, code: '', name: '', description: '', sortOrder: 0 };

/**
 * Modalِ سبکِ «مدیریتِ دسته‌بندی‌ها» — روی صفحهٔ لیستِ فرایندها، فقط برایِ
 * دارندگانِ WORKFLOW_MANAGE_CATEGORIES. حذفِ فیزیکی وجود ندارد؛ فقط فعال/غیرفعال
 * (IsActive) — دقیقاً هم‌الگو با toggleِ خودِ Definitionها.
 */
export default function CategoryManagerModal({ open, onClose, categories, onChanged }: CategoryManagerModalProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState<RowDraft>(emptyDraft);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) {
            setFormOpen(false);
            setDraft(emptyDraft);
            setError(null);
        }
    }, [open]);

    const openCreate = () => {
        setDraft(emptyDraft);
        setError(null);
        setFormOpen(true);
    };

    const openEdit = (cat: WorkflowCategory) => {
        setDraft({
            categoryId: cat.CategoryID,
            code: cat.Code,
            name: cat.Name,
            description: cat.Description || '',
            sortOrder: cat.SortOrder,
        });
        setError(null);
        setFormOpen(true);
    };

    const handleSave = async () => {
        if (!draft.code.trim() || !draft.name.trim()) {
            setError('کد و نامِ دسته الزامی است.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await wfApi('/workflow/categories', 'POST', {
            categoryId: draft.categoryId ?? undefined,
            code: draft.code.trim(),
            name: draft.name.trim(),
            description: draft.description.trim() || undefined,
            sortOrder: draft.sortOrder,
        });
        setSaving(false);

        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        setFormOpen(false);
        onChanged();
    };

    const handleToggle = async (cat: WorkflowCategory) => {
        setTogglingId(cat.CategoryID);
        const res = await wfApi(`/workflow/categories/${cat.CategoryID}/toggle`, 'POST');
        setTogglingId(null);

        if (!res.ok || !res.success) {
            setError(res.message);
            return;
        }
        onChanged();
    };

    const columns: ColumnsType<WorkflowCategory> = [
        { title: 'کد', dataIndex: 'Code', key: 'Code', width: 120, render: (v: string) => <Text code dir="ltr">{v}</Text> },
        { title: 'نام', dataIndex: 'Name', key: 'Name' },
        {
            title: 'تعدادِ فرایند',
            dataIndex: 'DefinitionCount',
            key: 'DefinitionCount',
            width: 100,
            align: 'center',
            render: (v: number) => <Tag style={{ borderRadius: 6 }}>{v}</Tag>,
        },
        {
            title: 'وضعیت',
            key: 'status',
            width: 100,
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
            width: 110,
            align: 'center',
            render: (_, r) => {
                const active = toBool(r.IsActive);
                return (
                    <Space>
                        <Button type="text" size="small" icon={<EditOutlined />} onClick={() => openEdit(r)} />
                        <Popconfirm
                            title={active ? 'غیرفعال‌کردنِ دسته' : 'فعال‌کردنِ دسته'}
                            description={active ? 'اگر فرایندِ فعالی به این دسته وابسته باشد، غیرفعال‌سازی مسدود می‌شود.' : 'آیا مطمئن هستید؟'}
                            onConfirm={() => handleToggle(r)}
                            okText="بله"
                            cancelText="خیر"
                        >
                            <Button
                                type="text"
                                size="small"
                                danger={active}
                                loading={togglingId === r.CategoryID}
                                icon={active ? <StopOutlined /> : <CheckCircleOutlined />}
                            />
                        </Popconfirm>
                    </Space>
                );
            },
        },
    ];

    return (
        <Modal
            title={
                <Space>
                    <TagsOutlined style={{ color: THEME.primary }} />
                    <span>مدیریتِ دسته‌بندی‌هایِ فرایند</span>
                </Space>
            }
            open={open}
            onCancel={onClose}
            width={720}
            footer={<Button onClick={onClose}>بستن</Button>}
            destroyOnClose
        >
            {error ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {!formOpen ? (
                <>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 12 }}>
                        <Button type="primary" icon={<PlusOutlined />} onClick={openCreate}>
                            دستهٔ جدید
                        </Button>
                    </div>
                    <Table
                        size="small"
                        rowKey="CategoryID"
                        columns={columns}
                        dataSource={categories}
                        pagination={false}
                        locale={{ emptyText: <Empty description="دسته‌ای تعریف نشده" /> }}
                    />
                </>
            ) : (
                <Space direction="vertical" style={{ width: '100%' }} size={12}>
                    <Space style={{ width: '100%' }} wrap>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>کد</Text>
                            <Input
                                dir="ltr"
                                style={{ width: 160 }}
                                value={draft.code}
                                onChange={(e) => setDraft((d) => ({ ...d, code: e.target.value.toUpperCase() }))}
                                placeholder="PROCUREMENT"
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نام</Text>
                            <Input style={{ width: 220 }} value={draft.name} onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))} placeholder="خرید" />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ترتیب</Text>
                            <InputNumber style={{ width: 90 }} value={draft.sortOrder} onChange={(v) => setDraft((d) => ({ ...d, sortOrder: v ?? 0 }))} />
                        </div>
                    </Space>
                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات</Text>
                        <Input.TextArea rows={2} value={draft.description} onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))} placeholder="توضیحِ اختیاری..." />
                    </div>
                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>
                            انصراف
                        </Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>
                            ذخیره
                        </Button>
                    </Space>
                </Space>
            )}
        </Modal>
    );
}
