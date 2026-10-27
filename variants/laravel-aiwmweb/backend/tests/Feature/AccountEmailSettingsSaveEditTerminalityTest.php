<?php

namespace Tests\Feature;

use App\Email\Services\AccountEmailRecipientService;
use App\Models\AccountEmailRecipient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AccountEmailSettingsSaveEditTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-EMAI-10F3C44369';

    public function test_route_and_source_semantics_are_bound_to_exact_save_edit_operation(): void
    {
        $route = Route::getRoutes()->match(
            Request::create('/api/v1/tenants/alpha/email/recipients/7', 'PATCH'),
        );
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/AccountEmailSettings.razor'),
        );
        $frontend = (string) file_get_contents(
            resource_path('js/account-email-settings-recipients-control.tsx'),
        );
        $summary = (string) file_get_contents(resource_path('js/account-email-settings-summary.tsx'));

        $this->assertSame('api.v1.email.recipients.update', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['PATCH'], $route->methods());
        $this->assertSame(self::OPERATION_ID, AccountEmailRecipientService::UPDATE_OPERATION_ID);

        $this->assertStringContainsString('OnClick="SaveEditClickedAsync"', $source);
        $this->assertStringContainsString('Service.UpdateRecipientAsync', $source);
        $this->assertStringContainsString(self::OPERATION_ID, $frontend);
        $this->assertStringContainsString('data-source-handler="SaveEditClickedAsync"', $frontend);
        $this->assertStringContainsString('<AccountEmailSettingsRecipientsControl context={context} />', $summary);
        $this->assertStringNotContainsString('CancelEditClicked', $frontend);
    }

    public function test_save_edit_updates_only_active_tenant_recipient_and_returns_authoritative_state(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.manage']);
        $recipient = $this->recipient($alpha, 'old@example.test', 'Old label', true);

        $response = $this->actingAs($user)
            ->patchJson('/api/v1/tenants/alpha/email/recipients/'.$recipient->id, [
                'email_address' => 'New@example.test',
                'display_name' => '  Updated label  ',
                'is_enabled' => false,
            ])
            ->assertOk()
            ->assertJsonPath('id', $recipient->id)
            ->assertJsonPath('email_address', 'New@example.test')
            ->assertJsonPath('display_name', 'Updated label')
            ->assertJsonPath('is_enabled', false);

        $context = app(TenantContext::class);
        $context->activate($alpha->tenant, $alpha);
        $updated = AccountEmailRecipient::query()->findOrFail($recipient->id);
        $this->assertSame('New@example.test', $updated->email_address);
        $this->assertSame('NEW@EXAMPLE.TEST', $updated->normalized_email_address);
        $this->assertSame('Updated label', $updated->display_name);
        $this->assertFalse((bool) $updated->is_enabled);
        $context->forget();

        $this->assertStringNotContainsString('normalized_email_address', $response->getContent());
        $this->assertStringNotContainsString('tenant_id', $response->getContent());
    }

    public function test_duplicate_email_fails_without_mutating_recipient(): void
    {
        $user = User::factory()->create();
        $alpha = $this->membership($user, 'alpha', ['tenant.manage']);
        $first = $this->recipient($alpha, 'first@example.test', 'First', true);
        $this->recipient($alpha, 'second@example.test', 'Second', true);

        $this->actingAs($user)
            ->patchJson('/api/v1/tenants/alpha/email/recipients/'.$first->id, [
                'email_address' => 'SECOND@example.test',
                'display_name' => 'Duplicate',
                'is_enabled' => false,
            ])
            ->assertUnprocessable();

        $context = app(TenantContext::class);
        $context->activate($alpha->tenant, $alpha);
        $unchanged = AccountEmailRecipient::query()->findOrFail($first->id);
        $this->assertSame('first@example.test', $unchanged->email_address);
        $this->assertSame('FIRST@EXAMPLE.TEST', $unchanged->normalized_email_address);
        $this->assertSame('First', $unchanged->display_name);
        $this->assertTrue((bool) $unchanged->is_enabled);
        $context->forget();
    }

    public function test_list_and_update_fail_closed_for_missing_permission_and_foreign_tenant(): void
    {
        $alphaOwner = User::factory()->create();
        $alpha = $this->membership($alphaOwner, 'alpha', ['tenant.manage']);
        $alphaRecipient = $this->recipient($alpha, 'alpha@example.test', 'Alpha', true);

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.manage']);
        $betaRecipient = $this->recipient($beta, 'beta@example.test', 'Beta', true);

        $this->actingAs($alphaOwner)
            ->getJson('/api/v1/tenants/alpha/email/recipients')
            ->assertOk()
            ->assertJsonPath('data.0.id', $alphaRecipient->id)
            ->assertJsonMissing(['email_address' => 'beta@example.test']);

        $this->actingAs($alphaOwner)
            ->patchJson('/api/v1/tenants/alpha/email/recipients/'.$betaRecipient->id, [
                'email_address' => 'stolen@example.test',
                'display_name' => 'No',
                'is_enabled' => false,
            ])
            ->assertNotFound();

        $this->actingAs($alphaOwner)
            ->getJson('/api/v1/tenants/beta/email/recipients')
            ->assertNotFound();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view']);
        $limitedRecipient = $this->recipient($limitedMembership, 'limited@example.test', null, true);

        $this->actingAs($limited)
            ->getJson('/api/v1/tenants/limited/email/recipients')
            ->assertForbidden();
        $this->actingAs($limited)
            ->patchJson('/api/v1/tenants/limited/email/recipients/'.$limitedRecipient->id, [
                'email_address' => 'limited2@example.test',
                'display_name' => null,
                'is_enabled' => true,
            ])
            ->assertForbidden();
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "account-email-save-edit-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }

    private function recipient(
        TenantMembership $membership,
        string $email,
        ?string $displayName,
        bool $enabled,
    ): AccountEmailRecipient {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);

        try {
            return AccountEmailRecipient::query()->create([
                'email_address' => $email,
                'normalized_email_address' => mb_strtoupper($email, 'UTF-8'),
                'display_name' => $displayName,
                'is_enabled' => $enabled,
                'created_by_user_id' => $membership->user_id,
            ]);
        } finally {
            $context->forget();
        }
    }
}
