import { useRef, useState } from 'react';
import { Table, Button, Input, Select, Checkbox, Space, Tag, Popconfirm, Typography, Alert, Empty } from 'antd';
import type { InputRef } from 'antd';
import { PlusOutlined, SaveOutlined, CloseOutlined, EditOutlined, CheckCircleOutlined, StopOutlined, StarFilled } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { crmApi } from '../../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../../Components/NotificationModal';
import { toBool } from '../../../Utils/bool';
import CrmDeleteButton from '../../../Components/Crm/CrmDeleteButton';

const { Text } = Typography;

interface Contact {
    ContactID: number;
    PartyID: number | null;
    PersonID: number | null;
    ContactTypeID: number;
    ContactTypeName: string;
    ContactValue: string;
    Extension: string | null;
    Description: string | null;
    IsPrimary: boolean | number | string;
    IsActive: boolean | number | string;
    RelatedPersonID: number | null;
    RelatedPersonName: string | null;
}

interface ContactsPanelProps {
    /** دقیقاً یکی از partyId یا personId باید مشخص باشد — تماس یا به طرف‌حساب تعلق دارد یا مستقیماً به مخاطب. */
    partyId?: number;
    personId?: number;
    items: Contact[];
    contactTypes: { ContactTypeID: number; DisplayName: string }[];
    /** فقط در حالتِ partyId معنا دارد — این تماس مالِ کدام مخاطبِ مرتبط با این طرف‌حساب است. */
    relatedPersons?: { PersonID: number; DisplayName: string }[];
    canManage: boolean;
    /** دسترسیِ CRM_DELETE */
    canDelete?: boolean;
}

/**
 * شماره‌هایِ دوبخشی در دو کادرِ جدا (پیش‌شماره سمتِ چپ، شماره سمتِ راست — مثلِ نوشتنِ 021 55955160)؛ هنگامِ ذخیره پشتِ‌هم (۱۱ رقم).
 * تلفن/فکس (پچِ ۱۳۳) = پیش‌شمارهٔ ۳ رقمی + ۸ رقم؛ موبایل = پیش‌شمارهٔ ۴ رقمی (09xx) + ۷ رقم. سرور همین قاعده‌ها را کنترل می‌کند.
 */
interface SplitSpec {
    areaLen: number;
    localLen: number;
    areaRe: RegExp;
    localRe: RegExp;
    areaHint: string;
    localHint: string;
    areaError: string;
    localError: string;
}
const LANDLINE_SPEC: SplitSpec = {
    areaLen: 3,
    localLen: 8,
    areaRe: /^0[1-9][0-9]$/,
    localRe: /^[1-9][0-9]{7}$/,
    areaHint: '021',
    localHint: '55955160',
    areaError: 'پیش‌شماره باید ۳ رقم باشد و با ۰ شروع شود (مثلاً 021).',
    localError: 'شمارهٔ ثابت باید ۸ رقم باشد و با ۰ شروع نشود (مثلاً 55955160).',
};
const MOBILE_SPEC: SplitSpec = {
    areaLen: 4,
    localLen: 7,
    areaRe: /^09[0-9]{2}$/,
    localRe: /^[0-9]{7}$/,
    areaHint: '0912',
    localHint: '3544125',
    areaError: 'پیش‌شمارهٔ موبایل باید ۴ رقم باشد و با ۰۹ شروع شود (مثلاً 0912).',
    localError: 'شمارهٔ موبایل بعد از پیش‌شماره باید ۷ رقم باشد (مثلاً 3544125).',
};
const emptyDraft = { id: null as number | null, contactTypeId: null as number | null, value: '', areaCode: '', localNumber: '', extension: '', description: '', isPrimary: false, relatedPersonId: null as number | null };

