<?php

namespace Tests\Feature;

use App\Jobs\JobCancellationRegistry;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Focused shared-cache contract for AIMW-AUTO-13392A9E67.
 *
 * This deterministic test proves that the registry uses the same tenant-scoped
 * shared-cache boundary that repository runtime acceptance separately exercises
 * with real Redis locks plus real Redis queue-worker and scheduler semantics.
 */
final class JobCancellationRegistryRedisParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_state_uses_the_shared_tenant_cache_namespace(): void
    {
        $tenant = Tenant::query()->create([
            'name' => 'Cancellation Redis',
            'slug' => 'cancellation-redis-'.Str::lower(Str::random(8)),
        ]);
        app(TenantContext::class)->activate($tenant);

        $jobId = (string) Str::uuid();
        $registrationId = (string) Str::uuid();
        $registry = app(JobCancellationRegistry::class);
        $registry->register($jobId, $registrationId);

        $key = "tenant:{$tenant->id}:job-cancellation-registry:".Str::lower($jobId);
        $state = Cache::get($key);

        $this->assertIsArray($state);
        $this->assertSame($registrationId, $state['registration_id']);
        $this->assertFalse($state['cancel_requested']);
        $this->assertFalse(Cache::has('job-cancellation-registry:'.Str::lower($jobId)));

        $this->assertTrue($registry->tryCancel($jobId));
        $this->assertTrue((bool) Cache::get($key)['cancel_requested']);
    }
}
