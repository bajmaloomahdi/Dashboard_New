<?php

namespace App\Services\Push;

/**
 * جلوگیری از SSRF: Endpointِ اشتراک از مرورگرِ کاربر می‌آید و سرور به آن درخواست می‌فرستد؛
 * پس فقط HTTPS رویِ پورتِ پیش‌فرض و فقط میزبان‌هایِ شناخته‌شدهٔ سرویس‌هایِ Push پذیرفته می‌شوند
 * (بدونِ IP، بدونِ نام‌کاربری/رمز در URL). همین بررسی پیش از هر ارسال هم تکرار می‌شود.
 */
class PushEndpointValidator
{
    /** @param  string[]  $allowedHosts  نامِ دقیق یا «.دامنه» برایِ زیرمیزبان‌ها */
    public function __construct(private array $allowedHosts)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('push.allowed_hosts', []));
    }

    public function isAllowed(?string $endpoint): bool
    {
        if (! is_string($endpoint) || $endpoint === '' || strlen($endpoint) > 2000) {
            return false;
        }
        $parts = parse_url($endpoint);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach ($this->allowedHosts as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($allowed === '') {
                continue;
            }
            if ($allowed[0] === '.') {
                if (str_ends_with($host, $allowed) && strlen($host) > strlen($allowed)) {
                    return true;
                }
            } elseif ($host === $allowed) {
                return true;
            }
        }

        return false;
    }
}
