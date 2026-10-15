<?php

namespace Tests\Feature\GlobalSearch;

use Tests\Feature\GlobalSearch\Fakes\FakeCrmProvider;
use Tests\Feature\GlobalSearch\Fakes\FakeFailingProvider;
use Tests\Feature\GlobalSearch\Fakes\FakeMessagesProvider;

/**
 * قراردادِ API مرحلهٔ ۱ (§3، §7، §8 مشخصات) — بدونِ دیتابیس و بدونِ ارائه‌دهندهٔ واقعی.
 */
class GlobalSearchEndpointTest extends GlobalSearchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeCrmProvider::reset();
        FakeMessagesProvider::reset();
        FakeFailingProvider::reset();
    }

    private function useProviders(array $map): void
    {
        $providers = [
            'crm' => ['enabled' => false, 'class' => null],
            'messages' => ['enabled' => false, 'class' => null],
            'projects' => ['enabled' => false, 'class' => null],
            'processes' => ['enabled' => false, 'class' => null],
        ];
        foreach ($map as $key => $class) {
            $providers[$key] = ['enabled' => true, 'class' => $class];
        }
        config(['global_search.providers' => $providers]);
    }

    // ---------- احراز هویت و فلگ ----------

    public function test_guest_gets_401(): void
    {
        $this->search('پیام')->assertStatus(401);
        $this->assertNoDatabaseQueries();
    }

    public function test_disabled_flag_returns_404_for_authenticated_user(): void
    {
        config(['global_search.enabled' => false]);

        $this->actingAs($this->fakeUser())->search('پیام')->assertStatus(404);
        $this->assertSame(0, $this->access->calls);
        $this->assertNoDatabaseQueries();
    }

    public function test_route_is_post_only(): void
    {
        $this->actingAs($this->fakeUser())->getJson('/global-search?q=' . rawurlencode('پیام'))->assertStatus(405);
        $this->actingAs($this->fakeUser())->getJson('/global-search')->assertStatus(405);
        $this->assertSame(0, $this->access->calls);
    }

    // ---------- پاسخِ موفق و هدرها ----------

    public function test_success_returns_contract_shape_and_no_store_headers(): void
    {
        $response = $this->actingAs($this->fakeUser())->search('  پيام  جديد ');

        $response->assertOk()
            ->assertExactJson([
                // TrimStrings (میان‌افزارِ سراسریِ لاراول) دو سرِ ورودی را Trim می‌کند؛ فاصلهٔ میانی حفظ می‌شود
                'query' => 'پيام  جديد',
                'normalized' => 'پیام جدید',
                'groups' => [],
                'complete' => true,
            ]);

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
        $this->assertStringContainsString('private', $cache);
        $this->assertSame('Cookie', $response->headers->get('Vary'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertSame(1, $this->access->calls);
        $this->assertNoDatabaseQueries();
    }

    public function test_phase1_default_providers_return_empty_groups(): void
    {
        // پیکربندیِ پیش‌فرضِ مرحلهٔ ۱: هیچ ارائه‌دهندهٔ واقعی روشن نیست
        $this->actingAs($this->fakeUser())->search('فروش')->assertOk()->assertJsonPath('groups', []);
    }

    // ---------- اعتبارسنجی ----------

    public function test_missing_query_is_422(): void
    {
        $this->actingAs($this->fakeUser())->postJson('/global-search')
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->assertSame(0, $this->access->calls);
    }

    public function test_query_shorter_than_minimum_after_normalization_is_422(): void
    {
        // «ـ» (کشیده) و فاصله حذف می‌شوند ← طولِ نرمال‌شده 1
        $this->actingAs($this->fakeUser())->search(' ـ آ ـ ')
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_raw_query_longer_than_limit_is_422(): void
    {
        $this->actingAs($this->fakeUser())->search(str_repeat('a', 201))
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_normalized_query_longer_than_limit_is_422(): void
    {
        $this->actingAs($this->fakeUser())->search(str_repeat('a', 101))
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_too_many_tokens_is_422(): void
    {
        $this->actingAs($this->fakeUser())->search('a1 b2 c3 d4 e5 f6 g7')
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_bidi_override_and_control_characters_are_rejected(): void
    {
        $this->actingAs($this->fakeUser())->search("abc\u{202E}def")
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->actingAs($this->fakeUser())->search("abc\u{0007}def")
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_array_query_parameter_is_422(): void
    {
        $this->actingAs($this->fakeUser())->postJson('/global-search', ['q' => ['abc', 'def']])
            ->assertStatus(422)->assertJsonValidationErrors(['q']);
    }

    public function test_unknown_group_is_422(): void
    {
        $this->actingAs($this->fakeUser())->search('پیام', ['groups' => 'crm,secret'])
            ->assertStatus(422)->assertJsonValidationErrors(['groups']);
    }

    public function test_like_wildcards_are_kept_as_text_not_rejected(): void
    {
        $this->actingAs($this->fakeUser())->search('50%_[x]')->assertOk()->assertJsonPath('normalized', '50%_[x]');
    }

    // ---------- کاربرِ غیرفعال ----------

    public function test_inactive_user_gets_403_and_no_provider_runs(): void
    {
        $this->useProviders(['messages' => FakeMessagesProvider::class]);
        $this->access->isActive = false;

        $this->actingAs($this->fakeUser(502, false))->search('پیام')
            ->assertStatus(403)->assertJsonMissingPath('groups');
        $this->assertSame([], FakeMessagesProvider::$calls);
    }

    // ---------- دروازهٔ مجوز و جلوگیری از افشا ----------

    public function test_group_without_permission_is_absent_not_empty(): void
    {
        $this->useProviders(['crm' => FakeCrmProvider::class, 'messages' => FakeMessagesProvider::class]);
        $this->access->permissions = []; // بدونِ CRM_VIEW

        $response = $this->actingAs($this->fakeUser())->search('مشتری')->assertOk();

        $keys = array_column($response->json('groups'), 'key');
        $this->assertSame(['messages'], $keys);
        $this->assertSame([], FakeCrmProvider::$calls, 'Provider must not be called without permission.');
        $this->assertStringNotContainsString('crm', $response->getContent());
    }

    public function test_group_with_permission_is_present_with_item_shape(): void
    {
        $this->useProviders(['crm' => FakeCrmProvider::class]);
        $this->access->permissions = ['CRM_VIEW'];

        $response = $this->actingAs($this->fakeUser(777))->search('مشتري')->assertOk();

        $response->assertJsonPath('groups.0.key', 'crm')
            ->assertJsonPath('groups.0.items.0.badges', ['غیرفعال'])
            ->assertJsonStructure(['groups' => [['key', 'label', 'items' => [['type', 'id', 'title', 'subtitle', 'url', 'badges', 'match']], 'hasMore', 'moreUrl']]]);
        $this->assertSame('مشتری', FakeCrmProvider::$calls[0]['normalized']);
        $this->assertSame(777, FakeCrmProvider::$calls[0]['userId']);
    }

    // ---------- سقف‌ها ----------

    public function test_per_group_limit_and_has_more(): void
    {
        $this->useProviders(['messages' => FakeMessagesProvider::class]);
        FakeMessagesProvider::reset(7);

        $response = $this->actingAs($this->fakeUser())->search('پیام')->assertOk();

        $this->assertCount(5, $response->json('groups.0.items'));
        $this->assertTrue($response->json('groups.0.hasMore'));
        $this->assertSame(5, FakeMessagesProvider::$calls[0]['limit']);
    }

    public function test_total_limit_across_groups(): void
    {
        $this->useProviders(['crm' => FakeCrmProvider::class, 'messages' => FakeMessagesProvider::class]);
        $this->access->permissions = ['CRM_VIEW'];
        FakeCrmProvider::reset(5);
        FakeMessagesProvider::reset(5);
        config(['global_search.limits.total' => 6]);

        $response = $this->actingAs($this->fakeUser())->search('پیام')->assertOk();

        $this->assertCount(5, $response->json('groups.0.items'));
        $this->assertCount(1, $response->json('groups.1.items'));
        $this->assertSame(1, FakeMessagesProvider::$calls[0]['limit']);
    }

    public function test_groups_parameter_filters_providers(): void
    {
        $this->useProviders(['crm' => FakeCrmProvider::class, 'messages' => FakeMessagesProvider::class]);
        $this->access->permissions = ['CRM_VIEW'];

        $response = $this->actingAs($this->fakeUser())->search('پیام', ['groups' => 'messages'])->assertOk();

        $this->assertSame(['messages'], array_column($response->json('groups'), 'key'));
        $this->assertSame([], FakeCrmProvider::$calls);
    }

    public function test_failing_provider_is_isolated_and_hides_details(): void
    {
        $this->useProviders(['messages' => FakeMessagesProvider::class, 'projects' => FakeFailingProvider::class]);

        $response = $this->actingAs($this->fakeUser())->search('پیام')->assertOk();

        $response->assertJsonPath('complete', false);
        $this->assertSame(['messages'], array_column($response->json('groups'), 'key'));
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('dbo.', $response->getContent());
    }

    // ---------- محدودیتِ نرخ ----------

    public function test_rate_limit_returns_429_with_retry_after_and_no_store(): void
    {
        config(['global_search.rate_limit.max_attempts' => 3, 'global_search.rate_limit.decay_seconds' => 10]);
        $user = $this->fakeUser(901);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($user)->search('پیام')->assertOk();
        }
        $blocked = $this->actingAs($user)->search('پیام')->assertStatus(429);

        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertStringContainsString('no-store', (string) $blocked->headers->get('Cache-Control'));

        // کاربرِ دیگر محدود نمی‌شود (کلید به‌ازایِ هر کاربر)
        $this->actingAs($this->fakeUser(902))->search('پیام')->assertOk();
        $this->assertNoDatabaseQueries();
    }

    public function test_rate_limit_runs_after_flag_check(): void
    {
        config(['global_search.enabled' => false, 'global_search.rate_limit.max_attempts' => 1]);
        $user = $this->fakeUser(903);

        $this->actingAs($user)->search('پیام')->assertStatus(404);
        $this->actingAs($user)->search('پیام')->assertStatus(404); // نه 429
    }
}
