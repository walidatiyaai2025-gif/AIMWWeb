<?php

namespace Tests\Feature;

use App\Http\Controllers\SiteOperationDetailsReadController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteOperationHistory;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Sites\SiteOperationHistoryService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SiteOperationsDetailsLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-4147188AB1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_source_and_laravel_bind_the_exact_details_row_navigation(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteOperationsOverview.razor'),
        );
        $pages = (string) file_get_contents(resource_path('js/pages.tsx'));
        $control = (string) file_get_contents(resource_path('js/site-operations-details-link-control.tsx'));

        $this->assertStringContainsString('href="@($"/operations/sites/{item.Id}")"', $source);
        $this->assertStringContainsString('Details', $source);
        $this->assertStringContainsString('تفاصيل', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('row.correlation_id', $control);
        $this->assertStringContainsString('/operations/sites/', $control);
        $this->assertStringContainsString('SiteOperationsDetailsLinkControl context={context} row={row}', $pages);
    }

    public function test_source_equivalent_alias_is_an_explicit_guarded_tenant_read(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/operations/sites/123e4567-e89b-42d3-a456-426614174000', 'GET'),
        );

        $this->assertSame('canonical.alias.operations-site-details', $route->getName());
        $this->assertSame(SiteOperationDetailsReadController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame('execution.view', $route->defaults['workspace_permissions'] ?? null);
    }

    public function test_authoritative_list_identifier_opens_the_same_tenant_operation_without_mutation(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha-details-link', ['tenant.view', 'execution.view']);
        $operation = $this->recordOperation($alpha, 'Alpha Details Site');

        $foreignOwner = User::factory()->create();
        $beta = $this->membership($foreignOwner, 'beta-details-link', ['tenant.view', 'execution.view']);
        $foreignOperation = $this->recordOperation($beta, 'Beta Secret Details Site');

        $before = SiteOperationHistory::query()->withoutGlobalScopes()->count();

        $this->actingAs($user)
            ->getJson('/tenants/alpha-details-link/route-api/site-operations')
            ->assertOk()
            ->assertJsonFragment([
                'correlation_id' => $operation->correlation_id,
                'operation' => 'content.sync',
            ])
            ->assertJsonMissing(['correlation_id' => $foreignOperation->correlation_id]);

        $this->actingAs($user)
            ->get('/tenants/alpha-details-link/operations/sites/'.$operation->correlation_id)
            ->assertOk()
            ->assertSee((string) $operation->correlation_id)
            ->assertSee('Alpha Details Site')
            ->assertDontSee('Beta Secret Details Site');

        $this->actingAs($user)
            ->get('/tenants/alpha-details-link/operations/sites/'.$foreignOperation->correlation_id)
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/tenants/beta-details-link/operations/sites/'.$foreignOperation->correlation_id)
            ->assertNotFound();

        $this->assertSame($before, SiteOperationHistory::query()->withoutGlobalScopes()->count());
    }

    public function test_missing_execution_permission_and_malformed_identifier_fail_closed(): void
    {
        $limited = User::factory()->create();
        $tenant = $this->membership($limited, 'limited-details-link', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/'.$tenant->slug.'/operations/sites/123e4567-e89b-42d3-a456-426614174000')
            ->assertForbidden();

        $allowed = User::factory()->create();
        $allowedTenant = $this->membership($allowed, 'allowed-details-link', ['tenant.view', 'execution.view']);

        $this->actingAs($allowed)
            ->get('/tenants/'.$allowedTenant->slug.'/operations/sites/not-a-guid')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => Str::headline($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => $slug.'-role-'.$user->id]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }

    private function recordOperation(Tenant $tenant, string $siteName): SiteOperationHistory
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $site = Site::query()->create([
            'name' => $siteName,
            'url' => 'https://'.Str::slug($siteName).'.example.test',
            'status' => 'active',
        ]);
        $operation = app(SiteOperationHistoryService::class)->record(
            $site->id,
            'content.sync',
            true,
            'Authoritative operation details',
            ['trace_id' => (string) Str::uuid()],
            1,
            (string) Str::uuid(),
            now()->subSecond(),
        );

        $context->forget();

        return $operation;
    }
}
