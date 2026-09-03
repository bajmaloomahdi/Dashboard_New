import { useEffect, useMemo } from 'react';
import { Modal, Form, Input, Row, Col, Button, TreeSelect, Typography } from 'antd';
import {
    SaveOutlined,
    CloseOutlined,
    ApartmentOutlined,
    IdcardOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

interface Unit {
    UnitID: number;
    UnitCode: string;
    UnitName: string;
    ParentUnitID: number | null;
    Description: string | null;
}

interface UnitFormModalProps {
    open: boolean;
    onClose: () => void;
    editingUnit: Unit | null;
    units: Unit[];
}

interface TreeNode {
    title: string;
    value: number;
    disabled?: boolean;
    children: TreeNode[];
}

export default function UnitFormModal({ open, onClose, editingUnit, units }: UnitFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingUnit;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        UnitName: '',
        ParentUnitID: null as number | null,
        Description: '',
    });

    // ساخت درخت واحدها؛ در حالت ویرایش، خودِ واحد و زیرمجموعه‌هایش انتخاب نمی‌شوند
    const treeData = useMemo<TreeNode[]>(() => {
        const excluded = new Set<number>();
        if (editingUnit) {
            excluded.add(editingUnit.UnitID);
            const childrenMap = new Map<number | null, Unit[]>();
            units.forEach((u) => {
                const key = u.ParentUnitID;
                if (!childrenMap.has(key)) childrenMap.set(key, []);
                childrenMap.get(key)!.push(u);
            });
            const queue = [editingUnit.UnitID];
            while (queue.length > 0) {
                const current = queue.shift()!;
                (childrenMap.get(current) || []).forEach((child) => {
                    if (!excluded.has(child.UnitID)) {
                        excluded.add(child.UnitID);
                        queue.push(child.UnitID);
                    }
                });
            }
        }

        const byParent = new Map<number | null, Unit[]>();
        units.forEach((u) => {
            const key = u.ParentUnitID;
            if (!byParent.has(key)) byParent.set(key, []);
            byParent.get(key)!.push(u);
        });
        const knownParentIDs = new Set(units.map((u) => u.ParentUnitID).filter(Boolean));

        const buildNode = (unit: Unit): TreeNode => ({
            title: `${unit.UnitCode} - ${unit.UnitName}`,
            value: unit.UnitID,
            disabled: excluded.has(unit.UnitID),
            children: (byParent.get(unit.UnitID) || []).map(buildNode),
        });

        return units
            .filter((u) => u.ParentUnitID === null || !knownParentIDs.has(u.ParentUnitID))
            .map(buildNode);
    }, [units, editingUnit]);

    // پر کردن فرم هنگام ویرایش
    useEffect(() => {
        if (open) {
            if (editingUnit) {
                const formData = {
                    UnitName: editingUnit.UnitName || '',
                    ParentUnitID: editingUnit.ParentUnitID ?? null,
                    Description: editingUnit.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                reset();
                form.resetFields();
            }
            clearErrors();
        }
    }, [open, editingUnit]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const options = {
                preserveScroll: true,
                onSuccess: () => onClose(),
            };
            if (isEdit) {
                put(`/organizational-units/${editingUnit!.UnitID}`, options);
            } else {
                post('/organizational-units', options);
            }
        });
    };

    const handleClose = () => {
        form.resetFields();
        reset();
        clearErrors();
        onClose();
    };

    return (
        <Modal
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <ApartmentOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش واحد' : 'ایجاد واحد جدید'}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={650}
            className="responsive-modal"
            footer={[
                <Button
                    key="cancel"
                    icon={<CloseOutlined />}
                    onClick={handleClose}
                    disabled={processing}
                >
                    انصراف
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<SaveOutlined />}
                    loading={processing}
                    onClick={handleSubmit}
                >
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد واحد'}
                </Button>,
            ]}
        >
            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="کد واحد"
                            extra="کد به صورت خودکار و از 101 ساخته می‌شود"
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="خودکار"
                                value={isEdit ? editingUnit?.UnitCode : 'خودکار'}
                                disabled
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نام واحد"
                            name="UnitName"
                            rules={[
                                { required: true, message: 'نام واحد الزامی است' },
                                { max: 200, message: 'حداکثر 200 کاراکتر' },
                            ]}
                            validateStatus={errors.UnitName ? 'error' : ''}
                            help={errors.UnitName}
                        >
                            <Input
                                prefix={<ApartmentOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: منابع انسانی"
                                value={data.UnitName}
                                onChange={(e) => setData('UnitName', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col span={24}>
                        <Form.Item
                            label="واحد والد"
                            name="ParentUnitID"
                            extra="در صورت انتخاب، این واحد به‌عنوان زیرمجموعه ثبت می‌شود"
                        >
                            <TreeSelect
                                style={{ width: '100%' }}
                                treeData={treeData}
                                placeholder="انتخاب واحد والد (اختیاری)"
                                allowClear
                                treeDefaultExpandAll
                                showSearch
                                treeNodeFilterProp="title"
                                size="large"
                                value={data.ParentUnitID ?? undefined}
                                onChange={(value: number | null) =>
                                    setData('ParentUnitID', value ?? null)
                                }
                            />
                        </Form.Item>
                    </Col>

                    <Col span={24}>
                        <Form.Item
                            label="توضیحات"
                            name="Description"
                            rules={[{ max: 500, message: 'حداکثر 500 کاراکتر' }]}
                        >
                            <Input.TextArea
                                placeholder="توضیحات اختیاری..."
                                value={data.Description}
                                onChange={(e) => setData('Description', e.target.value)}
                                rows={3}
                                maxLength={500}
                                showCount
                            />
                        </Form.Item>
                    </Col>
                </Row>
            </Form>
        </Modal>
    );
}