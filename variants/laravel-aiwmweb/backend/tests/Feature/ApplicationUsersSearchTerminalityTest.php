<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApplicationUsersSearchTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-26F2BF8EE8';

    public function test_source_search_semantics_are_bound_to_the_real_laravel_search_control(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor'),
        );
        $serviceSource = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Services/ApplicationUserAdministrationService.cs'),
        );
        $control = (string) file_get_contents(resource_path('js/application-users-search-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/AdminOperationsController.php'));
        $administration = (string) file_get_contents(app_path('Operations/AdministrationService.php'));

        $this->assertStringContainsString('OnClick="SearchClickedAsync"', $source);
        $this->assertStringContainsString('private Task SearchClickedAsync(MouseEventArgs _) => LoadAsync();', $source);
        $this->assertStringContainsString('_users = await _service.ListAsync(_search);', $source);
        $this->assertStringContainsString('NormalizedUserName.Contains(term)', $serviceSource);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ApplicationUsersSearchControl', $pages);
        $this->assertStringContainsString("route.key === 'application-users'", $pages);
        $this->assertStringContainsString("context.permissions.includes('members.manage')", $pages);
        $this->assertStringContainsString('applicationUsersSearchInFlight.current', $pages);
        $this->assertStringContainsString('endpointWithQuery(endpoint, 1, nextSearch)', $pages);

        $this->assertStringContainsString('public function members(Request $request, string $tenant)', $controller);
        $this->assertStringContainsString("'search' => ['nullable', 'string', 'max:255']", $controller);
        $this->assertStringContainsString("\$this->administration->members(\$data['search'] ?? null)", $controller);
        $this->assertStringContainsString('public function members(?string $search = null): array', $administration);
        $this->assertStringContainsString("Str::upper(trim(\$search ?? ''))", $administration);
        $this->assertStringContainsString("UPPER(name) LIKE ? ESCAPE '!'", $administration);

        $this->assertStringNotContainsString('AIMW-SYNC-9ABD7DEDBB', $control);
        $this->assertStringNotContainsString('AIMW-SYNC-F135B261B2', $control);
    }

    public function test_search_is_trimmed_case_insensitive_literal_and_tenant_scoped(): void
    {
        $actor = User::factory()->create(['name' => 'Owner', 'email' => 'owner@example.test']);
        $tenant = $this->membership($actor, 'alpha', ['tenant.view', 'users.view', 'members.manage']);

        $this->member($tenant, 'Alpha Operator', 'alpha-operator@example.test');
        $this->member($tenant, 'Beta Viewer', 'beta-viewer@example.test');
        $this->member($tenant, 'Percent 100% User', 'percent@example.test');

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members?search=%20aLpHa%20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', 'Alpha Operator');

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/members?search=%25')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.name', 'Percent 100% User');
    }

    public function test_foreign_tenant_search_fails_closed_with_404(): void
    {
        $actor = User::factory()->create();
        $this->membership($actor, 'alpha', ['tenant.view', 'users.view', 'members.manage']);

        $foreign = User::factory()->create();
        $beta = $this->membership($foreign, 'beta', ['tenant.view', 'users.view', 'members.manage']);
        $this->member($beta, 'Alpha Foreign', 'foreign-alpha@example.test');

        $this->actingAs($actor)
            ->getJson('/tenants/beta/admin/members?search=alpha')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "search-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function member(Tenant $tenant, string $name, string $email): void
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $context->forget();
    }
}
