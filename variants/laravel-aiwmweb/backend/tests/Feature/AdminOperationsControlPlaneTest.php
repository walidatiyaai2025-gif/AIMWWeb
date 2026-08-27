<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Operations\OperationsControlPlaneService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminOperationsControlPlaneTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_idor_and_last_owner_protection(): void
    {
        [, $ownerA] = $this->tenantWithOwner('alpha');
        [, $ownerB] = $this->tenantWithOwner('beta');
        $this->actingAs($ownerA->user)->patchJson("/tenants/alpha/admin/members/{$ownerB->id}", ['status' => 'inactive'])->assertNotFound();
        $this->actingAs($ownerA->user)->deleteJson("/tenants/alpha/admin/members/{$ownerA->id}")->assertUnprocessable()->assertJsonValidationErrors('member');
    }

    public function test_non_owner_cannot_grant_protected_permissions(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('alpha');
        $context = app(TenantContext::class);
        $context->activate($tenant, $owner);
        $user = User::factory()->create();
        $admin = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'delegated-admin']);
        foreach (['tenant.view', 'roles.manage'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $admin->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();
        $this->actingAs($user)->postJson('/tenants/alpha/admin/roles', ['name' => 'owner-copy', 'permissions' => ['roles.manage', 'backup.manage']])->assertForbidden();
    }

    public function test_session_revocation_is_user_scoped(): void
    {
        [, $ownerA] = $this->tenantWithOwner('alpha');
        [, $ownerB] = $this->tenantWithOwner('beta');
        DB::table('sessions')->insert([
            ['id' => 'alpha-session', 'user_id' => $ownerA->user_id, 'ip_address' => '127.0.0.1', 'user_agent' => 'A', 'payload' => '', 'last_activity' => time()],
            ['id' => 'beta-session', 'user_id' => $ownerB->user_id, 'ip_address' => '127.0.0.2', 'user_agent' => 'B', 'payload' => '', 'last_activity' => time()],
        ]);
        $this->actingAs($ownerA->user)->deleteJson('/tenants/alpha/admin/sessions/beta-session')->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'beta-session', 'user_id' => $ownerB->user_id]);
    }

    public function test_secret_settings_are_encrypted_and_tenant_isolated(): void
    {
        [, $ownerA] = $this->tenantWithOwner('alpha');
        [, $ownerB] = $this->tenantWithOwner('beta');
        $this->actingAs($ownerA->user)->putJson('/tenants/alpha/admin/settings', ['scope' => 'tenant', 'key' => 'provider.api_key', 'value' => 'alpha-secret', 'secret' => true])->assertOk()->assertJsonPath('value', '[REDACTED]');
        $raw = DB::table('scoped_settings')->where('tenant_id', $ownerA->tenant_id)->where('key', 'provider.api_key')->first();
        $this->assertNotSame('alpha-secret', $raw->encrypted_value);
        $this->assertSame('alpha-secret', json_decode(Crypt::decryptString($raw->encrypted_value), true));
        $this->actingAs($ownerB->user)->getJson('/tenants/beta/admin/settings?scope=tenant')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_due_schedules_queue_tenant_partitioned_operations(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithOwner('alpha');
        [$tenantB, $ownerB] = $this->tenantWithOwner('beta');
        $this->actingAs($ownerA->user)->postJson('/tenants/alpha/admin/schedules', ['name' => 'Alpha sync', 'task_type' => 'sync', 'schedule' => 'hourly', 'timezone' => 'UTC', 'payload' => ['site_key' => 'a']])->assertCreated();
        $this->actingAs($ownerB->user)->postJson('/tenants/beta/admin/schedules', ['name' => 'Beta sync', 'task_type' => 'sync', 'schedule' => 'hourly', 'timezone' => 'UTC', 'payload' => ['site_key' => 'b']])->assertCreated();
        DB::table('scheduled_tasks')->update(['next_run_at' => now()->subMinute()]);
        $this->assertSame(2, app(OperationsControlPlaneService::class)->dispatchDueSchedules(now()));
        $this->assertDatabaseHas('operation_executions', ['tenant_id' => $tenantA->id, 'type' => 'scheduled.sync', 'status' => 'queued']);
        $this->assertDatabaseHas('operation_executions', ['tenant_id' => $tenantB->id, 'type' => 'scheduled.sync', 'status' => 'queued']);
        $this->assertFalse(app(TenantContext::class)->active());
    }

    public function test_operation_and_export_ids_cannot_cross_tenants(): void
    {
        Queue::fake();
        [, $ownerA] = $this->tenantWithOwner('alpha');
        [, $ownerB] = $this->tenantWithOwner('beta');
        $export = $this->actingAs($ownerA->user)->postJson('/tenants/alpha/admin/reports/exports', ['report_type' => 'operations', 'format' => 'csv'])->assertAccepted()->json();
        Queue::assertPushed(GenerateReportExport::class, fn (GenerateReportExport $job) => $job->exportId === $export['id']);
        $this->actingAs($ownerB->user)->getJson("/tenants/beta/admin/reports/exports/{$export['id']}")->assertNotFound();
        $operationId = DB::table('report_exports')->where('id', $export['id'])->value('operation_execution_id');
        $this->actingAs($ownerB->user)->getJson("/tenants/beta/admin/operations/{$operationId}")->assertNotFound();
    }

    public function test_backup_restore_isolation_and_no_fake_connector_success(): void
    {
        [, $ownerA] = $this->tenantWithOwner('alpha');
        [$tenantB, $ownerB] = $this->tenantWithOwner('beta');
        $response = $this->actingAs($ownerA->user)->postJson('/tenants/alpha/admin/backups', ['level' => 'L1', 'site_key' => 'site-a', 'manifest' => ['objects' => ['posts']]])->assertCreated();
        $this->assertSame('blocked', $response->json('status'));
        $this->assertDatabaseHas('operation_executions', ['id' => $response->json('operation_execution_id'), 'status' => 'failed']);
        $foreignBackupId = DB::table('backups')->insertGetId(['tenant_id' => $tenantB->id, 'requested_by_user_id' => $ownerB->user_id, 'level' => 'L1', 'status' => 'succeeded', 'risk_level' => 'low', 'approval_required' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($ownerA->user)->postJson("/tenants/alpha/admin/backups/{$foreignBackupId}/restore")->assertNotFound();
    }

    public function test_sensitive_operation_payloads_are_redacted(): void
    {
        [, $ownerA] = $this->tenantWithOwner('alpha');
        $this->actingAs($ownerA->user)->postJson('/tenants/alpha/admin/schedules', ['name' => 'Secret-safe', 'task_type' => 'report', 'schedule' => 'daily', 'timezone' => 'UTC', 'payload' => ['api_key' => 'must-not-leak', 'report_type' => 'audit']])->assertCreated();
        $payload = DB::table('scheduled_tasks')->where('tenant_id', $ownerA->tenant_id)->value('payload');
        $this->assertStringNotContainsString('must-not-leak', $payload);
        $this->assertStringContainsString('[REDACTED]', $payload);
    }

    private function tenantWithOwner(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'owner']);
        foreach (['tenant.view', 'members.manage', 'roles.manage', 'sessions.manage', 'settings.manage', 'operations.manage', 'backup.manage', 'reports.manage'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->syncWithoutDetaching([$permission->id => ['tenant_id' => $tenant->id]]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $membership->setRelation('user', $user);
        $context->forget();
        return [$tenant, $membership];
    }
}
