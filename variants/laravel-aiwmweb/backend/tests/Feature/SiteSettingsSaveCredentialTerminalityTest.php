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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteSettingsSaveCredentialTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-723BEA8F1D';

    public function test_route_is_session_tenant_and_csrf_guarded_without_caller_owned_identity(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/sites/1/settings/credential', 'POST'));
        $this->assertSame('canonical.site.settings.credential.store', $route->getName());
        $this->assertSame(SiteCredentialController::class.'@store', ltrim($route->getActionName(), '\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'site'], $route->parameterNames());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
    }

    public function test_verified_credential_is_encrypted_reread_and_replay_upserts_one_row_without_secret_exposure(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha', 'https://alpha.example.test');
        $secret = 'application-password-plaintext-sentinel';

        Http::fake([
            'https://alpha.example.test/wp-json/wp/v2/users/me?context=edit' => Http::response([
                'id' => 17,
                'capabilities' => ['manage_options' => true],
            ], 200),
        ]);

        $page = $this->actingAs($user)->get("/tenants/alpha/sites/{$site->id}/settings");
        $page->assertOk()
            ->assertSee('Save &amp; Test', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('name="_token"', false)
            ->assertDontSee($secret);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $response = $this->actingAs($user)->post("/tenants/alpha/sites/{$site->id}/settings/credential", [
                'username' => 'wp-admin',
                'application_password' => $secret,
            ]);
            $response->assertRedirect("/tenants/alpha/sites/{$site->id}/settings");
        }

        $this->assertSame(1, DB::table('site_credentials')->where('tenant_id', $membership->tenant_id)->where('site_id', $site->id)->count());
        $raw = (string) DB::table('site_credentials')->where('site_id', $site->id)->value('secret_value');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, $raw);

        $credential = SiteCredential::withoutGlobalScopes()->where('tenant_id', $membership->tenant_id)->where('site_id', $site->id)->firstOrFail();
        $this->assertSame('wp-admin', $credential->username);
        $this->assertSame($secret, $credential->secret_value);
        $this->assertArrayNotHasKey('secret_value', $credential->toArray());

        $this->actingAs($user)->get("/tenants/alpha/sites/{$site->id}/settings")
            ->assertOk()
            ->assertSee('Credential saved and WordPress connection verified.')
            ->assertSee('wp-admin')
            ->assertDontSee($secret);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->url() === 'https://alpha.example.test/wp-json/wp/v2/users/me?context=edit'
            && $request->hasHeader('Authorization')
            && !str_contains((string) $request->body(), $secret));
    }

    public function test_provider_failure_preserves_existing_credential_and_does_not_flash_secret(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha', 'https://alpha.example.test');
        $old = $this->credential($membership, $site, 'old-user', 'old-secret-123');
        $newSecret = 'new-secret-never-persist';

        Http::fake(['*' => Http::response(['code' => 'rest_cannot_view'], 401)]);

        $response = $this->actingAs($user)->from("/tenants/alpha/sites/{$site->id}/settings")
            ->post("/tenants/alpha/sites/{$site->id}/settings/credential", [
                'username' => 'new-user',
                'application_password' => $newSecret,
            ]);
        $response->assertRedirect("/tenants/alpha/sites/{$site->id}/settings");
        $response->assertSessionHasErrors('application_password');
        $this->assertSame('new-user', session()->getOldInput('username'));
        $this->assertNull(session()->getOldInput('application_password'));

        $authoritative = SiteCredential::withoutGlobalScopes()->findOrFail($old->id);
        $this->assertSame('old-user', $authoritative->username);
        $this->assertSame('old-secret-123', $authoritative->secret_value);
        $this->assertStringNotContainsString($newSecret, (string) DB::table('site_credentials')->where('id', $old->id)->value('secret_value'));
    }

    public function test_guest_missing_permission_foreign_tenant_and_foreign_site_fail_closed_before_provider_or_persistence(): void
    {
        Http::fake();
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha, 'Alpha', 'https://alpha.example.test');

        $this->post("/tenants/alpha/sites/{$alphaSite->id}/settings/credential", ['username' => 'u', 'application_password' => '12345678'])
            ->assertRedirect('/login');

        $limitedUser = User::factory()->create();
        $limited = $this->membership($limitedUser, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limited, 'Limited', 'https://limited.example.test');
        $this->actingAs($limitedUser)->post("/tenants/limited/sites/{$limitedSite->id}/settings/credential", ['username' => 'user', 'application_password' => '12345678'])
            ->assertForbidden();

        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $betaSite = $this->site($beta, 'Beta', 'https://beta.example.test');
        $this->actingAs($alphaUser)->post("/tenants/alpha/sites/{$betaSite->id}/settings/credential", ['username' => 'user', 'application_password' => '12345678'])
            ->assertNotFound();
        $this->actingAs($alphaUser)->post("/tenants/beta/sites/{$alphaSite->id}/settings/credential", ['username' => 'user', 'application_password' => '12345678'])
            ->assertNotFound();

        $this->assertSame(0, DB::table('site_credentials')->count());
        Http::assertNothingSent();
    }

    public function test_validation_and_caller_identity_overrides_fail_closed_without_secret_replay(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha', 'https://alpha.example.test');
        $secret = 'secret-never-old-input';

        $response = $this->actingAs($user)->from("/tenants/alpha/sites/{$site->id}/settings")
            ->post("/tenants/alpha/sites/{$site->id}/settings/credential", [
                'username' => 'user',
                'application_password' => $secret,
                'tenant_id' => 999,
                'site_id' => 999,
                'url' => 'https://attacker.invalid',
            ]);
        $response->assertRedirect("/tenants/alpha/sites/{$site->id}/settings")->assertSessionHasErrors('request');
        $this->assertNull(session()->getOldInput('application_password'));
        $this->assertDatabaseCount('site_credentials', 0);
        Http::assertNothingSent();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "credential-save-{$slug}-{$user->id}"]);
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
        $credential = SiteCredential::query()->create(['site_id' => $site->id, 'username' => $username, 'secret_value' => $secret]);
        $context->forget();
        return $credential;
    }
}
