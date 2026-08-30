/**
 * ابزار مشترک برای ساخت نام نمایشی کاربر
 * اولویت: نام کامل (FullName) > ترکیب نام و نام‌خانوادگی > نام کاربری
 */

export interface UserNameFields {
    FullName?: string | null;
    FirstName?: string | null;
    LastName?: string | null;
    UserName?: string | null;
    PositionTitle?: string | null;
}

export function getUserDisplayName(u: UserNameFields): string {
    const fullName = (u.FullName || '').trim();
    if (fullName) return fullName;

    const combined = [u.FirstName, u.LastName].filter(Boolean).join(' ').trim();
    if (combined) return combined;

    return u.UserName || '';
}

/** نام + سمت، برای نمایش در گزینه‌های سلکت (مثلاً «مهدی باج مالو — مدیر فناوری اطلاعات») */
export function getUserOptionLabel(u: UserNameFields): string {
    const name = getUserDisplayName(u);
    const position = (u.PositionTitle || '').trim();
    return position ? `${name} — ${position}` : name;
}