<?php

namespace Tests\Feature;

use App\Jobs\TenantAwareJob;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSecret;
use App\Models\User;
use App\Repositories\TenantRepository;
use App\Tenancy\IdempotencyService;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLock;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_resolves_only_authenticated_membership_and_rbac(): void
    {
        [$tenantA, $memberA] = $this->tenantWithMember('alpha', true);
        [$tenantB] = $this->tenantWithMember('beta', true);

        $this->actingAs($memberA->user)
            ->getJson('/tenants/alpha/context')
            ->assertOk()->assertJsonPath('tenant.slug', 'alpha');

        $this->actingAs($memberA->user)
            ->getJson('/tenants/beta/context')
            ->assertNotFound();

        $this->assertNotSame($tenantA->id, $tenantB->id);
    }

    public function test_missing_permission_is_denied(): void
    {
        [, $membership] = $this->tenantWithMember('alpha', false);

        $this->actingAs($membership->user)
            ->getJson('/tenants/alpha/context')
            ->assertForbidden();
    }

    public function test_guessed_direct_ids_cannot_read_or_write_another_tenants_resource(): void
    {
        [$tenantA, $memberA] = $this->tenantWithMember('alpha', true);
        [$tenantB, $memberB] = $this->tenantWithMember('beta', true);
        $context = app(TenantContext::class);
        $repository = app(TenantRepository::class);

        $context->activate($tenantB, $memberB);
        $secretB = $repository->create(TenantSecret::class, ['name' => 'provider', 'encrypted_value' => 'beta-secret']);
        $context->forget();

        $context->activate($tenantA, $memberA);
        $this->assertNull(TenantSecret::query()->find($secretB->id));
        $this->expectException(ModelNotFoundException::class);
        $repository->findOrFail(TenantSecret::class, $secretB->id);
    }

    public function test_secret_is_encrypted_hidden_and_tenant_scoped(): void
    {
        [$tenant, $membership] = $this->tenantWithMember('alpha', true);
        app(TenantContext::class)->activate($tenant, $membership);
        $secret = TenantSecret::query()->create(['name' => 'provider', 'encrypted_value' => 'plain-value']);

        $raw = \DB::table('tenant_secrets')->where('id', $secret->id)->value('encrypted_value');
        $this->assertNotSame('plain-value', $raw);
        $this->assertSame('plain-value', Crypt::decryptString($raw));
        $this->assertArrayNotHasKey('encrypted_value', $secret->toArray());
    }

    public function test_cache_queue_and_idempotency_are_tenant_partitioned(): void
    {
        Queue::fake();
        [$tenantA, $memberA] = $this->tenantWithMember('alpha', true);
        [$tenantB, $memberB] = $this->tenantWithMember('beta', true);
        $context = app(TenantContext::class);

        $context->activate($tenantA, $memberA);
        $cacheA = app(TenantCache::class)->key('dashboard');
        Cache::put($cacheA, 'A');
        $resultA = app(IdempotencyService::class)->run('request-1', 'demo', ['value' => 1], fn () => ['tenant' => 'A']);
        DemoTenantJob::dispatch($tenantA->id);

        $context->activate($tenantB, $memberB);
        $cacheB = app(TenantCache::class)->key('dashboard');
        $resultB = app(IdempotencyService::class)->run('request-1', 'demo', ['value' => 1], fn () => ['tenant' => 'B']);
        DemoTenantJob::dispatch($tenantB->id);

        $this->assertSame("tenant:{$tenantA->id}:dashboard", $cacheA);
        $this->assertSame("tenant:{$tenantB->id}:dashboard", $cacheB);
        $this->assertNotSame($cacheA, $cacheB);
        $this->assertSame(['tenant' => 'A'], $resultA);
        $this->assertSame(['tenant' => 'B'], $resultB);
        Queue::assertPushed(DemoTenantJob::class, 2);
        $this->assertNotSame((new DemoTenantJob($tenantA->id))->uniqueId(), (new DemoTenantJob($tenantB->id))->uniqueId());
    }

    public function test_locks_and_audit_events_are_tenant_scoped_and_audits_are_immutable(): void
    {
        [$tenantA, $memberA] = $this->tenantWithMember('alpha', true);
        [$tenantB, $memberB] = $this->tenantWithMember('beta', true);
        $context = app(TenantContext::class);

        $context->activate($tenantA, $memberA);
        $lockA = app(TenantCache::class)->key('lock:publish');
        $this->assertSame('A', app(TenantLock::class)->block('publish', 1, fn () => 'A'));
        $audit = AuditEvent::query()->create([
            'actor_user_id' => $memberA->user_id,
            'event' => 'tenant.tested',
            'metadata' => ['result' => 'ok'],
            'occurred_at' => now(),
        ]);

        $context->activate($tenantB, $memberB);
        $lockB = app(TenantCache::class)->key('lock:publish');
        $this->assertNotSame($lockA, $lockB);
        $this->assertNull(AuditEvent::query()->find($audit->id));

        $context->activate($tenantA, $memberA);
        $this->expectException(\LogicException::class);
        $audit->update(['event' => 'tampered']);
    }

    private function tenantWithMember(string $slug, bool $grant): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'member']);
        if ($grant) {
            $permission = Permission::query()->create(['name' => 'tenant.view']);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }
}

final class DemoTenantJob extends TenantAwareJob
{
    public function handle(): void {}
}
