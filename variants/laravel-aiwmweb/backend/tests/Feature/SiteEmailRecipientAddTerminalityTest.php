<?php

namespace Tests\Feature;

use App\Billing\Enums\SubscriptionState;
use App\Models\BillingPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteEmailRecipientAddTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-07134347F9';

    public function test_exact_canonical_operation_is_the_site_email_settings_add_control(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/SiteEmailSettings.razor',
            $operation['current_source'],
        );
        $this->assertSame(
            '/module/site-email-settings | /sites/{SiteId:guid}/email-settings',
            $operation['route_screen'],
        );
        $this->assertSame('AddAsync [AddAsync]', $operation['visible_control']);
    }

    public function test_authorized_add_persists_normalized_tenant_scoped_recipient_and_reloads_page(): void
    {
        $user = User::factory()->create();
        [$tenant, $site] = $this->workspace($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage'], 2);

        $response = $this->actingAs($user)->post(
            "/tenants/{$tenant->slug}/sites/{$site->id}/email-settings/recipients",
            ['email_address' => '  Operator.Example@example.test  ', 'display_name' => '  Primary Operator  '],
        );

        $response->assertRedirect("/tenants/{$tenant->slug}/sites/{$site->id}/email-settings");
        $response->assertSessionHas('success', 'Recipient added.');
        $this->assertDatabaseHas('site_email_recipients', [
            'tenant_id' => $tenant->id,
            'site_id' => $site->id,
            'email_address' => 'Operator.Example@example.test',
            'normalized_email_address' => 'OPERATOR.EXAMPLE@EXAMPLE.TEST',
            'display_name' => 'Primary Operator',
            'is_enabled' => 1,
            'created_by_user_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->get("/tenants/{$tenant->slug}/sites/{$site->id}/email-settings")
            ->assertOk()
            ->assertSee(self::OPERATION_ID, false)
            ->assertSee('data-source-handler="AddAsync"', false)
            ->assertSee('Operator.Example@example.test');
    }

    public function test_duplicate_email_is_case_insensitive_and_does_not_create_a_second_recipient(): void
    {
        $user = User::factory()->create();
        [$tenant, $site] = $this->workspace($user, 'duplicate', ['tenant.view', 'sites.view', 'settings.manage'], 3);
        $url = "/tenants/{$tenant->slug}/sites/{$site->id}/email-settings/recipients";

        $this->actingAs($user)->post($url, ['email_address' => 'alerts@example.test'])->assertRedirect();
        $this->actingAs($user)
            ->from("/tenants/{$tenant->slug}/sites/{$site->id}/email-settings")
            ->post($url, ['email_address' => 'ALERTS@example.test'])
            ->assertRedirect()
            ->assertSessionHasErrors('email_address');

        $this->assertDatabaseCount('site_email_recipients', 1);
    }

    public function test_plan_recipient_limit_is_enforced_before_persistence(): void
    {
        $user = User::factory()->create();
        [$tenant, $site] = $this->workspace($user, 'quota', ['tenant.view', 'sites.view', 'settings.manage'], 1);
        $url = "/tenants/{$tenant->slug}/sites/{$site->id}/email-settings/recipients";

        $this->actingAs($user)->post($url, ['email_address' => 'first@example.test'])->assertRedirect();
        $this->actingAs($user)
            ->from("/tenants/{$tenant->slug}/sites/{$site->id}/email-settings")
            ->post($url, ['email_address' => 'second@example.test'])
            ->assertRedirect()
            ->assertSessionHasErrors('email_address');

        $this->assertDatabaseCount('site_email_recipients', 1);
        $this->assertDatabaseMissing('site_email_recipients', ['normalized_email_address' => 'SECOND@EXAMPLE.TEST']);
    }

    public function test_foreign_tenant_site_fails_closed_with_not_found(): void
    {
        $alphaUser = User::factory()->create();
        [$alpha] = $this->workspace($alphaUser, 'alpha-foreign', ['tenant.view', 'sites.view', 'settings.manage'], 2);

        $betaUser = User::factory()->create();
        [, $betaSite] = $this->workspace($betaUser, 'beta-foreign', ['tenant.view', 'sites.view', 'settings.manage'], 2);

        $this->actingAs($alphaUser)
            ->post(
                "/tenants/{$alpha->slug}/sites/{$betaSite->id}/email-settings/recipients",
                ['email_address' => 'foreign@example.test'],
            )
            ->assertNotFound();

        $this->assertDatabaseMissing('site_email_recipients', ['email_address' => 'foreign@example.test']);
    }

    public function test_missing_settings_permission_fails_closed_with_forbidden(): void
    {
        $user = User::factory()->create();
        [$tenant, $site] = $this->workspace($user, 'restricted', ['tenant.view', 'sites.view'], 2);

        $this->actingAs($user)
            ->post(
                "/tenants/{$tenant->slug}/sites/{$site->id}/email-settings/recipients",
                ['email_address' => 'blocked@example.test'],
            )
            ->assertForbidden();

        $this->assertDatabaseMissing('site_email_recipients', ['email_address' => 'blocked@example.test']);
    }

    /** @return array{Tenant, Site} */
    private function workspace(User $user, string $slug, array $permissions, ?int $recipientLimit): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "site-email-{$slug}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);

        $plan = BillingPlan::query()->create([
            'code' => "site-email-{$slug}",
            'name' => 'Site Email Test Plan',
            'price_minor' => 1000,
            'currency' => 'USD',
            'billing_interval' => 'month',
            'enabled' => true,
            'provider' => 'test',
            'provider_plan_id' => "provider-{$slug}",
            'limits' => ['email.siteRecipients.max' => $recipientLimit],
            'entitlements' => [],
        ]);
        TenantSubscription::query()->create([
            'billing_plan_id' => $plan->id,
            'state' => SubscriptionState::ACTIVE,
            'started_at' => now(),
        ]);
        $site = Site::query()->create([
            'name' => ucfirst($slug).' Site',
            'url' => "https://{$slug}.example.test",
            'status' => 'active',
        ]);

        $context->forget();

        return [$tenant, $site];
    }
}
