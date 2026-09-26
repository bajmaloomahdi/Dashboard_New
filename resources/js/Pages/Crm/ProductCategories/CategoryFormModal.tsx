import { useEffect, useMemo, useState } from 'react';
import { Modal, Form, Input, InputNumber, TreeSelect, Button, Alert, Typography } from 'antd';
import { SaveOutlined, CloseOutlined } from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';
import { ProductCategoryRow, selfAndDescendants, toTreeSelectData } from '../../../Components/Crm/productCategoryTree';

const { Text } = Typography;

interface CategoryFormModalProps {
    open: boolean;
    onClose: () => void;
    /** ردیفِ در حالِ ویرایش؛ null یعنی ایجاد */
    editingCategory: ProductCategoryRow | null;
    /** والدِ پیش‌فرض هنگامِ «زیرمجموعهٔ جدید»؛ null یعنی ریشه */
    defaultParentId: number | null;
    categories: ProductCategoryRow[];
    onSuccess: (message: string) => void;
}

/**
 * فرمِ ایجاد/ویرایشِ دستهٔ محصول. والد با TreeSelect انتخاب می‌شود؛ هنگامِ ویرایش خودِ دسته و همهٔ
 * زیرمجموعه‌هایش در گزینه‌ها نیستند (جلوگیری از حلقه — SP هم دوباره بررسی می‌کند). خالی = ریشه.
 */
export default function CategoryFormModal({ open, onClose, editingCategory, defaultParentId, categories, onSuccess }: CategoryFormModalProps) {
    const isEdit = !!editingCategory;
    const [displayName, setDisplayName] = useState('');
    const [parentId, setParentId] = useState<number | null>(null);
    const [sortOrder, setSortOrder] = useState<number>(0);
    const [processing, setProcessing] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);

    useEffect(() => {
        if (!open) return;
        setDisplayName(editingCategory?.DisplayName || '');
        setParentId(editingCategory ? (editingCategory.ParentCategoryID === null ? null : Number(editingCategory.ParentCategoryID)) : defaultParentId);
        setSortOrder(editingCategory ? Number(editingCategory.SortOrder) || 0 : 0);
        setFormError(null);
    }, [open, editingCategory, defaultParentId]);

    const originalParentId = editingCategory?.ParentCategoryID === null || editingCategory?.ParentCategoryID === undefined ? null : Number(editingCategory.ParentCategoryID);
    const treeData = useMemo(
        () => toTreeSelectData(categories, editingCategory ? selfAndDescendants(categories, Number(editingCategory.ProductCategoryID)) : undefined, originalParentId),
        [categories, editingCategory, originalParentId]
    );

    const handleClose = () => {
        if (processing) return;
        onClose();
    };

    const handleSubmit = async () => {
        if (!displayName.trim()) {
            setFormError('نامِ دسته‌بندی الزامی است.');
            return;
        }
        setProcessing(true);
        setFormError(null);
        const res = await crmApi('/crm/product-categories', 'POST', {
            productCategoryId: editingCategory ? Number(editingCategory.ProductCategoryID) : undefined,
            parentCategoryId: parentId ?? undefined,
            displayName: displayName.trim(),
            sortOrder,
        });
        setProcessing(false);
        if (!res.ok || !res.success) {
            setFormError(res.message);
            return;
        }
        onSuccess(res.message);
    };

    return (
        <Modal
            title={isEdit ? 'ویرایشِ دسته‌بندی' : 'دسته‌بندیِ جدید'}
            open={open}
            onCancel={handleClose}
            width={520}
            footer={[
                <Button key="cancel" icon={<CloseOutlined />} onClick={handleClose} disabled={processing}>
                    انصراف
                </Button>,
                <Button key="submit" type="primary" icon={<SaveOutlined />} loading={processing} onClick={handleSubmit}>
                    {isEdit ? 'ذخیرهٔ تغییرات' : 'ایجادِ دسته‌بندی'}
                </Button>,
            ]}
            destroyOnClose
        >
            {formError ? <Alert type="error" showIcon message={formError} style={{ marginBottom: 16, borderRadius: 8 }} /> : null}

            <Form layout="vertical" requiredMark>
                <Form.Item label="نام" required>
                    <Input value={displayName} maxLength={200} onChange={(e) => setDisplayName(e.target.value)} size="large" />
                </Form.Item>

                <Form.Item label="دستهٔ والد" extra={<Text type="secondary" style={{ fontSize: 12 }}>خالی = دستهٔ ریشه</Text>}>
                    <TreeSelect
                        allowClear
                        showSearch
                        treeDefaultExpandAll
                        size="large"
                        placeholder="بدونِ والد (ریشه)"
                        value={parentId ?? undefined}
                        onChange={(v) => setParentId(v ?? null)}
                        treeData={treeData}
                        treeNodeFilterProp="title"
                        style={{ width: '100%' }}
                    />
                </Form.Item>

                <Form.Item label="ترتیب">
                    <InputNumber value={sortOrder} onChange={(v) => setSortOrder(Number(v) || 0)} style={{ width: 120 }} size="large" />
                </Form.Item>
            </Form>
        </Modal>
    );
}
