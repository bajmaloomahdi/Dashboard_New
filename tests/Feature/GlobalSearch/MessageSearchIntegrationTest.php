<?php

namespace Tests\Feature\GlobalSearch;

use App\Models\User;
use App\Services\GlobalSearch\Providers\CrmSearchProvider;
use App\Services\GlobalSearch\Providers\MessageSearchProvider;
use App\Services\Message\MessageAccessChecker;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\GlobalSearch\Fakes\BrokenMessageSearchProvider;
use Tests\TestCase;

/**
 * آزمونِ یکپارچهٔ گروهِ نامه‌ها/وظایف رویِ LocalDB مستقل با دادهٔ مصنوعی (فقط خواندنی).
 *
 * دادهٔ seeder (GlobalSearchBusinessTestSeeder):
 *   M1 INF-1405-900001  U1→U3، رونوشت U2                       ← U1, U2, U3
 *   M2 TSK-1405-900002  وظیفه: U2→U5، ارجاعِ U5→U3              ← U2, U3, U5
 *   M3 INF-1405-900003  خصوصی U4→U6                              ← U4, U6   (U1 مدیر نمی‌بیند)
 *   M4 (بدونِ شماره)     U3→U1                                    ← U1, U3
 *   M5 INF-1405-900005  «ي» عربی، U1→U2، رونوشت U7 (غیرفعال)      ← U1, U2  (U7 ← 403)
 */
class MessageSearchIntegrationTest extends TestCase
{
    /** @var array<string,User> */
    private array $users = [];

    /** @var array<string,int> */
    private array $msg = [];

    private const EXPECTED = [
        'M1' => ['U1', 'U2', 'U3'],
        'M2' => ['U2', 'U3', 'U5'],
        'M3' => ['U4', 'U6'],
        'M4' => ['U1', 'U3'],
        'M5' => ['U1', 'U2'],
    ];

    /** پرس‌وجویِ یکتایِ هر نامه (شمارهٔ دقیق؛ M4 شماره ندارد ← موضوعِ کامل) */
    private const QUERIES = [
        'M1' => 'INF-1405-900001',
        'M2' => 'TSK-1405-900002',
        'M3' => 'INF-1405-900003',
        'M4' => 'پیام بدون شماره آزمایشی',
        'M5' => 'INF-1405-900005',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (strcasecmp((string) config('database.connections.sqlsrv.host'), '(localdb)\GsTest') !== 0
            || config('database.connections.sqlsrv.database') !== 'Dashboard_GsTest') {
            $this->fail('SAFETY: message integration tests must run only against (localdb)\GsTest / Dashboard_GsTest. Aborted.');
        }

        config([
            'global_search.enabled' => true,
            'global_search.providers.messages.enabled' => true,
            'global_search.providers.messages.class' => MessageSearchProvider::class,
            'global_search.providers.crm.enabled' => false,
            'global_search.rate_limit.store' => 'array',
            'global_search.rate_limit.max_attempts' => 1000,
        ]);

        foreach (['U1' => 'gs_u1_admin', 'U2' => 'gs_u2_crm', 'U3' => 'gs_u3_staff', 'U4' => 'gs_u4_process',
                  'U5' => 'gs_u5_forward', 'U6' => 'gs_u6_member', 'U7' => 'gs_u7_disabled'] as $k => $name) {
            $user = User::where('UserName', $name)->first();
            if (! $user) {
                $this->markTestSkipped("Synthetic user {$name} missing - run GlobalSearchTestSeeder first.");
            }
            $this->users[$k] = $user;
        }

        foreach (['M1' => 'INF-1405-900001', 'M2' => 'TSK-1405-900002', 'M3' => 'INF-1405-900003', 'M5' => 'INF-1405-900005'] as $k => $num) {
            $this->msg[$k] = (int) DB::table('Messages')->where('MessageNumber', $num)->value('MessageID');
        }
        $this->msg['M4'] = (int) DB::table('Messages')->whereNull('MessageNumber')->where('Subject', 'پیام بدون شماره آزمایشی')->value('MessageID');
        if (in_array(0, $this->msg, true)) {
            $this->markTestSkipped('Synthetic messages missing - run GlobalSearchBusinessTestSeeder first.');
        }
    }

    private function search(string $user, string $q): TestResponse
    {
        return $this->actingAs($this->users[$user])->postJson('/global-search', ['q' => $q]);
    }

    /** @return array<int,array<string,mixed>> */
    private function group(TestResponse $r, string $key = 'messages'): ?array
    {
        foreach ((array) $r->json('groups') as $g) {
            if (($g['key'] ?? null) === $key) {
                return $g;
            }
        }

        return null;
    }

    /** @return int[] */
    private function ids(TestResponse $r): array
    {
        return array_map(fn ($i) => (int) $i['id'], $this->group($r)['items'] ?? []);
    }

    // ================= ماتریسِ کامل U1..U6 × M1..M5 =================

