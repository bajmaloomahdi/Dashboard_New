<?php

namespace Tests\Feature\GlobalSearch;

use App\Services\GlobalSearch\RateLimit\RedisSearchRateLimiter;
use App\Services\GlobalSearch\RateLimit\SearchRateLimiter;
use Predis\Client;
use Tests\Feature\GlobalSearch\Fakes\FakeMessagesProvider;

/**
 * محدودیتِ نرخِ اتمیک رویِ Garnetِ آزمایشیِ مستقل (127.0.0.1:6390) — هرگز Redisِ دیگری.
 * رمز از ‎.envِ GsDev خوانده می‌شود و در هیچ پیام، خطِ فرمان یا خروجیِ آزمونی چاپ نمی‌شود.
 * کلیدهایِ آزمون تصادفی‌اند و با انقضایِ خودشان پاک می‌شوند (هیچ DEL/FLUSH اجرا نمی‌شود).
 *
 * اگر GS_RATELIMIT_REDIS_PASSWORD تعریف نشده باشد (محیط آماده نیست) آزمون‌ها Skip می‌شوند؛ اگر تعریف شده
 * ولی Garnet در دسترس نباشد، شکست می‌خورند (پنهان نمی‌شوند).
 */
class RedisRateLimitIntegrationTest extends GlobalSearchTestCase
{
    private array $redis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->redis = (array) config('global_search.rate_limit.redis');
        if (($this->redis['host'] ?? null) !== '127.0.0.1' || (int) ($this->redis['port'] ?? 0) !== 6390) {
            $this->fail('SAFETY: rate-limit tests may only use the isolated test Garnet on 127.0.0.1:6390.');
        }
        if (! is_string($this->redis['password'] ?? null) || $this->redis['password'] === '') {
            $this->markTestSkipped('GS_RATELIMIT_REDIS_PASSWORD is not provisioned in this environment.');
        }

