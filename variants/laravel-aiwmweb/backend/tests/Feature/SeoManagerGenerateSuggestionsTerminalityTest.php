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
use Tests\TestCase;

final class SeoManagerGenerateSuggestionsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-E3C47C563B';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_generate_suggestions_is_bound_to_real_ai_proposal_contract(): void
    {
        $source = file_get_contents(resource_path('js/seo-visible-controls.tsx'));

        $this->assertStringContainsString("generateSuggestions: '".self::OPERATION_ID."'", $source);
        $this->assertStringContainsString('data-canonical-operation={SEO_OPERATIONS.generateSuggestions}', $source);
        $this->assertStringContainsString('await requestAiProposal(finding)', $source);
        $this->assertStringContainsString('No WordPress mutation occurred.', $source);
    }

    public function test_generate_suggestions_preserves_tenant_and_site_isolation(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'seo.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'seo.view']);
        $alphaSite = $this->site($alpha, 'Alpha SEO', 'https://alpha.test');
        $betaSite = $this->site($beta, 'Beta SEO', 'https://beta.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/seo')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/seo')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "seo-generate-{$slug}-{$user->id}"]);

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
