<?php

namespace Tests\Feature\GlobalSearch;

use App\Http\Controllers\GlobalSearchController;
use App\Http\Middleware\EnsureGlobalSearchEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ThrottleGlobalSearch;
use App\Models\User;
use App\Services\GlobalSearch\Contracts\AccessContextResolver;
use App\Services\GlobalSearch\Support\SqlAccessContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * پیش‌فرض‌ها بدونِ هیچ override: قابلیت خاموش است (§9 مشخصات).
 * عمداً از GlobalSearchTestCase ارث نمی‌برد، چون آن پایه فلگ را برایِ آزمون روشن می‌کند.
 */
class GlobalSearchDefaultOffTest extends TestCase
{
    public function test_config_file_default_is_off(): void
    {
        // مقدارِ پیش‌فرضِ خودِ فایلِ پیکربندی وقتی متغیرِ محیطی تعریف نشده است
        $config = require base_path('config/global_search.php');
        $this->assertArrayHasKey('enabled', $config);

        $saved = getenv('GLOBAL_SEARCH_ENABLED');
        putenv('GLOBAL_SEARCH_ENABLED');
        unset($_ENV['GLOBAL_SEARCH_ENABLED'], $_SERVER['GLOBAL_SEARCH_ENABLED']);
        try {
            $fresh = require base_path('config/global_search.php');
            $this->assertFalse($fresh['enabled']);
        } finally {
            if ($saved !== false) {
                putenv('GLOBAL_SEARCH_ENABLED=' . $saved);
                $_ENV['GLOBAL_SEARCH_ENABLED'] = $saved;
            }
        }
    }

