<?php

namespace Tests\Feature;

use App\Http\Controllers\CanonicalEmailScheduleController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EmailSchedulesCreateTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-7101AC9489';

    public function test_source_contract_and_tenant_route_are_bound_exactly(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/EmailSchedules.razor'));
        $control = (string) file_get_contents(resource_path('js/email-schedules-create-control.tsx'));

        $this->assertStringContainsString('OnClick="CreateClicked"', $source);
        $this->assertStringContainsString('CreateAccountScheduleAsync(input)', $source);
        $this->assertStringContainsString('CreateSiteScheduleAsync(siteId, input)', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $control);

        $route = Route::getRoutes()->match(Request::create('/api/tenants/alpha/email/schedules', 'POST'));
        $this->assertSame(CanonicalEmailScheduleController::class.'@store', ltrim($route->getActionName(), '\\'));
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
    }

    public function test_account_create_is_idempotent_and_authoritatively_reread(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.test']);
        $this->membership($user, 'alpha', ['operations.manage']);

        $first = $this->actingAs($user)->postJson('/api/tenants/alpha/email/schedules', $this->payload(), [
            'Idempotency-Key' => 'email-schedule-create-1',
        ])->assertCreated()
            ->assertJsonPath('operation_id', self::OPERATION_ID)
            ->assertJsonPath('data.scope', 'Account')
            ->assertJsonPath('data.frequency', 'Daily')
            ->assertJsonPath('data.timezone_id', 'UTC');

        $id = (int) $first->json('data.id');

        $second = $this->actingAs($user)->postJson('/api/tenants/alpha/email/schedules', $this->payload(), [
            'Idempotency-Key' => 'email-schedule-create-1',
        ])->assertCreated()->assertJsonPath('data.id', $id);

        $this->assertDatabaseCount('email_schedules', 1);
        $this->assertDatabaseHas('email_schedules', [
            'id' => $id,
            'tenant_id' => Tenant::query()->where('slug', 'alpha')->value('id'),
            'site_id' => null,
            'frequency' => 'Daily',
            'timezone_id' => 'UTC',
            'time_of_day' => '08:00',
            'retry_count' => 3,
            'retry_delay_minutes' => 5,
        ]);
        $this->assertArrayNotHasKey('recipient', $first->json('data'));
    }

    public function test_site_create_is_tenant_scoped_and_caller_identity_overrides_are_rejected(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $alpha = $this->membership($owner, 'alpha', ['operations.manage']);
        $alphaSite = $this->site($alpha);

        $other = User::factory()->create(['email' => 'other@example.test']);
        $beta = $this->membership($other, 'beta', ['operations.manage']);
        $betaSite = $this->site($beta);

        $this->actingAs($owner)->postJson('/api/tenants/alpha/email/schedules', [
            ...$this->payload(),
            'scope' => 'Site',
            'site_id' => $betaSite->id,
        ], ['Idempotency-Key' => 'foreign-site'])->assertNotFound();

        $this->actingAs($owner)->postJson('/api/tenants/alpha/email/schedules', [
            ...$this->payload(),
            'tenant_id' => 999,
            'owner_user_id' => 999,
            'recipient' => 'attacker@example.test',
        ], ['Idempotency-Key' => 'spoof'])->assertUnprocessable();

        $this->actingAs($owner)->postJson('/api/tenants/alpha/email/schedules', [
            ...$this->payload(),
            'scope' => 'Site',
            'site_id' => $alphaSite->id,
        ], ['Idempotency-Key' => 'owned-site'])
            ->assertCreated()
            ->assertJsonPath('data.site_id', $alphaSite->id);
    }

    public function test_guest_and_missing_permission_fail_closed(): void
    {
        $this->postJson('/api/tenants/alpha/email/schedules', $this->payload(), ['Idempotency-Key' => 'guest'])
            ->assertUnauthorized();

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->postJson('/api/tenants/limited/email/schedules', $this->payload(), [
            'Idempotency-Key' => 'limited',
        ])->assertForbidden();
    }

    private function payload(): array
    {
        return [
            'scope' => 'Account',
            'site_id' => null,
            'frequency' => 'Daily',
            'time_of_day' => '08:00',
            'weekday' => null,
            'month_day' => null,
            'timezone_id' => 'UTC',
            'culture' => 'en',
            'retry_count' => 3,
            'retry_delay_minutes' => 5,
            'enabled' => true,
        ];
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->firstOrCreate(['user_id' => $user->id], ['status' => 'active']);
        $role = Role::query()->create(['name' => 'EmailSchedule-'.$slug.'-'.$user->id]);
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
        try {
            return Site::query()->create([
                'name' => ucfirst($membership->tenant->slug).' Site',
                'url' => 'https://'.$membership->tenant->slug.'.example.test',
                'status' => 'active',
            ]);
        } finally {
            $context->forget();
        }
    }
}
