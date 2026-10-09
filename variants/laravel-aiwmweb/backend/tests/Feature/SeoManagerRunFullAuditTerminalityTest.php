<?php

namespace Tests\Feature;

use App\Http\Controllers\SeoController;
use App\Jobs\RunSeoAuditJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoAudit;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SeoManagerRunFullAuditTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-3C55B3C299';

    public function test_exact_canonical_operation_is_bound_to_real_audit_post_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/sites/7/seo/audits', 'POST'));

        $this->assertSame('canonical.api.seo.audit.run', $route->getName());
        $this->assertSame(SeoController::class.'@startAudit', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_authorized_member_creates_queued_audit_and_dispatches_tenant_aware_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view', 'seo.manage']);
        $site = $this->site($membership, 'Alpha SEO', 'https://alpha.test');

        $response = $this->actingAs($user)
            ->postJson('/api/tenants/alpha/sites/'.$site->id.'/seo/audits', [])
            ->assertAccepted()
            ->assertJsonPath('site_id', $site->id)
            ->assertJsonPath('actor_user_id', $user->id)
            ->assertJsonPath('status', 'queued');

        $auditId = (int) $response->json('id');
        $this->assertDatabaseHas('seo_audits', [
            'id' => $auditId,
            'site_id' => $site->id,
            'actor_user_id' => $user->id,
            'status' => 'queued',
        ]);

        Queue::assertPushed(
            RunSeoAuditJob::class,
            fn (RunSeoAuditJob $job): bool => $job->tenantId === $membership->tenant_id && $job->auditId === $auditId,
        );
    }

    public function test_missing_permission_and_cross_tenant_site_fail_closed(): void
    {
        Queue::fake();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'seo.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited SEO', 'https://limited.test');

        $this->actingAs($limited)
            ->postJson('/api/tenants/limited/sites/'.$limitedSite->id.'/seo/audits', [])
            ->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'seo.view', 'seo.manage']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'seo.view', 'seo.manage']);
        $this->site($alpha, 'Alpha SEO', 'https://alpha.test');
        $betaSite = $this->site($beta, 'Beta SEO', 'https://beta.test');

        $this->actingAs($user)
            ->postJson('/api/tenants/alpha/sites/'.$betaSite->id.'/seo/audits', [])
            ->assertNotFound();

        $this->assertSame(0, SeoAudit::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "seo-audit-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create(['name' => $name, 'url' => $url, 'status' => 'active']);
        $context->forget();

        return $site;
    }
}
