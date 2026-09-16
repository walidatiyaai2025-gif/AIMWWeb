<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteCredentialController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SiteSettingsRemoveCredentialTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-E36C3E1427';

    public function test_exact_canonical_operation_is_the_critical_site_credential_removal_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor', $operation['current_source']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('critical', $operation['risk']);
    }

    public function test_delete_route_is_session_tenant_and_csrf_guarded_without_credential_id_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/1/settings/credential', 'DELETE'));

        $this->assertSame('canonical.site.settings.credential.destroy', $route->getName());
        $this->assertSame(SiteCredentialController::class.'@destroy', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
        $this->assertNotContains('credential', $route->parameterNames());
        $this->assertSame(['DELETE'], $route->methods());
    }

    public function test_authorized_removal_is_encrypted_secret_free_and_success_follows_authoritative_reread(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example.test');
        $secret = 'wp-app-password-plaintext-sentinel';
        $credential = $this->credential($membership, $site, 'wp-admin', $secret);

        $rawSecret = DB::table('site_credentials')->where('id', $credential->id)->value('secret_value');
        $this->assertNotSame($secret, $rawSecret);
        $this->assertStringNotContainsString($secret, (string) $rawSecret);

        $page = $this->actingAs($user)->get("/tenants/alpha/sites/{$site->id}/settings");
        $page->assertOk()
            ->assertSee('wp-admin')
            ->assertSee('Remove credential')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee($secret);

        $response = $this->actingAs($user)->delete(
            "/tenants/alpha/sites/{$site->id}/settings/credential?tenant=foreign&credential=999",
        );
        $response->assertRedirect("/tenants/alpha/sites/{$site->id}/settings");

        $this->assertDatabaseMissing('site_credentials', [
            'tenant_id' => $membership->tenant_id,
            'site_id' => $site->id,
        ]);

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/settings")
            ->assertOk()
            ->assertSee('Encrypted credential removed.')
            ->assertSee('No stored credential.')
            ->assertDontSee($secret);
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed_without_mutation(): void
    {
        $alphaUser = User::factory()->create();
        $alphaMembership = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alphaMembership, 'Alpha Site', 'https://alpha.example.test');
        $alphaCredential = $this->credential($alphaMembership, $alphaSite, 'alpha-user', 'alpha-secret');

        $this->delete("/tenants/alpha/sites/{$alphaSite->id}/settings/credential")->assertRedirect('/login');
        $this->assertDatabaseHas('site_credentials', ['id' => $alphaCredential->id]);

        $limitedUser = User::factory()->create();
        $limitedMembership = $this->membership($limitedUser, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.example.test');
        $limitedCredential = $this->credential($limitedMembership, $limitedSite, 'limited-user', 'limited-secret');

        $this->actingAs($limitedUser)
            ->get("/tenants/limited/sites/{$limitedSite->id}/settings")
            ->assertOk()
            ->assertDontSee('limited-user')
            ->assertDontSee('Remove credential');
        $this->actingAs($limitedUser)
            ->delete("/tenants/limited/sites/{$limitedSite->id}/settings/credential")
            ->assertForbidden();
        $this->assertDatabaseHas('site_credentials', ['id' => $limitedCredential->id]);

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $betaSite = $this->site($betaMembership, 'Beta Site', 'https://beta.example.test');
        $betaCredential = $this->credential($betaMembership, $betaSite, 'beta-user', 'beta-secret');

        $this->actingAs($alphaUser)
            ->delete("/tenants/alpha/sites/{$betaSite->id}/settings/credential")
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->delete("/tenants/beta/sites/{$alphaSite->id}/settings/credential")
            ->assertNotFound();

        $this->assertDatabaseHas('site_credentials', ['id' => $alphaCredential->id]);
        $this->assertDatabaseHas('site_credentials', ['id' => $betaCredential->id]);
    }

    public function test_read_is_non_mutating_and_replay_fails_closed_without_fake_success(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example.test');
        $credential = $this->credential($membership, $site, 'wp-admin', 'secret-never-render');

        $this->actingAs($user)->get("/tenants/alpha/sites/{$site->id}/settings")->assertOk();
        $this->assertDatabaseHas('site_credentials', ['id' => $credential->id]);

        $this->actingAs($user)
            ->delete("/tenants/alpha/sites/{$site->id}/settings/credential")
            ->assertRedirect();
        $this->actingAs($user)
            ->delete("/tenants/alpha/sites/{$site->id}/settings/credential")
            ->assertNotFound();
        $this->assertDatabaseMissing('site_credentials', ['id' => $credential->id]);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "credential-remove-{$slug}-{$user->id}"]);
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

    private function credential(TenantMembership $membership, Site $site, string $username, string $secret): SiteCredential
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $credential = SiteCredential::query()->create([
            'site_id' => $site->id,
            'username' => $username,
            'secret_value' => $secret,
        ]);
        $context->forget();

        return $credential;
    }
}
