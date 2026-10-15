<?php

namespace Tests\Feature\GlobalSearch;

use App\Services\GlobalSearch\Contracts\AccessContextResolver;
use App\Services\GlobalSearch\GlobalSearchService;
use App\Services\GlobalSearch\PersianNormalizer;
use App\Services\GlobalSearch\Providers\CrmSearchProvider;
use App\Services\GlobalSearch\Providers\MessageSearchProvider;
use App\Services\GlobalSearch\Providers\ProcessSearchProvider;
use App\Services\GlobalSearch\Providers\ProjectSearchProvider;
use App\Services\GlobalSearch\SearchQuery;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\Feature\GlobalSearch\Fakes\FakeFailingProvider;
use Tests\Feature\GlobalSearch\Fakes\FakeMessagesProvider;

/**
 * یافتهٔ ۷ ممیزی — مسیرِ POST: متنِ جست‌وجو در هیچ مسیری (JSON، فرمِ عادی، خطایِ اعتبارسنجی،
 * خطایِ CSRF، کاربرِ مهمان، پارامترِ ردشده در URL، خطایِ پیش‌بینی‌نشدهٔ 500) در session یا لاگِ برنامه
 * (پیام، context و رشتهٔ کاملِ استثنا با stack trace) ثبت نمی‌شود و در هیچ URLِ پاسخی نمی‌آید.
 */
class GlobalSearchSessionLogPrivacyTest extends GlobalSearchTestCase
{
    private const MARKER = 'gssessionlogmarker';

    /** @var MessageLogged[] */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        FakeMessagesProvider::reset();
        FakeFailingProvider::reset();
        config(['global_search.providers' => [
            'crm' => ['enabled' => false, 'class' => null],
            'messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class],
            'projects' => ['enabled' => true, 'class' => FakeFailingProvider::class], // یک هشدارِ لاگ در هر جست‌وجو
            'processes' => ['enabled' => false, 'class' => null],
        ]]);

