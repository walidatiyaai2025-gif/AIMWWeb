<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\MailConfiguration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AppDataGridClearFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-FF579F0EBF';

    public function test_canonical_ledger_identity_matches_app_data_grid_clear_filters_async(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('sync', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('component:AppDataGrid', $operation['route_screen']);
        $this->assertSame('@ClearFiltersText [ClearFiltersAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $operation['verification']);
    }

    public function test_production_control_is_wired_to_email_history_authoritative_read_state(): void
    {
        $control = (string) file_get_contents(resource_path('js/app-data-grid-clear-filters-control.tsx'));
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('onSearchInputChange', $control);
        $this->assertStringContainsString('onPageChange(1)', $control);
        $this->assertStringContainsString("await callbacks.onSearchChanged('')", $control);
        $this->assertStringContainsString('await callbacks.onClearFiltersRequested()', $control);

        $this->assertStringContainsString("route.key === 'email-history'", $pages);
        $this->assertStringContainsString('<AppDataGridClearFiltersControl', $pages);
        $this->assertStringContainsString('clearEmailHistoryGridFilters', $pages);
        $this->assertStringContainsString("endpointWithQuery(endpoint, 1, '')", $pages);
    }

    public function test_cleared_email_history_read_is_unfiltered_tenant_scoped_and_non_mutating(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');

        $alphaSent = $this->delivery($alpha, $alphaSite, 'SENT');
        $alphaFailed = $this->delivery($alpha, $alphaSite, 'FAILED');
        $this->delivery($beta, $betaSite, 'SENT');
        $before = EmailDelivery::withoutGlobalScopes()->count();

        $filtered = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?site_id='.$alphaSite->id.'&status=SENT&page=1')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $filtered);
        $this->assertSame($alphaSent->delivery_id, $filtered[0]['delivery_id'] ?? null);

        $unfiltered = $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?page=1')
            ->assertOk()
            ->json('data');

        $deliveryIds = collect($unfiltered)->pluck('delivery_id');
        $this->assertTrue($deliveryIds->contains($alphaSent->delivery_id));
        $this->assertTrue($deliveryIds->contains($alphaFailed->delivery_id));
        $this->assertCount(2, $unfiltered);
        $this->assertSame($before, EmailDelivery::withoutGlobalScopes()->count());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?site_id='.$betaSite->id)
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "app-data-grid-clear-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'.example.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function delivery(TenantMembership $membership, Site $site, string $status): EmailDelivery
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $configuration = MailConfiguration::query()->create([
            'configuration_key' => 'app-data-grid-clear-'.$site->id.'-'.Str::lower(Str::random(6)),
            'site_id' => $site->id,
            'transport' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'from_address' => 'noreply@example.test',
            'from_name' => 'AIWM',
            'enabled' => true,
            'timeout_seconds' => 20,
            'max_attempts' => 4,
        ]);

        $delivery = EmailDelivery::query()->create([
            'in_app_notification_id' => null,
            'mail_configuration_id' => $configuration->id,
            'event_id' => (string) Str::uuid(),
            'delivery_id' => (string) Str::uuid(),
            'idempotency_key' => 'app-data-grid-clear-'.Str::uuid(),
            'recipient' => 'person@example.test',
            'recipient_hash' => hash('sha256', 'person@example.test'),
            'template_stable_id' => 'system-test',
            'locale' => 'en',
            'status' => $status,
            'attempt_count' => $status === 'SENT' ? 1 : 2,
            'max_attempts' => 4,
            'variables' => [],
        ]);
        $context->forget();

        return $delivery;
    }
}