        FakeMessagesProvider::reset();
        config([
            'global_search.rate_limit.driver' => 'redis',
            'global_search.providers' => [
                'crm' => ['enabled' => false, 'class' => null],
                'messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class],
                'projects' => ['enabled' => false, 'class' => null],
                'processes' => ['enabled' => false, 'class' => null],
            ],
        ]);
    }

    private function raw(): Client
    {
        return new Client(['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6390, 'password' => $this->redis['password'], 'timeout' => 1.0]);
    }

    private function keyFor(string $userKey, int $decay): string
    {
        return $this->redis['prefix'] . sha1($userKey) . ':' . $decay;
    }

    private function uniqueUserId(): int
    {
        return random_int(1_000_000, 1_999_999_999);
    }

    private function assertNoSecretIn(string $text): void
    {
        $this->assertFalse(str_contains($text, (string) $this->redis['password']), 'The Garnet password must never appear in output.');
    }

    // ---------- سقف، انقضا، جداسازی ----------

    public function test_limit_is_enforced_per_user_through_the_endpoint(): void
    {
        config(['global_search.rate_limit.max_attempts' => 3, 'global_search.rate_limit.decay_seconds' => 10]);
        $a = $this->fakeUser($this->uniqueUserId());

        foreach ([200, 200, 200, 429, 429] as $expected) {
            $r = $this->actingAs($a)->search('پیام')->assertStatus($expected);
            $this->assertNoSecretIn((string) $r->getContent());
        }
        $retry = (int) $r->headers->get('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(10, $retry);
        $this->assertCount(3, FakeMessagesProvider::$calls, 'Only the allowed requests may reach a provider.');

        // کاربرِ دیگر مستقل است
        $this->actingAs($this->fakeUser($this->uniqueUserId()))->search('پیام')->assertOk();
    }

    public function test_counter_and_expiry_are_set_atomically_and_reset_after_the_window(): void
    {
        config(['global_search.rate_limit.max_attempts' => 1, 'global_search.rate_limit.decay_seconds' => 1]);
        $id = $this->uniqueUserId();
        $user = $this->fakeUser($id);

        $this->actingAs($user)->search('پیام')->assertOk();
        $key = $this->keyFor('user:' . $id, 1);
        $pttl = (int) $this->raw()->pttl($key);
        $this->assertGreaterThan(0, $pttl, 'The key must carry an expiry right after the first increment.');
        $this->assertLessThanOrEqual(1000, $pttl);
        $this->assertSame('1', (string) $this->raw()->get($key));
        $this->assertStringNotContainsString((string) $id, $key, 'The raw user id is not stored in the key.');

        $this->actingAs($user)->search('پیام')->assertStatus(429);

        usleep(1_300_000);
        $this->assertSame(0, (int) $this->raw()->exists($key), 'The counter must expire by itself.');
        $this->actingAs($user)->search('پیام')->assertOk();
    }

    public function test_a_key_without_expiry_is_repaired_by_the_script(): void
    {
        // حالتِ غیرعادی: کلیدی بدونِ انقضا (مثلاً دست‌کاری). اسکریپت باید انقضا را دوباره بگذارد، نه شمارنده را ابدی کند.
        $userKey = 'user:' . $this->uniqueUserId();
        $key = $this->keyFor($userKey, 2);
        $this->raw()->set($key, '5');
        $this->assertSame(-1, (int) $this->raw()->pttl($key));

        $result = (new RedisSearchRateLimiter($this->redis))->attempt($userKey, 10, 2);

        $this->assertSame(6, $result->count);
        $this->assertGreaterThan(0, (int) $this->raw()->pttl($key));
    }

    // ---------- هم‌زمانی از چند پردازش ----------

    /** @return array<int,array{allowed:int,blocked:int,errors:array,maxCount:int}> */
    private function runWorkers(array $jobs): array
    {
        $php = PHP_BINARY;
        $worker = __DIR__ . '/Support/rate_limit_worker.php';
        $env = array_merge(getenv(), [
            'GSW_HOST' => '127.0.0.1',
            'GSW_PORT' => '6390',
            'GSW_PASSWORD' => (string) $this->redis['password'], // فقط محیطِ پردازشِ فرزند، نه خطِ فرمان
        ]);
        $startAt = sprintf('%.6F', microtime(true) + 1.5);

        $procs = [];
        foreach ($jobs as $i => [$userKey, $attempts, $max, $decay]) {
            $cmd = [$php, $worker, $userKey, (string) $attempts, (string) $max, (string) $decay, $startAt];
            $procs[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($procs[$i]);
            $outs[$i] = $pipes;
        }

        $results = [];
        foreach ($procs as $i => $proc) {
            $stdout = stream_get_contents($outs[$i][1]);
            $stderr = stream_get_contents($outs[$i][2]);
            fclose($outs[$i][1]);
            fclose($outs[$i][2]);
            $code = proc_close($proc);
            $this->assertNoSecretIn($stdout . $stderr);
            $this->assertSame(0, $code, "worker {$i} exited with {$code}");
            $results[$i] = json_decode($stdout, true);
            $this->assertIsArray($results[$i], "worker {$i} output is not JSON");
            $this->assertSame([], $results[$i]['errors'], "worker {$i} had backend errors");
        }

        return $results;
    }

    public function test_concurrent_requests_from_many_processes_never_exceed_the_limit(): void
    {
        $userKey = 'user:' . $this->uniqueUserId();
        $workers = 8;
        $attempts = 30;
        $max = 100;

        $results = $this->runWorkers(array_fill(0, $workers, [$userKey, $attempts, $max, 10]));

        $allowed = array_sum(array_column($results, 'allowed'));
        $blocked = array_sum(array_column($results, 'blocked'));
        $this->assertSame($max, $allowed, 'Exactly maxAttempts requests may pass under concurrency (no lost or double counts).');
        $this->assertSame($workers * $attempts - $max, $blocked);
        $this->assertSame((string) ($workers * $attempts), (string) $this->raw()->get($this->keyFor($userKey, 10)), 'Every hit is counted exactly once.');
        $this->assertGreaterThan(0, (int) $this->raw()->pttl($this->keyFor($userKey, 10)));
    }

    public function test_concurrent_users_have_independent_counters(): void
    {
        $jobs = [];
        for ($i = 0; $i < 6; $i++) {
            $jobs[] = ['user:' . $this->uniqueUserId(), 12, 5, 10];
        }

        foreach ($this->runWorkers($jobs) as $i => $r) {
            $this->assertSame(5, $r['allowed'], "user {$i}");
            $this->assertSame(7, $r['blocked'], "user {$i}");
        }
    }

    // ---------- خطاها: رمزِ اشتباه، اتصال، Lua ----------

    public function test_wrong_password_is_fail_closed(): void
    {
        config(['global_search.rate_limit.redis.password' => 'wrong-' . bin2hex(random_bytes(12))]);

        $r = $this->actingAs($this->fakeUser($this->uniqueUserId()))->search('پیام')->assertStatus(503);

        $this->assertSame([], FakeMessagesProvider::$calls);
        $this->assertSame(0, $this->access->calls);
        $this->assertNoSecretIn((string) $r->getContent());
    }

    public function test_connection_error_is_fail_closed_and_bounded_in_time(): void
    {
        config(['global_search.rate_limit.redis.port' => 6391]); // هیچ سرویسی گوش نمی‌دهد

        $started = microtime(true);
        $this->actingAs($this->fakeUser($this->uniqueUserId()))->search('پیام')->assertStatus(503);
        $elapsed = microtime(true) - $started;

        $this->assertSame([], FakeMessagesProvider::$calls);
        $this->assertLessThan(2.0, $elapsed, 'Connect timeout (0.5s) must bound the failure.');
    }

    public function test_lua_error_is_fail_closed(): void
    {
        $this->app->instance(SearchRateLimiter::class, new class($this->redis) extends RedisSearchRateLimiter {
            protected const SCRIPT = "return redis.call('NOSUCHCOMMAND', KEYS[1])";
        });

        $this->actingAs($this->fakeUser($this->uniqueUserId()))->search('پیام')->assertStatus(503);
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    // ---------- توقفِ واقعیِ Garnet (فقط در سناریویِ هماهنگ‌شده) ----------

    public function test_real_garnet_outage_is_fail_closed(): void
    {
        if (getenv('GS_GARNET_OUTAGE_SCENARIO') !== '1') {
            $this->markTestSkipped('Runs only in the orchestrated scenario where the verified test Garnet PID is stopped.');
        }

        $r = $this->actingAs($this->fakeUser($this->uniqueUserId()))->search('پیام')->assertStatus(503);
        $this->assertSame([], FakeMessagesProvider::$calls);
        $this->assertNoSecretIn((string) $r->getContent());
    }
}
