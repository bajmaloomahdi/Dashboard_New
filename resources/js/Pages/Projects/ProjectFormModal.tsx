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
import { toBool } from '../../Utils/bool';
import { useCrmSearch } from '../Calendar/EventLinksFields';

interface Project {
    DepartmentID?: number | string | null;
    DepartmentName?: string | null;
    ParentProjectID?: number | string | null;
    HasParentProject?: boolean | number | string;
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
    departmentOptions?: { DepartmentID: number | string; DisplayName: string }[];
    returnToDetails?: boolean;
    parentOptions: { id: number | string; title: string; parentId: number | string | null }[];
    open: boolean;
    onClose: () => void;
    editingProject: Project | null;
    users: Option[];
    statuses: StatusOption[];
    priorities: PriorityOption[];
    canLinkCrm?: boolean;
}

export default function ProjectFormModal({
    open,
    onClose,
    editingProject,
    users,
    statuses,
    priorities,
    parentOptions,
    returnToDetails = false,
    departmentOptions = [],
    canLinkCrm = false,
}: ProjectFormModalProps) {
    const [form] = Form.useForm();
    const isEdit = !!editingProject;
    const partySearch = useCrmSearch('/crm/parties-list', 'PartyID', open && !isEdit && canLinkCrm);

    const [codeError, setCodeError] = useState<string>('');
    const [currentMembers, setCurrentMembers] = useState<Option[]>([]);
    const [team, setTeam] = useState<{ responsible: number | null; members: number[]; canManage: boolean } | null>(null);

    const { data, setData, post, put, transform, processing, errors, reset, clearErrors } = useForm({
        ProjectCode: '',
        ParentProjectID: null as string | null,
        DepartmentID: null as number | null,
        ProjectTitle: '',
        Description: '',
        ProjectStatusID: 1, // «ثبت‌شده» — وضعیت پیش‌فرضِ پروژه‌ی جدید
        ProjectPriorityID: null as number | null,
        ProgressPercent: 0,
        StartDate: null as string | null,
        PlannedEndDate: null as string | null,
        ActualEndDate: null as string | null,
        IsActive: true,
        ResponsibleUserID: null as number | null,
        MemberUserIDs: [] as number[],
        OwnerPartyID: null as number | null,
        ContractorPartyIDs: [] as number[],
    });

    useEffect(() => {
        if (open) {
            if (editingProject) {
                const d = {
                    ProjectCode: editingProject.ProjectCode || '',
                    ParentProjectID: editingProject.ParentProjectID == null ? null : String(editingProject.ParentProjectID),
                    DepartmentID: editingProject.DepartmentID == null ? null : Number(editingProject.DepartmentID),
                    ProjectTitle: editingProject.ProjectTitle || '',
                    Description: editingProject.Description || '',
                    ProjectStatusID: editingProject.ProjectStatusID ?? 1,
                    ProjectPriorityID: editingProject.ProjectPriorityID ?? null,
                    ProgressPercent: editingProject.ProgressPercent ?? 0,
                    StartDate: editingProject.StartDate || null,
                    PlannedEndDate: editingProject.PlannedEndDate || null,
                    ActualEndDate: editingProject.ActualEndDate || null,
                    IsActive: toBool(editingProject.IsActive),
                    ResponsibleUserID: null,
                    MemberUserIDs: [],
                    OwnerPartyID: null,
                    ContractorPartyIDs: [],
                };
                setData(d);
                form.setFieldsValue(d);
            } else {
                reset();
                form.resetFields();
                form.setFieldsValue({ IsActive: true });
                // وضعیت پیش‌فرض = نخستین وضعیت بر اساس ترتیب (معمولاً «ثبت‌شده»)
                const defaultStatus = (statuses || [])[0]?.ProjectStatusID ?? 1;
                setData('ProjectStatusID', defaultStatus);
            }
            setCodeError('');
            clearErrors();
        }
    }, [open, editingProject]);

    useEffect(() => {
        setTeam(null);
        if (!open || !editingProject) return;
        const abort = new AbortController();
        fetch(`/projects/${editingProject.ProjectID}/members`, {
            signal: abort.signal, headers: { Accept: 'application/json' },
        }).then((res) => {
            if (!res.ok) throw new Error('Could not load project members');
            return res.json();
        }).then(({ members, canManage }) => {
            if (abort.signal.aborted) return;
            const active = (members as (Option & { IsActive: boolean | number; IsResponsible: boolean | number })[])
                .filter((member) => toBool(member.IsActive));
            const responsible = active.find((member) => toBool(member.IsResponsible))?.UserID ?? null;
            const ids = active.filter((member) => Number(member.UserID) !== Number(responsible)).map((member) => Number(member.UserID));
            setCurrentMembers(active);
            setTeam({ responsible: responsible == null ? null : Number(responsible), members: ids, canManage: canManage === true });
            setData((current) => ({ ...current, ResponsibleUserID: responsible == null ? null : Number(responsible), MemberUserIDs: ids }));
        }).catch(() => { /* Scalar edits can be saved without replacing team data. */ });
        return () => abort.abort();
    }, [open, editingProject]);

    const handleSubmit = () => {
        form.validateFields().then(() => {
            if (!isEdit && !data.ResponsibleUserID) {
                setCodeError('');
                return;
            }
            const options = { preserveScroll: true, onSuccess: () => onClose() };
            transform((values) => {
                if (!isEdit) return values;
                const { ResponsibleUserID, MemberUserIDs, OwnerPartyID, ContractorPartyIDs, ParentProjectID, DepartmentID, ...fields } = values;
                const parentChanged = ParentProjectID !== (editingProject?.ParentProjectID == null ? null : String(editingProject.ParentProjectID));
                const departmentChanged = DepartmentID !== (editingProject?.DepartmentID == null ? null : Number(editingProject.DepartmentID));
                const projectFields = { ...fields, ...(parentChanged ? { ParentProjectID } : {}), ...(departmentChanged ? { DepartmentID } : {}), returnToDetails };
                const members = MemberUserIDs.filter((id) => id !== ResponsibleUserID);
                const changed = team?.canManage && (ResponsibleUserID !== team.responsible
                    || members.length !== team.members.length || members.some((id) => !team.members.includes(id)));
                return changed ? { ...projectFields, ResponsibleUserID, MemberUserIDs: members } : projectFields;
            });
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
    const userOptions = Array.from(new Map(
        [...(isEdit ? currentMembers : []), ...(users || [])].map((user) => [Number(user.UserID), { ...user, UserID: Number(user.UserID) }]),
    ).values());
    const memberOptions = userOptions.filter(
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
                {/* زیرِ md همهٔ ستون‌ها xs=24 هستند؛ gutterِ افقی فقط margin منفی می‌سازد که در بدنهٔ
                    اسکرول‌شوندهٔ responsive-modal اسکرولِ افقیِ 8px ایجاد می‌کرد. دسکتاپ همان 16 */}
                <Row gutter={{ xs: 0, sm: 0, md: 16 }}>
                    <Col xs={24} md={8}>
                        <Form.Item
                            label="کد پروژه"
                            extra={isEdit ? 'کد پروژه پس از ایجاد تغییر نمی‌کند' : 'به‌صورت خودکار ساخته می‌شود (مثل PRJ-1405-0001)'}
                            validateStatus={errors.ProjectCode ? 'error' : ''}
                            help={errors.ProjectCode}
                        >
                            <Input
                                prefix={<IdcardOutlined style={{ color: '#bfbfbf' }} />}
                                placeholder="به‌صورت خودکار پس از ذخیره"
                                value={data.ProjectCode}
                                size="large"
                                readOnly
                                disabled
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
                        {!isEdit && <Form.Item label="پروژهٔ والد" validateStatus={errors.ParentProjectID ? 'error' : undefined}
                            help={errors.ParentProjectID}>
                            <Select aria-label="پروژهٔ والد" allowClear showSearch optionFilterProp="label"
                                placeholder="بدون والد" value={data.ParentProjectID ?? undefined}
                                onChange={(value) => setData('ParentProjectID', value ?? null)}
                                options={parentOptions.filter(({ id }) => String(id) !== String(editingProject?.ProjectID))
                                    .map(({ id, title }) => ({ value: String(id), label: title }))} />
                        </Form.Item>}
                        <Form.Item label="دپارتمان" validateStatus={errors.DepartmentID ? 'error' : undefined} help={errors.DepartmentID}>
                            <Select aria-label="دپارتمان" allowClear showSearch optionFilterProp="label"
                                placeholder="بدون دپارتمان" value={data.DepartmentID ?? undefined}
                                onChange={(value) => setData('DepartmentID', value ?? null)}
                                options={departmentOptions.map((department) => ({ value: Number(department.DepartmentID), label: department.DisplayName }))}>
                            </Select>
                        </Form.Item>
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
                            required
                            validateStatus={errors.ProjectStatusID ? 'error' : ''}
                            help={errors.ProjectStatusID}
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
                        <Form.Item label="اولویت پروژه">
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
                        <Form.Item label="تاریخ پایان پیش‌بینی" name="PlannedEndDate"
                            validateStatus={errors.PlannedEndDate ? 'error' : undefined}
                            help={errors.PlannedEndDate || (!isEdit ? 'با انتخاب تاریخ، رویداد موعد پایان ساعت ۱۳ ثبت می‌شود.' : undefined)}>
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

                    {(!isEdit || team?.canManage) && (
                        <>
                            <Col xs={24} md={12}>
                                <Form.Item
                                    label={
                                        <span>
                                            <CrownOutlined style={{ color: '#D97706' }} /> مسئول پروژه
                                        </span>
                                    }
                                    required
                                    validateStatus={codeError || errors.ResponsibleUserID ? 'error' : ''}
                                    help={errors.ResponsibleUserID || codeError || 'مسئول می‌تواند بعداً اعضا را مدیریت کند'}
                                >
                                    <Select
                                        placeholder="انتخاب مسئول"
                                        showSearch
                                        optionFilterProp="label"
                                        options={userOptions.map((u) => ({ value: u.UserID, label: getUserOptionLabel(u) }))}
                                        value={data.ResponsibleUserID}
                                        onChange={(v) => setData((current) => ({
                                            ...current,
                                            ResponsibleUserID: v,
                                            MemberUserIDs: [...new Set([
                                                ...current.MemberUserIDs,
                                                ...(isEdit && current.ResponsibleUserID != null ? [current.ResponsibleUserID] : []),
                                            ])].filter((id) => id !== v),
                                        }))}
                                        size="large"
                                        disabled={processing}
                                    />
                                </Form.Item>
                            </Col>

                            <Col xs={24} md={12}>
                                <Form.Item
                                    validateStatus={errors.MemberUserIDs ? 'error' : ''}
                                    help={errors.MemberUserIDs}
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
                {!isEdit && canLinkCrm && (
                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item label="مالک پروژه" help={errors.OwnerPartyID} validateStatus={errors.OwnerPartyID ? 'error' : undefined}>
                                <Select aria-label="مالک پروژه" allowClear showSearch filterOption={false}
                                    placeholder="جست‌وجوی طرف‌حساب" options={partySearch.options} loading={partySearch.loading}
                                    value={data.OwnerPartyID ?? undefined} onSearch={partySearch.search} onFocus={() => partySearch.search('')}
                                    onChange={(value) => setData('OwnerPartyID', value ?? null)} />
                            </Form.Item>
                        </Col>
                        <Col xs={24} md={12}>
                            <Form.Item label="پیمانکاران" help={errors.ContractorPartyIDs} validateStatus={errors.ContractorPartyIDs ? 'error' : undefined}>
                                <Select aria-label="پیمانکاران" mode="multiple" allowClear showSearch filterOption={false}
                                    placeholder="جست‌وجوی پیمانکاران" options={partySearch.options} loading={partySearch.loading}
                                    value={data.ContractorPartyIDs} onSearch={partySearch.search} onFocus={() => partySearch.search('')}
                                    onChange={(value) => setData('ContractorPartyIDs', value)} />
                            </Form.Item>
                        </Col>
                    </Row>
                )}
            </Form>
        </Modal>
    );
}
