<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\BuildInformationReadService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CopyBuildReportVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-68B372C9FE';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_exact_canonical_operation_is_generator_backed_adapted_copy_build_report_control(): void
    {
        $payload = json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('sync', $row['domain']);
        $this->assertSame('/about-build | /release-notes', $row['route_screen']);
        $this->assertStringContainsString('CopyBuildReportClickedAsync', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AboutBuild.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame(
            'variants/laravel-aiwmweb/backend/resources/views/platform/about-build.blade.php',
            $row['laravel_destination'],
        );
        $this->assertSame(
            'variants/laravel-aiwmweb/backend/tests/Feature/CopyBuildReportVisibleControlTerminalityTest.php',
            $row['acceptance_test'],
        );
        $this->assertSame('focused_closure_contract', $row['reconciliation']['evidence_mode']);
        $this->assertSame(
            'variants/laravel-aiwmweb/docs/closure-evidence/copy-build-report-visible-control.json',
            $row['reconciliation']['evidence_path'],
        );
        $this->assertContains(self::OPERATION_ID, $payload['validation']['focused_closure_contract_terminals']);
        $this->assertSame(931, $payload['totals']['total']);
        $this->assertSame(
            $payload['totals']['total'],
            $payload['totals']['terminal'] + $payload['totals']['pending'] + $payload['totals']['blocked'],
        );
        $this->assertTrue((bool) ($payload['validation']['passed'] ?? false));
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
    }

    public function test_both_source_routes_render_the_real_control_with_the_authoritative_build_snapshot(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'execution.view']);
        $snapshot = app(BuildInformationReadService::class)->snapshot();

        foreach (['about-build', 'release-notes'] as $path) {
            $response = $this->actingAs($user)->get("/tenants/alpha/{$path}");
            $response->assertOk()
                ->assertSee('data-copy-build-report', false)
                ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
                ->assertSee('Copy build report')
                ->assertSee('data-copy-build-success', false)
                ->assertSee('data-copy-build-error', false)
                ->assertSee('data-copy-build-retry', false)
                ->assertSee('Retry copy')
                ->assertSee('build-report-payload', false);

            $reportPayload = $this->extractReportPayload($response->getContent());
            $this->assertSame($snapshot['assemblyName'], $reportPayload['assemblyName']);
            $this->assertSame($snapshot['version'], $reportPayload['version']);
            $this->assertSame($snapshot['informationalVersion'], $reportPayload['informationalVersion']);
            $this->assertSame($snapshot['branch'], $reportPayload['branch']);
            $this->assertSame($snapshot['commit'], $reportPayload['commit']);
            $this->assertSame($snapshot['buildTimeUtc'], $reportPayload['buildTimeUtc']);
            $this->assertNull($reportPayload['currentRelease']);
        }

        $this->actingAs($user)->getJson('/api/build')
            ->assertOk()
            ->assertExactJson($snapshot);
    }

    public function test_control_inherits_existing_auth_permission_and_tenant_fail_closed_contract(): void
    {
        foreach (['about-build', 'release-notes'] as $path) {
            $this->get("/tenants/alpha/{$path}")->assertRedirect('/login');
        }

        $limited = User::factory()->create();
        $this->membership($limited, 'alpha', ['execution.view']);

        foreach (['about-build', 'release-notes'] as $path) {
            $this->actingAs($limited)->get("/tenants/alpha/{$path}")->assertForbidden();
            $this->actingAs($limited)->get("/tenants/foreign/{$path}")->assertNotFound();
        }
    }

    private function extractReportPayload(string $html): array
    {
        $matched = preg_match(
            '/<script id="build-report-payload" type="application\/json">(.*?)<\/script>/s',
            $html,
            $matches,
        );
        $this->assertSame(1, $matched, 'The authoritative build-report payload must be rendered into the real page.');

        return json_decode(trim($matches[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "copy-build-report-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
