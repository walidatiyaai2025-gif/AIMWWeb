<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ObservabilityApiShapeSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnostics_returns_real_separate_arrays_and_tenant_only_rows(): void
    {
        [$alpha, $actor] = $this->member('alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        [$beta] = $this->member('beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);

        $this->seedFailedOperation($alpha, 'alpha.failed');
        $this->seedFailedOperation($beta, 'beta.private');
        $this->seedAudit($alpha, 'alpha.audit');
        $this->seedAudit($beta, 'beta.private');

        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/diagnostics')
            ->assertOk()
            ->assertJsonStructure(['failed_operations', 'recent_audit'])
            ->assertJsonCount(1, 'failed_operations')
            ->assertJsonCount(1, 'recent_audit');

        $response->assertJsonFragment(['type' => 'alpha.failed']);
        $response->assertJsonFragment(['event' => 'alpha.audit']);
        $this->assertStringNotContainsString('beta.private', $response->getContent());
        $this->assertArrayNotHasKey('data', $response->json());
    }

    public function test_diagnostics_never_transmits_payload_failure_or_audit_metadata_even_to_authorized_user(): void
    {
        [$alpha, $actor] = $this->member('alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        [$beta] = $this->member('beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $this->seedFailedOperation($alpha, 'sync.failed');
        $this->seedFailedOperation($beta, 'beta.private');
        $this->seedAudit($alpha, 'site.updated');
        $this->seedAudit($beta, 'beta.private');

        DB::table('operation_executions')->where('tenant_id', $alpha->id)->update([
            'payload' => json_encode(['api_key' => 'OPERATION_PAYLOAD_SECRET'], JSON_THROW_ON_ERROR),
            'result' => json_encode(['token' => 'OPERATION_RESULT_SECRET'], JSON_THROW_ON_ERROR),
            'failure' => 'OPERATION_FAILURE_PRIVATE_DETAIL',
            'subject_id' => 'PRIVATE_SUBJECT_IDENTIFIER',
        ]);
        DB::table('audit_events')->where('tenant_id', $alpha->id)->update([
            'actor_user_id' => $actor->id,
            'metadata' => json_encode(['password' => 'AUDIT_METADATA_SECRET'], JSON_THROW_ON_ERROR),
            'subject_id' => 'PRIVATE_AUDIT_SUBJECT',
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/diagnostics')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonCount(1, 'failed_operations')
            ->assertJsonCount(1, 'recent_audit')
            ->assertJsonPath('failed_operations.0.type', 'sync.failed')
            ->assertJsonPath('failed_operations.0.status', 'failed')
            ->assertJsonPath('recent_audit.0.event', 'site.updated');

        $operation = $response->json('failed_operations.0');
        $audit = $response->json('recent_audit.0');
        $this->assertSameCanonicalizing(['id', 'type', 'status', 'created_at'], array_keys($operation));
        $this->assertSameCanonicalizing(['id', 'event', 'occurred_at'], array_keys($audit));

        foreach ([
            'OPERATION_PAYLOAD_SECRET', 'OPERATION_RESULT_SECRET',
            'OPERATION_FAILURE_PRIVATE_DETAIL', 'AUDIT_METADATA_SECRET',
            'PRIVATE_SUBJECT_IDENTIFIER', 'PRIVATE_AUDIT_SUBJECT',
            'beta.private', 'actor_user_id', 'tenant_id',
            'metadata', 'payload', 'result', 'failure', 'correlation_id',
        ] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent(), 'Diagnostics must omit '.$private);
        }
    }

    public function test_genuine_empty_arrays_are_returned_and_guest_or_unprivileged_user_cannot_read(): void
    {
        [$alpha, $user] = $this->member('alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $this->getJson('/tenants/alpha/admin/diagnostics')->assertUnauthorized();

        $this->actingAs($user)->getJson('/tenants/alpha/admin/diagnostics')
            ->assertOk()
            ->assertExactJson(['failed_operations' => [], 'recent_audit' => []]);

        [, $limited] = $this->member('limited', ['tenant.view', 'diagnostics.view']);
        $this->actingAs($limited)
            ->getJson('/tenants/limited/admin/diagnostics')
            ->assertForbidden();
    }

    public function test_direct_foreign_tenant_access_is_rejected(): void
    {
        [, $actor] = $this->member('alpha', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        [$beta] = $this->member('beta', ['tenant.view', 'operations.manage', 'diagnostics.view']);
        $this->seedFailedOperation($beta, 'beta.private');

        $this->actingAs($actor)
            ->getJson('/tenants/beta/admin/diagnostics')
            ->assertNotFound();
    }

    private function seedFailedOperation(Tenant $tenant, string $type): void
    {
        DB::table('operation_executions')->insert([
            'tenant_id' => $tenant->id,
            'type' => $type,
            'status' => 'failed',
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedAudit(Tenant $tenant, string $event): void
    {
        DB::table('audit_events')->insert([
            'tenant_id' => $tenant->id,
            'actor_user_id' => null,
            'event' => $event,
            'metadata' => '{}',
            'occurred_at' => now(),
        ]);
    }

    private function member(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "observability-{$slug}-{$user->id}"]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $user];
    }
}
