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

final class SitesCloseFormClickedTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-B60F3F750B';

    public function test_source_and_laravel_control_preserve_close_form_clicked_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'),
        );
        $control = (string) file_get_contents(resource_path('js/sites-save-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));

        $this->assertStringContainsString('OnClick="CloseFormClicked"', $source);
        $this->assertStringContainsString('private Task CloseFormClicked(MouseEventArgs _)', $source);
        $this->assertStringContainsString('private void CloseForm() { if (_saving) return; _showForm = false; _editingId = null; _model = new(); }', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('data-canonical-operation={SITES_CLOSE_FORM_CLICKED_OPERATION_ID}', $control);
        $this->assertStringContainsString('data-source-handler="CloseFormClicked"', $control);
        $this->assertStringContainsString('disabled={m.isPending} onClick={closeForm}', $control);
        $this->assertStringContainsString('<SitesSaveControl context={context} />', $app);
    }

    public function test_foreign_tenant_sites_surface_is_not_available(): void
    {
        $this->withoutVite();

        $alphaUser = User::factory()->create();
        $this->membership($alphaUser, 'alpha-close-form', ['tenant.view', 'sites.view', 'sites.manage']);

        $betaUser = User::factory()->create();
        $this->membership($betaUser, 'beta-close-form', ['tenant.view', 'sites.view', 'sites.manage']);

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha-close-form/sites')
            ->assertOk();

        $this->actingAs($alphaUser)
            ->get('/tenants/beta-close-form/sites')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'sites-close-form-'.$slug.'-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
