<?php

namespace Tests\Feature\GlobalSearch;

use App\Services\GlobalSearch\RateLimit\CacheSearchRateLimiter;
use App\Services\GlobalSearch\RateLimit\RateLimiterUnavailable;
use App\Services\GlobalSearch\RateLimit\RateLimitResult;
use App\Services\GlobalSearch\RateLimit\RedisSearchRateLimiter;
use App\Services\GlobalSearch\RateLimit\SearchRateLimiter;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Predis\Response\ServerException;
use Tests\Feature\GlobalSearch\Fakes\FakeMessagesProvider;

/**
 * fail-closedِ محدودیتِ نرخ — بدونِ Redis (با محدودکنندهٔ ساختگی) و انتخابِ پشتیبان از پیکربندی.
 * هر خطایِ پشتیبان (اتصال، Timeout، احرازِ هویت، پرشدنِ حافظه، Lua، پاسخِ نامعتبر) ← 503ِ کنترل‌شده؛
 * نه مجوزها خوانده می‌شوند، نه ارائه‌دهنده‌ای اجرا می‌شود، نه جزئیاتی افشا می‌شود.
 */
class RateLimitFailClosedTest extends GlobalSearchTestCase
{
    /** @var MessageLogged[] */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeMessagesProvider::reset();
        config(['global_search.providers' => [
            'crm' => ['enabled' => false, 'class' => null],
            'messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class],
            'projects' => ['enabled' => false, 'class' => null],
            'processes' => ['enabled' => false, 'class' => null],
        ]]);
        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e;
        });
    }

    private function limiterThrowing(\Throwable $e): void
    {
        $this->app->instance(SearchRateLimiter::class, new class($e) implements SearchRateLimiter {
            public function __construct(private \Throwable $e)
            {
            }

            public function attempt(string $userKey, int $maxAttempts, int $decaySeconds): RateLimitResult
            {
                throw $this->e;
            }
        });
    }

    public static function backendFailures(): array
    {
        return [
            'connection refused / timeout' => ['connection'],
            'auth failure (WRONGPASS)' => ['wrongpass'],
            'memory full (OOM)' => ['oom'],
            'Lua error' => ['lua'],
            'not configured / invalid reply' => ['unavailable'],
            'any other error' => ['runtime'],
        ];
    }

    private function makeFailure(string $kind): \Throwable
    {
        return match ($kind) {
            'connection' => new ConnectionException($this->createMock(NodeConnectionInterface::class), 'Connection timed out [tcp://127.0.0.1:6390] SECRET-DETAIL'),
            'wrongpass' => new ServerException('WRONGPASS Invalid password SECRET-DETAIL'),
            'oom' => new ServerException('OOM command not allowed when used memory > maxmemory SECRET-DETAIL'),
            'lua' => new ServerException('ERR Error running script SECRET-DETAIL'),
            'unavailable' => new RateLimiterUnavailable('Rate-limit backend is not configured. SECRET-DETAIL'),
            default => new \RuntimeException('boom SECRET-DETAIL'),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backendFailures')]
    public function test_backend_failure_is_fail_closed_with_controlled_503(string $kind): void
    {
        $failure = $this->makeFailure($kind);
        $this->limiterThrowing($failure);

        $r = $this->actingAs($this->fakeUser())->search('پیام')->assertStatus(503);

        $this->assertSame(['message'], array_keys($r->json()));
        $this->assertSame('5', $r->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        foreach (['SECRET-DETAIL', 'Predis', 'WRONGPASS', 'OOM', '6390', 'exception'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase($needle, (string) $r->getContent());
        }
        $this->assertSame(0, $this->access->calls, 'Permissions must not be read when the limiter is down.');
        $this->assertSame([], FakeMessagesProvider::$calls, 'No search may run without a working limiter.');
        $this->assertNoDatabaseQueries();

        $warnings = array_values(array_filter($this->logged, fn ($e) => $e->message === 'global_search.rate_limit_unavailable'));
        $this->assertCount(1, $warnings);
        $this->assertSame(['exception' => $failure::class], $warnings[0]->context, 'Only the exception class is logged.');
    }

    public function test_blocked_result_returns_429_with_retry_after(): void
    {
        $this->app->instance(SearchRateLimiter::class, new class implements SearchRateLimiter {
            public function attempt(string $userKey, int $maxAttempts, int $decaySeconds): RateLimitResult
            {
                return new RateLimitResult(false, $maxAttempts + 1, 7);
            }
        });

        $r = $this->actingAs($this->fakeUser())->search('پیام')->assertStatus(429);
        $this->assertSame('7', $r->headers->get('Retry-After'));
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    public function test_limiter_receives_per_user_key_and_configured_limits(): void
    {
        $spy = new class implements SearchRateLimiter {
            public array $calls = [];

            public function attempt(string $userKey, int $maxAttempts, int $decaySeconds): RateLimitResult
            {
                $this->calls[] = [$userKey, $maxAttempts, $decaySeconds];

                return new RateLimitResult(true, 1, $decaySeconds);
            }
        };
        $this->app->instance(SearchRateLimiter::class, $spy);
        config(['global_search.rate_limit.max_attempts' => 7, 'global_search.rate_limit.decay_seconds' => 11]);

        $this->actingAs($this->fakeUser(901))->search('پیام')->assertOk();
        $this->actingAs($this->fakeUser(902))->search('پیام')->assertOk();

        $this->assertSame([['user:901', 7, 11], ['user:902', 7, 11]], $spy->calls);
    }

    public function test_driver_selection_defaults_to_redis_and_unknown_values_never_weaken_it(): void
    {
        foreach ([null, 'redis', 'REDIS', 'file', 'off', 'none', ''] as $driver) {
            config(['global_search.rate_limit.driver' => $driver]);
            $this->assertInstanceOf(RedisSearchRateLimiter::class, $this->app->make(SearchRateLimiter::class), var_export($driver, true));
        }
        config(['global_search.rate_limit.driver' => 'cache']);
        $this->assertInstanceOf(CacheSearchRateLimiter::class, $this->app->make(SearchRateLimiter::class));

        // پیش‌فرضِ خودِ فایلِ پیکربندی (بدونِ متغیرِ محیطی) = redis
        $saved = [getenv('GLOBAL_SEARCH_RATE_LIMIT_DRIVER'), $_ENV['GLOBAL_SEARCH_RATE_LIMIT_DRIVER'] ?? null, $_SERVER['GLOBAL_SEARCH_RATE_LIMIT_DRIVER'] ?? null];
        putenv('GLOBAL_SEARCH_RATE_LIMIT_DRIVER');
        unset($_ENV['GLOBAL_SEARCH_RATE_LIMIT_DRIVER'], $_SERVER['GLOBAL_SEARCH_RATE_LIMIT_DRIVER']);
        try {
            $fresh = require base_path('config/global_search.php');
            $this->assertSame('redis', $fresh['rate_limit']['driver']);
            $this->assertSame('127.0.0.1', $fresh['rate_limit']['redis']['host']);
        } finally {
            if ($saved[0] !== false) {
                putenv('GLOBAL_SEARCH_RATE_LIMIT_DRIVER=' . $saved[0]);
            }
            if ($saved[1] !== null) {
                $_ENV['GLOBAL_SEARCH_RATE_LIMIT_DRIVER'] = $saved[1];
            }
            if ($saved[2] !== null) {
                $_SERVER['GLOBAL_SEARCH_RATE_LIMIT_DRIVER'] = $saved[2];
            }
        }
    }

    public function test_redis_limiter_without_password_is_unavailable_not_open(): void
    {
        $this->expectException(RateLimiterUnavailable::class);
        (new RedisSearchRateLimiter(['host' => '127.0.0.1', 'port' => 6390, 'password' => null]))->attempt('user:1', 5, 10);
    }

    public function test_redis_limiter_config_is_redacted_in_stack_traces(): void
    {
        $param = (new \ReflectionMethod(RedisSearchRateLimiter::class, '__construct'))->getParameters()[0];
        $this->assertNotEmpty($param->getAttributes(\SensitiveParameter::class));
    }
}
