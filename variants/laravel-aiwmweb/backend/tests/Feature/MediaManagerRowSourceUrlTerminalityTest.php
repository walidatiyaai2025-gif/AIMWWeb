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

final class MediaManagerRowSourceUrlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-MEDI-34E7DCA832';

    public function test_media_manager_row_source_url_binding_is_exact_and_read_only(): void
    {
        $control = (string) file_get_contents(
            resource_path('js/media-manager-row-source-url-control.tsx'),
        );
        $host = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString(
            'safeContentExplorerMediaSourceUrl(value)',
            $control,
        );
        $this->assertStringContainsString('rel="noopener noreferrer"', $control);
        $this->assertStringContainsString(
            '<MediaManagerRowSourceUrlControl value={row.source_url} />',
            $host,
        );
        $this->assertStringNotContainsString('apiRequest', $control);
        $this->assertStringNotContainsString('fetch(', $control);
        $this->assertStringNotContainsString('POST', $control);
        $this->assertStringNotContainsString('PATCH', $control);
        $this->assertStringNotContainsString('DELETE', $control);
    }

    public function test_media_workspace_rejects_foreign_tenant_context(): void
    {
        $this->withoutVite();

        $alpha = User::factory()->create();
        $this->membership($alpha, 'alpha-media-row', ['tenant.view', 'content.view']);
        $beta = User::factory()->create();
        $this->membership($beta, 'beta-media-row', ['tenant.view', 'content.view']);

        $this->actingAs($alpha)
            ->get('/tenants/alpha-media-row/module/media')
            ->assertOk();

        $this->actingAs($alpha)
            ->get('/tenants/beta-media-row/module/media')
            ->assertNotFound();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create([
            'name' => "media-row-{$slug}-{$user->id}",
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
