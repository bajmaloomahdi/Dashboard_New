import { WEEKDAY_LABELS_FA } from '../../Utils/jalaliCalendar';

/**
 * نامِ کاملِ روزِ هفته («چهارشنبه»، نه «چ»). در ستونِ باریک (موبایل، ماهِ کوچک) فقط در صورتِ کمبودِ جا پیشوند و
 * «شنبه» در دو سطر می‌آیند («چهار / شنبه») — نام مخفف یا بریده نمی‌شود. index: شنبه = 0 … جمعه = 6.
 */
export default function WeekdayName({ index }: { index: number }) {
    const name = WEEKDAY_LABELS_FA[index];
    const i = name.lastIndexOf('شنبه');
    if (i <= 0) return <>{name}</>;
    return (
        <>
            {name.slice(0, i)}
            <wbr />
            {name.slice(i)}
        </>
    );
}
