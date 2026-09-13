<?php

namespace Tests\Feature;

use App\Jobs\JobCancellationRegistry;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class JobCancellationRegistryParityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-13392A9E67';

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }

    public function test_canonical_background_job_identity_is_bound_to_the_laravel_registry(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('background_job', $operation['kind']);
        $this->assertSame('automation', $operation['domain']);
        $this->assertSame('job:JobCancellationRegistry', $operation['route_screen']);
        $this->assertSame('JobCancellationRegistry', $operation['background_job']);
        $this->assertSame('src/AIWordPressManager.Infrastructure/Jobs/JobCancellationRegistry.cs', $operation['current_source']);
        $this->assertSame(self::OPERATION_ID, JobCancellationRegistry::OPERATION_ID);
    }

    public function test_register_cancel_probe_and_unregister_preserve_canonical_semantics(): void
    {
        $tenant = $this->tenant('alpha');
        app(TenantContext::class)->activate($tenant);
        $registry = app(JobCancellationRegistry::class);
        $jobId = (string) Str::uuid();
        $registrationId = (string) Str::uuid();

        $this->assertSame($registrationId, $registry->register($jobId, $registrationId));
        $this->assertTrue($registry->isRegistered($jobId));
        $this->assertFalse($registry->isCancellationRequested($jobId, $registrationId));

        $this->assertTrue($registry->tryCancel($jobId));
        $this->assertTrue($registry->tryCancel($jobId), 'Repeated cancellation is idempotently successful.');
        $this->assertTrue($registry->isCancellationRequested($jobId, $registrationId));

        $this->assertTrue($registry->unregister($jobId, $registrationId));
        $this->assertFalse($registry->isRegistered($jobId));
        $this->assertFalse($registry->tryCancel($jobId));
    }

    public function test_replacement_registration_is_retry_safe_and_stale_disposal_cannot_remove_it(): void
    {
        $tenant = $this->tenant('retry');
        app(TenantContext::class)->activate($tenant);
        $registry = app(JobCancellationRegistry::class);
        $jobId = (string) Str::uuid();
        $first = (string) Str::uuid();
        $retry = (string) Str::uuid();

        $registry->register($jobId, $first);
        $this->assertTrue($registry->tryCancel($jobId));
        $this->assertTrue($registry->isCancellationRequested($jobId, $first));

        $registry->register($jobId, $retry);
        $this->assertFalse($registry->isCancellationRequested($jobId, $first));
        $this->assertFalse($registry->isCancellationRequested($jobId, $retry), 'A replacement attempt receives a fresh cancellation token state.');
        $this->assertFalse($registry->unregister($jobId, $first), 'Stale disposal must not remove the replacement registration.');
        $this->assertTrue($registry->isRegistered($jobId));

        $this->assertTrue($registry->tryCancel($jobId));
        $this->assertTrue($registry->isCancellationRequested($jobId, $retry));
        $this->assertTrue($registry->unregister($jobId, $retry));
    }

    public function test_registry_is_strictly_tenant_partitioned(): void
    {
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');
        $jobId = (string) Str::uuid();
        $registrationA = (string) Str::uuid();
        $registrationB = (string) Str::uuid();

        $context = app(TenantContext::class);
        $registry = app(JobCancellationRegistry::class);

        $context->activate($tenantA);
        $registry->register($jobId, $registrationA);
        $this->assertTrue($registry->tryCancel($jobId));
        $this->assertTrue($registry->isCancellationRequested($jobId, $registrationA));

        $context->forget();
        $context->activate($tenantB);
        $this->assertFalse($registry->isRegistered($jobId));
        $this->assertFalse($registry->tryCancel($jobId));
        $registry->register($jobId, $registrationB);
        $this->assertFalse($registry->isCancellationRequested($jobId, $registrationB));

        $context->forget();
        $context->activate($tenantA);
        $this->assertTrue($registry->isCancellationRequested($jobId, $registrationA));
        $this->assertFalse($registry->isCancellationRequested($jobId, $registrationB));
    }

    public function test_invalid_identity_and_ttl_fail_closed_without_unscoped_cache_state(): void
    {
        $tenant = $this->tenant('invalid');
        app(TenantContext::class)->activate($tenant);
        $registry = app(JobCancellationRegistry::class);

        foreach (['', 'not-a-uuid'] as $jobId) {
            try {
                $registry->register($jobId);
                $this->fail('Invalid job identity must fail closed.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }

        try {
            $registry->register((string) Str::uuid(), null, 0);
            $this->fail('Invalid TTL must fail closed.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->assertFalse(Cache::has('job-cancellation-registry:not-a-uuid'));
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::query()->create([
            'name' => 'Cancellation '.$slug,
            'slug' => $slug.'-'.Str::lower(Str::random(8)),
        ]);
    }
}
