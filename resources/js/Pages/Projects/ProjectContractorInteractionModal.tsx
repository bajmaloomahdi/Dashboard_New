import { useState } from 'react';
import { Modal, Select, Button, Input, Space, Typography, Alert, TimePicker, Checkbox, Upload, Tooltip } from 'antd';
import { SaveOutlined, CloseOutlined, PaperClipOutlined } from '@ant-design/icons';
import dayjs, { Dayjs } from 'dayjs';
import { crmApi } from '../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import PersianDateInput from '../../Components/PersianDateInput';
import { ATTACH_MAX_DESCRIPTION, ATTACH_MAX_FILES, checkAttachment, formatSize } from '../Crm/Parties/InteractionsPanel';

const { Text } = Typography;

interface InteractionTypeOption {
    InteractionTypeID: number;
    Code: string;
    DisplayName: string;
}

interface ProjectContractorInteractionModalProps {
    open: boolean;
    onClose: () => void;
    projectId: number;
    projectTitle: string;
    partyId: number;
    partyName: string;
    users: { UserID: number; FullName: string }[];
    interactionTypes: InteractionTypeOption[];
}

const todayIso = () => dayjs().format('YYYY-MM-DD');

type InteractionStatus = 'PLANNED' | 'DONE' | 'CANCELED';

const emptyDraft = (defaultTypeId: number | null) => ({
    interactionTypeId: defaultTypeId,
    subject: '',
    date: todayIso() as string | null,
    time: dayjs() as Dayjs | null,
    status: 'DONE' as InteractionStatus,
    description: '',
    outcome: '',
    ownerUserId: null as number | null,
});

const toApiDateTime = (date: string, time: Dayjs | null) => `${date} ${(time ?? dayjs().startOf('day')).format('HH:mm')}:00`;

/**
 * ثبتِ تعامل برایِ یک پیمانکارِ مشخص از همین Project (مسیرِ Project→Contractor→ثبتِ تعامل —
 * Stage D). Party و Project از Context آمده‌اند و قابلِ‌تغییر نیستند (کاربر دوباره انتخاب
 * نمی‌کند)؛ «مرتبط با پروژه» همیشه فعال است، فقط به‌صورتِ نمایشی/غیرفعال نشان داده می‌شود.
 * ذخیره به یک Endpointِ محدودِ Project (نه Endpointِ عمومیِ CRM) ارسال می‌شود که با
 * canManage($projectId) مجوز می‌دهد — نه CRM_MANAGE_PARTIES — دقیقاً هم‌الگو با
 * sp_SearchCrmPartiesForContractor در ProjectContractorsModal.
 */
