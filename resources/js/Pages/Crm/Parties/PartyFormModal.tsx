import { useEffect, useState } from 'react';
import { Modal, Form, Input, Segmented, Row, Col, Button, Typography, Alert, Select } from 'antd';
import { UserOutlined, BankOutlined, SaveOutlined, CloseOutlined } from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';
import PersianDateInput from '../../../Components/PersianDateInput';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';

const { Text } = Typography;

export interface Party {
    PartyID: number;
    PartyNature: 'INDIVIDUAL' | 'LEGAL';
    OfficialName: string;
    TradeName: string | null;
    RegistrationNumber: string | null;
    EconomicCode: string | null;
    IdentifierNumber: string | null;
    IdentifierDate: string | null;
    Description: string | null;
    DepartmentID: number | null;
    PartyTypeID: number | null;
    ActivityID: number | null;
    IsActive: boolean | number | string;
    /** تاریخِ ثبتِ اولیهٔ رکورد (SYSDATETIME در SP) — «تاریخِ ایجاد» فقط نمایشِ همین مقدار است */
    Date_InsertFirst?: string | null;
}

/** گزینه‌هایِ Master Data («نقش» = CrmContactRoles، «سمت» = CrmPositions) — همان فهرست‌هایِ صفحهٔ «فهرست‌هایِ CRM» */
export interface RoleOption { ContactRoleID: number; DisplayName: string }
export interface PositionOption { PositionID: number; DisplayName: string }
/** نقش/سمتِ ثبت‌شده رویِ طرف‌حساب (خروجیِ sp_Crm_GetPartyRoles / sp_Crm_GetPartyPositions) */
export interface PartyRoleRow { RoleID: number; DisplayName: string; IsActive: boolean | number | string }
export interface PartyPositionRow { PositionID: number; DisplayName: string; IsActive: boolean | number | string }

interface PartyFormModalProps {
    open: boolean;
    onClose: () => void;
    editingParty: Party | null;
    onSuccess: (message: string, partyId: number) => void;
    /** گزینه‌هایِ فعالِ «نقش» و «سمت» برایِ انتخابِ چندگانه */
    contactRoles?: RoleOption[];
    positions?: PositionOption[];
    /** انتخاب‌هایِ فعلیِ طرف‌حسابِ در حالِ ویرایش؛ اگر داده نشود، هنگامِ باز شدن از سرور خوانده می‌شود */
    initialRoles?: PartyRoleRow[];
    initialPositions?: PartyPositionRow[];
}

const isActiveFlag = (v: boolean | number | string) => v === true || v === 1 || v === '1' || v === 'true';

const emptyValues = {
    partyNature: 'INDIVIDUAL' as 'INDIVIDUAL' | 'LEGAL',
    officialName: '',
    tradeName: '',
    registrationNumber: '',
    economicCode: '',
    identifierNumber: '',
    identifierDate: null as string | null,
    description: '',
};

/**
 * فرمِ ایجاد/ویرایشِ اطلاعاتِ هویتی/پایهٔ طرف‌حساب — طرف‌حساب یک نهادِ حقیقی
 * (فروشگاه/مغازه/صاحبِ‌کسب‌وکارِحقیقی) یا حقوقی (شرکت/سازمان/مؤسسه) است.
 * دپارتمان/نوع/فعالیت/برند/مخاطبین/تماس/آدرس — همگی *بعد از ایجاد*، در صفحهٔ
 * جزئیاتِ طرف‌حساب مدیریت می‌شوند، نه در این فرم (برند فقط از تبِ «برندها»). هویتِ اشخاصِ حقیقی («مخاطب»)
 * هرگز اینجا ثبت نمی‌شود — مخاطب موجودیتِ کاملاً مستقلی است.
 */
