<?php

namespace Tests\\Feature;

use App\\Jobs\\ContentTransferJob;
use App\\Models\\ContentTransfer;
use App\\Models\\Permission;
use App\\Models\\Role;
use App\\Models\\Site;
use App\\Models\\Tenant;
use App\\Models\\TenantMembership;
use App\\Models\\User;
use App\\Tenancy\\TenantContext;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Illuminate\\Http\\Request;
use Illuminate\\Http\\UploadedFile;
use Illuminate\\Support\\Facades\\Queue;
use Illuminate\\Support\\Facades\\Route;
use Illuminate\\Support\\Facades\\Storage;
use Tests\\TestCase;

final class ContentTransferWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_download_route_is_explicit_and_tenant_guarded(): void
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/transfers/22/download', 'GET'));

        $this->assertSame(ContentTransferJob::class, ContentTransferJob::class);
        $this->assertSame('App\\Http\\Controllers\\ContentApiController@download', ltrim($route->getActionName(), '\\\\'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_export_is_queued_and_returns_only_safe_transfer_metadata(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $membership = $this->membership($user, 'transfer-export', ['tenant.view', 'content.view']);
        $site = $this->site($membership);

        $response = $this->actingAs($user)->postJson("/api/v1/tenants/transfer-export/sites/{$site->id}/transfers/export");

        $response->assertStatus(202)
            ->assertJsonPath('site_id', $site->id)
            ->assertJsonPath('kind', 'export')
            ->assertJsonPath('state', 'queued')
            ->assertJsonMissingPath('storage_path')
            ->assertJsonMissingPath('last_error');
        Queue::assertPushed(ContentTransferJob::class);
    }

    public function test_import_requires_edit_permission_and_returns_no_internal_storage_path(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $membership = $this->membership($user, 'transfer-import', ['tenant.view', 'content.view']);
        $site = $this->site($membership);
        $file = UploadedFile::fake()->createWithContent('content.json', '{"version":1,"content":[]}');

        $this->actingAs($user)
            ->postJson("/api/v1/tenants/transfer-import/sites/{$site->id}/transfers/import", ['file' => $file])
            ->assertForbidden();

        $editor = User::factory()->create();
        $editorMembership = $this->membership($editor, 'transfer-import-editor', ['tenant.view', 'content.view', 'content.edit']);
        $editorSite = $this->site($editorMembership);
        $response = $this->actingAs($editor)
            ->postJson("/api/v1/tenants/transfer-import-editor/sites/{$editorSite->id}/transfers/import", [
                'file' => UploadedFile::fake()->createWithContent('content.json', '{"version":1,"content":[]}'),
            ]);

        $response->assertStatus(202)
            ->assertJsonPath('kind', 'import')
            ->assertJsonPath('state', 'queued')
            ->assertJsonMissingPath('storage_path')
            ->assertJsonMissingPath('last_error');
        Queue::assertPushed(ContentTransferJob::class);
    }

    public function test_status_is_sanitized_and_completed_export_download_is_tenant_and_site_scoped(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'transfer-alpha', ['tenant.view', 'content.view']);
        $beta = $this->membership($user, 'transfer-beta', ['tenant.view', 'content.view']);
        $alphaSite = $this->site($alpha);
        $betaSite = $this->site($beta);
        $path = 'content-transfers/'.$beta->tenant->id.'/'.$betaSite->id.'/export.json';
        $json = '{"version":1,"content":[]}';
        Storage::disk('local')->put($path, $json);
        $foreign = $this->transfer($beta, $betaSite, $path);

        $this->actingAs($user)
            ->getJson("/api/v1/tenants/transfer-beta/sites/{$betaSite->id}/transfers/{$foreign->id}")
            ->assertOk()
            ->assertJsonPath('state', 'completed')
            ->assertJsonPath('failure_present', true)
            ->assertJsonMissingPath('storage_path')
            ->assertJsonMissingPath('last_error');

        $this->actingAs($user)
            ->get("/api/v1/tenants/transfer-beta/sites/{$betaSite->id}/transfers/{$foreign->id}/download")
            ->assertOk()
            ->assertHeader('content-type', 'application/json');

        $this->actingAs($user)
            ->get("/api/v1/tenants/transfer-alpha/sites/{$alphaSite->id}/transfers/{$foreign->id}/download")
            ->assertNotFound();
    }

    public function test_queued_export_cannot_be_downloaded(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $membership = $this->membership($user, 'transfer-queued', ['tenant.view', 'content.view']);
        $site = $this->site($membership);
        $transfer = $this->transfer($membership, $site, null, 'queued');

        $this->actingAs($user)
            ->get("/api/v1/tenants/transfer-queued/sites/{$site->id}/transfers/{$transfer->id}/download")
            ->assertStatus(409);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "content-transfer-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $membership->tenant->name.' Site',
            'url' => 'https://'.$membership->tenant->slug.'.test',
        ]);
        $context->forget();

        return $site;
    }

    private function transfer(TenantMembership $membership, Site $site, ?string $path, string $state = 'completed'): ContentTransfer
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $transfer = ContentTransfer::query()->create([
            'site_id' => $site->id,
            'kind' => 'export',
            'state' => $state,
            'progress' => $state === 'completed' ? 100 : 5,
            'storage_path' => $path,
            'options' => [],
            'result' => $state === 'completed' ? ['content' => 0] : null,
            'last_error' => 'Private failure detail',
        ]);
        $context->forget();

        return $transfer;
    }
}
