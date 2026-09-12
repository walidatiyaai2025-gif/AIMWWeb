<?php

namespace Tests\Feature;

use App\Automation\SuggestedChangeService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SuggestedChange;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class SuggestedChangeExecutionStatusTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AUTO-1584B94390';

    public function test_suggested_change_service_set_execution_status_async_preserves_source_status_contract(): void
    {
        [$tenant, $membership] = $this->membership(['operations.manage']);
        app(TenantContext::class)->activate($tenant, $membership);
        $change = $this->change();
        $service = app(SuggestedChangeService::class);

        foreach (['Executing', 'Executed', 'Failed', 'RolledBack'] as $status) {
            $this->assertSame($status, $service->SetExecutionStatusAsync($change->change_id, $status)->execution_status);
        }

        $before = $change->fresh()->updated_at;
        $this->assertSame('RolledBack', $service->SetExecutionStatusAsync($change->change_id, 'NotStarted')->execution_status);
        $this->assertTrue($before->equalTo($change->fresh()->updated_at));
        $this->assertSame(self::OPERATION_ID, SuggestedChangeService::OPERATION_ID);
    }

    public function test_suggested_change_service_set_execution_status_async_rejects_unsupported_status(): void
    {
        [$tenant, $membership] = $this->membership(['operations.manage']);
        app(TenantContext::class)->activate($tenant, $membership);
        $change = $this->change();

        $this->expectException(InvalidArgumentException::class);
        app(SuggestedChangeService::class)->SetExecutionStatusAsync($change->change_id, 'Succeeded');
    }

    public function test_suggested_change_service_set_execution_status_async_fails_closed_without_permission(): void
    {
        [$tenant, $membership] = $this->membership([]);
        app(TenantContext::class)->activate($tenant, $membership);
        $change = $this->change();

        $this->expectException(AuthorizationException::class);
        app(SuggestedChangeService::class)->SetExecutionStatusAsync($change->change_id, 'Executed');
    }

    public function test_suggested_change_service_set_execution_status_async_cannot_mutate_foreign_tenant_change(): void
    {
        [$alpha, $alphaMembership] = $this->membership(['operations.manage'], 'alpha');
        app(TenantContext::class)->activate($alpha, $alphaMembership);
        $change = $this->change();

        [$beta, $betaMembership] = $this->membership(['operations.manage'], 'beta');
        app(TenantContext::class)->activate($beta, $betaMembership);

        $this->expectException(ModelNotFoundException::class);
        app(SuggestedChangeService::class)->SetExecutionStatusAsync($change->change_id, 'Executed');
    }

    private function change(): SuggestedChange
    {
        return SuggestedChange::query()->create([
            'site_id' => 42,
            'change_id' => (string) Str::uuid(),
            'fingerprint' => hash('sha256', (string) Str::uuid()),
            'source_type' => 'SEO Audit',
            'object_type' => 'Content',
            'object_id' => '99',
            'change_type' => 'SetTitle',
            'current_value' => 'Old title',
            'proposed_value' => 'New title',
            'reason' => 'Acceptance fixture',
            'confidence' => 0.9,
            'risk_level' => 'Low',
            'requires_backup' => false,
            'requires_staging' => false,
            'approval_status' => 'Approved',
            'execution_status' => 'NotStarted',
        ]);
    }

    private function membership(array $permissions, ?string $slug = null): array
    {
        $slug ??= 'tenant-'.Str::lower(Str::random(8));
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create();
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'suggested-change-'.$slug]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $membership];
    }
}
