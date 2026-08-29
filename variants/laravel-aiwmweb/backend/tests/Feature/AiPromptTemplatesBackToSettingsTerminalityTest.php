<?php

namespace Tests\Feature;

use App\Models\AiPromptRevision;
use App\Models\AiPromptTemplate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiPromptTemplatesBackToSettingsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_reconciliation_row_is_the_pending_back_to_settings_control(): void
    {
        $row = $this->canonicalRow('AIMW-AI-E1A964346F');

        $this->assertNotNull($row);
        $this->assertSame('ai', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/settings/ai-prompts', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AIPromptTemplates.razor', $row['current_source']);
        $this->assertStringContainsString('settings', strtolower((string) $row['visible_control']));
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('PENDING', $row['migration_state']);
    }

    public function test_settings_manager_gets_real_tenant_derived_back_to_settings_control_without_mutation(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);

        $templateCount = AiPromptTemplate::query()->withoutGlobalScopes()->count();
        $revisionCount = AiPromptRevision::query()->withoutGlobalScopes()->count();

        $response = $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts');

        $response->assertOk()
            ->assertSee('data-canonical-operation="AIMW-AI-E1A964346F"', false)
            ->assertSee('aria-label="Back to Settings"', false)
            ->assertSee('href="/tenants/alpha/settings"', false)
            ->assertSee('← Settings', false);

        $this->actingAs($user)->get('/tenants/alpha/settings')->assertOk();

        $this->assertSame($templateCount, AiPromptTemplate::query()->withoutGlobalScopes()->count());
        $this->assertSame($revisionCount, AiPromptRevision::query()->withoutGlobalScopes()->count());
    }

    public function test_control_stays_fail_closed_for_guest_missing_permission_and_foreign_tenant(): void
    {
        $this->get('/tenants/alpha/settings/ai-prompts')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')->assertForbidden();
        $this->actingAs($user)->get('/tenants/beta/settings/ai-prompts')->assertNotFound();
        $this->actingAs($user)->get('/tenants/beta/settings')->assertNotFound();
    }

    public function test_control_cannot_be_redirected_to_a_caller_supplied_tenant_or_resource_id(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage', 'tenant.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $response = $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts');

        $response->assertOk()
            ->assertSee('href="/tenants/alpha/settings"', false)
            ->assertDontSee('href="/tenants/beta/settings"', false)
            ->assertDontSee('template=', false)
            ->assertDontSee('revision=', false);
    }

    private function canonicalRow(string $operationId): ?array
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return collect($payload['operations'])->firstWhere('operation_id', $operationId);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "ai-prompts-back-settings-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);

        return $tenant;
    }
}
