<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\MailConfiguration;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailHistoryApplyFiltersTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-F8E8A2BEE9';

    public function test_apply_filters_reads_only_matching_authoritative_tenant_rows(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $betaSite = $this->site($beta, 'Beta Site');

        $alphaSent = $this->delivery($alpha, $alphaSite, 'SENT');
        $this->delivery($alpha, $alphaSite, 'FAILED');
        $this->delivery($beta, $betaSite, 'SENT');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?site_id='.$alphaSite->id
                .'&status=SENT&correlation='.$alphaSent->event_id.'&page=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.delivery_id', $alphaSent->delivery_id)
            ->assertJsonPath('data.0.correlation_id', $alphaSent->event_id)
            ->assertJsonPath('data.0.status', 'SENT');

        $this->assertSame('AIMW-EMAI-F8E8A2BEE9', self::OPERATION_ID);
    }

    public function test_apply_filters_preserves_empty_results_and_foreign_site_fails_closed(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        $beta = $this->membership($user, 'beta', ['tenant.view', 'tenant.manage', 'diagnostics.view']);
        $alphaSite = $this->site($alpha, 'Alpha Site');
        $foreignSite = $this->site($beta, 'Foreign Site');
        $this->delivery($alpha, $alphaSite, 'SENT');

        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?site_id='.$alphaSite->id.'&status=FAILED')
            ->assertOk()
            ->assertJsonPath('total', 0)
            ->assertJsonPath('data', []);

        // AIMW-EMAI-F8E8A2BEE9 tenant-isolation proof: foreign tenant IDs fail closed.
        $this->actingAs($user)
            ->getJson('/api/v1/tenants/alpha/email/deliveries?site_id='.$foreignSite->id)
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "email-history-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function site(TenantMembership $membership, string $name): Site
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $site = Site::query()->create([
            'name' => $name,
            'url' => 'https://'.Str::slug($name).'.example.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function delivery(TenantMembership $membership, Site $site, string $status): EmailDelivery
    {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        $configuration = MailConfiguration::query()->create([
            'configuration_key' => 'site-'.$site->id.'-'.Str::lower(Str::random(6)),
            'site_id' => $site->id,
            'transport' => 'smtp',
            'host' => 'smtp.example.test',
            'port' => 587,
            'from_address' => 'noreply@example.test',
            'from_name' => 'AIWM',
            'enabled' => true,
            'timeout_seconds' => 20,
            'max_attempts' => 4,
        ]);

        $eventId = (string) Str::uuid();
        $delivery = EmailDelivery::query()->create([
            'in_app_notification_id' => null,
            'mail_configuration_id' => $configuration->id,
            'event_id' => $eventId,
            'delivery_id' => (string) Str::uuid(),
            'idempotency_key' => 'email-history-'.Str::uuid(),
            'recipient' => 'person@example.test',
            'recipient_hash' => hash('sha256', 'person@example.test'),
            'template_stable_id' => 'system-test',
            'locale' => 'en',
            'status' => $status,
            'attempt_count' => $status === 'SENT' ? 1 : 2,
            'max_attempts' => 4,
            'variables' => [],
        ]);
        $context->forget();

        return $delivery;
    }
}
