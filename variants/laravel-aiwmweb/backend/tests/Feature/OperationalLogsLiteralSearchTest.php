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

final class OperationalLogsLiteralSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_log_search_matches_literal_percent_underscore_and_escape_marker_without_leaking_tenants(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');

        $percent = $this->log($alpha, 'Disk at 90% used');
        $this->log($alpha, 'Disk at 90X used');
        $underscore = $this->log($alpha, 'queue_worker stalled');
        $this->log($alpha, 'queueXworker stalled');
        $exclamation = $this->log($alpha, 'auth!error surfaced');
        $this->log($alpha, 'authXerror surfaced');
        $this->log($beta, 'Disk at 90% used');

        foreach ([
            ['q' => '90%', 'id' => $percent],
            ['search' => 'queue_', 'id' => $underscore],
            ['q' => 'auth!', 'id' => $exclamation],
        ] as $case) {
            $key = isset($case['q']) ? 'q' : 'search';
            $term = $case[$key];
            $this->actingAs($actor)
                ->getJson('/tenants/alpha/admin/logs?'.$key.'='.rawurlencode($term))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $case['id']);
        }

        // A wildcard is user text, not permission to search every row.
        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q='.rawurlencode('%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $percent);

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q='.rawurlencode('not-present%'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_log_filters_remain_authorized_and_tenant_scoped(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');
        $alphaId = $this->log($alpha, 'sync_job finished');
        $this->log($beta, 'sync_job finished');

        $this->getJson('/tenants/alpha/admin/logs?q=sync_job')
            ->assertUnauthorized();

        $this->actingAs($actor)
            ->getJson('/tenants/beta/admin/logs?q=sync_job')
            ->assertNotFound();

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q='.rawurlencode('sync_'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $alphaId);
    }

    /** @return array{Tenant,User} */
    private function member(string $slug): array
    {
        $tenant = Tenant::query()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $user = User::factory()->create();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'logs-'.$slug.'-'.$user->id]);
        foreach (['tenant.view', 'operations.manage'] as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return [$tenant, $user];
    }

    private function log(Tenant $tenant, string $message): int
    {
        return DB::table('operation_logs')->insertGetId([
            'tenant_id' => $tenant->id,
            'correlation_id' => (string) Str::uuid(),
            'level' => 'info',
            'message' => $message,
            'context' => '{}',
            'occurred_at' => now(),
        ]);
    }
}
