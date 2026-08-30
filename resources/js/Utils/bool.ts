/**
 * تبدیل امن مقادیر BIT که از SQL Server می‌آیند (ممکن است به‌صورت رشته‌ی
 * "0"/"1" برگردند، نه boolean واقعی) به یک boolean واقعی.
 *
 * چرا لازم است: در جاوااسکریپت رشته‌ی "0" یک مقدار truthy است
 * (فقط رشته‌ی خالی "" falsy است)، پس چک ساده‌ی `if (value)` وقتی
 * value رشته‌ی "0" باشد به‌اشتباه true می‌شود.
 */
export function toBool(value: unknown): boolean {
    if (typeof value === 'boolean') return value;
    if (typeof value === 'number') return value === 1;
    if (typeof value === 'string') return value === '1' || value.toLowerCase() === 'true';
    return false;
}