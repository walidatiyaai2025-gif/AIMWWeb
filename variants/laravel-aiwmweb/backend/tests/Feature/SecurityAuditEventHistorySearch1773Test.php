<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecurityAuditEventHistorySearch1773Test extends TestCase
{
    use RefreshDatabase;

    public function test_event_search_pages_old_literal_wildcard_events_with_real_tenant_scoped_sql_totals(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $own = Tenant::query()->create(['name' => 'Search Own', 'slug' => 'audit-search-own-1773']);
        $other = Tenant::query()->create(['name' => 'Search Foreign', 'slug' => 'audit-search-foreign-1773']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $own->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];
        for ($i = 1; $i <= 36; $i++) {
            $rows[] = [
                'tenant_id' => $own->id,
                'actor_user_id' => $admin->id,
                'event' => 'SECURITY!%_audit-event-'.$i,
                'metadata' => '{"category":"Authorization","outcome":"Failed","api_key":"HIDDEN_SECRET_1773"}',
                'occurred_at' => '2026-11-01 00:00:00',
            ];
        }
        // 1,105 newer unrelated events previously exhausted the legacy
        // latest-1,000 in-memory search and hid every matching event above.
        for ($i = 1; $i <= 1105; $i++) {
            $rows[] = [
                'tenant_id' => $own->id,
                'actor_user_id' => $admin->id,
                'event' => 'routine-unrelated-'.$i,
                'metadata' => '{}',
                'occurred_at' => '2026-11-02 00:00:00',
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('audit_events')->insert($chunk);
        }
        DB::table('audit_events')->insert([
            'tenant_id' => $other->id,
            'actor_user_id' => null,
            'event' => 'SECURITY!%_foreign-private',
            'metadata' => '{"category":"Authorization","outcome":"Failed"}',
            'occurred_at' => '2026-11-03 00:00:00',
        ]);

        $url = '/admin/security-audit?event_q='.rawurlencode('security!%_')
            .'&category=Authorization&outcome=Failed&per_page=10';

        $this->actingAs($admin)->get($url)->assertOk()
            ->assertSee('data-audit-total="36"', false)
            ->assertSee('1–10 of 36')
            ->assertSee('SECURITY!%_audit-event-36')
            ->assertDontSee('SECURITY!%_audit-event-1</strong>', false)
            ->assertDontSee('SECURITY!%_foreign-private')
            ->assertDontSee('HIDDEN_SECRET_1773')
            ->assertDontSee('data-audit-filter-limit', false)
            ->assertSee('event_q=', false)
            ->assertSee('page=4', false);

        $this->actingAs($admin)->get($url.'&page=4')->assertOk()
            ->assertSee('31–36 of 36')
            ->assertSee('SECURITY!%_audit-event-1</strong>', false)
            ->assertDontSee('SECURITY!%_audit-event-36')
            ->assertDontSee('SECURITY!%_foreign-private');

        $this->actingAs($admin)->get($url.'&page=999999')->assertOk()
            ->assertSee('31–36 of 36');

        // A user-supplied percent is literal, not a SQL LIKE wildcard.
        $this->actingAs($admin)->get('/admin/security-audit?event_q='.rawurlencode('routine%'))
            ->assertOk()->assertSee('data-audit-total="0"', false);

        // Distinguish a normal bounded general Search from full event history:
        // legacy q or take never invents a global matching count.
        $this->actingAs($admin)->get($url.'&q=security')->assertOk()
            ->assertSee('data-audit-total="unavailable"', false)
            ->assertSee('data-audit-filter-limit', false)
            ->assertSee('latest 1,000 events only');
        $this->actingAs($admin)->get($url.'&take=25')->assertOk()
            ->assertSee('data-audit-total="unavailable"', false)
            ->assertSee('data-audit-filter-limit', false);

        $this->actingAs($admin)->getJson('/admin/security-audit?event_q[]=nope')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/security-audit?event_q='.str_repeat('x', 161))->assertUnprocessable();
        $this->assertDatabaseCount('audit_events', 1142);

        $unprivileged = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($unprivileged)->get('/admin/security-audit?event_q=security')
            ->assertForbidden();
    }

    public function test_event_search_is_distinct_from_ar_en_bounded_general_search(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Scoped', 'slug' => 'audit-search-locale-1773']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)->withSession(['locale' => 'en'])
            ->get('/admin/security-audit?event_q=Login')
            ->assertOk()
            ->assertSee('Event search (all history)')
            ->assertSee('General search (latest 1,000 only)')
            ->assertSee('name="event_q"', false)
            ->assertSee('data-audit-total="0"', false)
            ->assertDontSee('data-audit-filter-limit', false);
    }
}