        $this->logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e;
        });
    }

    private function enforceCsrf(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });
    }

    /** متنِ کاملِ هر رکوردِ لاگ: پیام + context + رشتهٔ استثنا (شاملِ stack trace با آرگومان‌ها) */
    private function loggedText(): string
    {
        $out = '';
        foreach ($this->logged as $e) {
            $context = $e->context;
            $exception = $context['exception'] ?? null;
            unset($context['exception']);
            $out .= $e->level . ' ' . $e->message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($exception instanceof \Throwable) {
                $out .= ' ' . $exception . ' ' . $exception->getTraceAsString();
            }
            $out .= "\n";
        }

        return $out;
    }

    private function assertMarkerStoredNowhere(array $responses = []): void
    {
        $session = $this->app['session.store']->all();
        $this->assertStringNotContainsString(self::MARKER, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), 'session');
        $this->assertArrayNotHasKey('_old_input', $session, 'input must never be flashed');
        $this->assertStringNotContainsString(self::MARKER, $this->loggedText(), 'application log');

        foreach ($responses as $r) {
            foreach (['Location', 'Refresh', 'Link'] as $h) {
                $this->assertStringNotContainsString(self::MARKER, (string) $r->headers->get($h), $h);
            }
        }
    }

    public function test_successful_json_and_form_requests(): void
    {
        $responses = [
            $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => self::MARKER])->assertOk(),
            $this->actingAs($this->fakeUser())->post('/global-search', ['q' => self::MARKER . ' فرم'])->assertOk(),
        ];

        $this->assertNotEmpty($this->logged, 'The failing fake provider must have produced a log record to inspect.');
        $this->assertMarkerStoredNowhere($responses);
    }

    public function test_validation_errors_json_and_form(): void
    {
        $bad = [
            self::MARKER . "\u{202E}",                     // جهت‌دهیِ ممنوع
            self::MARKER . ' a b c d e f',                  // بیش از ۶ کلمه
            self::MARKER . "\xFF",                          // UTF-8 نامعتبر
            str_repeat(self::MARKER, 15),                    // بیش از حدِ طول
        ];
        $responses = [];
        foreach ($bad as $q) {
            $responses[] = $this->actingAs($this->fakeUser())->post('/global-search', ['q' => $q], ['Accept' => 'application/json'])->assertStatus(422);
            $responses[] = $this->actingAs($this->fakeUser())->post('/global-search', ['q' => $q])->assertStatus(422); // فرمِ عادی: باز هم JSON، بدونِ redirect
        }
        $responses[] = $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => self::MARKER, 'groups' => 'secret'])->assertStatus(422);

        foreach ($responses as $r) {
            $this->assertNull($r->headers->get('Location'), 'Validation errors must not redirect back with input.');
        }
        $this->assertSame([], FakeMessagesProvider::$calls);
        $this->assertMarkerStoredNowhere($responses);
    }

    public function test_csrf_failure_json_and_form(): void
    {
        $this->enforceCsrf();

        $responses = [
            $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => self::MARKER])->assertStatus(419),
            $this->actingAs($this->fakeUser())->post('/global-search', ['q' => self::MARKER])->assertStatus(419),
        ];

        foreach ($responses as $r) {
            $this->assertStringNotContainsString(self::MARKER, (string) $r->getContent());
        }
        $this->assertSame([], FakeMessagesProvider::$calls);
        $this->assertMarkerStoredNowhere($responses);
    }

    public function test_guest_json_and_form(): void
    {
        $responses = [
            $this->postJson('/global-search', ['q' => self::MARKER])->assertStatus(401),
            $this->post('/global-search', ['q' => self::MARKER])->assertRedirect(), // ← /login
        ];

        $this->assertStringNotContainsString(self::MARKER, (string) $this->app['session.store']->get('url.intended'));
        $this->assertSame(0, $this->access->calls);
        $this->assertMarkerStoredNowhere($responses);
    }

    public function test_rejected_url_parameter_is_not_kept_server_side(): void
    {
        $responses = [
            $this->actingAs($this->fakeUser())->postJson('/global-search?q=' . self::MARKER, ['q' => 'پیام'])->assertStatus(422),
            $this->actingAs($this->fakeUser())->get('/global-search?q=' . self::MARKER)->assertStatus(405),
        ];

        $this->assertMarkerStoredNowhere($responses);
    }

    public function test_unexpected_500_does_not_log_or_return_the_search_text(): void
    {
        $this->app->instance(AccessContextResolver::class, new class implements AccessContextResolver {
            public function resolve(Authenticatable $user): \App\Services\GlobalSearch\AccessContext
            {
                throw new \RuntimeException('permission store unavailable');
            }
        });

        $response = $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => self::MARKER])->assertStatus(500);

        $this->assertStringContainsString('permission store unavailable', $this->loggedText(), 'The 500 must have been reported, so the check is not vacuous.');
        $this->assertStringNotContainsString(self::MARKER, (string) $response->getContent());
        $this->assertMarkerStoredNowhere([$response]);
    }

    public function test_every_parameter_that_carries_search_text_is_redacted_in_stack_traces(): void
    {
        $sensitive = [
            [GlobalSearchService::class, 'buildQuery', ['raw']],
            [PersianNormalizer::class, 'normalize', ['input']],
            [PersianNormalizer::class, 'tokenize', ['normalized']],
            [PersianNormalizer::class, 'escapeLike', ['value']],
            [SearchQuery::class, '__construct', ['raw', 'normalized', 'tokens']],
            [CrmSearchProvider::class, 'execute', ['bindings']],
            [MessageSearchProvider::class, 'execute', ['bindings']],
            [ProjectSearchProvider::class, 'execute', ['bindings']],
            [ProcessSearchProvider::class, 'execute', ['bindings']],
        ];

        foreach ($sensitive as [$class, $method, $params]) {
            foreach ((new \ReflectionMethod($class, $method))->getParameters() as $p) {
                if (in_array($p->getName(), $params, true)) {
                    $this->assertNotEmpty($p->getAttributes(\SensitiveParameter::class), "{$class}::{$method}(\${$p->getName()}) must be #[\\SensitiveParameter]");
                }
            }
        }
    }
}
