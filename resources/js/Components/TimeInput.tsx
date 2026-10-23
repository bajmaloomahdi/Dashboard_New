import { useEffect, useMemo, useState } from 'react';
import { AutoComplete, Input } from 'antd';
import { ClockCircleOutlined } from '@ant-design/icons';
import dayjs, { Dayjs } from 'dayjs';

/** گزینه‌هایِ فهرست هر ۱۵ دقیقه (00:00 … 23:45) */
const QUARTERS = Array.from({ length: 96 }, (_, i) => `${String(Math.floor(i / 4)).padStart(2, '0')}:${String((i % 4) * 15).padStart(2, '0')}`);

const toDigits = (s: string) => s.replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d))).replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));

/**
 * متنِ تایپ‌شده → «HH:mm»؛ نامعتبر → null.
 * 942 → 09:42 ، 0942 → 09:42 ، 1530 → 15:30 ، 9 → 09:00 ، 14 → 14:00 ، 9:5 → 09:05
 * ساعتِ ۱ تا ۶ بدونِ صفرِ اول، مثلِ Outlook، بعدازظهر است (ساعتِ کاری): 145 → 13:45 ، 6 → 18:00 ؛ با صفرِ اول همان: 0145 → 01:45
 */
export function parseTimeText(text: string): string | null {
    const t = toDigits(text).trim();
    if (!t) return null;
    let h: number;
    let m: number;
    let hourText: string;
    const parts = t.split(/[:.\s]/).filter(Boolean);
    if (parts.length >= 2) {
        if (!/^\d{1,2}$/.test(parts[0]) || !/^\d{1,2}$/.test(parts[1])) return null;
        hourText = parts[0];
        h = Number(parts[0]);
        m = Number(parts[1]);
    } else {
        const d = t.replace(/\D/g, '');
        if (!d || d.length > 4) return null;
        if (d.length <= 2) {
            hourText = d;
            h = Number(d);
            m = 0;
        } else if (d.length === 3) {
            hourText = d[0];
            h = Number(d[0]);
            m = Number(d.slice(1));
        } else {
            hourText = d.slice(0, 2);
            h = Number(d.slice(0, 2));
            m = Number(d.slice(2));
        }
    }
    if (h > 23 || m > 59) return null;
    if (!hourText.startsWith('0') && h >= 1 && h <= 6) h += 12; // ۱ تا ۶ = بعدازظهر

    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}

interface TimeInputProps {
    value?: Dayjs | null;
    onChange?: (v: Dayjs | null) => void;
    size?: 'small' | 'middle' | 'large';
    placeholder?: string;
    disabled?: boolean;
}

/**
 * ورودیِ ساعت: فهرستِ ۱۵ دقیقه‌ای برایِ انتخاب، و تایپِ آزاد (مثلاً «942» → «09:42») که با خروج از فیلد یا Enter ثبت می‌شود.
 * مقدار Dayjs است (سازگار با Form.Item و فیلدهایِ TimePickerِ قبلی).
 */
export default function TimeInput({ value, onChange, size = 'large', placeholder = 'مثلاً 942 یا 145 (13:45)', disabled }: TimeInputProps) {
    const formatted = value && value.isValid() ? value.format('HH:mm') : '';
    const [text, setText] = useState(formatted);
    useEffect(() => setText(formatted), [formatted]);

    const commit = (raw: string) => {
        const parsed = parseTimeText(raw);
        if (!parsed) {
            setText(formatted); // نامعتبر → همان مقدارِ قبلی
            return;
        }
        const [h, m] = parsed.split(':').map(Number);
        const base = value && value.isValid() ? value : dayjs();
        setText(parsed);
        if (parsed !== formatted) onChange?.(base.hour(h).minute(m).second(0).millisecond(0));
    };

    // تایپِ عددی (مثلاً «9») گزینه‌هایِ همان ساعت را نشان می‌دهد؛ خالی = همهٔ گزینه‌ها
    const options = useMemo(() => {
        const t = toDigits(text).trim();
        if (!t || t === formatted) {
            // از ربعِ ساعتِ فعلیِ فیلد (یا الان) شروع و بعد از 23:45 دوباره از 00:00
            const base = value && value.isValid() ? value : dayjs();
            const start = base.hour() * 4 + Math.floor(base.minute() / 15);
            return [...QUARTERS.slice(start), ...QUARTERS.slice(0, start)].map((q) => ({ value: q }));
        }
        const parsed = parseTimeText(t);
        const hourPrefix = parsed ? parsed.slice(0, 2) : t.replace(/\D/g, '').slice(0, 2).padStart(2, '0');
        const list = QUARTERS.filter((q) => q.startsWith(hourPrefix));
        if (parsed && !list.includes(parsed)) list.unshift(parsed);
        return list.map((q) => ({ value: q }));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [text, formatted]);

    return (
        <AutoComplete
            value={text}
            options={options}
            onChange={(v) => setText(v)}
            onSelect={(v) => commit(String(v))}
            onBlur={() => commit(text)}
            disabled={disabled}
            style={{ width: '100%' }}
            popupMatchSelectWidth
        >
            <Input
                size={size}
                dir="ltr"
                inputMode="numeric"
                placeholder={placeholder}
                prefix={<ClockCircleOutlined style={{ color: '#bfbfbf' }} />}
                onPressEnter={(e) => {
                    e.preventDefault();
                    commit((e.target as HTMLInputElement).value);
                }}
            />
        </AutoComplete>
    );
}
