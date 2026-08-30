import { useEffect, useState } from 'react';
import { Input } from 'antd';
import DatePicker from 'react-multi-date-picker';
import persian from 'react-date-object/calendars/persian';
import persian_fa from 'react-date-object/locales/persian_fa';
import { THEME } from '../theme';
import {
    toEnglishDigits,
    gregorianToJalaliDisplay,
    dateToGregorianISO,
    parseJalaliString,
} from '../Utils/jalali';

interface PersianDateInputProps {
    /** تاریخ به فرمت میلادی YYYY-MM-DD (همان چیزی که به سرور ارسال می‌شود) */
    value: string | null;
    /** مقدار جدید نیز به فرمت میلادی YYYY-MM-DD برگردانده می‌شود */
    onChange: (value: string | null) => void;
    placeholder?: string;
    disabled?: boolean;
    size?: 'large' | 'middle' | 'small';
}

/**
 * ورودی تاریخ شمسی - تایپ عدد و / خودکار + دکمه تقویم
 * (نسخه‌ی مشترک همان کامپوننتی که در صفحه گزارش‌گیری استفاده می‌شود)
 */
export default function PersianDateInput({
    value,
    onChange,
    placeholder = 'مثال: 14040101',
    disabled = false,
    size = 'large',
}: PersianDateInputProps) {
    const [inputValue, setInputValue] = useState('');

    useEffect(() => {
        setInputValue(value ? gregorianToJalaliDisplay(value) : '');
    }, [value]);

    const formatWithSlash = (raw: string): string => {
        const digits = toEnglishDigits(raw).replace(/\D/g, '');
        if (digits.length === 0) return '';
        if (digits.length <= 4) return digits;
        if (digits.length <= 6) return `${digits.substring(0, 4)}/${digits.substring(4)}`;
        return `${digits.substring(0, 4)}/${digits.substring(4, 6)}/${digits.substring(6, 8)}`;
    };

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const raw = e.target.value;
        const formatted = formatWithSlash(raw);
        setInputValue(formatted);

        const digits = toEnglishDigits(raw).replace(/\D/g, '');
        if (digits.length === 8) {
            const parsed = parseJalaliString(formatted);
            if (parsed) onChange(dateToGregorianISO(parsed));
        } else if (digits.length === 0) {
            onChange(null);
        }
    };

    return (
        <div style={{ position: 'relative', width: '100%' }}>
            <Input
                value={inputValue}
                onChange={handleInputChange}
                placeholder={placeholder}
                size={size}
                maxLength={10}
                disabled={disabled}
                style={{
                    direction: 'ltr',
                    textAlign: 'center',
                    fontFamily: 'monospace',
                    fontSize: 14,
                    letterSpacing: 1,
                    paddingLeft: 40,
                }}
            />

            {/* دکمه تقویم روی input */}
            <div
                style={{
                    position: 'absolute',
                    left: 8,
                    top: '50%',
                    transform: 'translateY(-50%)',
                    zIndex: 10,
                }}
            >
                <DatePicker
                    value={value ? new Date(value) : null}
                    onChange={(date: any) => {
                        onChange(date ? dateToGregorianISO(date.toDate()) : null);
                    }}
                    calendar={persian}
                    locale={persian_fa}
                    calendarPosition="bottom-left"
                    format="YYYY/MM/DD"
                    disabled={disabled}
                    render={(_value, openCalendar) => (
                        <button
                            type="button"
                            onClick={disabled ? undefined : openCalendar}
                            disabled={disabled}
                            style={{
                                background: 'transparent',
                                border: 'none',
                                cursor: disabled ? 'default' : 'pointer',
                                fontSize: 20,
                                padding: 4,
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                color: THEME.primary,
                            }}
                            title="انتخاب از تقویم"
                        >
                            📅
                        </button>
                    )}
                />
            </div>
        </div>
    );
}
