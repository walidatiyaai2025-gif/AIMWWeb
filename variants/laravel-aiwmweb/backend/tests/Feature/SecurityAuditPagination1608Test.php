<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecurityAuditPagination1608Test extends TestCase
{
    use RefreshDatabase;

    public function test_unfiltered_audit_paginates_beyond_legacy_thousand_row_cap_with_stable_tenant_scoped_totals(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Allowed tenant', 'slug' => 'allowed-audit']);
        $foreign = Tenant::query()->create(['name' => 'Foreign tenant', 'slug' => 'foreign-audit']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];
        for ($id = 1; $id <= 1003; $id++) {
            $rows[] = [
                'tenant_id' => $tenant->id,
                'actor_user_id' => $admin->id,
                'event' => $id === 1 ? 'audit-oldest-marker' : 'audit-event-'.$id,
                'metadata' => '{"outcome":"Succeeded"}',
                'occurred_at' => '2026-11-01 00:00:00',
            ];
            if (count($rows) === 100) {
                DB::table('audit_events')->insert($rows);
                $rows = [];
            }
        }
        DB::table('audit_events')->insert($rows);
        DB::table('audit_events')->insert([
            'tenant_id' => $foreign->id,
            'actor_user_id' => null,
            'event' => 'foreign-unreadable-event',
            'metadata' => '{}',
            'occurred_at' => '2026-11-01 00:00:00',
        ]);

        $first = $this->actingAs($admin)->get('/admin/security-audit?per_page=100');
        $first->assertOk()
            ->assertSee('data-audit-total="1003"', false)
            ->assertSee('1–100 of 1003')
            ->assertSee('audit-event-1003')
            ->assertDontSee('audit-oldest-marker')
            ->assertDontSee('foreign-unreadable-event')
            ->assertSee('aria-label="Security audit pages"', false)
            ->assertSee('page=11', false);

        $last = $this->actingAs($admin)->get('/admin/security-audit?page=11&per_page=100');
        $last->assertOk()
            ->assertSee('1001–1003 of 1003')
            ->assertSee('audit-oldest-marker')
            ->assertDontSee('audit-event-1003')
            ->assertDontSee('foreign-unreadable-event')
            ->assertSee('aria-current="page"', false);

        $overflow = $this->actingAs($admin)->get('/admin/security-audit?page=999999&per_page=100');
        $overflow->assertOk()
            ->assertSee('1001–1003 of 1003')
            ->assertSee('audit-oldest-marker');
    }

    public function test_filtered_audit_paginates_only_the_disclosed_bounded_window_without_cross_tenant_leakage(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Filtered tenant', 'slug' => 'filtered-audit']);
        $foreign = Tenant::query()->create(['name' => 'Other tenant', 'slug' => 'other-audit']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];
        for ($id = 1; $id <= 36; $id++) {
            $rows[] = [
                'tenant_id' => $tenant->id,
                'actor_user_id' => $admin->id,
                'event' => $id === 36 ? 'different-event' : 'filtered-match-'.$id,
                'metadata' => '{}',
                'occurred_at' => '2026-11-01 00:00:00',
            ];
        }
        DB::table('audit_events')->insert($rows);
        DB::table('audit_events')->insert([
            'tenant_id' => $foreign->id,
            'actor_user_id' => null,
            'event' => 'filtered-match-foreign',
            'metadata' => '{}',
            'occurred_at' => '2026-11-01 00:00:00',
        ]);

        $first = $this->actingAs($admin)->get('/admin/security-audit?q=filtered-match&per_page=10');
        $first->assertOk()
            ->assertSee('data-audit-total="unavailable"', false)
            ->assertSee('data-audit-filter-window', false)
            ->assertSee('1–10 of matches inside the limited search window')
            ->assertSee('filtered-match-35')
            ->assertDontSee('filtered-match-1</strong>', false)
            ->assertDontSee('filtered-match-foreign')
            ->assertSee('page=4', false);

        $last = $this->actingAs($admin)->get('/admin/security-audit?q=filtered-match&per_page=10&page=4');
        $last->assertOk()
            ->assertSee('31–35 of matches inside the limited search window')
            ->assertSee('filtered-match-1</strong>', false)
            ->assertDontSee('filtered-match-35')
            ->assertDontSee('filtered-match-foreign')
            ->assertSee('q=filtered-match', false);

        $overflow = $this->actingAs($admin)->get('/admin/security-audit?q=filtered-match&per_page=10&page=999');
        $overflow->assertOk()->assertSee('31–35 of matches inside the limited search window');

        $bounded = $this->actingAs($admin)->get('/admin/security-audit?q=filtered-match&take=12&per_page=10&page=2');
        $bounded->assertOk()
            ->assertSee('11–12 of matches inside the limited search window')
            ->assertDontSee('filtered-match-1</strong>', false);
    }

    public function test_filtered_audit_without_explicit_take_pages_all_matches_in_the_disclosed_window(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $tenant = Tenant::query()->create(['name' => 'Authorized tenant', 'slug' => 'authorized-tenant']);
        DB::table('tenant_memberships')->insert([
            'tenant_id' => $tenant->id,
            'user_id' => $admin->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $rows = [];
        for ($id = 1; $id <= 225; $id++) {
            $rows[] = [
                'tenant_id' => $tenant->id,
                'actor_user_id' => $admin->id,
                'event' => 'audit-filter-matched-'.$id,
                'metadata' => '{}',
                'occurred_at' => '2026-11-01 00:00:00',
            ];
            if (count($rows) === 75) {
                DB::table('audit_events')->insert($rows);
                $rows = [];
            }
        }

        $first = $this->actingAs($admin)->get('/admin/security-audit?q=audit-filter-matched&per_page=25');
        $first->assertOk()
            ->assertSee('1–25 of matches inside the limited search window')
            ->assertSee('page=9', false)
            ->assertSee('audit-filter-matched-225');

        $last = $this->actingAs($admin)->get('/admin/security-audit?q=audit-filter-matched&per_page=25&page=9');
        $last->assertOk()
            ->assertSee('201–225 of matches inside the limited search window')
            ->assertSee('audit-filter-matched-1</strong>', false)
            ->assertDontSee('audit-filter-matched-225');

        // The explicit legacy take parameter still limits the filtered window.
        $limited = $this->actingAs($admin)->get('/admin/security-audit?q=audit-filter-matched&take=100&per_page=25&page=4');
        $limited->assertOk()
            ->assertSee('76–100 of matches inside the limited search window')
            ->assertDontSee('page=9', false);
    }

    public function test_unfiltered_audit_rejects_invalid_pages_and_discloses_legacy_filtered_ceiling(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);

        $this->actingAs($admin)->getJson('/admin/security-audit?page=0')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/security-audit?page=1000001')->assertUnprocessable();
        $this->actingAs($admin)->getJson('/admin/security-audit?per_page=200')->assertUnprocessable();
        // Legacy take=200 may still bound a filtered compatibility request,
        // but cannot silently render 200 SQL rows under an invalid page size.
        $this->actingAs($admin)->get('/admin/security-audit?take=200')
            ->assertOk()
            ->assertSee('value="25" selected', false)
            ->assertSee('data-audit-total="0"', false);

        $this->actingAs($admin)->get('/admin/security-audit?q=nonexistent')
            ->assertOk()
            ->assertSee('data-audit-filter-limit', false)
            ->assertSee('latest 1,000 events only')
            ->assertSee('data-audit-total="unavailable"', false)
            ->assertDontSee('data-audit-range', false);
    }
}