export default function ProjectContractorInteractionModal({
    open,
    onClose,
    projectId,
    projectTitle,
    partyId,
    partyName,
    users,
    interactionTypes,
}: ProjectContractorInteractionModalProps) {
    const defaultTypeId = interactionTypes.find((t) => t.Code === 'CALL')?.InteractionTypeID ?? interactionTypes[0]?.InteractionTypeID ?? null;
    const [draft, setDraft] = useState(emptyDraft(defaultTypeId));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    // پیوست‌هایِ در انتظار — همراهِ «ذخیره» در همان درخواست ارسال می‌شوند
    const [pending, setPending] = useState<{ uid: string; file: File; description: string }[]>([]);
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const draftTypeCode = interactionTypes.find((t) => t.InteractionTypeID === draft.interactionTypeId)?.Code;

    const reset = () => {
        setDraft(emptyDraft(defaultTypeId));
        setError(null);
        setPending([]);
    };

    const addPending = (file: File) => {
        const problem = checkAttachment(file);
        if (problem) return setError(problem);
        setPending((list) => [...list, { uid: `${Date.now()}-${Math.random().toString(36).slice(2)}`, file, description: '' }]);
    };

    const handleClose = () => {
        reset();
        onClose();
    };

    const onTypeChange = (id: number) => {
        const code = interactionTypes.find((t) => t.InteractionTypeID === id)?.Code;
        setDraft((d) => ({ ...d, interactionTypeId: id, status: code === 'NOTE' ? 'DONE' : code === 'FOLLOWUP' ? 'PLANNED' : d.status === 'CANCELED' ? 'DONE' : d.status }));
    };

    const handleSave = async () => {
        if (!draft.subject.trim()) return setError('موضوع الزامی است.');
        if (!draft.date) return setError('تاریخ الزامی است.');
        if (pending.length > ATTACH_MAX_FILES) return setError('در هر بار حداکثر ۱۰ فایل قابلِ پیوست است.');
        if (pending.some((p) => p.description.trim().length > ATTACH_MAX_DESCRIPTION)) return setError('توضیحاتِ هر پیوست حداکثر ۱۰۰۰ کاراکتر است.');

        const fields: Record<string, string | number | undefined> = {
            interactionTypeId: draft.interactionTypeId ?? undefined,
            subject: draft.subject.trim(),
            description: draft.description.trim() || undefined,
            outcome: draft.outcome.trim() || undefined,
            interactionDate: toApiDateTime(draft.date, draft.time),
            status: draft.status,
            ownerUserId: draft.ownerUserId ?? undefined,
        };
        // با پیوست → multipart (تعامل و فایل‌ها در یک درخواست؛ فایلِ نامعتبر یعنی تعامل هم ثبت نشود)
        let body: Record<string, unknown> | FormData = fields;
        if (pending.length) {
            const form = new FormData();
            Object.entries(fields).forEach(([k, v]) => {
                if (v !== undefined) form.append(k, String(v));
            });
            pending.forEach((p, i) => {
                form.append(`attachments[${i}]`, p.file);
                form.append(`descriptions[${i}]`, p.description.trim());
            });
            body = form;
        }

        setSaving(true);
        setError(null);
        const res = await crmApi(`/projects/${projectId}/contractors/${partyId}/interactions`, 'POST', body);
        setSaving(false);

        if (!res.ok || !res.success) {
            return setError(res.status === 413 ? 'حجمِ مجموعِ فایل‌ها از سقفِ مجازِ سرور بیشتر است.' : res.message);
        }

        reset();
        onClose();
        setNotification({ open: true, type: 'success', message: res.message });
    };

    return (
        <>
            <Modal
                title={`ثبتِ تعامل — ${partyName}`}
                open={open}
                onCancel={handleClose}
                width={560}
                className="responsive-modal"
                footer={[
                    <Button key="close" icon={<CloseOutlined />} onClick={handleClose} disabled={saving}>انصراف</Button>,
                    <Button key="save" type="primary" icon={<SaveOutlined />} loading={saving} onClick={handleSave}>ذخیره</Button>,
                ]}
            >
                <Space direction="vertical" style={{ width: '100%' }} size={12}>
                    {error ? <Alert type="error" showIcon message={error} style={{ borderRadius: 8 }} /> : null}

                    <div>
                        <Checkbox checked disabled>مرتبط با پروژه</Checkbox>
                        <div style={{ marginTop: 4 }}>
                            <Text type="secondary" style={{ fontSize: 12 }}>پروژه: </Text>
                            <Text style={{ fontSize: 12 }}>{projectTitle}</Text>
                        </div>
                    </div>

                    <Space style={{ width: '100%' }} wrap align="start">
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نوع</Text>
                            <Select
                                style={{ width: 150 }}
                                value={draft.interactionTypeId ?? undefined}
                                onChange={onTypeChange}
                                options={interactionTypes.map((t) => ({ value: t.InteractionTypeID, label: t.DisplayName }))}
                            />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>تاریخ</Text>
                            <PersianDateInput size="middle" value={draft.date} onChange={(v) => setDraft((d) => ({ ...d, date: v }))} />
                        </div>
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>ساعت</Text>
                            <TimePicker format="HH:mm" style={{ width: 100 }} value={draft.time} onChange={(v) => setDraft((d) => ({ ...d, time: v }))} />
                        </div>
                        {draftTypeCode === 'CALL' || draftTypeCode === 'MEETING' ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>وضعیت</Text>
                                <Select
                                    style={{ width: 150 }}
                                    value={draft.status}
                                    onChange={(v) => setDraft((d) => ({ ...d, status: v }))}
                                    options={[{ value: 'DONE', label: 'انجام‌شده' }, { value: 'PLANNED', label: 'برنامه‌ریزی‌شده' }]}
                                />
                            </div>
                        ) : null}
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>مسئول</Text>
                            <Select
                                allowClear
                                showSearch
                                style={{ width: 180 }}
                                placeholder="پیش‌فرض: خودِ من"
                                value={draft.ownerUserId ?? undefined}
                                onChange={(v) => setDraft((d) => ({ ...d, ownerUserId: v ?? null }))}
                                options={(users || []).map((u) => ({ value: u.UserID, label: u.FullName }))}
                                filterOption={(input, option) => (option?.label as string)?.includes(input)}
                            />
                        </div>
                    </Space>

                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>موضوع</Text>
                        <Input value={draft.subject} onChange={(e) => setDraft((d) => ({ ...d, subject: e.target.value }))} />
                    </div>

                    <Space style={{ width: '100%' }} wrap align="start">
                        <div>
                            <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>توضیحات</Text>
                            <Input.TextArea rows={2} style={{ width: 240 }} value={draft.description} onChange={(e) => setDraft((d) => ({ ...d, description: e.target.value }))} />
                        </div>
                        {draftTypeCode !== 'NOTE' ? (
                            <div>
                                <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>نتیجه</Text>
                                <Input.TextArea rows={2} style={{ width: 240 }} value={draft.outcome} onChange={(e) => setDraft((d) => ({ ...d, outcome: e.target.value }))} />
                            </div>
                        ) : null}
                    </Space>

                    <div>
                        <Text style={{ fontSize: 12, display: 'block', marginBottom: 4 }}>پیوست‌ها</Text>
                        {pending.map((p) => (
                            <div key={p.uid} style={{ display: 'flex', alignItems: 'center', gap: 6, marginBottom: 6, padding: '4px 8px', background: '#f6ffed', border: '1px solid #d9f7be', borderRadius: 6 }}>
                                <PaperClipOutlined />
                                <span style={{ maxWidth: '40%', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={p.file.name}>{p.file.name}</span>
                                <Text type="secondary" style={{ fontSize: 12, flex: 'none' }}>{formatSize(p.file.size)}</Text>
                                <Input
                                    size="small"
                                    style={{ flex: '1 1 120px', minWidth: 0 }}
                                    placeholder="توضیحاتِ پیوست (اختیاری)"
                                    maxLength={ATTACH_MAX_DESCRIPTION}
                                    value={p.description}
                                    disabled={saving}
                                    onChange={(e) => setPending((list) => list.map((x) => (x.uid === p.uid ? { ...x, description: e.target.value } : x)))}
                                />
                                <Tooltip title="حذف از فهرست">
                                    <Button type="text" size="small" danger icon={<CloseOutlined />} disabled={saving} onClick={() => setPending((list) => list.filter((x) => x.uid !== p.uid))} />
                                </Tooltip>
                            </div>
                        ))}
                        <Upload
                            multiple
                            showUploadList={false}
                            disabled={saving}
                            beforeUpload={(file) => {
                                addPending(file);
                                return false; // ارسال فقط همراهِ «ذخیره»
                            }}
                        >
                            <Button size="small" icon={<PaperClipOutlined />} disabled={saving}>افزودنِ فایل</Button>
                        </Upload>
                        <Text type="secondary" style={{ display: 'block', fontSize: 12, marginTop: 4 }}>
                            هر فایل حداکثر ۱۰ مگابایت، در هر بار حداکثر ۱۰ فایل؛ فایل‌هایِ اجرایی/اسکریپتی مجاز نیستند.
                        </Text>
                    </div>
                </Space>
            </Modal>

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </>
    );
}
