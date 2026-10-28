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

final class SiteOnboardingPreviousStepTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-SYNC-C1B6FA6677';

    public function test_previous_step_is_a_client_only_onboarding_control_with_no_backend_mutation(): void
    {
        $source = (string) file_get_contents(resource_path('js/site-onboarding-save-test-sync-control.tsx'));
        $this->assertStringContainsString(self::OPERATION_ID, $source);
        $this->assertStringContainsString('data-canonical-operation={SITE_ONBOARDING_PREVIOUS_OPERATION_ID}', $source);
        $this->assertStringContainsString('cancelOperationId={SITE_ONBOARDING_PREVIOUS_OPERATION_ID}', $source);
        $this->assertStringContainsString('showCancel={!result}', $source);
        $start = strpos($source, 'const previousStep = () =>');
        $end = strpos($source, 'const finalSubmitDisabled', $start);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        $previous = substr($source, $start, $end - $start);
        $this->assertStringNotContainsString('apiRequest', $previous);
        $this->assertStringNotContainsString('fetch(', $previous);
        $this->assertStringContainsString('setStep((step - 1)', $previous);
    }

    public function test_the_underlying_onboarding_mutation_remains_protected_from_a_foreign_tenant(): void
    {
        $user = User::factory()->create();
        $alpha = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $beta = Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        app(TenantContext::class)->activate($alpha);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "previous-alpha-{$user->id}"]);
        foreach (['tenant.view', 'sites.manage'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $alpha->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $alpha->id]);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->postJson('/api/tenants/beta/sites/onboarding', [])
            ->assertNotFound();

        $this->assertNotSame($alpha->id, $beta->id);
    }
}
