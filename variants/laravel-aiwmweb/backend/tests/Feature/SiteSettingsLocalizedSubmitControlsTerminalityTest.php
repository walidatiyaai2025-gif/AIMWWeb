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

final class SiteSettingsLocalizedSubmitControlsTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-E1A7A8CAA7';

    public function test_exact_canonical_input_is_the_pending_localized_submit_control_collision(): void
    {
        $ledger = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{Id:guid}/settings', $operation['route_screen']);
        $this->assertSame('@(L.IsArabic ?', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor',
            $operation['current_source'],
        );
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
    }

    public function test_source_and_laravel_preserve_the_two_localized_submit_controls_without_reclaiming_their_mutations(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteSettings.razor'),
        );
        $view = (string) file_get_contents(resource_path('views/sites/settings.blade.php'));

        $this->assertStringContainsString('OnSubmit="SaveProfileAsync"', $source);
        $this->assertStringContainsString('حفظ التغييرات', $source);
        $this->assertStringContainsString('Save changes', $source);
        $this->assertStringContainsString('OnSubmit="SaveCredentialAsync"', $source);
        $this->assertStringContainsString('حفظ واختبار', $source);
        $this->assertStringContainsString('Save and test', $source);

        $this->assertSame(
            2,
            substr_count($view, 'data-canonical-control-operation="'.self::OPERATION_ID.'"'),
        );
        $this->assertStringContainsString('data-canonical-operation="AIMW-BILL-5C15471E4B"', $view);
        $this->assertStringContainsString('data-canonical-operation="AIMW-BILL-723BEA8F1D"', $view);
        $this->assertStringContainsString("'حفظ التغييرات' : 'Save changes'", $view);
        $this->assertStringContainsString("'حفظ واختبار' : 'Save and test'", $view);
    }

    public function test_authorized_english_read_renders_both_controls_and_does_not_mutate_site_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example.test');

        $before = Site::query()->withoutGlobalScopes()->findOrFail($site->id)->only([
            'name',
            'url',
            'status',
            'connection_status',
        ]);

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('Save changes')
            ->assertSee('Save and test');

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'data-canonical-control-operation="'.self::OPERATION_ID.'"'),
        );

        $after = Site::query()->withoutGlobalScopes()->findOrFail($site->id)->only([
            'name',
            'url',
            'status',
            'connection_status',
        ]);
        $this->assertSame($before, $after);
    }

    public function test_arabic_locale_renders_source_equivalent_labels_on_the_same_real_forms(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $site = $this->site($membership, 'Alpha Site', 'https://alpha.example.test');
        app()->setLocale('ar');

        $response = $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertSee('حفظ التغييرات')
            ->assertSee('حفظ واختبار');

        $this->assertSame(
            2,
            substr_count($response->getContent(), 'data-canonical-control-operation="'.self::OPERATION_ID.'"'),
        );
        $response->assertSee('data-canonical-operation="AIMW-BILL-5C15471E4B"', false);
        $response->assertSee('data-canonical-operation="AIMW-BILL-723BEA8F1D"', false);
    }

    public function test_read_only_viewer_does_not_receive_manage_submit_controls(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'reader', ['tenant.view', 'sites.view']);
        $site = $this->site($membership, 'Reader Site', 'https://reader.example.test');

        $response = $this->actingAs($user)
            ->get('/tenants/reader/sites/'.$site->id.'/settings')
            ->assertOk()
            ->assertDontSee('Save changes')
            ->assertDontSee('Save and test');

        $this->assertSame(
            0,
            substr_count($response->getContent(), 'data-canonical-control-operation="'.self::OPERATION_ID.'"'),
        );
    }

    public function test_missing_view_permission_and_foreign_tenant_site_fail_closed(): void
    {
        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited Site', 'https://limited.example.test');

        $this->actingAs($limited)
            ->get('/tenants/limited/sites/'.$limitedSite->id.'/settings')
            ->assertForbidden();

        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'sites.manage']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'sites.view', 'sites.manage']);
        $alphaSite = $this->site($alpha, 'Alpha Site', 'https://alpha.example.test');
        $foreignTenantSite = $this->site($beta, 'Beta Site', 'https://beta.example.test');

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$alphaSite->id.'/settings')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tenants/alpha/sites/'.$foreignTenantSite->id.'/settings')
            ->assertNotFound();
    }

    public function test_terminal_closure_evidence_is_exactly_operation_scoped(): void
    {
        $evidence = json_decode(
            (string) file_get_contents(base_path('../docs/closure-evidence/site-settings-localized-submit-controls-terminality.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame(self::OPERATION_ID, $evidence['operation']['operation_id'] ?? null);
        $this->assertSame('ADAPTED', $evidence['operation']['terminal_state'] ?? null);
        $this->assertSame([self::OPERATION_ID], $evidence['scope_guards']['claimed_operations'] ?? null);
        $this->assertFalse((bool) ($evidence['scope_guards']['profile_mutation_claimed'] ?? true));
        $this->assertFalse((bool) ($evidence['scope_guards']['credential_mutation_claimed'] ?? true));
        $this->assertFalse((bool) ($evidence['scope_guards']['aspnet_product_modified'] ?? true));
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-settings-submit-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name, string $url): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => $url,
            'status' => 'active',
            'connection_status' => 'connected',
        ]);
        $context->forget();

        return $site;
    }
}

// exact-head gate retrigger after parity materialization and composition sync
