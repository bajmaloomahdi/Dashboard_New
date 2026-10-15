<?php

namespace Tests\Feature\GlobalSearch;

use App\Services\GlobalSearch\AccessContext;
use App\Services\GlobalSearch\GlobalSearchService;
use Tests\Feature\GlobalSearch\Fakes\FakeCrmProvider;
use Tests\Feature\GlobalSearch\Fakes\FakeMessagesProvider;

/** سرویس مستقل از HTTP: ترتیبِ گروه‌ها، ارائه‌دهندهٔ نامعتبر، گروهِ خالی، بودجهٔ زمانی. */
class GlobalSearchServiceTest extends GlobalSearchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeCrmProvider::reset();
        FakeMessagesProvider::reset();
    }

    private function service(): GlobalSearchService
    {
        return $this->app->make(GlobalSearchService::class);
    }

    public function test_groups_follow_config_order(): void
    {
        config(['global_search.providers' => [
            'messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class],
            'crm' => ['enabled' => true, 'class' => FakeCrmProvider::class],
        ]]);
        $svc = $this->service();

        $result = $svc->search(new AccessContext(1, true, ['CRM_VIEW']), $svc->buildQuery('پیام'));

        $this->assertSame(['messages', 'crm'], array_column($result['groups'], 'key'));
        $this->assertTrue($result['complete']);
    }

    public function test_disabled_or_unimplemented_providers_are_skipped(): void
    {
        config(['global_search.providers' => [
            'crm' => ['enabled' => false, 'class' => FakeCrmProvider::class],
            'messages' => ['enabled' => true, 'class' => null],
        ]]);
        $svc = $this->service();

        $result = $svc->search(new AccessContext(1, true, ['CRM_VIEW']), $svc->buildQuery('پیام'));

        $this->assertSame([], $result['groups']);
        $this->assertSame([], FakeCrmProvider::$calls);
    }

    public function test_class_not_implementing_contract_is_ignored(): void
    {
        config(['global_search.providers' => [
            'crm' => ['enabled' => true, 'class' => \stdClass::class],
            'messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class],
        ]]);
        $svc = $this->service();

        $result = $svc->search(new AccessContext(1, true, []), $svc->buildQuery('پیام'));

        $this->assertSame(['messages'], array_column($result['groups'], 'key'));
    }

    public function test_empty_group_is_omitted(): void
    {
        config(['global_search.providers' => ['messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class]]]);
        FakeMessagesProvider::reset(0);
        $svc = $this->service();

        $this->assertSame([], $svc->search(new AccessContext(1, true, []), $svc->buildQuery('پیام'))['groups']);
    }

    public function test_exhausted_time_budget_stops_further_groups_and_marks_incomplete(): void
    {
        config([
            'global_search.limits.time_budget_ms' => 1,
            'global_search.providers' => [
                'messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class],
                'crm' => ['enabled' => true, 'class' => FakeCrmProvider::class],
            ],
        ]);
        FakeMessagesProvider::reset(3, 20000); // گروهِ اول ۲۰ میلی‌ثانیه طول می‌کشد > بودجهٔ ۱ میلی‌ثانیه
        $svc = $this->service();

        $result = $svc->search(new AccessContext(1, true, ['CRM_VIEW']), $svc->buildQuery('پیام'));

        $this->assertSame(['messages'], array_column($result['groups'], 'key'));
        $this->assertSame([], FakeCrmProvider::$calls, 'Groups after the budget must not run.');
        $this->assertFalse($result['complete']);
        $this->assertNoDatabaseQueries();
    }

    public function test_first_provider_gets_the_remaining_budget_rounded_up_to_seconds(): void
    {
        config([
            'global_search.limits.time_budget_ms' => 1500,
            'global_search.providers' => ['messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class]],
        ]);
        $svc = $this->service();

        $svc->search(new AccessContext(1, true, []), $svc->buildQuery('پیام'));

        $this->assertSame(2, FakeMessagesProvider::$calls[0]['timeout']); // ceil(≈1.5s) — نه بودجهٔ جداگانه
    }

    public function test_budget_is_shared_later_providers_get_only_what_is_left(): void
    {
        config([
            'global_search.limits.time_budget_ms' => 2000,
            'global_search.providers' => [
                'messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class],
                'crm' => ['enabled' => true, 'class' => FakeCrmProvider::class],
            ],
        ]);
        FakeMessagesProvider::reset(3, 1_100_000); // ۱٫۱ ثانیه از ۲ ثانیه مصرف می‌شود
        $svc = $this->service();

        $result = $svc->search(new AccessContext(1, true, ['CRM_VIEW']), $svc->buildQuery('پیام'));

        $this->assertSame(2, FakeMessagesProvider::$calls[0]['timeout']);
        $this->assertSame(1, FakeCrmProvider::$calls[0]['timeout'], 'Second provider must only get the ~0.9s left (rounded up).');
        $this->assertTrue($result['complete']);
    }

    public function test_timeout_is_never_zero_because_zero_means_unlimited_in_the_driver(): void
    {
        config([
            'global_search.limits.time_budget_ms' => 200,
            'global_search.providers' => ['messages' => ['enabled' => true, 'class' => FakeMessagesProvider::class]],
        ]);
        $svc = $this->service();

        $svc->search(new AccessContext(1, true, []), $svc->buildQuery('پیام'));

        $this->assertCount(1, FakeMessagesProvider::$calls);
        $this->assertSame(1, FakeMessagesProvider::$calls[0]['timeout']); // ceil(<0.2s) = 1، هرگز 0
    }

    public function test_known_group_keys_come_from_config(): void
    {
        $this->assertSame(['crm', 'messages', 'projects', 'processes'], $this->service()->knownGroupKeys());
    }
}
