<?php

namespace Tests\Feature;

use App\Http\Controllers\LegacyNotificationReadController;
use App\Models\InAppNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyNotificationApiTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_notification_read_requires_auth_and_resolves_to_real_controller(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();

        $route = Route::getRoutes()->match(Request::create('/api/notifications', 'GET'));
        $this->assertSame(
            LegacyNotificationReadController::class.'@index',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
    }

    public function test_caller_supplied_user_id_is_ignored_and_filters_apply_to_current_user_only(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $membership = $this->tenantMembership($user, 'alpha', ['execution.view']);
        $this->tenantMembershipForExistingTenant($other, $membership->tenant, ['execution.view']);

        $this->notification($membership, $user, 'Current unread', read: false);
        $this->notification($membership, $user, 'Current read', read: true);
        $this->notification($membership, $other, 'Foreign user notification', read: false);

        $response = $this->actingAs($user)
            ->getJson('/api/notifications?userId='.$other->id.'&unreadOnly=true&take=1')
            ->assertOk()
            ->assertJsonCount(1);

        $this->assertSame('Current unread', $response->json('0.title'));
        $this->assertNull($response->json('0.read_at'));
        $this->assertNotSame('Foreign user notification', $response->json('0.title'));
    }

    public function test_multi_tenant_legacy_reads_fail_closed_and_explicit_selection_is_isolated(): void
    {
        $user = User::factory()->create();
        $alpha = $this->tenantMembership($user, 'alpha', ['execution.view']);
        $beta = $this->tenantMembership($user, 'beta', ['execution.view']);
        $this->notification($alpha, $user, 'Alpha notification', read: false);
        $this->notification($beta, $user, 'Beta notification', read: false);

        $this->actingAs($user)->getJson('/api/notifications')
            ->assertConflict()
            ->assertJsonPath('code', 'TENANT_SELECTION_REQUIRED');

        $alphaResponse = $this->actingAs($user)->getJson('/api/notifications?tenant=alpha')->assertOk()->assertJsonCount(1);
        $this->assertSame('Alpha notification', $alphaResponse->json('0.title'));

        $betaResponse = $this->actingAs($user)->getJson('/api/notifications?tenant=beta')->assertOk()->assertJsonCount(1);
        $this->assertSame('Beta notification', $betaResponse->json('0.title'));

        $this->actingAs($user)->getJson('/api/notifications?tenant=foreign')->assertNotFound();
    }

    public function test_legacy_notification_read_requires_operations_read_equivalent_permission(): void
    {
        $user = User::factory()->create();
        $this->tenantMembership($user, 'limited', ['tenant.view']);

        $this->actingAs($user)->getJson('/api/notifications')->assertForbidden();
    }

    public function test_opt_in_pages_report_real_filtered_totals_across_tied_timestamp_notifications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $alpha = $this->tenantMembership($user, 'notification-pages-alpha', ['execution.view']);
        $this->tenantMembershipForExistingTenant($otherUser, $alpha->tenant, ['execution.view']);
        $beta = $this->tenantMembership($user, 'notification-pages-beta', ['execution.view']);

        // Same timestamps deliberately exercise the stable ID tie-breaker.
        $this->travelTo(now()->startOfSecond());
        $mine = [];
        for ($i = 1; $i <= 63; $i++) {
            $mine[] = $this->notification($alpha, $user, 'Page row '.$i, read: $i % 2 === 0);
        }
        $this->notification($alpha, $otherUser, 'Different member private', read: false);
        $this->notification($beta, $user, 'Different tenant private', read: false);
        $this->travelBack();

        $url = '/api/notifications?tenant=notification-pages-alpha';
        $expected = [
            [1, 63, 25],
            [2, 38, 25],
            [3, 13, 13],
            [1000000, 13, 13],
        ];
        foreach ($expected as [$page, $firstNumber, $size]) {
            $response = $this->actingAs($user)
                ->getJson($url."&page={$page}&per_page=25&userId={$otherUser->id}")
                ->assertOk()
                ->assertJsonPath('total', 63)
                ->assertJsonPath('last_page', 3)
                ->assertJsonPath('current_page', min($page, 3))
                ->assertJsonPath('per_page', 25)
                ->assertJsonCount($size, 'data');
            $this->assertSame('Page row '.$firstNumber, $response->json('data.0.title'));
        }

        $this->actingAs($user)->getJson($url.'&per_page=100')
            ->assertOk()->assertJsonPath('total', 63)->assertJsonPath('current_page', 1)
            ->assertJsonCount(63, 'data');
        $this->actingAs($user)->getJson($url.'&page=1&take=1')
            ->assertOk()->assertJsonPath('per_page', 25)->assertJsonCount(25, 'data');

        $unread = $this->actingAs($user)->getJson($url.'&page=1&per_page=25&unreadOnly=true')
            ->assertOk()->assertJsonPath('total', 32)->assertJsonPath('last_page', 2)
            ->assertJsonCount(25, 'data');
        foreach ($unread->json('data') as $row) {
            $this->assertNull($row['read_at']);
        }
        $this->actingAs($user)->getJson($url.'&page=1000000&per_page=25&unreadOnly=true')
            ->assertOk()->assertJsonPath('current_page', 2)->assertJsonCount(7, 'data');

        // The existing array response still works for clients without paging.
        $this->actingAs($user)->getJson($url.'&take=5')
            ->assertOk()->assertJsonCount(5)
            ->assertJsonPath('0.title', 'Page row 63');

        foreach (['page=0', 'page=-1', 'page=nope', 'page=1000001', 'page%5B%5D=2', 'per_page=0', 'per_page=101', 'per_page=nope'] as $invalid) {
            $this->actingAs($user)->getJson($url.'&'.$invalid)->assertUnprocessable();
        }

        $this->actingAs($user)->getJson('/api/notifications?tenant=notification-pages-beta&page=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Different tenant private');

        $this->actingAs($otherUser)->getJson($url.'&page=1')
            ->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Different member private');

        $this->actingAs($user)->getJson('/api/notifications?page=1')
            ->assertConflict()->assertJsonPath('code', 'TENANT_SELECTION_REQUIRED');
    }

    private function tenantMembership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);

        return $this->tenantMembershipForExistingTenant($user, $tenant, $permissions);
    }

    private function tenantMembershipForExistingTenant(User $user, Tenant $tenant, array $permissions): TenantMembership
    {
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => 'legacy-notification-'.$tenant->slug.'-'.$user->id]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function notification(TenantMembership $membership, User $user, string $title, bool $read): InAppNotification
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $notification = InAppNotification::query()->create([
            'user_id' => $user->id,
            'notification_id' => (string) Str::uuid(),
            'event_id' => (string) Str::uuid(),
            'category' => 'operations',
            'severity' => 'info',
            'source' => 'test',
            'title' => $title,
            'message' => $title.' message',
            'mandatory' => false,
            'locale' => 'en',
            'delivery_mode' => 'immediate',
            'metadata' => [],
            'read_at' => $read ? now() : null,
        ]);
        $context->forget();

        return $notification;
    }
}
