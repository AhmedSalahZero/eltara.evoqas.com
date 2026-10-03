<?php

namespace Tests\Feature;

use App\Http\Middleware\PreventDuplicateSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

// Q26 — the double-click guard and throttles must not depend on an "array"/"null" cache.
class DurableCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_safety_store_is_never_array_or_null(): void
    {
        $this->assertNotContains(config('cache.durable'), ['array', 'null']);
        $this->assertSame(config('cache.durable'), config('cache.limiter'));
    }

    public function test_safety_store_refuses_a_repeat_key(): void
    {
        $store = Cache::store(config('cache.durable'));

        $this->assertTrue($store->add('q26:test', true, 30));
        $this->assertFalse($store->add('q26:test', true, 30));
    }

    public function test_guard_lets_the_request_through_when_the_store_breaks(): void
    {
        config(['cache.stores.broken' => ['driver' => 'database', 'table' => 'no_such_table'], 'cache.durable' => 'broken']);

        $m = new PreventDuplicateSubmission;
        $firstTime = new \ReflectionMethod($m, 'firstTime');
        $firstTime->setAccessible(true);

        $this->assertTrue($firstTime->invoke($m, 'dup:x'));
    }

    public function test_rate_limiter_remembers(): void
    {
        RateLimiter::hit('q26-limiter', 60);
        $this->assertSame(1, RateLimiter::attempts('q26-limiter'));
    }

    public function test_check_command_runs(): void
    {
        // The test setup uses "array" for speed; show the command what a live server looks like.
        config(['cache.default' => 'database', 'session.driver' => 'database']);

        $this->artisan('eltara:check')->assertExitCode(0);
    }
}
