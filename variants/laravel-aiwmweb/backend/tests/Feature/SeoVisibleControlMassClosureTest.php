<?php

namespace Tests\Feature;

use App\Http\Controllers\SeoVisibleControlController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\SyncedContent;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SeoVisibleControlMassClosureTest extends TestCase
{
    use RefreshDatabase;

    private const IDS = [
        'AIMW-SEO-C48570747C',
        'AIMW-SEO-126222BD60',
        'AIMW-SEO-0B5FC34109',
        'AIMW-SEO-A4307E94C8',
        'AIMW-SEO-C7C22677CB',
        'AIMW-SEO-4F3F2AC874',
        'AIMW-SEO-5F71B89C92',
        'AIMW-SEO-9FE309C9AE',
        'AIMW-SEO-250C53DAC5',
        'AIMW-SEO-4CBBC7AAD9',
    ];

    private const VISIBLE_IDS = [
        'AIMW-SEO-C48570747C',
        'AIMW-SEO-126222BD60',
        'AIMW-SEO-0B5FC34109',
        'AIMW-SEO-A4307E94C8',
        'AIMW-SEO-C7C22677CB',
        'AIMW-SEO-4F3F2AC874',
        'AIMW-SEO-9FE309C9AE',
        'AIMW-SEO-250C53DAC5',
    ];

    public function test_manager_and_workspace_are_explicit_canonical_routes_with_fail_closed_permissions(): void
    {
        $manager = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/7/seo', 'GET'));
        $this->assertSame('canonical.site.seo', $manager->getName());
        $this->assertSame(SeoVisibleControlController::class.'@manager', ltrim($manager->getActionName(), '\\'));
        $this->assertSame('AIMW-SEO-5F71B89C92', $manager->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $manager->gatherMiddleware());
        $this->assertContains('tenant.context', $manager->gatherMiddleware());

        $workspace = Route::getRoutes()->match(Request::create('/tenants/alpha/seo-workspace', 'GET'));
        $this->assertSame('canonical.workspace.seo-hub', $workspace->getName());
        $this->assertSame(SeoVisibleControlController::class.'@workspace', ltrim($workspace->getActionName(), '\\'));
        $this->assertSame('AIMW-SEO-4CBBC7AAD9', $workspace->defaults['canonical_operation_id'] ?? null);
    }

    public function test_authorized_user_reaches_real_manager_workspace_and_navigation_controls(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $site = $this->site($membership, 'Alpha SEO', 'https://alpha.test');

        $response = $this->actingAs($user)->get('/tenants/alpha/sites/'.$site->id.'/seo');
        $response->assertOk()
            ->assertViewIs('seo.manager')
            ->assertViewHas('config', function (array $config) use ($site): bool {
                return ($config['site']['id'] ?? null) === $site->id
                    && ($config['urls']['audits'] ?? null) === '/api/tenants/alpha/sites/'.$site->id.'/seo/audits'
                    && ($config['urls']['prepare_bulk'] ?? null) === '/api/tenants/alpha/sites/'.$site->id.'/seo/remediations/bulk'
                    && ($config['urls']['proposals'] ?? null) === '/api/v1/tenants/alpha/sites/'.$site->id.'/seo/remediations/proposals'
                    && ($config['urls']['execution'] ?? null) === '/tenants/alpha/module/execution'
                    && ($config['urls']['explorer'] ?? null) === '/tenants/alpha/module/posts?site='.$site->id
                    && ($config['urls']['approvals'] ?? null) === '/tenants/alpha/approvals';
            })
            ->assertSee('id="seo-visible-controls"', false)
            ->assertSee('data-canonical-operation="AIMW-SEO-5F71B89C92"', false);

        $this->actingAs($user)->get('/tenants/alpha/seo-workspace')
            ->assertOk()
            ->assertViewIs('seo.workspace')
            ->assertViewHas('links', static function (array $links): bool {
                return ($links['audit'] ?? null) === '/tenants/alpha/module/seo-audit'
                    && ($links['suggestions'] ?? null) === '/tenants/alpha/module/seo-suggestions'
                    && ($links['approvals'] ?? null) === '/tenants/alpha/approvals';
            })
            ->assertSee('data-canonical-operation="AIMW-SEO-4CBBC7AAD9"', false);
    }

    public function test_presentation_endpoint_reads_real_tenant_scoped_content_links_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $site = $this->site($membership, 'Alpha SEO', 'https://alpha.test');
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $content = SyncedContent::query()->create([
            'site_id' => $site->id,
            'resource_type' => 'post',
            'remote_id' => 91,
            'slug' => 'authoritative-post',
            'title' => 'Authoritative Post',
            'content' => 'Persisted content',
            'excerpt' => '',
            'headings' => [],
            'taxonomy' => [],
            'media' => [],
            'seo_title' => 'Authoritative SEO title',
            'seo_description' => 'Description',
            'seo_provider' => 'yoast-seo',
            'seo_canonical' => 'https://alpha.test/canonical-post/',
            'seo_robots' => ['index', 'follow'],
        ]);
        $audit = SeoAudit::query()->create(['site_id' => $site->id, 'actor_user_id' => $user->id, 'status' => 'succeeded']);
        $finding = SeoFinding::query()->create([
            'seo_audit_id' => $audit->id,
            'synced_content_id' => $content->id,
            'code' => 'title_length',
            'field' => 'seo_title',
            'severity' => 'medium',
            'recommendation' => 'Adjust title',
            'suggested_value' => 'A better persisted title',
        ]);
        app(TenantContext::class)->forget();

        $before = SyncedContent::query()->withoutGlobalScopes()->findOrFail($content->id)->only(['slug', 'seo_canonical', 'seo_title']);
        $this->actingAs($user)
            ->getJson('/tenants/alpha/sites/'.$site->id.'/seo/presentation')
            ->assertOk()
            ->assertJsonPath('audit_id', $audit->id)
            ->assertJsonPath('links.'.$finding->id, 'https://alpha.test/canonical-post/');
        $after = SyncedContent::query()->withoutGlobalScopes()->findOrFail($content->id)->only(['slug', 'seo_canonical', 'seo_title']);
        $this->assertSame($before, $after);
    }

    public function test_guest_missing_permission_and_foreign_site_fail_closed(): void
    {
        $guestTenant = Tenant::query()->create(['name' => 'Guest', 'slug' => 'guest']);
        $this->get('/tenants/'.$guestTenant->slug.'/sites/1/seo')->assertRedirect();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited', 'https://limited.test');
        $this->actingAs($limited)->get('/tenants/limited/sites/'.$limitedSite->id.'/seo')->assertForbidden();
        $this->actingAs($limited)->get('/tenants/limited/seo-workspace')->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'seo.view']);
        $alphaSite = $this->site($alpha, 'Alpha', 'https://alpha.test');
        $betaSite = $this->site($beta, 'Beta', 'https://beta.test');
        $this->actingAs($user)->get('/tenants/alpha/sites/'.$alphaSite->id.'/seo')->assertOk();
        $this->actingAs($user)->get('/tenants/alpha/sites/'.$betaSite->id.'/seo')->assertNotFound();
    }

    public function test_production_surfaces_carry_all_ten_operation_ids_and_real_backend_contracts(): void
    {
        $frontend = file_get_contents(resource_path('js/seo-visible-controls.tsx'));
        foreach (self::VISIBLE_IDS as $operationId) {
            $this->assertStringContainsString($operationId, $frontend);
        }

        $provider = file_get_contents(app_path('Providers/SeoVisibleControlRouteServiceProvider.php'));
        $managerView = file_get_contents(resource_path('views/seo/manager.blade.php'));
        $workspaceView = file_get_contents(resource_path('views/seo/workspace.blade.php'));
        $this->assertStringContainsString('AIMW-SEO-5F71B89C92', $provider);
        $this->assertStringContainsString('AIMW-SEO-5F71B89C92', $managerView);
        $this->assertStringContainsString('AIMW-SEO-4CBBC7AAD9', $provider);
        $this->assertStringContainsString('AIMW-SEO-4CBBC7AAD9', $workspaceView);

        foreach (self::IDS as $operationId) {
            $this->assertStringContainsString($operationId, implode("\n", [$frontend, $provider, $managerView, $workspaceView]));
        }
        $this->assertStringContainsString('prepare_bulk', $frontend);
        $this->assertStringContainsString("method: 'POST'", $frontend);
        $this->assertStringContainsString('No WordPress mutation occurs until explicit approval.', $frontend);
        $this->assertStringContainsString('loadAuthoritative', $frontend);
        $this->assertStringContainsString('target="_blank"', $frontend);

        $vite = file_get_contents(base_path('vite.config.js'));
        $this->assertStringContainsString('resources/js/seo-visible-controls.tsx', $vite);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "seo-visible-{$slug}-{$user->id}"]);
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