    public function test_visibility_matrix_matches_message_page_rule(): void
    {
        $checker = new MessageAccessChecker();

        foreach (self::QUERIES as $m => $q) {
            foreach (['U1', 'U2', 'U3', 'U4', 'U5', 'U6'] as $u) {
                $r = $this->search($u, $q)->assertOk();
                $expected = in_array($u, self::EXPECTED[$m], true);
                $found = in_array($this->msg[$m], $this->ids($r), true);

                $this->assertSame($expected, $found, "{$u} / {$m}");
                // ناوردا: همان قاعدهٔ صفحهٔ نامه
                $this->assertSame($expected, $checker->isParticipant($this->msg[$m], (int) $this->users[$u]->UserID), "checker {$u} / {$m}");

                if (! $expected) {
                    // هیچ ردی: نه گروه، نه عنوان، نه لینک
                    $this->assertNull($this->group($r), "{$u} must get no messages group for {$m}");
                    $this->assertStringNotContainsString('/messages/' . $this->msg[$m], (string) $r->getContent());
                }
            }
        }
    }

    public function test_every_returned_item_passes_the_page_access_rule(): void
    {
        $checker = new MessageAccessChecker();
        foreach (['U1', 'U2', 'U3', 'U4', 'U5', 'U6'] as $u) {
            foreach ($this->group($this->search($u, 'INF')->assertOk())['items'] ?? [] as $item) {
                $this->assertTrue($checker->isParticipant((int) $item['id'], (int) $this->users[$u]->UserID), "{$u} got message {$item['id']}");
                $this->assertSame('/messages/' . $item['id'], $item['url']);
            }
        }
    }

    // ================= سناریوهایِ نقش در پیام =================

    public function test_sender_current_previous_recipient_and_copy(): void
    {
        $this->assertContains($this->msg['M1'], $this->ids($this->search('U1', 'بودجه')->assertOk()), 'sender');
        $this->assertContains($this->msg['M1'], $this->ids($this->search('U3', 'بودجه')->assertOk()), 'recipient');
        $this->assertContains($this->msg['M1'], $this->ids($this->search('U2', 'بودجه')->assertOk()), 'copy recipient');
        $this->assertContains($this->msg['M2'], $this->ids($this->search('U3', 'بررسی قرارداد')->assertOk()), 'current recipient after forward');
        $this->assertContains($this->msg['M2'], $this->ids($this->search('U5', 'بررسی قرارداد')->assertOk()), 'earlier recipient (forwarded on)');
    }

    public function test_admin_role_does_not_grant_access_to_private_message(): void
    {
        $r = $this->search('U1', 'پیام خصوصی')->assertOk();

        $this->assertNotContains($this->msg['M3'], $this->ids($r));
        // نه عنوان (پرس‌وجو فقط «پیام خصوصی» است) و نه لینکِ M3 در پاسخ
        $this->assertStringNotContainsString('خصوصی هماهنگی', (string) $r->getContent());
        $this->assertStringNotContainsString('/messages/' . $this->msg['M3'], (string) $r->getContent());
    }

    public function test_deactivated_user_gets_403_even_as_copy_recipient(): void
    {
        $this->search('U7', 'INF-1405-900005')->assertStatus(403)->assertJsonMissingPath('groups');
    }

    public function test_stored_procedure_enforces_rule_independently_of_php(): void
    {
        $exec = fn (string $u, string $q) => DB::select(
            'EXEC dbo.sp_Gs_SearchMessages @UserID = ?, @Query = ?, @QueryLike = ?, @Top = ?',
            [$this->users[$u]->UserID, $q, $q, 5]
        );

        $this->assertNotEmpty($exec('U4', 'inf-1405-900003'));
        $this->assertSame([], $exec('U1', 'inf-1405-900003'), 'admin is not a participant of M3');
        $this->assertSame([], $exec('U7', 'inf-1405-900005'), 'deactivated user');
    }

    // ================= وظیفه، بدونِ شماره، شماره، نرمال‌سازی =================

    public function test_task_type_status_and_url(): void
    {
        $item = ($this->group($this->search('U3', 'TSK-1405-900002')->assertOk())['items'] ?? [])[0] ?? null;

        $this->assertNotNull($item);
        $this->assertSame('task', $item['type']);
        $this->assertSame('code', $item['match']);
        $this->assertSame('/messages/' . $this->msg['M2'], $item['url']);
        $this->assertStringContainsString('وظیفه', $item['subtitle']);
        $this->assertStringContainsString('TSK-1405-900002', $item['subtitle']);
        $this->assertStringContainsString('ارسال شده', $item['subtitle']); // آخرین وضعیت
    }

    public function test_message_without_number(): void
    {
        $item = ($this->group($this->search('U1', 'بدون شماره')->assertOk())['items'] ?? [])[0] ?? null;

        $this->assertNotNull($item);
        $this->assertSame('message', $item['type']);
        $this->assertSame($this->msg['M4'], (int) $item['id']);
        $this->assertStringContainsString('بدون شماره', $item['subtitle']);
    }

