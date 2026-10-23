import { useState } from 'react';
import { Alert, Button, Checkbox, Modal, Popconfirm, Space, Tag, Tooltip } from 'antd';
import { EditOutlined, StopOutlined, UndoOutlined } from '@ant-design/icons';
import dayjs, { Dayjs } from 'dayjs';
import { crmApi } from './crmApi';
import PersianDateInput from '../PersianDateInput';
import TimeInput from '../TimeInput';
import { gregorianToJalaliDateTimeDisplay } from '../../Utils/jalali';

/** «قطعِ همکاری» برایِ طرف‌حساب و مخاطب (پچِ ۱۱۹) — CooperationEndedAt: NULL = همکاری برقرار */
export interface CooperationState {
    ended: boolean;
    date: string | null; // YYYY-MM-DD
    time: Dayjs | null;
}

export const emptyCooperation = (): CooperationState => ({ ended: false, date: null, time: null });

/** مقدارِ سرور ("2026-10-23 14:30:00…") → وضعیتِ فرم */
export function cooperationFromServer(at?: string | null): CooperationState {
    const m = at ? /^(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2})/.exec(String(at)) : null;
    if (!m) return emptyCooperation();

    return { ended: true, date: m[1], time: dayjs().hour(+m[2]).minute(+m[3]).second(0) };
}

/** وضعیتِ فرم → فیلدهایِ API (cooperationEnded / cooperationEndedAt) */
export function cooperationToApi(s: CooperationState): { cooperationEnded: boolean; cooperationEndedAt?: string } {
    if (!s.ended) return { cooperationEnded: false };

    return { cooperationEnded: true, cooperationEndedAt: `${s.date} ${(s.time ?? dayjs().startOf('day')).format('HH:mm')}:00` };
}

/** خطایِ اعتبارسنجیِ سمتِ مرورگر یا null */
export function cooperationError(s: CooperationState): string | null {
    return s.ended && (!s.date || !s.time) ? 'تاریخ و ساعتِ قطعِ همکاری الزامی است.' : null;
}

/** چک‌باکسِ «قطعِ همکاری» + تاریخ و ساعت (با زدنِ چک‌باکس، پیش‌فرض = همین لحظه) */
export default function CooperationEndedField({ value, onChange, disabled }: { value: CooperationState; onChange: (v: CooperationState) => void; disabled?: boolean }) {
    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 12 }}>
            <Checkbox
                checked={value.ended}
                disabled={disabled}
                onChange={(e) => {
                    const now = dayjs();
                    onChange(e.target.checked ? { ended: true, date: value.date ?? now.format('YYYY-MM-DD'), time: value.time ?? now.second(0) } : emptyCooperation());
                }}
            >
                <span style={{ color: value.ended ? '#cf1322' : undefined, fontWeight: value.ended ? 600 : undefined }}>قطعِ همکاری</span>
            </Checkbox>
            {value.ended ? (
                <>
                    <div style={{ width: 170 }}>
                        <PersianDateInput value={value.date} onChange={(v) => onChange({ ...value, date: v })} />
                    </div>
                    <div style={{ width: 120 }}>
                        <TimeInput value={value.time} onChange={(v) => onChange({ ...value, time: v })} disabled={disabled} placeholder="ساعت" />
                    </div>
                </>
            ) : null}
        </div>
    );
}

/** برچسبِ «قطعِ همکاری» با تاریخ و ساعت (برایِ جدول و صفحهٔ جزئیات) */
export function CooperationEndedTag({ at, showDate = false }: { at?: string | null; showDate?: boolean }) {
    if (!at) return null;
    const label = gregorianToJalaliDateTimeDisplay(at);

    return (
        <Tooltip title={`قطعِ همکاری از ${label}`}>
            <Tag color="red" icon={<StopOutlined />} style={{ marginInlineEnd: 0 }}>
                قطعِ همکاری{showDate ? <span dir="ltr" style={{ marginInlineStart: 4 }}>{label}</span> : null}
            </Tag>
        </Tooltip>
    );
}

/**
 * ثبت/ویرایش/برداشتنِ «قطعِ همکاری» مستقیماً از صفحهٔ اصلیِ طرف‌حساب/مخاطب (پچِ ۱۲۰) — فقط همین فیلد ذخیره می‌شود.
 * onSaved(message, ok): پیامِ نتیجه برایِ پنجرهٔ اعلانِ صفحه و بازخوانیِ رکورد.
 */
export function CooperationEndedAction({ entity, id, at, canManage, onSaved }: {
    entity: 'parties' | 'persons';
    id: number;
    at?: string | null;
    canManage: boolean;
    onSaved: (message: string, ok: boolean) => void;
}) {
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<CooperationState>(emptyCooperation());
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const send = async (body: { cooperationEnded: boolean; cooperationEndedAt?: string }) => {
        setSaving(true);
        setError(null);
        const res = await crmApi(`/crm/${entity}/${id}/cooperation`, 'POST', body);
        setSaving(false);
        if (!res.ok || !res.success) {
            if (open) setError(res.message);
            else onSaved(res.message, false);
            return;
        }
        setOpen(false);
        onSaved(res.message, true);
    };

    const openModal = () => {
        const now = dayjs();
        setState(at ? cooperationFromServer(at) : { ended: true, date: now.format('YYYY-MM-DD'), time: now.second(0) });
        setError(null);
        setOpen(true);
    };

    const save = () => {
        if (!state.ended) return send({ cooperationEnded: false });
        const problem = cooperationError(state);
        if (problem) return setError(problem);
        send(cooperationToApi(state));
    };

    return (
        <Space size={8} wrap>
            {at ? <CooperationEndedTag at={at} showDate /> : <span>خیر</span>}
            {canManage ? (
                at ? (
                    <>
                        <Button size="small" icon={<EditOutlined />} onClick={openModal}>ویرایش</Button>
                        <Popconfirm title="قطعِ همکاری برداشته شود؟" okText="بله" cancelText="خیر" onConfirm={() => send({ cooperationEnded: false })}>
                            <Button size="small" icon={<UndoOutlined />} loading={saving && !open}>برداشتن</Button>
                        </Popconfirm>
                    </>
                ) : (
                    <Button size="small" danger icon={<StopOutlined />} onClick={openModal}>ثبتِ قطعِ همکاری</Button>
                )
            ) : null}

            <Modal
                title="قطعِ همکاری"
                open={open}
                onCancel={() => !saving && setOpen(false)}
                maskClosable={false}
                okText="ذخیره"
                cancelText="انصراف"
                onOk={save}
                confirmLoading={saving}
                destroyOnClose
            >
                {error ? <Alert type="error" showIcon message={error} style={{ marginBottom: 12, borderRadius: 8 }} /> : null}
                <CooperationEndedField value={state} onChange={setState} disabled={saving} />
            </Modal>
        </Space>
    );
}
