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

final class MediaBatchClearSelectionTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-B83AB5A666';

    public function test_exact_pending_source_contract_is_cancel_of_local_media_batch_selection(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('component:MediaBatchUploadPanel', $row['route_screen']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/MediaBatchUploadPanel.razor', $row['current_source']);
        $this->assertSame('@L[ [ClearClickedAsync]', $row['visible_control']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/MediaBatchUploadPanel.razor'));
        $this->assertStringContainsString('OnClick="ClearClickedAsync"', $source);
        $this->assertStringContainsString('private Task ClearClickedAsync(MouseEventArgs _)', $source);
        $this->assertStringContainsString('ClearSelection();', $source);

        $control = (string) file_get_contents(resource_path('js/media-batch-clear-selection-control.tsx'));
        $app = (string) file_get_contents(resource_path('js/app.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('setFiles([])', $control);
        $this->assertStringContainsString("fileInput.current.value = ''", $control);
        $this->assertStringContainsString('data-canonical-operation={MEDIA_BATCH_CLEAR_SELECTION_OPERATION_ID}', $control);
        $this->assertStringContainsString('context.api.media === expected', $control);
        $this->assertStringContainsString('if (busy) return;', $control);
        $this->assertStringContainsString('<MediaBatchClearSelectionControl context={context} />', $app);
    }

    public function test_media_workspace_is_real_and_foreign_tenant_site_is_rejected(): void
    {
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha');
        $alphaSite = $this->site($alpha);
        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta');
        $betaSite = $this->site($beta);
        $this->withoutVite();

        $this->get('/tenants/alpha/sites/'.$alphaSite->id.'/media')
            ->assertRedirect('/login');

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/media')
            ->assertRedirect('/tenants/alpha/module/media?site='.$alphaSite->id);
        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/module/media?site='.$alphaSite->id)
            ->assertOk()
            ->assertSee('id="app"', false);

        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/sites/'.$betaSite->id.'/media')
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->get('/tenants/beta/module/media?site='.$betaSite->id)
            ->assertNotFound();
        $this->actingAs($alphaUser)
            ->get('/tenants/alpha/context?site='.$betaSite->id)
            ->assertNotFound();

        $this->assertDatabaseHas('sites', ['id' => $alphaSite->id, 'tenant_id' => $alpha->tenant_id]);
        $this->assertDatabaseHas('sites', ['id' => $betaSite->id, 'tenant_id' => $beta->tenant_id]);
    }

    private function membership(User $user, string $slug): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'media-batch-'.$slug.'-'.$user->id]);
        foreach (['tenant.view', 'sites.view', 'content.view', 'content.edit'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership): Site
    {
        app(TenantContext::class)->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => 'Media Batch Site',
            'url' => 'https://'.$membership->tenant->slug.'.example.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }
}
