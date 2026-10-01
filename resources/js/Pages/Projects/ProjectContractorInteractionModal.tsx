import { useState } from 'react';
import { Modal, Select, Button, Input, Space, Typography, Alert, TimePicker, Checkbox } from 'antd';
import { SaveOutlined, CloseOutlined } from '@ant-design/icons';
import dayjs, { Dayjs } from 'dayjs';
import { crmApi } from '../../Components/Crm/crmApi';
import NotificationModal, { NotificationType } from '../../Components/NotificationModal';
import PersianDateInput from '../../Components/PersianDateInput';

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
    const [notification, setNotification] = useState<{ open: boolean; type: NotificationType; message: string }>({ open: false, type: 'success', message: '' });

    const draftTypeCode = interactionTypes.find((t) => t.InteractionTypeID === draft.interactionTypeId)?.Code;

    const reset = () => {
        setDraft(emptyDraft(defaultTypeId));
        setError(null);
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

        setSaving(true);
        setError(null);
        const res = await crmApi(`/projects/${projectId}/contractors/${partyId}/interactions`, 'POST', {
            interactionTypeId: draft.interactionTypeId,
            subject: draft.subject.trim(),
            description: draft.description.trim() || undefined,
            outcome: draft.outcome.trim() || undefined,
            interactionDate: toApiDateTime(draft.date, draft.time),
            status: draft.status,
            ownerUserId: draft.ownerUserId ?? undefined,
        });
        setSaving(false);

        if (!res.ok || !res.success) {
            return setError(res.message);
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
                </Space>
            </Modal>

            <NotificationModal open={notification.open} type={notification.type} message={notification.message} onClose={() => setNotification((p) => ({ ...p, open: false }))} />
        </>
    );
}
