import { useEffect, useMemo } from 'react';
import { Modal, Form, Input, Row, Col, Button, TreeSelect, Select, Switch, Typography } from 'antd';
import {
    IdcardOutlined,
    SaveOutlined,
    CloseOutlined,
    ApartmentOutlined,
    CrownOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';

interface Position {
    PositionID: number;
    PositionCode: string;
    PositionName: string;
    UnitID: number;
    IsUnitManager: boolean | number;
    ParentPositionID: number | null;
    Description: string | null;
}

interface Unit {
    UnitID: number;
    UnitName: string;
}

interface PositionFormModalProps {
    open: boolean;
    onClose: () => void;
    editingPosition: Position | null;
    positions: Position[];
    units: Unit[];
}

interface TreeNode {
    title: string;
    value: number;
    disabled?: boolean;
    children: TreeNode[];
}

export default function PositionFormModal({
    open,
    onClose,
    editingPosition,
    positions,
    units,
}: PositionFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingPosition;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        PositionName: '',
        UnitID: null as number | null,
        IsUnitManager: false,
        ParentPositionID: null as number | null,
        Description: '',
    });

    const treeData = useMemo<TreeNode[]>(() => {
        const excluded = new Set<number>();
        if (editingPosition) {
            excluded.add(editingPosition.PositionID);
            const childrenMap = new Map<number | null, Position[]>();
            positions.forEach((p) => {
                const key = p.ParentPositionID;
                if (!childrenMap.has(key)) childrenMap.set(key, []);
                childrenMap.get(key)!.push(p);
            });
            const queue = [editingPosition.PositionID];
            while (queue.length > 0) {
                const current = queue.shift()!;
                (childrenMap.get(current) || []).forEach((child) => {
                    if (!excluded.has(child.PositionID)) {
                        excluded.add(child.PositionID);
                        queue.push(child.PositionID);
                    }
                });
            }
        }

        const byParent = new Map<number | null, Position[]>();
        positions.forEach((p) => {
            const key = p.ParentPositionID;
            if (!byParent.has(key)) byParent.set(key, []);
            byParent.get(key)!.push(p);
        });

        const knownParentIDs = new Set(positions.map((p) => p.ParentPositionID).filter(Boolean));

        const buildNode = (position: Position): TreeNode => ({
            title: `${position.PositionCode} - ${position.PositionName}`,
            value: position.PositionID,
            disabled: excluded.has(position.PositionID),
            children: (byParent.get(position.PositionID) || [])
                .sort((a, b) => a.PositionCode.localeCompare(b.PositionCode))
                .map(buildNode),
        });

        return positions
            .filter((p) => p.ParentPositionID === null || !knownParentIDs.has(p.ParentPositionID))
            .sort((a, b) => a.PositionCode.localeCompare(b.PositionCode))
            .map(buildNode);
    }, [positions, editingPosition]);

    useEffect(() => {
        if (open) {
            if (editingPosition) {
                const formData = {
                    PositionName: editingPosition.PositionName || '',
                    UnitID: editingPosition.UnitID ?? null,
                    IsUnitManager: String(editingPosition.IsUnitManager) === '1' || editingPosition.IsUnitManager === true,
                    ParentPositionID: editingPosition.ParentPositionID ?? null,
                    Description: editingPosition.Description || '',
                };
                setData(formData);
                form.setFieldsValue(formData);
            } else {
                reset();
                form.resetFields();
            }
            clearErrors();
        }
    }, [open, editingPosition]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            const options = {
                preserveScroll: true,
                onSuccess: () => onClose(),
            };

            if (isEdit) {
                put(`/positions/${editingPosition!.PositionID}`, options);
            } else {
                post('/positions', options);
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
                    <IdcardOutlined style={{ color: '#1890ff', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش سمت' : 'ایجاد سمت جدید'}</span>
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
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد سمت'}
                </Button>,
            ]}
        >
            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item
                            label="کد سمت"
                            extra="کد به صورت خودکار و از 101 ساخته می‌شود"
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="خودکار"
                                value={isEdit ? editingPosition?.PositionCode : 'خودکار'}
                                disabled
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={12}>
                        <Form.Item
                            label="نام سمت"
                            name="PositionName"
                            rules={[
                                { required: true, message: 'نام سمت الزامی است' },
                                { max: 200, message: 'حداکثر 200 کاراکتر' },
                            ]}
                            validateStatus={errors.PositionName ? 'error' : ''}
                            help={errors.PositionName}
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="مثلاً: مدیر منابع انسانی"
                                value={data.PositionName}
                                onChange={(e) => setData('PositionName', e.target.value)}
                                size="large"
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={12}>
                        <Form.Item
                            label="واحد"
                            name="UnitID"
                            rules={[{ required: true, message: 'انتخاب واحد الزامی است' }]}
                            validateStatus={errors.UnitID ? 'error' : ''}
                            help={errors.UnitID}
                        >
                            <Select
                                style={{ width: '100%' }}
                                placeholder="انتخاب واحد"
                                showSearch
                                optionFilterProp="label"
                                size="large"
                                value={data.UnitID ?? undefined}
                                onChange={(value: number) => setData('UnitID', value)}
                                options={(units || []).map((u: Unit) => ({
                                    value: u.UnitID,
                                    label: u.UnitName,
                                }))}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={12}>
                        <Form.Item
                            label="مدیر واحد"
                            name="IsUnitManager"
                            valuePropName="checked"
                            extra="در صورت فعال بودن، این سمت مدیر واحد محسوب می‌شود"
                        >
                            <Switch
                                checkedChildren={<CrownOutlined />}
                                unCheckedChildren={<ApartmentOutlined />}
                                checked={data.IsUnitManager}
                                onChange={(checked: boolean) => setData('IsUnitManager', checked)}
                            />
                        </Form.Item>
                    </Col>

                    <Col span={24}>
                        <Form.Item
                            label="سمت والد"
                            name="ParentPositionID"
                            extra="در صورت انتخاب، این سمت زیرمجموعه ثبت می‌شود"
                        >
                            <TreeSelect
                                style={{ width: '100%' }}
                                treeData={treeData}
                                placeholder="انتخاب سمت والد (اختیاری)"
                                allowClear
                                treeDefaultExpandAll
                                showSearch
                                treeNodeFilterProp="title"
                                size="large"
                                value={data.ParentPositionID ?? undefined}
                                onChange={(value: number | null) =>
                                    setData('ParentPositionID', value ?? null)
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