export default function PartyFormModal({
    open, onClose, editingParty, onSuccess, contactRoles = [], positions = [], initialRoles, initialPositions,
}: PartyFormModalProps) {
    const isEdit = !!editingParty;
    const [values, setValues] = useState(emptyValues);
    const [processing, setProcessing] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    // نقش‌ها و سمت‌هایِ خودِ طرف‌حساب (چندگانه، مستقل از هم)
    const [roleIds, setRoleIds] = useState<number[]>([]);
    const [positionIds, setPositionIds] = useState<number[]>([]);
    const [selectedRoleRows, setSelectedRoleRows] = useState<PartyRoleRow[]>([]);
    const [selectedPositionRows, setSelectedPositionRows] = useState<PartyPositionRow[]>([]);
    // تا انتخاب‌هایِ قبلی با اطمینان بارگذاری نشده‌اند، این دو فهرست ارسال نمی‌شوند (تا پاک نشوند)
    const [selectionReady, setSelectionReady] = useState(true);

    useEffect(() => {
        if (!open) return;

        const apply = (roles: PartyRoleRow[], pos: PartyPositionRow[]) => {
            setSelectedRoleRows(roles);
            setSelectedPositionRows(pos);
            setRoleIds(roles.map((r) => Number(r.RoleID)));
            setPositionIds(pos.map((p) => Number(p.PositionID)));
            setSelectionReady(true);
        };

        if (!editingParty) {
            apply([], []);
            return;
        }
        if (initialRoles && initialPositions) {
            apply(initialRoles, initialPositions);
            return;
        }

        let cancelled = false;
        setSelectionReady(false);
        setRoleIds([]);
        setPositionIds([]);
        crmApi(`/crm/parties/${editingParty.PartyID}/roles-positions`, 'GET').then((res) => {
            if (cancelled) return;
            if (res.ok && res.success) {
                apply(res.roles || [], res.positions || []);
            } else {
                setFormError('بارگذاریِ نقش‌ها و سمت‌هایِ فعلی ناموفق بود؛ این دو فیلد در این ویرایش تغییر نمی‌کنند.');
            }
        });

        return () => {
            cancelled = true;
        };
    }, [open, editingParty, initialRoles, initialPositions]);

    // گزینه‌ها = فعال‌ها + هر انتخابِ قبلیِ غیرفعال (تا ویرایش آن را بی‌صدا حذف نکند)
    const roleSelectOptions = [
        ...contactRoles.map((r) => ({ value: Number(r.ContactRoleID), label: r.DisplayName })),
        ...selectedRoleRows
            .filter((r) => !contactRoles.some((o) => Number(o.ContactRoleID) === Number(r.RoleID)))
            .map((r) => ({ value: Number(r.RoleID), label: isActiveFlag(r.IsActive) ? r.DisplayName : `${r.DisplayName} (غیرفعال)` })),
    ];
    const positionSelectOptions = [
        ...positions.map((p) => ({ value: Number(p.PositionID), label: p.DisplayName })),
        ...selectedPositionRows
            .filter((p) => !positions.some((o) => Number(o.PositionID) === Number(p.PositionID)))
            .map((p) => ({ value: Number(p.PositionID), label: isActiveFlag(p.IsActive) ? p.DisplayName : `${p.DisplayName} (غیرفعال)` })),
    ];

    useEffect(() => {
        if (!open) return;

        const initial = editingParty
            ? {
                  partyNature: editingParty.PartyNature,
                  officialName: editingParty.OfficialName || '',
                  tradeName: editingParty.TradeName || '',
                  registrationNumber: editingParty.RegistrationNumber || '',
                  economicCode: editingParty.EconomicCode || '',
                  identifierNumber: editingParty.IdentifierNumber || '',
                  identifierDate: editingParty.IdentifierDate,
                  description: editingParty.Description || '',
              }
            : emptyValues;

        setValues(initial);
        setFormError(null);
        setFieldErrors({});
    }, [open, editingParty]);

    const isIndividual = values.partyNature === 'INDIVIDUAL';
    const identifierLabel = 'شناسهٔ ملی';
    const identifierMaxLen = 11;
    const dateLabel = isIndividual ? 'تاریخِ افتتاح' : 'تاریخِ ثبت';
    // «تاریخِ ایجاد» فقط نمایشی است و ارسال نمی‌شود: در ایجاد، امروز (مقدارِ واقعی را SP با SYSDATETIME در Date_InsertFirst ثبت می‌کند)؛
    // در ویرایش، همان Date_InsertFirstِ رکورد
    const createdDateDisplay = isEdit
        ? (editingParty?.Date_InsertFirst ? gregorianToJalaliDisplay(editingParty.Date_InsertFirst) : '—')
        : gregorianToJalaliDisplay(new Date());

    const handleClose = () => {
        if (processing) return;
        onClose();
    };

    const handleSubmit = async () => {
        if (!values.officialName.trim()) {
            setFormError('نام الزامی است.');
            return;
        }
        if (!isIndividual && !values.identifierNumber.trim()) {
            setFormError(`${identifierLabel} الزامی است.`);
            return;
        }

        setProcessing(true);
        setFormError(null);
        setFieldErrors({});

        const res = await crmApi('/crm/parties', 'POST', {
            partyId: editingParty ? editingParty.PartyID : undefined,
            partyNature: values.partyNature,
            officialName: values.officialName.trim(),
            tradeName: !isIndividual ? (values.tradeName.trim() || undefined) : undefined,
            registrationNumber: !isIndividual ? (values.registrationNumber.trim() || undefined) : undefined,
            economicCode: !isIndividual ? (values.economicCode.trim() || undefined) : undefined,
            identifierNumber: !isIndividual ? values.identifierNumber.trim() : undefined,
            identifierDate: values.identifierDate || undefined,
            description: values.description.trim() || undefined,
            ...(selectionReady ? { roleIds, positionIds } : {}),
        });

        setProcessing(false);

        if (!res.ok || !res.success) {
            if (res.errors && typeof res.errors === 'object' && !Array.isArray(res.errors)) {
                const mapped: Record<string, string> = {};
                Object.entries(res.errors as Record<string, string[]>).forEach(([field, messages]) => {
                    mapped[field] = Array.isArray(messages) ? messages[0] : String(messages);
                });
                setFieldErrors(mapped);
            }
            setFormError(res.message);
            return;
        }

        onSuccess(res.message, res.partyId);
    };

    return (
        <Modal
            title={isEdit ? 'ویرایشِ طرف‌حساب' : 'طرف‌حسابِ جدید'}
            open={open}
            onCancel={handleClose}
            width={640}
            footer={[
                <Button key="cancel" icon={<CloseOutlined />} onClick={handleClose} disabled={processing}>
                    انصراف
                </Button>,
                <Button key="submit" type="primary" icon={<SaveOutlined />} loading={processing} onClick={handleSubmit}>
                    {isEdit ? 'ذخیرهٔ تغییرات' : 'ایجادِ طرف‌حساب'}
                </Button>,
            ]}
            destroyOnClose
        >
            {formError ? <Alert type="error" showIcon message={formError} style={{ marginBottom: 16, borderRadius: 8 }} /> : null}

            <Form layout="vertical" requiredMark>
                <Form.Item label="ماهیتِ طرف‌حساب">
                    <Segmented
                        block
                        disabled={isEdit}
                        value={values.partyNature}
                        onChange={(v) => setValues((s) => ({ ...s, partyNature: v as 'INDIVIDUAL' | 'LEGAL' }))}
                        options={[
                            { label: 'حقیقی', value: 'INDIVIDUAL', icon: <UserOutlined /> },
                            { label: 'حقوقی', value: 'LEGAL', icon: <BankOutlined /> },
                        ]}
                    />
                    {isEdit ? <Text type="secondary" style={{ fontSize: 12 }}>ماهیت پس از ایجاد قابلِ‌تغییر نیست.</Text> : null}
                </Form.Item>

                <Row gutter={16}>
                    <Col xs={24} md={isIndividual ? 24 : 12}>
                        <Form.Item label="نام" required validateStatus={fieldErrors.officialName ? 'error' : ''} help={fieldErrors.officialName}>
                            <Input value={values.officialName} onChange={(e) => setValues((s) => ({ ...s, officialName: e.target.value }))} size="large" />
                        </Form.Item>
                    </Col>
                    {!isIndividual ? (
                        <Col xs={24} md={12}>
                            <Form.Item label="نامِ تجاری">
                                <Input value={values.tradeName} onChange={(e) => setValues((s) => ({ ...s, tradeName: e.target.value }))} size="large" />
                            </Form.Item>
                        </Col>
                    ) : null}
                </Row>

                {!isIndividual ? (
                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item label="شمارهٔ ثبت">
                                <Input dir="ltr" value={values.registrationNumber} onChange={(e) => setValues((s) => ({ ...s, registrationNumber: e.target.value }))} size="large" />
                            </Form.Item>
                        </Col>
                        <Col xs={24} md={12}>
                            <Form.Item label="کدِ اقتصادی">
                                <Input dir="ltr" value={values.economicCode} onChange={(e) => setValues((s) => ({ ...s, economicCode: e.target.value }))} size="large" />
                            </Form.Item>
                        </Col>
                    </Row>
                ) : null}

                <Row gutter={16}>
                    <Col xs={24} md={{ span: isIndividual ? 24 : 12, offset: isIndividual ? 0 : 12 }}>
                        <Form.Item label="تاریخِ ایجاد" tooltip="تاریخِ ثبتِ اولیهٔ این طرف‌حساب در سیستم؛ خودکار است و قابلِ‌ویرایش نیست.">
                            <Input value={createdDateDisplay} disabled size="large" aria-readonly />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={16}>
                    {!isIndividual ? (
                        <Col xs={24} md={12}>
                            <Form.Item label={identifierLabel} required validateStatus={fieldErrors.identifierNumber ? 'error' : ''} help={fieldErrors.identifierNumber}>
                                <Input
                                    dir="ltr"
                                    maxLength={identifierMaxLen}
                                    value={values.identifierNumber}
                                    onChange={(e) => setValues((s) => ({ ...s, identifierNumber: e.target.value.replace(/\D/g, '') }))}
                                    size="large"
                                    placeholder="۱۱ رقم"
                                />
                            </Form.Item>
                        </Col>
                    ) : null}
                    <Col xs={24} md={isIndividual ? 24 : 12}>
                        <Form.Item label={dateLabel}>
                            <PersianDateInput value={values.identifierDate} onChange={(v) => setValues((s) => ({ ...s, identifierDate: v }))} />
                        </Form.Item>
                    </Col>
                </Row>

                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item label="نقش‌ها" tooltip="چند نقش قابلِ انتخاب است؛ فهرست از «فهرست‌هایِ مخاطبین و ارتباطات ← نقش» مدیریت می‌شود.">
                            <Select
                                mode="multiple"
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                size="large"
                                placeholder="انتخابِ نقش"
                                loading={!selectionReady}
                                disabled={!selectionReady}
                                value={roleIds}
                                onChange={(v) => setRoleIds(v as number[])}
                                options={roleSelectOptions}
                                notFoundContent="نقشی تعریف نشده است"
                            />
                        </Form.Item>
                    </Col>
                    <Col xs={24} md={12}>
                        <Form.Item label="سمت‌ها" tooltip="چند سمت قابلِ انتخاب است؛ فهرست از «فهرست‌هایِ مخاطبین و ارتباطات ← سمت» مدیریت می‌شود.">
                            <Select
                                mode="multiple"
                                allowClear
                                showSearch
                                optionFilterProp="label"
                                size="large"
                                placeholder="انتخابِ سمت"
                                loading={!selectionReady}
                                disabled={!selectionReady}
                                value={positionIds}
                                onChange={(v) => setPositionIds(v as number[])}
                                options={positionSelectOptions}
                                notFoundContent="سمتی تعریف نشده است"
                            />
                        </Form.Item>
                    </Col>
                </Row>

                <Form.Item label="توضیحات">
                    <Input.TextArea value={values.description} onChange={(e) => setValues((s) => ({ ...s, description: e.target.value }))} rows={3} maxLength={2000} showCount />
                </Form.Item>
            </Form>
        </Modal>
    );
}
