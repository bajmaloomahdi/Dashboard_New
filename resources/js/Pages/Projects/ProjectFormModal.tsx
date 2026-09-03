import { useEffect, useState } from 'react';
import { Modal, Form, Input, InputNumber, Select, Row, Col, Button, Switch } from 'antd';
import {
    SaveOutlined,
    CloseOutlined,
    ProjectOutlined,
    IdcardOutlined,
    TeamOutlined,
    CrownOutlined,
} from '@ant-design/icons';
import { useForm } from '@inertiajs/react';
import PersianDateInput from '../../Components/PersianDateInput';
import { getUserOptionLabel } from '../../Utils/userHelpers';

interface Project {
    ProjectID: number;
    ProjectCode: string;
    ProjectTitle: string;
    Description: string | null;
    StartDate: string | null;
    PlannedEndDate: string | null;
    ActualEndDate: string | null;
    ProjectStatusID: number;
    ProjectPriorityID: number | null;
    ProgressPercent: number;
    IsActive: boolean | number;
}

interface Option {
    UserID: number;
    FullName: string;
    FirstName?: string | null;
    LastName?: string | null;
    UserName?: string | null;
    PositionTitle?: string | null;
}
interface StatusOption {
    ProjectStatusID: number;
    Title: string;
}
interface PriorityOption {
    ProjectPriorityID: number;
    Name: string;
    ColorHex: string;
}

interface ProjectFormModalProps {
    open: boolean;
    onClose: () => void;
    editingProject: Project | null;
    users: Option[];
    statuses: StatusOption[];
    priorities: PriorityOption[];
}

