import { useMemo } from 'react';
import { Modal, Button, Typography, Tag, Select } from 'antd';
import { BellFilled, CheckOutlined, ClockCircleOutlined, CalendarOutlined, FieldTimeOutlined } from '@ant-design/icons';
import { THEME } from '../theme';
import { gregorianToJalaliDateTimeDisplay, gregorianToJalaliDisplay, parseServerDateTime } from '../Utils/jalali';

export interface DueReminder {
    ReminderID: number;
    EntityType: string;
    EventID: number;
    Title: string | null;
    StartDateTime: string | null;
    EndDateTime: string | null;
    IsAllDay: boolean | number | null;
    RecurrenceType: 'NONE' | 'DAILY' | 'WEEKLY' | 'MONTHLY' | 'YEARLY' | null;
    OffsetMinutes: number;
    RemindAt: string;
    /** پایانِ تعویق (پچِ ۱۳۱)؛ null = بدونِ تعویق */
    SnoozedUntil?: string | null;
    /** متن/توضیحاتِ رویداد (فقط با دسترسیِ خودِ کاربر)؛ خالی = بدونِ متن */
    Description?: string | null;
}

interface Props {
    reminder: DueReminder;
    queueCount: number; // تعداد یادآوری‌های دیگرِ در صف
    acknowledging: boolean;
    onAcknowledge: () => void;
    /** «چند دقیقهٔ دیگر دوباره یادآوری کن» (۵ تا ۶۰ دقیقه، گامِ ۵) */
    onSnooze?: (minutes: number) => void;
    snoozing?: boolean;
    /** کاربر با پنجره کار کرد → بسته‌شدنِ خودکار متوقف شود */
    onInteract?: () => void;
    /** زمان‌سنجِ بسته‌شدنِ خودکار متوقف است */
    held?: boolean;
    /** پنجره پس از این مدت (میلی‌ثانیه) خودکار بسته می‌شود — فقط برایِ نوارِ پیشرفت؛ بستن در ReminderWatcher است */
    autoCloseMs?: number;
}

const RECURRENCE_LABEL: Record<string, string> = {
    DAILY: 'تکرارِ روزانه',
    WEEKLY: 'تکرارِ هفتگی',
    MONTHLY: 'تکرارِ ماهانه',
    YEARLY: 'تکرارِ سالانه',
};

/** «۱۰ دقیقه دیگر» / «هم‌اکنون» / «۵ دقیقه گذشته» بر اساس فاصله‌ی زمانِ شروع تا الان */
function relativeStart(startIso: string | null): { label: string; tone: 'now' | 'soon' | 'past' } {
    if (!startIso) return { label: '', tone: 'now' };
    const diffMin = Math.round((parseServerDateTime(startIso).getTime() - Date.now()) / 60000);
    if (diffMin <= 0 && diffMin > -3) return { label: 'هم‌اکنون آغاز می‌شود', tone: 'now' };
    if (diffMin > 0) {
        if (diffMin < 60) return { label: `${diffMin} دقیقه‌ی دیگر`, tone: 'soon' };
        const h = Math.floor(diffMin / 60);
        const m = diffMin % 60;
        return { label: m ? `${h} ساعت و ${m} دقیقه‌ی دیگر` : `${h} ساعت دیگر`, tone: 'soon' };
    }
    const ago = Math.abs(diffMin);
    if (ago < 60) return { label: `${ago} دقیقه پیش آغاز شده`, tone: 'past' };
    return { label: `${Math.floor(ago / 60)} ساعت پیش آغاز شده`, tone: 'past' };
}

const { Title, Text, Paragraph } = Typography;

/** گزینه‌هایِ بیشترِ تعویق (۲۰ تا ۶۰ دقیقه) — ۵، ۱۰ و ۱۵ دکمهٔ مستقیم دارند */
const MORE_SNOOZE = [20, 25, 30, 35, 40, 45, 50, 55, 60];

