<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TaxonomyTerm;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TaxonomyShowTagsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-TAXO-D784149312';

    public function test_authorized_tag_read_returns_only_post_tag_terms_for_the_owned_site_without_mutation(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'content.view']);
        $site = $this->site($membership, 'Alpha Site');

        $this->term($membership, $site, 11, 'category', 'News');
        $tag = $this->term($membership, $site, 12, 'post_tag', 'Editorial');
        $before = TaxonomyTerm::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray();

        $response = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/sites/'.$site->id.'/taxonomy?taxonomy=post_tag')
            ->assertOk();

        $response
            ->assertJsonPath('data.0.id', $tag->id)
            ->assertJsonPath('data.0.taxonomy', 'post_tag')
            ->assertJsonPath('data.0.name', 'Editorial')
            ->assertJsonMissing(['taxonomy' => 'category']);

        $after = TaxonomyTerm::query()->withoutGlobalScopes()->orderBy('id')->get()->toArray();
        $this->assertSame($before, $after);
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site');

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'Beta Site');
        $this->term($betaMembership, $betaSite, 91, 'post_tag', 'Private Beta Tag');

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site');

        $this->getJson('/api/v1/tenants/alpha/sites/'.$alphaSite->id.'/taxonomy?taxonomy=post_tag')
            ->assertUnauthorized();

        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited/sites/'.$limitedSite->id.'/taxonomy?taxonomy=post_tag')
            ->assertForbidden();

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/beta/sites/'.$betaSite->id.'/taxonomy?taxonomy=post_tag')
            ->assertNotFound();

        $this->actingAs($alphaUser)
            ->getJson('/api/v1/tenants/alpha/sites/'.$betaSite->id.'/taxonomy?taxonomy=post_tag')
            ->assertNotFound();
    }

    public function test_production_wiring_marks_the_exact_show_tags_control_and_constant_post_tag_filter(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/TaxonomyManager.razor'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/ContentApiController.php'));

        $this->assertStringContainsString('ShowTags', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $pages);
        $this->assertStringContainsString("useState<'category' | 'post_tag'>('category')", $pages);
        $this->assertStringContainsString("setTaxonomyType('post_tag')", $pages);
        $this->assertStringContainsString("{ taxonomy: taxonomyType }", $pages);
        $this->assertStringContainsString("Site::query()->findOrFail(\$site)", $controller);
        $this->assertStringContainsString("where('taxonomy', \$taxonomy)", $controller);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "taxonomy-tags-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('tenant', $tenant);
        $context->forget();

        return $membership;
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function term(TenantMembership $membership, Site $site, int $remoteId, string $taxonomy, string $name): TaxonomyTerm
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $term = TaxonomyTerm::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'taxonomy' => $taxonomy,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'description' => null,
            'parent_remote_id' => null,
            'usage_count' => 1,
            'metadata' => [],
        ]);
        $context->forget();

        return $term;
    }
}
