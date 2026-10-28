<?php

namespace Tests\Feature;

use App\Http\Controllers\ContentApiController;
use App\Jobs\MediaUploadJob;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class MediaBatchUploadTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-6766C503DA';

    public function test_source_upload_control_and_tenant_scoped_api_are_exactly_linked(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $this->assertNotNull($operation);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:MediaBatchUploadPanel', $operation['route_screen']);
        $this->assertStringContainsString('UploadClickedAsync', $operation['visible_control']);

        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/MediaBatchUploadPanel.razor'));
        $this->assertStringContainsString('OnClick="UploadClickedAsync"', $source);
        $client = (string) file_get_contents(resource_path('js/media-batch-clear-selection-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $client);
        $this->assertStringContainsString('data-canonical-operation={MEDIA_BATCH_UPLOAD_OPERATION_ID}', $client);
        $this->assertStringContainsString('authorizedMediaUploadEndpoint(context)', $client);
        $this->assertStringContainsString("transfer.state !== 'queued'", $client);

        $route = Route::getRoutes()->match(Request::create('/api/v1/tenants/alpha/sites/7/media', 'POST'));
        $this->assertSame(ContentApiController::class.'@uploadMedia', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_owner_can_queue_exact_site_file_and_single_file_metadata_without_claiming_remote_success(): void
    {
        Queue::fake();
        Storage::fake('local');
        Http::fake();
        $user = User::factory()->create();
        $member = $this->membership($user, 'alpha', true);
        $site = $this->site($member);

        $response = $this->actingAs($user)->post(
            "/api/v1/tenants/alpha/sites/{$site->id}/media",
            [
                'file' => UploadedFile::fake()->image('photo.png'),
                'title' => 'Photo', 'alt_text' => 'A flower', 'caption' => 'Garden',
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertStatus(202)->assertJsonPath('state', 'queued');
        $transferId = (int) $response->json('id');
        $this->assertGreaterThan(0, $transferId);
        $this->assertDatabaseHas('content_transfers', [
            'id' => $transferId, 'site_id' => $site->id, 'kind' => 'media-upload', 'state' => 'queued',
        ]);
        Queue::assertPushed(MediaUploadJob::class, fn (MediaUploadJob $job) => $job->transferId === $transferId
            && $job->tenantId === $member->tenant_id);
        Http::assertNothingSent(); // 202 confirms a durable job, not a successful WordPress POST.

        $response = $this->actingAs($user)
            ->getJson("/api/v1/tenants/alpha/sites/{$site->id}/transfers/{$transferId}");
        $response->assertOk()->assertJsonPath('state', 'queued');
        $this->assertSame('Photo', $response->json('options.metadata.title'));
        $this->assertSame('A flower', $response->json('options.metadata.alt_text'));
        $this->assertSame('Garden', $response->json('options.metadata.caption'));
    }

    public function test_guest_permission_foreign_tenant_and_foreign_site_fail_closed_before_storage_or_queue(): void
    {
        Queue::fake();
        Storage::fake('local');
        $alphaUser = User::factory()->create();
        $alpha = $this->membership($alphaUser, 'alpha', true);
        $alphaSite = $this->site($alpha);
        $betaUser = User::factory()->create();
        $beta = $this->membership($betaUser, 'beta', true);
        $betaSite = $this->site($beta);
        $limited = User::factory()->create();
        $limitedTenant = $this->membership($limited, 'limited', false);
        $limitedSite = $this->site($limitedTenant);

        $this->post("/api/v1/tenants/alpha/sites/{$alphaSite->id}/media", [
            'file' => UploadedFile::fake()->image('photo.png'),
        ], ['Accept' => 'application/json'])->assertUnauthorized();

        $this->actingAs($limited)->post("/api/v1/tenants/limited/sites/{$limitedSite->id}/media", [
            'file' => UploadedFile::fake()->image('photo.png'),
        ], ['Accept' => 'application/json'])->assertForbidden(); // RBAC 403

        $this->actingAs($alphaUser)->post("/api/v1/tenants/beta/sites/{$betaSite->id}/media", [
            'file' => UploadedFile::fake()->image('photo.png'),
        ], ['Accept' => 'application/json'])->assertNotFound(); // cross-tenant 404

        $this->actingAs($alphaUser)->post("/api/v1/tenants/alpha/sites/{$betaSite->id}/media", [
            'file' => UploadedFile::fake()->image('photo.png'),
        ], ['Accept' => 'application/json'])->assertNotFound(); // foreign site 404

        Queue::assertNotPushed(MediaUploadJob::class);
        $this->assertDatabaseCount('content_transfers', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invalid_file_and_caller_owned_state_cannot_enqueue_or_write_storage(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = User::factory()->create();
        $member = $this->membership($user, 'alpha', true);
        $site = $this->site($member);
        $url = "/api/v1/tenants/alpha/sites/{$site->id}/media";

        $this->actingAs($user)->post($url, [
            'file' => UploadedFile::fake()->create('program.exe', 1, 'application/octet-stream'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->actingAs($user)->post($url, [
            'file' => UploadedFile::fake()->image('huge.png')->size(25601),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->actingAs($user)->post($url, [
            'file' => UploadedFile::fake()->image('photo.png'),
            'tenant_id' => $member->tenant_id, 'site_id' => $site->id, 'state' => 'completed',
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        Queue::assertNotPushed(MediaUploadJob::class);
        $this->assertDatabaseCount('content_transfers', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(1, (new MediaUploadJob($member->tenant_id, 99))->tries);
    }

    private function membership(User $user, string $slug, bool $canEdit): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'batch-upload-'.$slug.'-'.$user->id]);
        $permissions = ['tenant.view', 'sites.view', 'content.view'];
        if ($canEdit) $permissions[] = 'content.edit';
        foreach ($permissions as $name) {
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
            'name' => 'Media upload site',
            'url' => 'https://'.$membership->tenant->slug.'.example.test',
            'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        return $site;
    }
}