export default function ReminderNotificationModal({ reminder, queueCount, acknowledging, onAcknowledge, autoCloseMs, onSnooze, snoozing, onInteract, held }: Props) {
    const isAllDay = Number(reminder.IsAllDay) === 1;
    const isStandalone = reminder.EntityType === 'STANDALONE' || !reminder.StartDateTime;

    // شروعِ همین رخداد = زمانِ یادآوری + Offset.
    // (برای رویدادهای تکرارشونده، StartDateTimeِ سرور همان شروعِ «رخدادِ اصلی» است نه رخدادِ جاری.)
    const occurrenceStart = useMemo(() => {
        if (isStandalone) return null;
        const base = new Date(parseServerDateTime(reminder.RemindAt)); // Safari/iOS: نه new Date(رشتهٔ سرور)
        base.setMinutes(base.getMinutes() + (Number(reminder.OffsetMinutes) || 0));
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${base.getFullYear()}-${pad(base.getMonth() + 1)}-${pad(base.getDate())} ${pad(base.getHours())}:${pad(base.getMinutes())}:00`;
    }, [reminder.RemindAt, reminder.OffsetMinutes, isStandalone]);

    const rel = useMemo(() => relativeStart(occurrenceStart), [occurrenceStart]);
    const toneColor = rel.tone === 'past' ? THEME.error : rel.tone === 'now' ? THEME.success : THEME.primary;

    return (
        <Modal
            open
            centered
            closable={false}
            maskClosable={false}
            keyboard={false}
            width={420}
            footer={null}
            styles={{
                mask: { background: 'rgba(17, 24, 39, 0.45)', backdropFilter: 'blur(2px)' },
                content: { padding: 0, overflow: 'hidden', borderRadius: 18 },
            }}
        >
            <div className="reminder-pop" onPointerDown={onInteract} onMouseEnter={onInteract} onFocus={onInteract}>
                {autoCloseMs && !held ? (
                    // نوارِ زمانِ باقی‌مانده تا بسته‌شدنِ خودکار (بدونِ ثبتِ «دیدم»)
                    <div className="reminder-pop-timer" style={{ animationDuration: `${autoCloseMs}ms`, animationPlayState: acknowledging ? 'paused' : 'running' }} />
                ) : null}
                <div className="reminder-pop-top">
                    <span className="reminder-pop-bell">
                        <BellFilled />
                    </span>
                    <Text className="reminder-pop-kicker">یادآوری</Text>
                    {queueCount > 0 && (
                        <Tag className="reminder-pop-queue">{`+${queueCount} یادآوری دیگر`}</Tag>
                    )}
                </div>

                <div className="reminder-pop-body">
                    <Title level={4} style={{ margin: '4px 0 10px', textAlign: 'center' }}>
                        {reminder.Title || 'یادآوری'}
                    </Title>

                    {reminder.Description && (
                        // متنِ بلند کوتاه می‌شود؛ «بیشتر» کاملش را همین‌جا نشان می‌دهد (و کامل در جزئیاتِ رویداد هم هست)
                        <Paragraph
                            className="reminder-pop-desc"
                            ellipsis={{ rows: 3, expandable: true, symbol: 'بیشتر' }}
                            style={{ textAlign: 'center', whiteSpace: 'pre-wrap', color: THEME.textSecondary, marginBottom: 12 }}
                        >
                            {reminder.Description}
                        </Paragraph>
                    )}

                    {!isStandalone && (
                        <div className="reminder-pop-meta">
                            <div className="reminder-pop-row">
                                <ClockCircleOutlined />
                                <span>
                                    {isAllDay
                                        ? `${gregorianToJalaliDisplay(occurrenceStart)} — تمام‌روز`
                                        : gregorianToJalaliDateTimeDisplay(occurrenceStart)}
                                </span>
                            </div>
                            {rel.label && (
                                <div className="reminder-pop-chip" style={{ color: toneColor, borderColor: toneColor }}>
                                    {rel.label}
                                </div>
                            )}
                            {reminder.RecurrenceType && reminder.RecurrenceType !== 'NONE' && (
                                <div className="reminder-pop-row" style={{ color: THEME.textSecondary, fontSize: 12 }}>
                                    <CalendarOutlined />
                                    <span>{RECURRENCE_LABEL[reminder.RecurrenceType]}</span>
                                </div>
                            )}
                        </div>
                    )}

                    {isStandalone && (
                        <div className="reminder-pop-meta">
                            <div className="reminder-pop-row" style={{ color: THEME.textSecondary, fontSize: 12 }}>
                                <ClockCircleOutlined />
                                <span>زمانِ یادآوری: {gregorianToJalaliDateTimeDisplay(reminder.RemindAt)}</span>
                            </div>
                        </div>
                    )}

                    <Button
                        type="primary"
                        size="large"
                        block
                        icon={<CheckOutlined />}
                        loading={acknowledging}
                        onClick={onAcknowledge}
                        style={{ marginTop: 18, height: 44, borderRadius: 10, ...(THEME ? {} : {}) }}
                        className="reminder-pop-ack"
                    >
                        متوجه شدم
                    </Button>

                    {onSnooze && (
                        // تعویق: همین یادآوری چند دقیقهٔ دیگر دوباره نمایش داده می‌شود (و Push می‌شود)
                        <div className="reminder-pop-snooze">
                            <span className="reminder-pop-snooze-label"><FieldTimeOutlined /> دوباره یادآوری کن:</span>
                            <div className="reminder-pop-snooze-row">
                                {[5, 10, 15].map((m) => (
                                    <Button key={m} size="small" disabled={acknowledging || snoozing} onClick={() => onSnooze(m)}>
                                        {m} دقیقه بعد
                                    </Button>
                                ))}
                                <Select
                                    size="small"
                                    placeholder="بیشتر…"
                                    style={{ width: 96 }}
                                    disabled={acknowledging || snoozing}
                                    value={null}
                                    onChange={(v) => v && onSnooze(Number(v))}
                                    options={MORE_SNOOZE.map((m) => ({ value: m, label: `${m} دقیقه` }))}
                                />
                            </div>
                        </div>
                    )}
                </div>
            </div>

            <style>{`
                .reminder-pop { animation: reminderPopIn .28s cubic-bezier(.16,1,.3,1); }
                @keyframes reminderPopIn {
                    from { opacity: 0; transform: scale(.92) translateY(12px); }
                    to   { opacity: 1; transform: scale(1) translateY(0); }
                }
                .reminder-pop-top {
                    position: relative;
                    display: flex; flex-direction: column; align-items: center; gap: 6px;
                    padding: 22px 20px 14px;
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    color: #fff;
                }
                .reminder-pop-bell {
                    width: 56px; height: 56px; border-radius: 50%;
                    background: rgba(255,255,255,.18);
                    display: flex; align-items: center; justify-content: center;
                    font-size: 26px;
                    animation: reminderBell 1.6s ease-in-out infinite;
                }
                @keyframes reminderBell {
                    0%, 100% { transform: rotate(0); }
                    10%, 30% { transform: rotate(-14deg); }
                    20%, 40% { transform: rotate(14deg); }
                    50% { transform: rotate(0); }
                }
                .reminder-pop-kicker { color: rgba(255,255,255,.92) !important; font-size: 13px; letter-spacing: .5px; }
                .reminder-pop-queue {
                    position: absolute; top: 12px; inset-inline-start: 12px;
                    background: rgba(255,255,255,.2); border: none; color: #fff; border-radius: 20px; margin: 0;
                }
                .reminder-pop-body { padding: 18px 22px 22px; }
                .reminder-pop-meta { display: flex; flex-direction: column; align-items: center; gap: 8px; }
                .reminder-pop-row { display: flex; align-items: center; gap: 6px; font-size: 13px; color: ${THEME.textPrimary}; }
                .reminder-pop-chip {
                    display: inline-block; padding: 3px 12px; border-radius: 20px;
                    border: 1px solid; font-size: 12px; font-weight: 600;
                }
.reminder-pop-timer {
                    position: absolute; top: 0; inset-inline-start: 0; height: 3px; width: 100%; z-index: 1;
                    background: rgba(255,255,255,.85); transform-origin: right; animation: reminderTimer linear forwards;
                }
                @keyframes reminderTimer { from { transform: scaleX(1); } to { transform: scaleX(0); } }
                .reminder-pop { position: relative; }
                .reminder-pop-snooze { margin-top: 14px; display: flex; flex-direction: column; align-items: center; gap: 8px; }
                .reminder-pop-snooze-label { font-size: 12px; color: ${THEME.textSecondary}; }
                .reminder-pop-snooze-row { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
                .reminder-pop-ack.ant-btn-primary {
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    border: none; box-shadow: 0 6px 16px rgba(102,126,234,.35);
                }
            `}</style>
        </Modal>
    );
}