    public function test_all_feature_and_provider_flags_are_off_by_default(): void
    {
        $flags = ['GLOBAL_SEARCH_ENABLED', 'GLOBAL_SEARCH_CRM_ENABLED', 'GLOBAL_SEARCH_MESSAGES_ENABLED',
                  'GLOBAL_SEARCH_PROJECTS_ENABLED', 'GLOBAL_SEARCH_PROCESSES_ENABLED'];

        // ۱) پیش‌فرضِ خودِ فایلِ پیکربندی وقتی هیچ‌کدام از متغیرها تعریف نشده‌اند
        $saved = [];
        foreach ($flags as $flag) {
            $saved[$flag] = getenv($flag);
            putenv($flag);
            unset($_ENV[$flag], $_SERVER[$flag]);
        }
        try {
            $fresh = require base_path('config/global_search.php');
            $this->assertFalse($fresh['enabled']);
            $this->assertSame(['crm', 'messages', 'projects', 'processes'], array_keys($fresh['providers']));
            foreach ($fresh['providers'] as $key => $def) {
                $this->assertFalse($def['enabled'], "Provider {$key} must default to off.");
            }
        } finally {
            foreach ($saved as $flag => $value) {
                if ($value !== false) {
                    putenv($flag . '=' . $value);
                    $_ENV[$flag] = $value;
                }
            }
        }

        // ۲) پیکربندیِ مؤثرِ همین اجرا
        $this->assertFalse(config('global_search.enabled'));
        foreach (config('global_search.providers') as $key => $def) {
            $this->assertFalse($def['enabled'], "Provider {$key} must be off in this environment.");
        }

        // ۳) فقط در محیطِ ایزوله (GsDev؛ phpunit.xml آن GLOBAL_SEARCH_ASSERT_DOTENV_OFF=true می‌گذارد):
        //    فایلِ .envِ همان محیط هیچ فلگی را روشن نکرده است. در محیطِ اصلی قابلیت عمداً در .env روشن است و
        //    این بخش اجرا نمی‌شود؛ بخش‌هایِ ۱ و ۲ (پیش‌فرضِ کد و پیکربندیِ مؤثرِ آزمون) همه‌جا اجرا می‌شوند.
        $envFile = base_path('.env');
        if (env('GLOBAL_SEARCH_ASSERT_DOTENV_OFF') === true && is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES) as $line) {
                if (preg_match('/^\s*(GLOBAL_SEARCH_[A-Z_]*ENABLED)\s*=\s*(.*?)\s*$/', $line, $m)) {
                    $this->assertContains(strtolower(trim($m[2], '"\'')), ['false', '0', ''], "{$m[1]} must stay off in .env.");
                }
            }
        }
    }

    private const FLAGS = ['GLOBAL_SEARCH_ENABLED', 'GLOBAL_SEARCH_CRM_ENABLED', 'GLOBAL_SEARCH_MESSAGES_ENABLED',
                           'GLOBAL_SEARCH_PROJECTS_ENABLED', 'GLOBAL_SEARCH_PROCESSES_ENABLED'];

    /**
     * فایلِ پیکربندی را با مقدارهایِ داده‌شده برایِ فلگ‌ها (null = تعریف‌نشده) ارزیابی می‌کند و
     * وضعیتِ قبلیِ محیط را برمی‌گرداند.
     *
     * @param  array<string,?string>  $values
     * @return array<string,bool>
     */
    private function evaluateFlags(array $values): array
    {
        $saved = [];
        foreach (self::FLAGS as $flag) {
            $saved[$flag] = [getenv($flag), $_ENV[$flag] ?? null, $_SERVER[$flag] ?? null];
            putenv($flag);
            unset($_ENV[$flag], $_SERVER[$flag]);
            if (($values[$flag] ?? null) !== null) {
                putenv($flag . '=' . $values[$flag]);
                $_ENV[$flag] = $_SERVER[$flag] = $values[$flag];
            }
        }
        try {
            $config = require base_path('config/global_search.php');
        } finally {
            foreach ($saved as $flag => [$env, $envArr, $server]) {
                putenv($flag);
                unset($_ENV[$flag], $_SERVER[$flag]);
                if ($env !== false) {
                    putenv($flag . '=' . $env);
                }
                if ($envArr !== null) {
                    $_ENV[$flag] = $envArr;
                }
                if ($server !== null) {
                    $_SERVER[$flag] = $server;
                }
            }
        }

        $result = ['GLOBAL_SEARCH_ENABLED' => $config['enabled']];
        foreach (['crm', 'messages', 'projects', 'processes'] as $key) {
            $result['GLOBAL_SEARCH_' . strtoupper($key) . '_ENABLED'] = $config['providers'][$key]['enabled'];
        }

        return $result;
    }

    public static function flagValues(): array
    {
        return [
            'true' => ['true', true],
            'TRUE' => ['TRUE', true],
            'True' => ['True', true],
            '(true)' => ['(true)', true],
            'false' => ['false', false],
            '(false)' => ['(false)', false],
            'off' => ['off', false],
            'OFF' => ['OFF', false],
            'no' => ['no', false],
            'on' => ['on', false],
            'yes' => ['yes', false],
            '1' => ['1', false],
            '0' => ['0', false],
            '-1' => ['-1', false],
            'enabled' => ['enabled', false],
            'tru' => ['tru', false],
            'true1' => ['true1', false],
            'true with space' => ['true ', false],
            'quoted true (raw env)' => ['"true"', false],
            'empty' => ['', false],
            '(empty)' => ['empty', false],
            'null' => ['null', false],
            'garbage' => ['x%$#', false],
            'persian' => ['روشن', false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flagValues')]
    public function test_only_an_explicit_true_turns_a_flag_on(string $value, bool $expected): void
    {
        $result = $this->evaluateFlags(array_fill_keys(self::FLAGS, $value));

        foreach ($result as $flag => $enabled) {
            $this->assertSame($expected, $enabled, "{$flag}=" . var_export($value, true));
        }
    }

    public function test_flags_are_independent_and_unset_flags_stay_off(): void
    {
        $result = $this->evaluateFlags(['GLOBAL_SEARCH_PROJECTS_ENABLED' => 'true']);

        $this->assertSame([
            'GLOBAL_SEARCH_ENABLED' => false,
            'GLOBAL_SEARCH_CRM_ENABLED' => false,
            'GLOBAL_SEARCH_MESSAGES_ENABLED' => false,
            'GLOBAL_SEARCH_PROJECTS_ENABLED' => true,
            'GLOBAL_SEARCH_PROCESSES_ENABLED' => false,
        ], $result);
        $this->assertSame(array_fill_keys(self::FLAGS, false), $this->evaluateFlags([]));
    }

    public function test_effective_config_is_off_in_this_environment(): void
    {
        $this->assertFalse(config('global_search.enabled'));
        foreach (config('global_search.providers') as $key => $def) {
            $this->assertFalse($def['enabled'], "Provider {$key} must be off by default.");
        }
        // هر چهار گروه پیاده‌سازی دارند و همه پیش‌فرض خاموش‌اند
        $this->assertSame(\App\Services\GlobalSearch\Providers\CrmSearchProvider::class, config('global_search.providers.crm.class'));
        $this->assertSame(\App\Services\GlobalSearch\Providers\MessageSearchProvider::class, config('global_search.providers.messages.class'));
        $this->assertSame(\App\Services\GlobalSearch\Providers\ProjectSearchProvider::class, config('global_search.providers.projects.class'));
        $this->assertSame(\App\Services\GlobalSearch\Providers\ProcessSearchProvider::class, config('global_search.providers.processes.class'));
    }

    public function test_authenticated_request_gets_404_when_off(): void
    {
        $user = (new User())->forceFill(['UserID' => 601, 'UserName' => 'gs_off', 'IsActive' => true]);
        $user->exists = true;

        $this->actingAs($user)->postJson('/global-search', ['q' => 'test'])->assertStatus(404);
    }

    public function test_route_is_registered_inside_auth_group_with_guards(): void
    {
        $route = Route::getRoutes()->getByName('global-search');

        $this->assertNotNull($route);
        $this->assertSame('global-search', $route->uri());
        $this->assertSame(['POST'], $route->methods()); // متنِ جست‌وجو هرگز در URL (یافتهٔ ۱ ممیزی)
        $this->assertSame(GlobalSearchController::class, $route->getActionName());

        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth', $middleware);
        $this->assertContains(EnsureGlobalSearchEnabled::class, $middleware);
        $this->assertContains(ThrottleGlobalSearch::class, $middleware);
        $this->assertLessThan(
            array_search(ThrottleGlobalSearch::class, $middleware, true),
            array_search(EnsureGlobalSearchEnabled::class, $middleware, true),
            'The flag check must run before the rate limiter.'
        );

        // JSONِ خالص: میان‌افزارِ Inertia برایِ این مسیر اجرا نمی‌شود (هدرِ Vary: Cookie حفظ می‌شود)
        $this->assertContains(HandleInertiaRequests::class, $route->excludedMiddleware());
    }

    public function test_access_resolver_binding_points_to_sql_implementation(): void
    {
        $this->assertInstanceOf(SqlAccessContextResolver::class, $this->app->make(AccessContextResolver::class));
    }

    public function test_inertia_shares_feature_flag_false_by_default_and_true_when_enabled(): void
    {
        $middleware = new HandleInertiaRequests();

        $this->assertFalse($middleware->share(Request::create('/'))['features']['globalSearch']);

        config(['global_search.enabled' => true]);
        $this->assertTrue($middleware->share(Request::create('/'))['features']['globalSearch']);
    }
}
