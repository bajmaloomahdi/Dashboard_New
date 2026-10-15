<?php

namespace App\Services\GlobalSearch\RateLimit;

use Predis\Client;

/**
 * محدودیتِ نرخِ اتمیک رویِ Redis/Garnet با اتصالِ مستقل (Predis) — جدا از کش، session و Redisِ اصلیِ برنامه.
 *
 * - افزایشِ شمارنده و تنظیمِ انقضا در یک اسکریپتِ Lua اجرا می‌شوند (اتمیک؛ هیچ کلیدی بدونِ انقضا نمی‌ماند).
 * - کلید: پیشوند + sha1(شناسهٔ کاربر) + طولِ پنجره ← هر کاربر و هر پنجرهٔ زمانی شمارندهٔ جدا دارد؛
 *   شناسهٔ کاربر به‌صورتِ خام در Redis ذخیره نمی‌شود.
 * - پنجرهٔ ثابت از اولین درخواست: پس از انقضایِ کلید، شمارش از نو شروع می‌شود.
 * - هر خطایِ اتصال، Timeout، احرازِ هویت، پرشدنِ حافظه یا اجرایِ Lua به‌صورتِ استثنا بالا می‌رود (fail-closed).
 * - رمز فقط از پیکربندی (‎.env) خوانده می‌شود و در هیچ پیام یا لاگی قرار نمی‌گیرد.
 */
class RedisSearchRateLimiter implements SearchRateLimiter
{
    /** INCR و PEXPIRE در یک اجرایِ اتمیک؛ اگر کلید (به هر دلیل) بدونِ انقضا مانده باشد، انقضا دوباره تنظیم می‌شود */
    protected const SCRIPT = <<<'LUA'
local count = redis.call('INCR', KEYS[1])
local ttl = redis.call('PTTL', KEYS[1])
if count == 1 or ttl < 0 then
  redis.call('PEXPIRE', KEYS[1], ARGV[1])
  ttl = tonumber(ARGV[1])
end
return {count, ttl}
LUA;

    private ?Client $client = null;

    /**
     * @param  array{host?:string,port?:int|string,password?:?string,timeout?:float|int,read_write_timeout?:float|int,prefix?:string}  $config
     */
    public function __construct(#[\SensitiveParameter] private readonly array $config)
    {
    }

    public function attempt(string $userKey, int $maxAttempts, int $decaySeconds): RateLimitResult
    {
        $maxAttempts = max(1, $maxAttempts);
        $decayMs = max(1, $decaySeconds) * 1000;
        $key = ($this->config['prefix'] ?? 'gs:rl:v1:') . sha1($userKey) . ':' . max(1, $decaySeconds);

        $reply = $this->client()->eval(static::SCRIPT, 1, $key, (string) $decayMs);

        if (! is_array($reply) || count($reply) !== 2 || ! is_numeric($reply[0]) || ! is_numeric($reply[1])) {
            throw new RateLimiterUnavailable('Unexpected rate-limit reply.');
        }
        $count = (int) $reply[0];
        $ttlMs = (int) $reply[1];
        if ($count < 1 || $ttlMs <= 0) {
            throw new RateLimiterUnavailable('Invalid rate-limit state.');
        }

        return new RateLimitResult($count <= $maxAttempts, $count, max(1, (int) ceil($ttlMs / 1000)));
    }

    protected function client(): Client
    {
        if ($this->client === null) {
            $password = $this->config['password'] ?? null;
            if (! is_string($password) || $password === '') {
                throw new RateLimiterUnavailable('Rate-limit backend is not configured.');
            }

            $this->client = new Client([
                'scheme' => 'tcp',
                'host' => (string) ($this->config['host'] ?? '127.0.0.1'),
                'port' => (int) ($this->config['port'] ?? 6390),
                'password' => $password,
                'timeout' => (float) ($this->config['timeout'] ?? 0.5),
                'read_write_timeout' => (float) ($this->config['read_write_timeout'] ?? 0.5),
            ], ['exceptions' => true]);
        }

        return $this->client;
    }
}
