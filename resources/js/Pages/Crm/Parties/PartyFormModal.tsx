import { useEffect, useState } from 'react';
import { Modal, Form, Input, Segmented, Row, Col, Button, Typography, Alert, Select } from 'antd';
import { UserOutlined, BankOutlined, SaveOutlined, CloseOutlined } from '@ant-design/icons';
import { crmApi } from '../../../Components/Crm/crmApi';
import { crmSaveWithDuplicateConfirm } from '../../../Components/Crm/duplicateNameConfirm';
import PersianDateInput from '../../../Components/PersianDateInput';
import { gregorianToJalaliDisplay } from '../../../Utils/jalali';
import CooperationEndedField, { CooperationState, cooperationError, cooperationFromServer, cooperationToApi, emptyCooperation } from '../../../Components/Crm/CooperationEnded';

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
    /** قطعِ همکاری (پچِ ۱۱۹) — NULL = همکاری برقرار */
    CooperationEndedAt?: string | null;
    /** شناسه ۱ و ۲ (متنی) — طرف‌حسابِ حقیقی (پچِ ۱۲۴) */
    Identifier1?: string | null;
    Identifier2?: string | null;
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
    /** گروه (= نوع) و زیرگروه (= فعالیت)ِ اختیاریِ فرمِ تعریفِ جدید — فقط فعال‌ها */
    partyTypes?: { PartyTypeID: number | string; DisplayName: string }[];
    activities?: { ActivityID: number | string; PartyTypeID: number | string; DisplayName: string }[];
}

const isActiveFlag = (v: boolean | number | string) => v === true || v === 1 || v === '1' || v === 'true';

const emptyValues = {
    partyNature: 'INDIVIDUAL' as 'INDIVIDUAL' | 'LEGAL',
    officialName: '',
    tradeName: '',
    registrationNumber: '',
    economicCode: '',
    identifier1: '',
    identifier2: '',
    identifierNumber: '',
    identifierDate: null as string | null,
    description: '',
};

/**
 * فرمِ ایجاد/ویرایشِ اطلاعاتِ هویتی/پایهٔ طرف‌حساب — طرف‌حساب یک نهادِ حقیقی
 * (فروشگاه/مغازه/صاحبِ‌کسب‌وکارِحقیقی) یا حقوقی (شرکت/سازمان/مؤسسه) است.
 * گروه/زیرگروهِ اختیاری فقط هنگامِ تعریف؛ دپارتمان/گروه/زیرگروهِ بیشتر/برند/مخاطبین/تماس/آدرس — همگی *بعد از ایجاد*، در صفحهٔ
 * جزئیاتِ طرف‌حساب مدیریت می‌شوند، نه در این فرم (برند فقط از تبِ «برندها»). هویتِ اشخاصِ حقیقی («مخاطب»)
 * هرگز اینجا ثبت نمی‌شود — مخاطب موجودیتِ کاملاً مستقلی است.
 */
