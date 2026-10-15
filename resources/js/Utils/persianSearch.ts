/**
 * یکسان‌سازیِ متنِ فارسی برایِ جستجو (سمتِ مرورگر) — قواعدِ تأییدشده:
 * نیم‌فاصله ← فاصله؛ آ/أ/إ/ٱ ← ا؛ «ئ» و «ؤ» بدونِ تغییر؛ ي/ى ← ی؛ ك ← ک؛
 * ارقامِ فارسی و عربی ← 0-9؛ حذفِ اعراب (U+064B..U+0652، U+0670)، کشیده و ZWJ/LRM/RLM؛
 * فاصله‌هایِ پشتِ‌سرِهم یکی، Trim، حروفِ لاتین کوچک.
 * «ة» و «ۀ» فعلاً بدونِ تغییر می‌مانند (نیازمندِ تصمیمِ جداگانه).
 */

const CHAR_MAP: Record<string, string> = {
    '\u200C': ' ', // نیم‌فاصله (ZWNJ)
    '\u0622': '\u0627', // آ
    '\u0623': '\u0627', // أ
    '\u0625': '\u0627', // إ
    '\u0671': '\u0627', // ٱ
    '\u064A': '\u06CC', // ي
    '\u0649': '\u06CC', // ى
    '\u0643': '\u06A9', // ك
};

/** اعراب (U+064B..U+0652، U+0670)، کشیده (U+0640)، ZWJ (U+200D)، LRM (U+200E)، RLM (U+200F) */
const REMOVED = /[\u064B-\u0652\u0670\u0640\u200D\u200E\u200F]/;

export interface NormalizedText {
    /** متنِ یکسان‌سازی‌شده */
    text: string;
    /**
     * map[i] = اندیسِ واحدِ UTF-16 در متنِ اصلی که واحدِ i‌امِ متنِ یکسان‌سازی‌شده از آن آمده است.
     * همیشه map.length === text.length (حتی وقتی toLowerCase یک کاراکتر را به چند واحد تبدیل کند، مثلِ «İ»).
     */
    map: number[];
}

export function normalizeWithMap(input: string): NormalizedText {
    let text = '';
    const map: number[] = [];
    let prevSpace = true; // فاصله‌هایِ ابتدایی حذف می‌شوند

    for (let i = 0; i < input.length; i++) {
        let c = input[i];
        if (REMOVED.test(c)) continue;

        const code = c.charCodeAt(0);
        if (code >= 0x06f0 && code <= 0x06f9) c = String.fromCharCode(code - 0x06f0 + 48);
        else if (code >= 0x0660 && code <= 0x0669) c = String.fromCharCode(code - 0x0660 + 48);
        else c = CHAR_MAP[c] ?? c;

        if (/\s/.test(c)) {
            if (prevSpace) continue;
            text += ' ';
            map.push(i);
            prevSpace = true;
            continue;
        }
        prevSpace = false;

        // toLowerCase ممکن است بیش از یک واحد برگرداند (مثلاً «İ» → «i» + U+0307)؛
        // برایِ هر واحدِ خروجی یک ورودیِ map ثبت می‌شود تا اندیس‌ها هرگز از هم جدا نشوند.
        const lowered = c.toLowerCase();
        for (let k = 0; k < lowered.length; k++) {
            text += lowered[k];
            map.push(i);
        }
    }

    if (text.endsWith(' ')) {
        text = text.slice(0, -1);
        map.pop();
    }

    return { text, map };
}

export const normalizeForSearch = (input: string): string => normalizeWithMap(input).text;

export const tokenize = (normalized: string): string[] => normalized.split(' ').filter(Boolean);

/**
 * محدودهٔ [شروع، پایان) در متنِ اصلی برایِ اولین تطبیقِ عبارتِ یکسان‌سازی‌شده.
 * اگر تطبیقی نباشد یا اندیسی معتبر نباشد، null برمی‌گرداند تا هیچ برجسته‌سازیِ اشتباهی انجام نشود.
 */
export function findMatchRange(source: NormalizedText, normalizedQuery: string): [number, number] | null {
    if (!normalizedQuery) return null;
    const idx = source.text.indexOf(normalizedQuery);
    if (idx < 0) return null;

    const first = source.map[idx];
    const last = source.map[idx + normalizedQuery.length - 1];
    if (first === undefined || last === undefined || last < first) return null;

    return [first, last + 1];
}
