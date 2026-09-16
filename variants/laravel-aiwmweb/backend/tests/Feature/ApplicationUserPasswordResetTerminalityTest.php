<?php

namespace Tests\Feature;

use App\Http\Controllers\ApplicationUserPasswordResetController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Providers\ApplicationUserPasswordResetRouteServiceProvider;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApplicationUserPasswordResetTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-3BE40F2E00';

    public function test_exact_canonical_operation_is_materialized_as_adapted(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ApplicationUsers.razor', $operation['current_source']);
    }

    public function test_route_is_session_guarded_tenant_scoped_and_operation_bound(): void
    {
        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/admin/members/7/reset-password', 'POST'));

        $this->assertSame(ApplicationUserPasswordResetController::class, ltrim($route->getActionName(), '\\'));
        $this->assertSame('canonical.application-users.reset-password', $route->getName());
        $this->assertSame(ApplicationUserPasswordResetRouteServiceProvider::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['tenant', 'membership'], $route->parameterNames());
    }

    public function test_guest_missing_permission_and_foreign_membership_fail_closed(): void
    {
        [$alpha, $ownerA] = $this->tenantWithOwner('alpha');
        [, $ownerB] = $this->tenantWithOwner('beta');
        $targetA = $this->member($alpha, 'alpha-target@example.test');

        $payload = $this->validPayload($targetA->user->email);
        $this->postJson("/tenants/alpha/admin/members/{$targetA->id}/reset-password", $payload)->assertUnauthorized();

        $limited = $this->member($alpha, 'limited@example.test', ['tenant.view']);
        $this->actingAs($limited->user)
            ->postJson("/tenants/alpha/admin/members/{$targetA->id}/reset-password", $payload)
            ->assertForbidden();

        $this->actingAs($ownerA->user)
            ->postJson("/tenants/alpha/admin/members/{$ownerB->id}/reset-password", $this->validPayload($ownerB->user->email))
            ->assertNotFound();
    }

    public function test_validation_confirmation_and_caller_supplied_identity_are_rejected_without_mutation(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('alpha');
        $target = $this->member($tenant, 'target@example.test');
        $oldHash = $target->user->password;
        DB::table('sessions')->insert($this->sessionRow('target-session', $target->user_id));

        $this->actingAs($owner->user)
            ->postJson("/tenants/alpha/admin/members/{$target->id}/reset-password", [
                'password' => 'weakpass',
                'password_confirmation' => 'weakpass',
                'confirmation_identity' => $target->user->email,
            ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->actingAs($owner->user)
            ->postJson("/tenants/alpha/admin/members/{$target->id}/reset-password", $this->validPayload('wrong@example.test'))
            ->assertUnprocessable()->assertJsonValidationErrors('confirmation_identity');

        $poisoned = $this->validPayload($target->user->email) + [
            'user_id' => $owner->user_id,
            'tenant_id' => $tenant->id,
            'membership_id' => $owner->id,
        ];
        $this->actingAs($owner->user)
            ->postJson("/tenants/alpha/admin/members/{$target->id}/reset-password", $poisoned)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id', 'tenant_id', 'membership_id']);

        $this->assertSame($oldHash, $target->user->fresh()->password);
        $this->assertDatabaseHas('sessions', ['id' => 'target-session', 'user_id' => $target->user_id]);
        $this->assertDatabaseMissing('audit_events', ['event' => 'member.password_reset']);
    }

    public function test_success_commits_hash_revokes_target_sessions_audits_safely_and_returns_no_secret(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('alpha');
        $target = $this->member($tenant, 'target@example.test');
        $other = $this->member($tenant, 'other@example.test');
        DB::table('sessions')->insert([
            $this->sessionRow('target-a', $target->user_id),
            $this->sessionRow('target-b', $target->user_id),
            $this->sessionRow('other-session', $other->user_id),
        ]);

        $password = 'StrongPass9';
        $response = $this->actingAs($owner->user)
            ->postJson("/tenants/alpha/admin/members/{$target->id}/reset-password", [
                'password' => $password,
                'password_confirmation' => $password,
                'confirmation_identity' => strtoupper($target->user->email),
            ])
            ->assertOk()
            ->assertJsonPath('membership_id', $target->id)
            ->assertJsonPath('user.id', $target->user_id)
            ->assertJsonPath('user.email', $target->user->email)
            ->assertJsonPath('password_changed', true)
            ->assertJsonPath('revoked_sessions', 2);

        $freshUser = User::query()->findOrFail($target->user_id);
        $this->assertTrue(Hash::check($password, $freshUser->password));
        $this->assertDatabaseMissing('sessions', ['user_id' => $target->user_id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $other->user_id]);
        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => $tenant->id,
            'actor_user_id' => $owner->user_id,
            'event' => 'member.password_reset',
            'subject_type' => 'tenant_membership',
            'subject_id' => (string) $target->id,
        ]);

        $body = $response->getContent();
        $audit = (string) DB::table('audit_events')->where('event', 'member.password_reset')->value('metadata');
        $this->assertStringNotContainsString($password, $body);
        $this->assertStringNotContainsString($freshUser->password, $body);
        $this->assertStringNotContainsString($password, $audit);
        $this->assertStringNotContainsString($freshUser->password, $audit);
    }

    public function test_frontend_uses_same_origin_csrf_no_retry_duplicate_suppression_and_secret_inputs(): void
    {
        $control = (string) file_get_contents(resource_path('js/application-user-password-reset-control.tsx'));
        $core = (string) file_get_contents(resource_path('js/core.ts'));

        $this->assertStringContainsString("retry: false", $control);
        $this->assertStringContainsString('resetMutation.isPending', $control);
        $this->assertStringContainsString('type="password"', $control);
        $this->assertStringContainsString('confirmation_identity', $control);
        $this->assertStringContainsString("method: 'POST'", $control);
        $this->assertStringContainsString('credentials: \'same-origin\'', $core);
        $this->assertStringContainsString("headers.set('X-CSRF-TOKEN', csrf)", $core);
        $this->assertStringNotContainsString('user_id:', $control);
        $this->assertStringNotContainsString('tenant_id:', $control);
    }

    private function validPayload(string $identity): array
    {
        return [
            'password' => 'StrongPass9',
            'password_confirmation' => 'StrongPass9',
            'confirmation_identity' => $identity,
        ];
    }

    private function sessionRow(string $id, int $userId): array
    {
        return [
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ];
    }

    private function tenantWithOwner(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $membership = $this->member($tenant, $slug.'-owner@example.test', ['tenant.view', 'members.manage'], 'owner');

        return [$tenant, $membership];
    }

    private function member(Tenant $tenant, string $email, array $permissions = ['tenant.view'], string $roleName = 'member'): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $user = User::factory()->create(['email' => $email]);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => $roleName.'-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();

        return $membership;
    }
}
