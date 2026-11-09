<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecurityAuditClassificationSqlPaging1771Test extends TestCase
{
    use RefreshDatabase;

    public function test_category_and_outcome_sql_count_all_authorized_matches_beyond_latest_thousand(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $own = Tenant::query()->create(['name' => 'Owned Tenant', 'slug' => 'audit-owned-1771']);
        $other = Tenant::query()->create(['name' => 'Unowned Tenant', 'slug' => 'audit-unowned-1771']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $own->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Matching rows are deliberately OLD: a latest-1000 PHP filter would
        // incorrectly hide most or all of them.
        $rows = [];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = [
                'tenant_id' => $own->id,
                'actor_user_id' => $admin->id,
                'event' => 'configuration-blocked-original-'.$i,
                'metadata' => json_encode([
                    'category' => 'Authorization',
                    'outcome' => 'Failed',
                    'api_key' => 'NEVER_RENDER_PRIVATE_PROVIDER_TOKEN',
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => '2026-11-01 00:00:00',
            ];
        }
        for ($i = 1; $i <= 1011; $i++) {
            $rows[] = [
                'tenant_id' => $own->id,
                'actor_user_id' => $admin->id,
                'event' => 'routine-account-'.$i,
                'metadata' => '{}',
                'occurred_at' => '2026-11-01 00:00:00',
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('audit_events')->insert($chunk);
        }
        DB::table('audit_events')->insert([
            'tenant_id' => $other->id,
            'actor_user_id' => null,
            'event' => 'foreign-private-audit-1771',
            'metadata' => '{"category":"Authorization","outcome":"Failed"}',
            'occurred_at' => '2026-11-01 00:00:00',
        ]);

        $url = '/admin/security-audit?category=Authorization&outcome=Failed&per_page=10';
        $first = $this->actingAs($admin)->get($url);
        $first->assertOk()
            ->assertSee('data-audit-total="30"', false)
            ->assertSee('1–10 of 30')
            ->assertSee('configuration-blocked-original-30')
            ->assertDontSee('configuration-blocked-original-1</strong>', false)
            ->assertDontSee('foreign-private-audit-1771')
            ->assertDontSee('NEVER_RENDER_PRIVATE_PROVIDER_TOKEN')
            ->assertDontSee('data-audit-filter-limit', false)
            ->assertSee('page=3', false);

        $last = $this->actingAs($admin)->get($url.'&page=3');
        $last->assertOk()->assertSee('21–30 of 30')
            ->assertSee('configuration-blocked-original-1</strong>', false)
            ->assertDontSee('configuration-blocked-original-30')
            ->assertDontSee('foreign-private-audit-1771');
        $this->actingAs($admin)->get($url.'&page=999999')
            ->assertOk()->assertSee('21–30 of 30');

        // No audit writes, provider calls or tenant mutation in a read-only GET.
        $this->assertDatabaseCount('audit_events', 1042);
        $unauthorized = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($unauthorized)->get('/admin/security-audit?outcome=Failed')
            ->assertForbidden();
    }

    public function test_classification_matches_safe_snapshot_precedence_and_keeps_text_search_bounded(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Classified', 'slug' => 'classified-1771']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('audit_events')->insert([
            [
                'tenant_id' => $tenant->id, 'actor_user_id' => $admin->id,
                'event' => 'auth-error-original', 'metadata' => '{"status":"Failed"}',
                'occurred_at' => '2026-11-01 00:00:00',
            ],
            [
                'tenant_id' => $tenant->id, 'actor_user_id' => $admin->id,
                'event' => 'session-open', 'metadata' => '{"outcome":42,"status":"Blocked"}',
                'occurred_at' => '2026-11-01 00:00:00',
            ],
            [
                'tenant_id' => $tenant->id, 'actor_user_id' => $admin->id,
                'event' => 'login-normal', 'metadata' => '{"outcome":"unknown","status":"Blocked"}',
                'occurred_at' => '2026-11-01 00:00:00',
            ],
            [
                'tenant_id' => $tenant->id, 'actor_user_id' => $admin->id,
                'event' => 'configuration-failed', 'metadata' => '{"category":{"token":"DO_NOT_SHOW"},"outcome":"Failed"}',
                'occurred_at' => '2026-11-01 00:00:00',
            ],
        ]);

        $this->actingAs($admin)->get('/admin/security-audit?category=Authentication&outcome=Failed')
            ->assertOk()->assertSee('data-audit-total="1"', false)
            ->assertSee('auth-error-original')->assertDontSee('login-normal');

        $this->actingAs($admin)->get('/admin/security-audit?category=Session&outcome=Blocked')
            ->assertOk()->assertSee('data-audit-total="1"', false)->assertSee('session-open');

        $this->actingAs($admin)->get('/admin/security-audit?category=Authentication&outcome=Succeeded')
            ->assertOk()->assertSee('data-audit-total="1"', false)->assertSee('login-normal');

        $this->actingAs($admin)->get('/admin/security-audit?category=Configuration&outcome=Failed')
            ->assertOk()->assertSee('data-audit-total="1"', false)->assertSee('configuration-failed')
            ->assertDontSee('DO_NOT_SHOW');

        if (DB::getDriverName() === 'sqlite') {
            // Historical malformed TEXT JSON is possible on SQLite; MySQL's
            // native JSON column rightly rejects such inserts.
            DB::table('audit_events')->insert([
                'tenant_id' => $tenant->id,
                'actor_user_id' => $admin->id,
                'event' => 'session-error-corrupt',
                'metadata' => 'not-valid-json',
                'occurred_at' => '2026-11-01 00:00:00',
            ]);
            $this->actingAs($admin)->get('/admin/security-audit?category=Session&outcome=Failed')
                ->assertOk()->assertSee('data-audit-total="1"', false)->assertSee('session-error-corrupt');
        }

        $this->actingAs($admin)->get('/admin/security-audit?category=Authentication&q=login')
            ->assertOk()->assertSee('data-audit-total="unavailable"', false)
            ->assertSee('data-audit-filter-limit', false)
            ->assertSee('latest 1,000 events only');
        $this->actingAs($admin)->get('/admin/security-audit?category=Configuration&take=2')
            ->assertOk()->assertSee('data-audit-total="unavailable"', false)
            ->assertSee('data-audit-filter-limit', false);
    }
}
