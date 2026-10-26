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

final class AppDataGridToggleSortTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-0108E40967';

    public function test_canonical_ledger_identity_matches_app_data_grid_toggle_sort_async(): void
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
        $this->assertSame('@SortText [ToggleSortAsync]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $operation['verification']);
    }

    public function test_source_semantics_and_laravel_runtime_binding_are_exact(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Shared/AppDataGrid.razor'));
        $contract = (string) file_get_contents(resource_path('js/app-data-grid-sort-control.tsx'));
        $consumer = (string) file_get_contents(resource_path('js/pages.tsx'));
        $controller = (string) file_get_contents(app_path('Http/Controllers/EmailNotificationController.php'));
        $service = (string) file_get_contents(app_path('Email/Services/EmailDeliveryService.php'));

        $this->assertStringContainsString('Text="@SortText"', $source);
        $this->assertStringContainsString('AriaPressed="@_sortDescending"', $source);
        $this->assertStringContainsString('OnClick="ToggleSortAsync"', $source);
        $this->assertStringContainsString('_sortDescending = !_sortDescending;', $source);
        $this->assertStringContainsString('CurrentPage = 1;', $source);
        $this->assertStringContainsString('await SortChanged.InvokeAsync(_sortDescending);', $source);
        $this->assertLessThan(
            strpos($source, 'private IReadOnlyList<TItem> PagedItems'),
            strpos($source, 'private IEnumerable<TItem> SortedItems'),
        );

        $this->assertStringContainsString(self::OPERATION_ID, $contract);
        $this->assertStringContainsString('return !sortDescending;', $contract);
        $this->assertStringContainsString('data-canonical-operation={APP_DATA_GRID_TOGGLE_SORT_OPERATION_ID}', $contract);
        $this->assertStringContainsString('aria-pressed={sortDescending}', $contract);

        $this->assertStringContainsString('AppDataGridSortControl', $consumer);
        $this->assertStringContainsString("sort_desc: appDataGridSortDescending ? '1' : '0'", $consumer);
        $this->assertStringContainsString('emailHistoryFilters.correlation, appDataGridSortDescending', $consumer);
        $this->assertStringContainsString('setAppDataGridSortDescending(sortDescending);', $consumer);
        $this->assertStringContainsString('setPage(1);', $consumer);
        $this->assertStringContainsString("'sort_desc' => ['nullable', 'boolean']", $controller);
        $this->assertStringContainsString("array_key_exists('sort_desc', \$filters)", $service);
        $this->assertStringContainsString("\$query->orderBy('created_at', \$direction)->orderBy('id', \$direction);", $service);
        $this->assertLessThan(
            strpos($service, '->forPage($page, $perPage)'),
            strpos($service, "\$query->orderBy('created_at', \$direction)"),
        );
    }

    public function test_server_sort_is_applied_to_the_authoritative_tenant_set_before_pagination(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha-sort', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        $site = $this->site($membership, 'Alpha Sort');

        $oldest = $this->delivery($membership, $site, 'SENT', now()->subDays(3));
        $middle = $this->delivery($membership, $site, 'FAILED', now()->subDays(2));
        $newest = $this->delivery($membership, $site, 'SENT', now()->subDay());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-sort/email/deliveries?sort_desc=0&page=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.delivery_id', $oldest->delivery_id)
            ->assertJsonPath('data.1.delivery_id', $middle->delivery_id);

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha-sort/email/deliveries?sort_desc=1&page=1&per_page=2')
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('data.0.delivery_id', $newest->delivery_id)
            ->assertJsonPath('data.1.delivery_id', $middle->delivery_id);
    }

    public function test_real_email_history_consumer_is_read_only_and_foreign_tenant_fails_closed(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $before = EmailDelivery::withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?sort_desc=0&page=1')
            ->assertOk()
            ->assertJsonPath('current_page', 1);

        $this->assertSame($before, EmailDelivery::withoutGlobalScopes()->count());

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/beta/email/deliveries?sort_desc=1&page=1')
            ->assertNotFound();

        $this->assertSame($before, EmailDelivery::withoutGlobalScopes()->count());
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "app-data-grid-sort-{$slug}-{$user->id}"]);

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

    private function delivery(TenantMembership $membership, Site $site, string $status, \DateTimeInterface $createdAt): EmailDelivery
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $configuration = MailConfiguration::query()->create([
            'configuration_key' => 'site-'.$site->id.'-'.Str::lower(Str::random(6)),
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
            'idempotency_key' => 'app-data-grid-sort-'.Str::uuid(),
            'recipient' => 'person@example.test',
            'recipient_hash' => hash('sha256', 'person@example.test'),
            'template_stable_id' => 'system-test',
            'locale' => 'en',
            'status' => $status,
            'attempt_count' => 1,
            'max_attempts' => 4,
            'variables' => [],
        ]);
        $delivery->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $context->forget();

        return $delivery->fresh();
    }
}
