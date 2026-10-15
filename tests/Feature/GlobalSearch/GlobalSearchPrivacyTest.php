<?php

namespace Tests\Feature\GlobalSearch;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\PersianNormalizer;
use App\Services\GlobalSearch\Providers\CrmSearchProvider;
use App\Services\GlobalSearch\Providers\MessageSearchProvider;
use App\Services\GlobalSearch\Providers\ProcessSearchProvider;
use App\Services\GlobalSearch\Providers\ProjectSearchProvider;
use App\Services\GlobalSearch\SearchQuery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Feature\GlobalSearch\Fakes\FakeMessagesProvider;

/**
 * رگرسیونِ یافته‌هایِ ممیزی (بدونِ دیتابیس، با ارائه‌دهنده‌هایِ ساختگی):
 *   یافتهٔ ۱ — متنِ جست‌وجو در URL، لینکِ «بیشتر»، session و بدونِ CSRF پذیرفته نمی‌شود.
 *   یافتهٔ ۳ — UTF-8 نامعتبر پیش از هر regex ← 422 (نه 500 و نه جزئیاتِ استثنا).
 */
class GlobalSearchPrivacyTest extends GlobalSearchTestCase
{
    private const MARKER = 'gsprivacymarker';

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
    }

    // ---------- یافتهٔ ۱: URL، session، لینکِ «بیشتر»، CSRF ----------

    public function test_get_with_search_text_in_url_is_rejected_before_anything_runs(): void
    {
        $this->actingAs($this->fakeUser())->getJson('/global-search?q=' . self::MARKER)->assertStatus(405);
        $this->actingAs($this->fakeUser())->get('/global-search?q=' . self::MARKER)->assertStatus(405);

        $this->assertSame(0, $this->access->calls);
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    public function test_url_parameters_on_post_are_rejected(): void
    {
        $this->actingAs($this->fakeUser())->postJson('/global-search?q=' . self::MARKER, ['q' => 'پیام'])
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->actingAs($this->fakeUser())->postJson('/global-search?groups=messages', ['q' => 'پیام'])
            ->assertStatus(422);

        $this->assertSame(0, $this->access->calls);
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    public function test_search_text_is_not_stored_in_session(): void
    {
        // درخواستِ فرمِ عادی (بدونِ X-Requested-With) و JSON؛ هر دو نباید متن را در session نگه دارند
        $this->actingAs($this->fakeUser())->post('/global-search', ['q' => self::MARKER])->assertOk();
        $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => self::MARKER . ' دوم'])->assertOk();

        $session = $this->app['session.store']->all();
        $this->assertStringNotContainsString(self::MARKER, json_encode($session, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $this->assertStringNotContainsString(self::MARKER, (string) ($session['_previous']['url'] ?? ''));
        $this->assertArrayNotHasKey('url', $session); // url.intended
    }

    public function test_response_urls_never_contain_search_text(): void
    {
        $response = $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => self::MARKER])->assertOk();

        foreach ($response->json('groups') as $group) {
            $this->assertStringNotContainsString(self::MARKER, (string) $group['moreUrl']);
            $this->assertStringNotContainsString('?', (string) $group['moreUrl']);
            foreach ($group['items'] as $item) {
                $this->assertStringNotContainsString(self::MARKER, $item['url']);
            }
        }
        $this->assertNull($response->headers->get('Location'));
    }

    public function test_real_providers_build_more_urls_without_search_text(): void
    {
        $normalizer = new PersianNormalizer();
        $query = new SearchQuery(self::MARKER, self::MARKER, [self::MARKER]);
        $access = new AccessContext(501, true, ['CRM_VIEW', 'WORKFLOW_VIEW']);

        // فقط execute (دسترسی به دیتابیس) خنثی می‌شود؛ ساختِ نتیجه همان کدِ واقعی است
        $providers = [
            '/crm/parties' => new class($normalizer) extends CrmSearchProvider {
                protected function execute(string $sql, array $bindings, int $timeoutSeconds): array { return []; }
            },
            '/messages' => new class($normalizer) extends MessageSearchProvider {
                protected function execute(string $sql, array $bindings, int $timeoutSeconds): array { return []; }
            },
            '/projects' => new class($normalizer) extends ProjectSearchProvider {
                protected function execute(string $sql, array $bindings, int $timeoutSeconds): array { return []; }
            },
            '/process/instances' => new class($normalizer) extends ProcessSearchProvider {
                protected function execute(string $sql, array $bindings, int $timeoutSeconds): array { return []; }
            },
        ];
        foreach ($providers as $url => $provider) {
            $this->assertSame($url, $provider->search($query, $access, 5, 1)->moreUrl, $provider->key());
        }
    }

    public function test_csrf_token_is_required_when_enforced(): void
    {
        // در آزمون‌ها لاراول CSRF را خودکار رد می‌کند؛ اینجا میان‌افزارِ واقعی را اجباری می‌کنیم
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        $denied = $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => self::MARKER])->assertStatus(419);
        $this->assertStringNotContainsString(self::MARKER, (string) $denied->getContent());
        $this->assertSame(0, $this->access->calls);
        $this->assertSame([], FakeMessagesProvider::$calls);

        $this->actingAs($this->fakeUser())
            ->withSession(['_token' => 'gs-test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'gs-test-csrf-token')
            ->postJson('/global-search', ['q' => 'پیام'])
            ->assertOk();
    }

    public function test_route_is_in_web_group_and_not_excluded_from_csrf(): void
    {
        $route = app('router')->getRoutes()->getByName('global-search');

        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains(ValidateCsrfToken::class, $route->excludedMiddleware());
        $this->assertNotContains(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, $route->excludedMiddleware());
    }

    // ---------- یافتهٔ ۳: UTF-8 نامعتبر ----------

    private function assertCleanValidationError($response, string $field): void
    {
        $response->assertStatus(422)->assertJsonValidationErrors([$field]);
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
        $this->assertTrue(mb_check_encoding((string) $response->getContent(), 'UTF-8'));
        $this->assertStringNotContainsString('exception', strtolower((string) $response->getContent()));
    }

    public function test_invalid_utf8_query_is_422_not_500(): void
    {
        $response = $this->actingAs($this->fakeUser())->post('/global-search', ['q' => "ab\xFFcd"], ['Accept' => 'application/json']);

        $this->assertCleanValidationError($response, 'q');
        $this->assertSame(0, $this->access->calls);
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    public function test_invalid_utf8_cannot_smuggle_a_bidi_override(): void
    {
        // قبلاً preg_match رویِ UTF-8 نامعتبر false می‌داد و not_regex را دور می‌زد
        $response = $this->actingAs($this->fakeUser())->post('/global-search', ['q' => "ab\xFF\u{202E}cd"], ['Accept' => 'application/json']);

        $this->assertCleanValidationError($response, 'q');
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    public function test_invalid_utf8_groups_is_422(): void
    {
        $response = $this->actingAs($this->fakeUser())->post('/global-search', ['q' => 'پیام', 'groups' => "messages\xC3"], ['Accept' => 'application/json']);

        $this->assertCleanValidationError($response, 'groups');
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    public function test_invalid_utf8_inside_json_body_is_422(): void
    {
        $response = $this->actingAs($this->fakeUser())->call('POST', '/global-search', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], '{"q":"ab' . "\xFF" . 'cd"}');

        $this->assertCleanValidationError($response, 'q');
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    public function test_valid_input_normalization_and_bidi_rule_are_unchanged(): void
    {
        $this->actingAs($this->fakeUser())->search('كتاب  ۱۲۳')
            ->assertOk()->assertJsonPath('normalized', 'کتاب 123')->assertJsonPath('query', 'كتاب  ۱۲۳');
        $this->actingAs($this->fakeUser())->search("abc\u{202E}def")
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->actingAs($this->fakeUser())->search("abc\u{2067}def")
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }
}
