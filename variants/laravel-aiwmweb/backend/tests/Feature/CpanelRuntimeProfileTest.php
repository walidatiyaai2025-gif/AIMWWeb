<?php

namespace Tests\Feature;

use App\Http\Middleware\CpanelTransportPolicy;
use App\Services\RuntimeHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class CpanelRuntimeProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cpanel.enabled' => true, 'cpanel.cron_enabled' => false, 'cpanel.ai_enabled' => false, 'cpanel.preflight_passed' => false, 'cache.default' => 'database', 'session.driver' => 'database', 'session.connection' => null, 'queue.default' => 'database', 'app.url' => 'https://canonical.example']);
    }

    public function test_database_profile_is_ready_with_ai_and_cron_disabled_without_redis(): void
    {
        Redis::shouldReceive('connection')->never();
        $report = app(RuntimeHealthService::class)->ready();
        $this->assertSame('ready', $report['status']);
        $this->assertSame('pending', $report['hosting_preflight']);
        $this->assertSame('not_required', $report['checks']['redis']['status']);
        $this->assertSame('disabled', $report['checks']['scheduler']['status']);
        $this->assertSame('disabled', $report['checks']['ai']['status']);
        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_enabled_cron_requires_a_live_heartbeat_and_database_locks_work(): void
    {
        config(['cpanel.cron_enabled' => true, 'cpanel.preflight_passed' => true]);
        $this->assertSame('not_ready', app(RuntimeHealthService::class)->ready()['status']);
        Cache::store('database')->put(RuntimeHealthService::SCHEDULER_HEARTBEAT_KEY, time(), 60);
        $this->assertSame('ready', app(RuntimeHealthService::class)->ready()['status']);
        $first = Cache::store('database')->lock('cpanel-test-lock', 30);
        $second = Cache::store('database')->lock('cpanel-test-lock', 30);
        $this->assertTrue($first->get());
        $this->assertFalse($second->get());
        $first->release();
        $this->assertTrue($second->get());
        $second->release();
    }

    public function test_http_redirect_uses_the_configured_canonical_https_origin(): void
    {
        $called = false;
        $response = (new CpanelTransportPolicy)->handle(Request::create('http://untrusted.example/login?from=task'), function () use (&$called) {
            $called = true;

            return response('unexpected');
        });
        $this->assertSame(308, $response->getStatusCode());
        $this->assertSame('https://canonical.example/login?from=task', $response->headers->get('Location'));
        $this->assertFalse($called);
    }

    public function test_https_responses_enable_hsts(): void
    {
        $response = (new CpanelTransportPolicy)->handle(Request::create('https://canonical.example/login'), fn () => response('secure'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }
}
