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
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WordPressUsersWorkingStateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-346657AD9D';

    private const EXISTING_SAVE_OPERATION_ID = 'AIMW-BILL-D307963B59';

    public function test_exact_source_working_state_is_adapted_without_duplicating_the_save_mutation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/UsersManager.razor'),
        );
        $control = (string) file_get_contents(
            resource_path('js/wordpress-users-working-state-control.tsx'),
        );
        $components = (string) file_get_contents(resource_path('js/components.tsx'));

        $this->assertStringContainsString('Text="@(_working ? L["Saving"] : L["Save"])"', $source);
        $this->assertStringContainsString('Busy="@_working"', $source);
        $this->assertStringContainsString('Disabled="@_working"', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString("busy ? 'true' : 'false'", $control);
        $this->assertStringContainsString("'Saving…'", $control);
        $this->assertStringContainsString("'Save'", $control);
        $this->assertStringNotContainsString('apiRequest(', $control);
        $this->assertStringNotContainsString(self::EXISTING_SAVE_OPERATION_ID, $control);

        $this->assertStringContainsString('WordPressUsersWorkingStateControl', $components);
        $this->assertStringContainsString("actionKey === 'wordpress-users.save'", $components);
        $this->assertStringContainsString('busy={busy}', $components);

        $contract = config('frontend_actions.wordpress-users.save');
        $this->assertIsArray($contract);
        $this->assertSame(self::EXISTING_SAVE_OPERATION_ID, $contract['operation_id']);
        $this->assertSame('content.edit', $contract['permission']);
        $this->assertSame('site', $contract['ownership']);
        $this->assertSame('wp-users', $contract['reconcile_api_key']);
    }

    public function test_foreign_tenant_context_fails_closed_before_wordpress_users_are_read(): void
    {
        Http::fake();

        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha', ['sites.view', 'content.view']);

        $betaUser = User::factory()->create();
        $betaMembership = $this->membership($betaUser, 'beta', ['sites.view', 'content.view']);
        $betaSite = $this->site($betaMembership, 'https://beta.example.test');

        $this->actingAs($alphaUser)
            ->getJson("/api/v1/tenants/beta/sites/{$betaSite->id}/users")
            ->assertNotFound();

        Http::assertNothingSent();
        app(TenantContext::class)->forget();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "working-state-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $url): Site
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'WordPress Users Working State',
            'url' => $url,
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }
}
