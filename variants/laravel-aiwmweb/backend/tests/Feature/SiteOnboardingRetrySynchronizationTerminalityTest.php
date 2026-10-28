<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class SiteOnboardingRetrySynchronizationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-AE16CF49C6';

    public function test_retry_control_uses_the_existing_tenant_scoped_sync_route_and_no_credential_repost(): void
    {
        $source = (string) file_get_contents(resource_path('js/site-onboarding-save-test-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $source);
        $this->assertStringContainsString('canonicalOnboardingRetrySyncEndpoint', $source);
        $this->assertStringContainsString("'Idempotency-Key': nextIdempotencyKey()", $source);
        $this->assertStringContainsString("result.sync?.status === 'failed'", $source);
        $this->assertStringContainsString("body: JSON.stringify({})", $source);
    }

    public function test_foreign_tenant_site_sync_is_not_found_and_cannot_enqueue_work(): void
    {
        Queue::fake();
        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['tenant.view', 'sites.manage']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        app(TenantContext::class)->activate($beta);
        $foreign = Site::query()->create([
            'name' => 'Beta Site', 'url' => 'https://beta.example.test', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        $this->actingAs($manager)
            ->withHeader('Idempotency-Key', 'onboarding-foreign-retry')
            ->postJson("/api/tenants/alpha/sites/{$foreign->id}/sync", [])
            ->assertNotFound();

        Queue::assertNothingPushed();
        $this->assertNotSame($alpha->id, $beta->id);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "onboarding-retry-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }
}
