<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // صفحهٔ اصلی عمداً به صفحهٔ ورود هدایت می‌کند (routes/web.php)؛ خودِ صفحهٔ ورود باید 200 بدهد.
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    }
}