    public function test_partial_number_and_persian_digits(): void
    {
        $this->assertContains($this->msg['M2'], $this->ids($this->search('U5', '900002')->assertOk()));
        $this->assertContains($this->msg['M2'], $this->ids($this->search('U5', '۹۰۰۰۰۲')->assertOk()));
        $this->assertContains($this->msg['M2'], $this->ids($this->search('U5', 'tsk-1405')->assertOk()));
    }

    public function test_persian_query_finds_arabic_subject(): void
    {
        $this->assertContains($this->msg['M5'], $this->ids($this->search('U2', 'اطلاعیه جلسه')->assertOk()));
        // «كيان نگار» با ک/ی عربی و فاصله به‌جایِ نیم‌فاصله
        $this->assertContains($this->msg['M2'], $this->ids($this->search('U3', "\u{0643}\u{064A}\u{0627}\u{0646} \u{0646}\u{06AF}\u{0627}\u{0631}")->assertOk()));
    }

    public function test_message_body_is_not_searched(): void
    {
        // متنِ همهٔ نامه‌هایِ ساختگی شاملِ «متن ساختگی آزمون» است؛ نباید یافت شود
        $this->assertNull($this->group($this->search('U1', 'متن ساختگی')->assertOk()));
    }

    // ================= عدمِ افشا از طریقِ شمارش/hasMore =================

    public function test_counts_and_has_more_come_only_from_visible_messages(): void
    {
        config(['global_search.limits.per_group' => 1]);

        // U4 فقط M3 را می‌بیند؛ M4 («پیام بدون شماره…») و بقیه نباید hasMore را true کنند
        $g = $this->group($this->search('U4', 'پیام')->assertOk());
        $this->assertNotNull($g);
        $this->assertSame([$this->msg['M3']], array_map(fn ($i) => (int) $i['id'], $g['items']));
        $this->assertFalse($g['hasMore']);

        // U1 دو نامهٔ «آزمایشی» می‌بیند (M1, M4) ← یک مورد + hasMore
        $g1 = $this->group($this->search('U1', 'آزمایشی')->assertOk());
        $this->assertCount(1, $g1['items']);
        $this->assertTrue($g1['hasMore']);

        // U4 هیچ نامهٔ «آزمایشی» نمی‌بیند ← گروه وجود ندارد
        $this->assertNull($this->group($this->search('U4', 'آزمایشی')->assertOk()));
    }

    // ================= ورودی‌هایِ نامعتبر و خطا =================

    public function test_invalid_and_wildcard_queries(): void
    {
        foreach (['%%', '__', '[a]%', "%' OR 1=1 --"] as $q) {
            $this->assertNull($this->group($this->search('U1', $q)->assertOk()), "Query {$q} must match nothing.");
        }
        $this->search('U1', 'a')->assertStatus(422);
        $this->search('U1', "abc\u{202E}def")->assertStatus(422);
    }

    public function test_provider_failure_is_isolated_and_hides_sql(): void
    {
        config([
            'global_search.providers.messages.class' => BrokenMessageSearchProvider::class,
            'global_search.providers.crm.enabled' => true,
            'global_search.providers.crm.class' => CrmSearchProvider::class,
        ]);

        $r = $this->search('U2', 'کیان‌نگار')->assertOk();

        $r->assertJsonPath('complete', false);
        $this->assertNull($this->group($r));
        $this->assertNotNull($this->group($r, 'crm'), 'Other groups keep working.');
        $this->assertStringNotContainsString('SQLSTATE', (string) $r->getContent());
        $this->assertStringNotContainsString('sp_Gs', (string) $r->getContent());
    }

    public function test_crm_and_messages_together_respect_each_rule(): void
    {
        config(['global_search.providers.crm.enabled' => true, 'global_search.providers.crm.class' => CrmSearchProvider::class]);

        // U2: CRM_VIEW + گیرندهٔ M2 ← هر دو گروه
        $r2 = $this->search('U2', 'کیان‌نگار')->assertOk();
        $this->assertSame(['crm', 'messages'], array_column($r2->json('groups'), 'key'));

        // U5: بدونِ CRM_VIEW ولی گیرندهٔ قبلیِ M2 ← فقط نامه‌ها
        $r5 = $this->search('U5', 'کیان‌نگار')->assertOk();
        $this->assertSame(['messages'], array_column($r5->json('groups'), 'key'));

        // U4: هیچ‌کدام
        $this->assertSame([], $this->search('U4', 'کیان‌نگار')->assertOk()->json('groups'));
    }

    public function test_searches_do_not_write_any_data(): void
    {
        $tables = ['Messages', 'MessageDetails', 'MessageCopies', 'UserNotifications', 'Users', 'CrmParties', 'DevChangeLog'];
        $before = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));

        foreach (['INF', 'پیام', 'TSK-1405-900002', '%%'] as $q) {
            foreach (['U1', 'U4', 'U5'] as $u) {
                $this->search($u, $q)->assertOk();
            }
        }

        $after = array_map(fn ($t) => DB::table($t)->count(), array_combine($tables, $tables));
        $this->assertSame($before, $after);
    }
}
