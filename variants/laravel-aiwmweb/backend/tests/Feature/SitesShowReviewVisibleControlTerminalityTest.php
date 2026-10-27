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

final class SitesShowReviewVisibleControlTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-CA17F05024';

    public function test_exact_canonical_operation_matches_source_show_review_semantics(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites', $operation['route_screen']);
        $this->assertStringContainsString('ShowReviewSites', (string) $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Sites.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Sites.razor'),
        );

        $this->assertStringContainsString('@onclick="ShowReviewSites"', $source);
        $this->assertStringContainsString('private void ShowReviewSites() => SetFilter("review");', $source);
        $this->assertStringContainsString(
            '"review" => site.ConnectionStatus is not AIWordPressManager.Domain.Enums.SiteConnectionStatus.Connected and not AIWordPressManager.Domain.Enums.SiteConnectionStatus.Unknown',
            $source,
        );
    }

    public function test_laravel_control_is_local_read_only_and_uses_authoritative_connection_status(): void
    {
        $frontend = (string) file_get_contents(resource_path('js/pages.tsx'));

        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString("setSitesFilter('review')", $frontend);
        $this->assertStringContainsString("row.connection_status ?? 'unknown'", $frontend);
        $this->assertStringContainsString("normalized !== 'connected' && normalized !== 'unknown'", $frontend);
        $this->assertStringContainsString("'يحتاج مراجعة' : 'Needs review'", $frontend);

        $start = strpos($frontend, 'function isSiteReviewRow');
        $end = strpos($frontend, 'function endpointWithQuery', $start);
        $slice = substr($frontend, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
    }

    public function test_sites_view_member_can_reach_review_filter_surface_without_manage_permission(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.view']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites')
            ->assertOk();
    }

    public function test_missing_sites_view_and_foreign_tenant_fail_closed(): void
    {
        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);

        $this->actingAs($limited)
            ->get('/tenants/limited/sites')
            ->assertForbidden();

        $authorized = User::factory()->create();
        $this->membership($authorized, 'alpha', ['tenant.view', 'sites.view']);
        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);

        $this->actingAs($authorized)
            ->get('/tenants/beta/sites')
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
            'name' => "sites-show-review-{$slug}-{$user->id}",
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
