<?php

namespace Tests\Feature;

use App\Http\Controllers\ApprovalsReportExportController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportsRouteProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = [
        'AIMW-CONT-5D18F49928' => ['canonical.workspace.reports', 'tenants/{tenant}/module/reports', '/module/reports'],
        'AIMW-CONT-8140D785B5' => ['canonical.alias.reports', 'tenants/{tenant}/reports', '/reports'],
    ];

    public function test_exact_report_route_operations_are_bound_to_the_real_guarded_controller(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $rows = collect($ledger['operations'])->keyBy('operation_id');

        foreach (self::ROUTES as $operationId => [$routeName, $expectedUri, $sourceRoute]) {
            $row = $rows->get($operationId);
            $this->assertNotNull($row, "Missing canonical report route {$operationId}");
            $this->assertSame('route', $row['kind'], $operationId);
            $this->assertSame('content', $row['domain'], $operationId);
            $this->assertSame($sourceRoute, $row['route_screen'], $operationId);
            $this->assertFalse((bool) $row['mutation'], $operationId);

            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, "Missing Laravel route {$routeName}");
            $this->assertSame($expectedUri, $route->uri(), $operationId);
            $this->assertStringContainsString(ApprovalsReportExportController::class.'@show', $route->getActionName(), $operationId);
            $this->assertContains('auth', $route->gatherMiddleware(), $operationId);
            $this->assertContains('tenant.context', $route->gatherMiddleware(), $operationId);
        }
    }

    public function test_both_report_routes_render_real_tenant_scoped_report_state(): void
    {
        [, $member] = $this->tenantMember('alpha', ['reports.view']);

        foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $path) {
            $this->actingAs($member->user)->get($path)
                ->assertOk()
                ->assertSee('Approvals report')
                ->assertSee('No approval rows are available for this tenant.');
        }
    }

    public function test_report_routes_fail_closed_for_guest_missing_permission_and_foreign_tenant(): void
    {
        [, $alpha] = $this->tenantMember('alpha', ['reports.view']);
        $this->tenantMember('beta', ['reports.view']);
        [, $limited] = $this->tenantMember('limited', []);

        foreach (['/reports', '/module/reports'] as $suffix) {
            $this->get('/tenants/alpha'.$suffix)->assertRedirect('/login');
        }

        foreach (['/reports', '/module/reports'] as $suffix) {
            $this->actingAs($limited->user)->get('/tenants/limited'.$suffix)->assertForbidden();
            $this->actingAs($alpha->user)->get('/tenants/beta'.$suffix)->assertNotFound();
        }
    }

    public function test_authenticated_report_aliases_share_bilingual_dashboard_shell_without_cross_tenant_or_export_leak(): void
    {
        [, $viewer] = $this->tenantMember('alpha', ['reports.view']);
        $this->withoutVite();

        $original = app()->getLocale();

        try {
            foreach ([
                'en' => ['ltr', 'Reports & Exports', 'Approvals report'],
                'ar' => ['rtl', 'التقارير والتصدير', 'تقرير الموافقات'],
            ] as $locale => [$direction, $title, $approvalsTitle]) {
                app()->setLocale($locale);

                foreach (['/tenants/alpha/reports', '/tenants/alpha/module/reports'] as $url) {
                    $response = $this->actingAs($viewer->user)->get($url)
                        ->assertOk()
                        ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                        ->assertSee('data-dashboard-shell="aiwmweb"', false)
                        ->assertSee('class="app-shell workspace-read-shell"', false)
                        ->assertSee('class="sidebar"', false)
                        ->assertSee('class="topbar"', false)
                        ->assertSee('id="workspace-read-content"', false)
                        ->assertSee('class="breadcrumb"', false)
                        ->assertSee('aria-current="page"', false)
                        ->assertSee('id="report-sites"', false)
                        ->assertSee('id="report-planner"', false)
                        ->assertSee('id="report-approvals"', false)
                        ->assertSee($title)
                        ->assertSee($approvalsTitle)
                        ->assertSee('href="/tenants/alpha/reports"', false)
                        ->assertDontSee('href="/tenants/beta/', false)
                        ->assertDontSee('href="/tenants/alpha/reports/approvals.csv"', false)
                        ->assertDontSee('href="/tenants/alpha/reports/sites.csv"', false)
                        ->assertDontSee('href="/tenants/alpha/reports/content-planner.csv"', false);
                }
            }
        } finally {
            app()->setLocale($original);
        }
    }

    public function test_report_shell_uses_real_compiled_css_and_responsive_keyboard_reachable_tables(): void
    {
        $source = (string) file_get_contents(resource_path('views/reports/approvals-export.blade.php'));

        $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
        $this->assertStringContainsString("@include('layouts.partials.workspace-read-styles')", $source);
        $this->assertStringContainsString("@include('layouts.partials.workspace-read-sidebar')", $source);
        $this->assertStringContainsString("@include('layouts.partials.workspace-read-topbar')", $source);
        $this->assertStringContainsString('data-dashboard-shell="aiwmweb"', $source);
        $this->assertStringContainsString('class="reports-table-scroll" role="region"', $source);
        $this->assertStringContainsString('tabindex="0"', $source);
        $this->assertStringContainsString('overflow-x: auto', $source);
        $this->assertStringContainsString('@media (max-width: 760px)', $source);

        $controller = (string) file_get_contents(app_path('Http/Controllers/ApprovalsReportExportController.php'));
        $this->assertStringContainsString("'activeTenant' => \$context->tenant()", $controller);
        $this->assertStringContainsString("\$authorizer->authorize('reports.view')", $controller);
        $this->assertStringContainsString("\$authorizer->authorize('reports.manage')", $controller);
    }

    private function tenantMember(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $slug.'-reports-route-role']);

        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return [$tenant, $membership];
    }
}