/** اطلاعاتِ تماسِ یک طرف‌حساب یا یک مخاطب — «داخلی» فیلدی کنارِ مقدار است، نه نوعِ تماسِ جدا. */
export default function ContactsPanel({ partyId, personId, items: initialItems, contactTypes, relatedPersons, canManage, canDelete = false }: ContactsPanelProps) {
    const [items, setItems] = useState<Contact[]>(initialItems || []);
    const [formOpen, setFormOpen] = useState(false);
    const [draft, setDraft] = useState(emptyDraft);
    const [saving, setSaving] = useState(false);
    const [togglingId, setTogglingId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const ownerQuery = partyId ? `partyId=${partyId}` : `personId=${personId}`;

    const reload = async () => {
        const res = await crmApi(`/crm/contacts?${ownerQuery}`);
        if (res.ok && res.success) setItems(res.items || []);
    };

    const isPhoneType = (typeId: number | null) => {
        const t = contactTypes.find((c) => c.ContactTypeID === typeId);
        return t ? /تلفن|فکس/.test(t.DisplayName) : false;
    };

    /** موبایل/تلفن/فکس فقط رقم می‌پذیرند (سرور هم همین را کنترل می‌کند) */
    const isNumberType = (typeId: number | null) => {
        const t = contactTypes.find((c) => c.ContactTypeID === typeId);
        return t ? ['موبایل', 'تلفن', 'فکس'].includes(t.DisplayName.trim()) : false;
    };

    /** نوعِ دوبخشی (موبایل/تلفن/فکس) → قاعدهٔ پیش‌شماره و شماره؛ بقیهٔ نوع‌ها یک کادرِ ساده */
    const splitSpec = (typeId: number | null): SplitSpec | null => {
        const name = contactTypes.find((c) => c.ContactTypeID === typeId)?.DisplayName.trim();
        if (!name) return null;
        return name === 'موبایل' ? MOBILE_SPEC : /تلفن|فکس/.test(name) ? LANDLINE_SPEC : null;
    };
    /** شمارهٔ ذخیره‌شده → پیش‌شماره + شماره؛ مقدارِ غیرِاستاندارد در کادرِ شماره می‌ماند تا کاربر اصلاحش کند */
    const splitFor = (typeId: number | null, v: string) => {
        const spec = splitSpec(typeId);
        if (!spec) return { areaCode: '', localNumber: '' };
        const d = digitsOnly(v || '');
        return d.length === spec.areaLen + spec.localLen && d.startsWith('0')
            ? { areaCode: d.slice(0, spec.areaLen), localNumber: d.slice(spec.areaLen) }
            : { areaCode: '', localNumber: d.slice(0, spec.localLen) };
    };
    const localNumberRef = useRef<InputRef>(null);

    /** ارقامِ فارسی/عربی → لاتین و حذفِ هر نویسهٔ غیرِرقمی */
    const digitsOnly = (v: string) =>
        v.replace(/[۰-۹]/g, (d) => String(d.charCodeAt(0) - 0x06f0))
            .replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660))
            .replace(/\D/g, '')
            .slice(0, 11);

    const handleSave = async () => {
        if (!draft.contactTypeId) {
            setError('نوعِ تماس الزامی است.');
            return;
        }
        const spec = splitSpec(draft.contactTypeId);
        if (spec) {
            if (!spec.areaRe.test(draft.areaCode)) {
                setError(spec.areaError);
                return;
            }
            if (!spec.localRe.test(draft.localNumber)) {
                setError(spec.localError);
                return;
            }
        }
        const value = spec ? draft.areaCode + draft.localNumber : draft.value.trim();
        if (!value) {
            setError('مقدارِ تماس الزامی است.');
            return;
        }
        setSaving(true);
        setError(null);
        const res = await crmApi('/crm/contacts', 'POST', {
            contactId: draft.id ?? undefined,
            partyId,
            personId,
            contactTypeId: draft.contactTypeId,
            contactValue: value,
            extension: draft.extension.trim() || undefined,
            description: draft.description.trim() || undefined,
            isPrimary: draft.isPrimary,
            relatedPersonId: partyId ? (draft.relatedPersonId ?? undefined) : undefined,
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

    const handleToggle = async (row: Contact) => {
        setTogglingId(row.ContactID);
        const res = await crmApi(`/crm/contacts/${row.ContactID}/toggle`, 'POST');
        setTogglingId(null);
        setNotification({ open: true, type: res.success ? 'success' : 'error', message: res.message });
        if (res.success) reload();
    };

    const columns: ColumnsType<Contact> = [
        { title: 'نوع', dataIndex: 'ContactTypeName', key: 'ContactTypeName', width: 100 },
        {
            title: 'مقدار',
            key: 'value',
            render: (_, r) => (
                <Space size={4}>
                    {toBool(r.IsPrimary) ? <StarFilled style={{ color: '#F59E0B', fontSize: 12 }} /> : null}
                    <Text dir="ltr">{r.ContactValue}</Text>
                    {r.Extension ? <Text type="secondary" style={{ fontSize: 12 }}>(داخلی: {r.Extension})</Text> : null}
                </Space>
            ),
        },
        { title: 'توضیحات', dataIndex: 'Description', key: 'Description', render: (v) => v || <Text type="secondary">—</Text> },
        ...(partyId ? [{
            title: 'فرد مرتبط',
            key: 'relatedPerson',
            dataIndex: 'RelatedPersonName',
            render: (v: string | null) => v || <Text type="secondary">—</Text>,
        }] : []),
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
            width: 130,
            align: 'center',
            render: (_, r) => {
                if (!canManage) return canDelete ? <CrmDeleteButton entity="contact" id={r.ContactID} label="اطلاعاتِ تماس" onDone={(ok, msg) => { setNotification({ open: true, type: ok ? 'success' : 'error', message: msg }); if (ok) reload(); }} /> : null;
                const active = toBool(r.IsActive);
                return (
                    <Space>
                        <Button
                            type="text"
                            size="small"
                            icon={<EditOutlined />}
                            onClick={() => {
                                setDraft({
                                    id: r.ContactID, contactTypeId: r.ContactTypeID, value: r.ContactValue, ...splitFor(r.ContactTypeID, r.ContactValue), extension: r.Extension || '',
                                    description: r.Description || '', isPrimary: toBool(r.IsPrimary), relatedPersonId: r.RelatedPersonID,
                                });
                                setError(null);
                                setFormOpen(true);
                            }}
                        />
                        <Popconfirm title={active ? 'غیرفعال‌کردنِ تماس' : 'فعال‌کردنِ تماس'} onConfirm={() => handleToggle(r)} okText="بله" cancelText="خیر">
                            <Button type="text" size="small" danger={active} loading={togglingId === r.ContactID} icon={active ? <StopOutlined /> : <CheckCircleOutlined />} />
                        </Popconfirm>
                        {canDelete ? <CrmDeleteButton entity="contact" id={r.ContactID} label="اطلاعاتِ تماس" onDone={(ok, msg) => { setNotification({ open: true, type: ok ? 'success' : 'error', message: msg }); if (ok) reload(); }} /> : null}
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
                        تماسِ جدید
                    </Button>
                </div>
            ) : null}

            {error && !formOpen ? <Alert type="error" showIcon message={error} closable onClose={() => setError(null)} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}

            {formOpen ? (
                <Space direction="vertical" style={{ width: '100%', marginBottom: 16 }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}
                    <Space style={{ width: '100%' }} wrap>
                        {partyId ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>فرد مرتبط</Text>
                                <Select
                                    allowClear
                                    style={{ width: 200 }}
                                    placeholder="این تماس مالِ کدام فرد مرتبط است؟"
                                    value={draft.relatedPersonId ?? undefined}
                                    onChange={(v) => setDraft((d) => ({ ...d, relatedPersonId: v ?? null }))}
                                    options={(relatedPersons || []).map((p) => ({ value: p.PersonID, label: p.DisplayName }))}
                                    notFoundContent="ابتدا از تبِ «افراد مرتبط» یک نفر را به این طرف‌حساب مرتبط کنید"
                                />
                            </div>
                        ) : null}
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوعِ تماس</Text>
                            <Select
                                style={{ width: 160 }}
                                value={draft.contactTypeId ?? undefined}
                                onChange={(v) =>
                                    setDraft((d) => {
                                        const value = isNumberType(v) ? digitsOnly(d.value) : d.value;
                                        return { ...d, contactTypeId: v, value, ...splitFor(v, value) };
                                    })
                                }
                                options={contactTypes.map((c) => ({ value: c.ContactTypeID, label: c.DisplayName }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>{splitSpec(draft.contactTypeId) ? 'شماره — پیش‌شماره' : 'مقدار'}</Text>
                            {(() => {
                                const spec = splitSpec(draft.contactTypeId);
                                if (!spec) return null;
                                // راست‌به‌چپ: کادرِ اول سمتِ راست → «شماره» راست، «پیش‌شماره» چپ (مثلِ 021 55955160)
                                return (
                                    <Space.Compact style={{ width: 220 }}>
                                        <Input
                                            ref={localNumberRef}
                                            dir="ltr"
                                            style={{ width: spec.areaLen === 4 ? 140 : 148 }}
                                            inputMode="numeric"
                                            maxLength={spec.localLen}
                                            placeholder={spec.localHint}
                                            aria-label="شماره"
                                            value={draft.localNumber}
                                            onChange={(e) => setDraft((d) => ({ ...d, localNumber: digitsOnly(e.target.value).slice(0, spec.localLen) }))}
                                        />
                                        <Input
                                            dir="ltr"
                                            style={{ width: spec.areaLen === 4 ? 80 : 72 }}
                                            inputMode="numeric"
                                            maxLength={spec.areaLen}
                                            placeholder={spec.areaHint}
                                            aria-label="پیش‌شماره"
                                            value={draft.areaCode}
                                            onChange={(e) => {
                                                const areaCode = digitsOnly(e.target.value).slice(0, spec.areaLen);
                                                setDraft((d) => ({ ...d, areaCode }));
                                                if (areaCode.length === spec.areaLen) localNumberRef.current?.focus();
                                            }}
                                        />
                                    </Space.Compact>
                                );
                            })() ?? (
                                <Input dir="ltr" style={{ width: 220 }} value={draft.value} onChange={(e) => setDraft((d) => ({ ...d, value: e.target.value }))} />
                            )}
                        </div>
                        {isPhoneType(draft.contactTypeId) ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>داخلی (اختیاری)</Text>
                                <Input dir="ltr" style={{ width: 100 }} value={draft.extension} onChange={(e) => setDraft((d) => ({ ...d, extension: e.target.value }))} />
                            </div>
                        ) : null}
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات/کاربرد</Text>
                            <Input style={{ width: 200 }} value={draft.description} onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))} />
                        </div>
                        <div style={{ paddingTop: 22 }}>
                            <Checkbox checked={draft.isPrimary} onChange={(e) => setDraft((d) => ({ ...d, isPrimary: e.target.checked }))}>
                                تماسِ اصلی
                            </Checkbox>
                        </div>
                    </Space>
                    <Space style={{ justifyContent: 'flex-end', width: '100%' }}>
                        <Button icon={<CloseOutlined />} onClick={() => setFormOpen(false)} disabled={saving}>انصراف</Button>
                        <Button type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>
                    </Space>
                </Space>
            ) : null}

            <Table rowKey="ContactID" columns={columns} dataSource={items} pagination={false} scroll={{ x: true }} locale={{ emptyText: <Empty description="اطلاعاتِ تماسی ثبت نشده است" /> }} />

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </div>
    );
}
