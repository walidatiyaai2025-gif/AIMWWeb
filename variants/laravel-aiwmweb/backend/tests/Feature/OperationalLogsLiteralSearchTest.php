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

    public function test_legacy_persisted_context_secrets_are_masked_at_read_time(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');
        $logId = $this->log($alpha, 'legacy operation completed');
        $this->log($beta, 'foreign tenant private event');

        // Simulate historical/imported data that bypassed the modern writer.
        // Reads must never trust the persisted JSON as already sanitized.
        DB::table('operation_logs')->where('id', $logId)->update([
            'context' => json_encode([
                'status' => 'completed',
                'api_key' => 'LEGACY_API_SECRET_MARKER',
                'nested' => [
                    'authorization' => 'Bearer LEGACY_BEARER_MARKER',
                    'safe' => 'keep-this-value',
                    'refresh_token' => 'LEGACY_REFRESH_MARKER',
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q=legacy')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $logId)
            ->assertJsonPath('data.0.context.status', 'completed')
            ->assertJsonPath('data.0.context.api_key', '[REDACTED]')
            ->assertJsonPath('data.0.context.nested.authorization', '[REDACTED]')
            ->assertJsonPath('data.0.context.nested.refresh_token', '[REDACTED]')
            ->assertJsonPath('data.0.context.nested.safe', 'keep-this-value');

        foreach (['LEGACY_API_SECRET_MARKER', 'LEGACY_BEARER_MARKER', 'LEGACY_REFRESH_MARKER', 'foreign tenant private event'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }

        // Read-time redaction is non-destructive and does not rewrite logs.
        $this->assertStringContainsString('LEGACY_API_SECRET_MARKER', (string) DB::table('operation_logs')->where('id', $logId)->value('context'));
    }

    public function test_legacy_plain_log_messages_redact_inline_credentials_without_rewriting_persisted_rows(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');
        $original = 'Sync failed Authorization: Bearer BEARER_SECRET_CANARY api_key=API_SECRET_CANARY '
            .'access_token=ACCESS_SECRET_CANARY refresh_token="REFRESH SECRET CANARY" '
            .'password=PASSWORD_SECRET_CANARY client_secret=CLIENT_SECRET_CANARY '
            .'provider sk-THIS_IS_A_FAKE_SECRET_1234567890';
        $id = $this->log($alpha, $original);
        $this->log($alpha, 'Sync retry scheduled safely');
        $this->log($beta, 'foreign tenant private canary');

        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q=Sync')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.tenant_records', 2)
            ->assertJsonPath('meta.matching_records', 2)
            ->assertJsonPath('data.0.message',
                'Sync retry scheduled safely');

        $messages = collect($response->json('data'))->pluck('message')->all();
        $sanitized = implode(' ', $messages);
        $this->assertStringContainsString('Sync failed Authorization: Bearer [REDACTED]', $sanitized);
        $this->assertStringContainsString('api_key=[REDACTED]', $sanitized);
        $this->assertStringContainsString('access_token=[REDACTED]', $sanitized);
        $this->assertStringContainsString('refresh_token=[REDACTED]', $sanitized);
        $this->assertStringContainsString('client_secret=[REDACTED]', $sanitized);
        $this->assertStringContainsString('provider [REDACTED]', $sanitized);

        foreach (['BEARER_SECRET_CANARY', 'API_SECRET_CANARY', 'ACCESS_SECRET_CANARY',
            'REFRESH SECRET CANARY', 'PASSWORD_SECRET_CANARY', 'CLIENT_SECRET_CANARY',
            'sk-THIS_IS_A_FAKE_SECRET_1234567890', 'foreign tenant private canary'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        // Read-boundary protection must not mutate persisted historical evidence.
        $this->assertSame($original, DB::table('operation_logs')->where('id', $id)->value('message'));
        $this->actingAs($actor)->getJson('/tenants/beta/admin/logs')->assertNotFound();
    }

    public function test_legacy_basic_authorization_and_x_api_key_are_redacted_only_on_read(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');
        $original = 'Sync failed Authorization: Basic BASIC_CREDENTIAL_CANARY '
            .'X-API-Key: HEADER_CREDENTIAL_CANARY next action scheduled';
        $id = $this->log($alpha, $original);
        $this->log($alpha, 'Basic authorization is configured for testing');
        $this->log($beta, 'foreign tenant private event');

        $this->getJson('/tenants/alpha/admin/logs')->assertUnauthorized();
        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q=Sync')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('meta.tenant_records', 2)
            ->assertJsonPath('meta.matching_records', 1)
            ->assertJsonPath(
                'data.0.message',
                'Sync failed Authorization: Basic [REDACTED] X-API-Key: [REDACTED] next action scheduled',
            );

        $this->assertStringNotContainsString('BASIC_CREDENTIAL_CANARY', $response->getContent());
        $this->assertStringNotContainsString('HEADER_CREDENTIAL_CANARY', $response->getContent());
        $this->assertStringNotContainsString('foreign tenant private event', $response->getContent());
        $this->assertSame($original, DB::table('operation_logs')->where('id', $id)->value('message'));
        $this->actingAs($actor)->getJson('/tenants/beta/admin/logs')->assertNotFound();
        $this->actingAs($actor)->getJson('/tenants/alpha/admin/logs?q=authorization')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.message', 'Basic authorization is configured for testing');
    }

    public function test_invalid_logs_get_filters_return_422_without_reading_foreign_rows_or_writing_logs(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');
        $this->log($alpha, 'tenant alpha search marker');
        $this->log($beta, 'tenant beta private marker');

        $before = DB::table('operation_logs')->count();
        foreach ([
            'q%5B%5D=marker',
            'search%5B%5D=marker',
            'level%5B%5D=info',
            'correlation_id%5B%5D=not-a-uuid',
            'q='.str_repeat('x', 161),
            'search='.str_repeat('y', 161),
            'level='.str_repeat('z', 33),
            'correlation_id='.str_repeat('w', 101),
        ] as $query) {
            $response = $this->actingAs($actor)
                ->getJson('/tenants/alpha/admin/logs?'.$query)
                ->assertUnprocessable()
                ->assertJsonStructure(['message', 'errors']);

            $this->assertStringNotContainsString('tenant beta private marker', $response->getContent());
            $this->assertSame($before, DB::table('operation_logs')->count());
        }
    }

    public function test_logs_get_filter_precedence_keeps_bounded_literal_search_and_authorization(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        $expected = $this->log($alpha, 'queue_worker finished');
        $this->log($alpha, 'other task finished');

        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?search='.rawurlencode('queue_'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expected);

        // The explicit q filter takes precedence over the legacy search alias.
        $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q='.rawurlencode('queue_').'&search=other')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $expected);
    }

    public function test_zero_search_filters_correctly_and_exposes_only_tenant_bounded_counts(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');
        $expected = $this->log($alpha, 'Job 0 completed');
        $this->log($alpha, 'Job 7 completed');
        $this->log($alpha, 'other operation completed');
        $this->log($beta, 'Job 0 completed for another tenant');

        foreach (['q=0', 'search=0', 'q=0&search=other'] as $query) {
            $this->actingAs($actor)
                ->getJson('/tenants/alpha/admin/logs?'.$query)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $expected)
                ->assertJsonPath('meta.scope', 'tenant_operation_logs')
                ->assertJsonPath('meta.tenant_records', 3)
                ->assertJsonPath('meta.matching_records', 1)
                ->assertJsonPath('meta.returned_records', 1)
                ->assertJsonPath('meta.limit', 500)
                ->assertJsonPath('meta.truncated', false)
                ->assertJsonPath('meta.empty_reason', 'not_empty');
        }

        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs?q=does-not-match')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.tenant_records', 3)
            ->assertJsonPath('meta.matching_records', 0)
            ->assertJsonPath('meta.returned_records', 0)
            ->assertJsonPath('meta.empty_reason', 'filter_matched_none');
        $this->assertStringNotContainsString('another tenant', $response->getContent());

        [$emptyTenant, $emptyActor] = $this->member('gamma');
        $this->actingAs($emptyActor)
            ->getJson('/tenants/gamma/admin/logs?q=0')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.tenant_records', 0)
            ->assertJsonPath('meta.empty_reason', 'no_tenant_events');
        $this->assertSame(4, DB::table('operation_logs')->count());
    }

    public function test_log_evidence_preserves_the_500_row_limit_without_leaking_other_tenants(): void
    {
        [$alpha, $actor] = $this->member('alpha');
        [$beta] = $this->member('beta');
        $this->log($beta, 'private beta record');

        // Bulk insert disposable tenant-owned rows with a stable timestamp;
        // the read must bound the response while reporting that it was capped.
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = [
                'tenant_id' => $alpha->id,
                'correlation_id' => (string) Str::uuid(),
                'level' => 'info',
                'message' => 'alpha-row-'.$i,
                'context' => '{}',
                'occurred_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('operation_logs')->insert($chunk);
        }

        $response = $this->actingAs($actor)
            ->getJson('/tenants/alpha/admin/logs')
            ->assertOk()
            ->assertJsonCount(500, 'data')
            ->assertJsonPath('meta.tenant_records', 501)
            ->assertJsonPath('meta.matching_records', 501)
            ->assertJsonPath('meta.returned_records', 500)
            ->assertJsonPath('meta.truncated', true)
            ->assertJsonPath('meta.empty_reason', 'not_empty')
            // All 501 records share a timestamp: the cap must preserve
            // newest IDs, not arbitrarily return the oldest 500.
            ->assertJsonPath('data.0.message', 'alpha-row-500')
            ->assertJsonPath('data.499.message', 'alpha-row-1');
        $this->assertStringNotContainsString('alpha-row-0', json_encode($response->json('data'), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('private beta record', $response->getContent());
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