export default function PartyFormModal({
    open, onClose, editingParty, onSuccess, contactRoles = [], positions = [], initialRoles, initialPositions, partyTypes = [], activities = [],
}: PartyFormModalProps) {
    const [groupId, setGroupId] = useState<number | null>(null);
    const [subGroupId, setSubGroupId] = useState<number | null>(null);
    const isEdit = !!editingParty;
    const [values, setValues] = useState(emptyValues);
    const [processing, setProcessing] = useState(false);
    const [formError, setFormError] = useState<string | null>(null);
    const [coop, setCoop] = useState<CooperationState>(emptyCooperation());
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
                  identifier1: editingParty.Identifier1 || '',
                  identifier2: editingParty.Identifier2 || '',
                  identifierNumber: editingParty.IdentifierNumber || '',
                  identifierDate: editingParty.IdentifierDate,
                  description: editingParty.Description || '',
              }
            : emptyValues;

        setValues(initial);
        setGroupId(null);
        setSubGroupId(null);
        setCoop(cooperationFromServer(editingParty?.CooperationEndedAt));
        setFormError(null);
        setFieldErrors({});
    }, [open, editingParty]);

    const isIndividual = values.partyNature === 'INDIVIDUAL';
    // حقوقی: شناسهٔ ملیِ الزامی (۱۱ رقم)؛ حقیقی: کدِ ملیِ اختیاری (۱۰ رقم — پچِ ۱۲۳)
    const identifierLabel = isIndividual ? 'کدِ ملی' : 'شناسهٔ ملی';
    const identifierMaxLen = isIndividual ? 10 : 11;
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
        if (isIndividual && values.identifierNumber.trim() && values.identifierNumber.trim().length !== 10) {
            setFormError('کد ملی باید دقیقاً ۱۰ رقم باشد.');
            return;
        }
        const coopProblem = isEdit ? cooperationError(coop) : null;
        if (coopProblem) {
            setFormError(coopProblem);
            return;
        }

        setProcessing(true);
        setFormError(null);
        setFieldErrors({});

        const res = await crmSaveWithDuplicateConfirm('/crm/parties', {
            partyId: editingParty ? editingParty.PartyID : undefined,
            partyNature: values.partyNature,
            officialName: values.officialName.trim(),
            tradeName: !isIndividual ? (values.tradeName.trim() || undefined) : undefined,
            registrationNumber: !isIndividual ? (values.registrationNumber.trim() || undefined) : undefined,
            economicCode: !isIndividual ? (values.economicCode.trim() || undefined) : undefined,
            // حقیقی: شناسه ۱ و ۲ (پچِ ۱۲۴) و کدِ ملیِ اختیاری (پچِ ۱۲۳)
            identifier1: isIndividual ? (values.identifier1.trim() || undefined) : undefined,
            identifier2: isIndividual ? (values.identifier2.trim() || undefined) : undefined,
            identifierNumber: values.identifierNumber.trim() || undefined,
            identifierDate: values.identifierDate || undefined,
            description: values.description.trim() || undefined,
            // قطعِ همکاری فقط در ویرایش؛ گروه/زیرگروه فقط در تعریفِ جدید
            ...(isEdit ? cooperationToApi(coop) : { groupId: groupId ?? undefined, subGroupId: subGroupId ?? undefined }),
            // نقش/سمت فقط در ویرایش (در تعریفِ طرف‌حسابِ جدید نمایش داده و ارسال نمی‌شود)
            ...(isEdit && selectionReady ? { roleIds, positionIds } : {}),
        });

        setProcessing(false);

        if (res === null) {
            // کاربر هشدارِ نامِ تکراری را تأیید نکرد → چیزی ثبت نشد؛ فرم با همان مقادیر باز می‌ماند
            setFormError('ثبت انجام نشد؛ نام را اصلاح کنید یا دوباره ذخیره و تأیید کنید.');
            return;
        }

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

                {/* حقوقی: شمارهٔ ثبت و کدِ اقتصادی؛ حقیقی: شناسه ۱ و ۲ (متنی، ستون‌هایِ جدا — پچِ ۱۲۴) */}
                {isIndividual ? (
                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item label="شناسه ۱">
                                <Input maxLength={100} value={values.identifier1} onChange={(e) => setValues((s) => ({ ...s, identifier1: e.target.value }))} size="large" />
                            </Form.Item>
                        </Col>
                        <Col xs={24} md={12}>
                            <Form.Item label="شناسه ۲">
                                <Input maxLength={100} value={values.identifier2} onChange={(e) => setValues((s) => ({ ...s, identifier2: e.target.value }))} size="large" />
                            </Form.Item>
                        </Col>
                    </Row>
                ) : (
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
                )}

                <Row gutter={16}>
                    <Col xs={24} md={12}>
                        <Form.Item label={identifierLabel} required={!isIndividual} validateStatus={fieldErrors.identifierNumber ? 'error' : ''} help={fieldErrors.identifierNumber}>
                            <Input
                                dir="ltr"
                                maxLength={identifierMaxLen}
                                value={values.identifierNumber}
                                onChange={(e) => setValues((s) => ({ ...s, identifierNumber: e.target.value.replace(/\D/g, '') }))}
                                size="large"
                                placeholder={isIndividual ? '۱۰ رقم (اختیاری)' : '۱۱ رقم'}
                            />
                        </Form.Item>
                    </Col>
                </Row>

                {/* تاریخ‌ها فقط در ویرایش؛ در تعریفِ طرف‌حسابِ جدید نمایش داده نمی‌شوند */}
                {isEdit ? (
                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item label="تاریخِ ایجاد" tooltip="تاریخِ ثبتِ اولیهٔ این طرف‌حساب در سیستم؛ خودکار است و قابلِ‌ویرایش نیست.">
                                <Input value={createdDateDisplay} disabled size="large" aria-readonly />
                            </Form.Item>
                        </Col>
                        <Col xs={24} md={12}>
                            <Form.Item label={dateLabel}>
                                <PersianDateInput value={values.identifierDate} onChange={(v) => setValues((s) => ({ ...s, identifierDate: v }))} />
                            </Form.Item>
                        </Col>
                    </Row>
                ) : (
                    // گروه و زیرگروهِ اختیاری فقط در تعریفِ جدید (بعداً از تبِ «گروه و زیرگروه» مدیریت می‌شود)
                    <Row gutter={16}>
                        <Col xs={24} md={12}>
                            <Form.Item label="گروه">
                                <Select
                                    allowClear
                                    showSearch
                                    optionFilterProp="label"
                                    size="large"
                                    placeholder="انتخابِ گروه (اختیاری)"
                                    value={groupId ?? undefined}
                                    onChange={(v) => {
                                        setGroupId(v == null ? null : Number(v));
                                        setSubGroupId(null);
                                    }}
                                    options={partyTypes.map((t) => ({ value: Number(t.PartyTypeID), label: t.DisplayName }))}
                                    notFoundContent="گروهی تعریف نشده است"
                                />
                            </Form.Item>
                        </Col>
                        <Col xs={24} md={12}>
                            <Form.Item label="زیرگروه">
                                <Select
                                    allowClear
                                    showSearch
                                    optionFilterProp="label"
                                    size="large"
                                    disabled={groupId === null}
                                    placeholder={groupId === null ? 'ابتدا گروه را انتخاب کنید' : 'انتخابِ زیرگروه (اختیاری)'}
                                    value={subGroupId ?? undefined}
                                    onChange={(v) => setSubGroupId(v == null ? null : Number(v))}
                                    options={activities.filter((a) => Number(a.PartyTypeID) === groupId).map((a) => ({ value: Number(a.ActivityID), label: a.DisplayName }))}
                                    notFoundContent="زیرگروهی برایِ این گروه تعریف نشده است"
                                />
                            </Form.Item>
                        </Col>
                    </Row>
                )}

                {isEdit ? (
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
                ) : null}

                {/* قطعِ همکاری فقط در ویرایش (و از صفحهٔ اصلیِ طرف‌حساب) */}
                {isEdit ? (
                    <Form.Item>
                        <CooperationEndedField value={coop} onChange={setCoop} disabled={processing} />
                    </Form.Item>
                ) : null}

                <Form.Item label="توضیحات">
                    <Input.TextArea value={values.description} onChange={(e) => setValues((s) => ({ ...s, description: e.target.value }))} rows={3} maxLength={2000} showCount />
                </Form.Item>
            </Form>
        </Modal>
    );
}