export default function ProjectFormModal({
    open,
    onClose,
    editingProject,
    users,
    statuses,
    priorities,
}: ProjectFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingProject;

    const [codeError, setCodeError] = useState<string>('');

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        ProjectCode: '',
        ProjectTitle: '',
        Description: '',
        ProjectStatusID: 2,
        ProjectPriorityID: null as number | null,
        ProgressPercent: 0,
        StartDate: null as string | null,
        PlannedEndDate: null as string | null,
        ActualEndDate: null as string | null,
        IsActive: true,
        ResponsibleUserID: null as number | null,
        MemberUserIDs: [] as number[],
    });

    useEffect(() => {
        if (open) {
            if (editingProject) {
                const d = {
                    ProjectCode: editingProject.ProjectCode || '',
                    ProjectTitle: editingProject.ProjectTitle || '',
                    Description: editingProject.Description || '',
                    ProjectStatusID: editingProject.ProjectStatusID ?? 2,
                    ProjectPriorityID: editingProject.ProjectPriorityID ?? null,
                    ProgressPercent: editingProject.ProgressPercent ?? 0,
                    StartDate: editingProject.StartDate || null,
                    PlannedEndDate: editingProject.PlannedEndDate || null,
                    ActualEndDate: editingProject.ActualEndDate || null,
                    IsActive: editingProject.IsActive ? true : false,
                    ResponsibleUserID: null,
                    MemberUserIDs: [],
                };
                setData(d);
                form.setFieldsValue({
                    ProjectCode: d.ProjectCode,
                    ProjectTitle: d.ProjectTitle,
                    Description: d.Description,
                    ProjectStatusID: d.ProjectStatusID,
                    ProjectPriorityID: d.ProjectPriorityID,
                    ProgressPercent: d.ProgressPercent,
                    IsActive: d.IsActive,
                });
            } else {
                reset();
                form.resetFields();
                form.setFieldsValue({ ProjectStatusID: 2, IsActive: true });
            }
            setCodeError('');
            clearErrors();
        }
    }, [open, editingProject]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            if (!isEdit && !data.ResponsibleUserID) {
                setCodeError('');
                return;
            }
            const options = { preserveScroll: true, onSuccess: () => onClose() };
            if (isEdit) {
                put(`/projects/${editingProject!.ProjectID}`, options);
            } else {
                post('/projects', options);
            }
        });
    };

    const handleClose = () => {
        form.resetFields();
        reset();
        clearErrors();
        setCodeError('');
        onClose();
    };

    // گزینه‌های اعضا = همه کاربران به جز مسئول انتخاب‌شده
    const memberOptions = (users || []).filter(
        (u) => u.UserID !== data.ResponsibleUserID
    );

    return (
        <Modal
            title={
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                    <ProjectOutlined style={{ color: '#667eea', fontSize: 20 }} />
                    <span>{isEdit ? 'ویرایش پروژه' : 'ایجاد پروژه جدید'}</span>
                </div>
            }
            open={open}
            onCancel={handleClose}
            width={720}
            className="responsive-modal"
            footer={[
                <Button key="cancel" icon={<CloseOutlined />} onClick={handleClose} disabled={processing}>
                    انصراف
                </Button>,
                <Button
                    key="submit"
                    type="primary"
                    icon={<SaveOutlined />}
                    loading={processing}
                    onClick={handleSubmit}
                >
                    {isEdit ? 'ذخیره تغییرات' : 'ایجاد پروژه'}
                </Button>,
            ]}
        >
            <Form form={form} layout="vertical" requiredMark>
                <Row gutter={16}>
                    <Col xs={24} md={8}>
                        <Form.Item
                            label="کد پروژه"
                            extra="کد یکتا (مثلاً PRJ-001)"
                            validateStatus={errors.ProjectCode || codeError ? 'error' : ''}
                            help={errors.ProjectCode || codeError}
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="PRJ-001"
                                value={data.ProjectCode}
                                onChange={(e) => setData('ProjectCode', e.target.value)}
                                size="large"
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={16}>
                        <Form.Item
                            label="عنوان پروژه"
                            name="ProjectTitle"
                            rules={[
                                { required: true, message: 'عنوان پروژه الزامی است' },
                                { max: 250, message: 'حداکثر 250 کاراکتر' },
                            ]}
                            validateStatus={errors.ProjectTitle ? 'error' : ''}
                            help={errors.ProjectTitle}
                        >
                            <Input
                                placeholder="مثلاً: پیاده‌سازی ماژول حسابداری"
                                value={data.ProjectTitle}
                                onChange={(e) => setData('ProjectTitle', e.target.value)}
                                size="large"
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col span={24}>
                        <Form.Item label="توضیحات" name="Description">
                            <Input.TextArea
                                placeholder="توضیحات اختیاری..."
                                value={data.Description}
                                onChange={(e) => setData('Description', e.target.value)}
                                rows={2}
                                maxLength={2000}
                                showCount
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={6}>
                        <Form.Item
                            label="وضعیت پروژه"
                            name="ProjectStatusID"
                            rules={[{ required: true, message: 'وضعیت را انتخاب کنید' }]}
                        >
                            <Select
                                placeholder="انتخاب وضعیت"
                                options={(statuses || []).map((s) => ({ value: s.ProjectStatusID, label: s.Title }))}
                                value={data.ProjectStatusID}
                                onChange={(v) => setData('ProjectStatusID', v)}
                                size="large"
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={6}>
                        <Form.Item label="اولویت پروژه" name="ProjectPriorityID">
                            <Select
                                placeholder="انتخاب اولویت"
                                allowClear
                                options={(priorities || []).map((p) => ({
                                    value: p.ProjectPriorityID,
                                    label: (
                                        <span>
                                            <span
                                                style={{
                                                    display: 'inline-block',
                                                    width: 10,
                                                    height: 10,
                                                    borderRadius: '50%',
                                                    background: p.ColorHex,
                                                    marginLeft: 8,
                                                }}
                                            />
                                            {p.Name}
                                        </span>
                                    ),
                                }))}
                                value={data.ProjectPriorityID}
                                onChange={(v) => setData('ProjectPriorityID', v ?? null)}
                                size="large"
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={6}>
                        <Form.Item label="درصد پیشرفت" name="ProgressPercent">
                            <InputNumber
                                min={0}
                                max={100}
                                addonAfter="%"
                                value={data.ProgressPercent}
                                onChange={(v) => setData('ProgressPercent', Number(v) || 0)}
                                style={{ width: '100%' }}
                                size="large"
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={6}>
                        <Form.Item label="فعال" name="IsActive" valuePropName="checked">
                            <Switch
                                checked={data.IsActive}
                                onChange={(v) => setData('IsActive', v)}
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={8}>
                        <Form.Item label="تاریخ شروع" name="StartDate">
                            <PersianDateInput
                                value={data.StartDate}
                                onChange={(v) => setData('StartDate', v)}
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={8}>
                        <Form.Item label="تاریخ پایان پیش‌بینی" name="PlannedEndDate">
                            <PersianDateInput
                                value={data.PlannedEndDate}
                                onChange={(v) => setData('PlannedEndDate', v)}
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    <Col xs={24} md={8}>
                        <Form.Item label="تاریخ پایان واقعی" name="ActualEndDate">
                            <PersianDateInput
                                value={data.ActualEndDate}
                                onChange={(v) => setData('ActualEndDate', v)}
                                disabled={processing}
                            />
                        </Form.Item>
                    </Col>

                    {/* فقط در حالت ایجاد: انتخاب مسئول و اعضا */}
                    {!isEdit && (
                        <>
                            <Col xs={24} md={12}>
                                <Form.Item
                                    label={
                                        <span>
                                            <CrownOutlined style={{ color: '#D97706' }} /> مسئول پروژه
                                        </span>
                                    }
                                    required
                                    validateStatus={codeError ? 'error' : ''}
                                    help={codeError || 'مسئول می‌تواند بعداً اعضا را مدیریت کند'}
                                >
                                    <Select
                                        placeholder="انتخاب مسئول"
                                        showSearch
                                        optionFilterProp="label"
                                        options={(users || []).map((u) => ({ value: u.UserID, label: getUserOptionLabel(u) }))}
                                        value={data.ResponsibleUserID}
                                        onChange={(v) => setData('ResponsibleUserID', v)}
                                        size="large"
                                        disabled={processing}
                                    />
                                </Form.Item>
                            </Col>

                            <Col xs={24} md={12}>
                                <Form.Item
                                    label={
                                        <span>
                                            <TeamOutlined /> اعضای پروژه
                                        </span>
                                    }
                                >
                                    <Select
                                        mode="multiple"
                                        placeholder="انتخاب اعضا"
                                        optionFilterProp="label"
                                        maxTagCount="responsive"
                                        options={memberOptions.map((u) => ({ value: u.UserID, label: getUserOptionLabel(u) }))}
                                        value={data.MemberUserIDs}
                                        onChange={(v) => setData('MemberUserIDs', v)}
                                        size="large"
                                        disabled={processing}
                                    />
                                </Form.Item>
                            </Col>
                        </>
                    )}
                </Row>
            </Form>
        </Modal>
    );
}