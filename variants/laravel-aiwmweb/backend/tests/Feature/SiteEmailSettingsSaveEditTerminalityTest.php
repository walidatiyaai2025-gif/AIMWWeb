<?php

namespace Tests\Feature;

use App\Email\Services\SiteEmailRecipientService;
use App\Http\Controllers\SiteEmailSettingsController;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteEmailRecipient;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SiteEmailSettingsSaveEditTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-F189D80354';

    public function test_exact_save_edit_control_is_bound_to_guarded_patch_route(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/SiteEmailSettings.razor'));
        $view = (string) file_get_contents(resource_path('views/sites/email-settings.blade.php'));

        $this->assertStringContainsString('@onclick="SaveEditAsync"', $source);
        $this->assertStringContainsString('private async Task SaveEditAsync()', $source);
        $this->assertStringContainsString('RecipientService.UpdateAsync', $source);

        $this->assertSame(self::OPERATION_ID, SiteEmailRecipientService::UPDATE_OPERATION_ID);
        $this->assertStringContainsString(self::OPERATION_ID, $view);
        $this->assertStringContainsString('data-source-handler="SaveEditAsync"', $view);

        $route = Route::getRoutes()->match(
            Request::create('/tenants/alpha/sites/17/email-settings/recipients/23', 'PATCH'),
        );

        $this->assertSame(
            SiteEmailSettingsController::class.'@saveEdit',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.workspace.site-email-settings.recipient-update', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame(['PATCH'], $route->methods());
    }

    public function test_authorized_member_updates_owned_recipient_and_rereads_authoritative_state(): void
    {
        $user = User::factory()->create();
        $membership = $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $site = $this->site($membership, 'Alpha Mail');
        $recipient = $this->recipient($membership, $site, 'old@example.test', 'Old label', true);
        $this->withoutVite();

        $this->actingAs($user)
            ->patch("/tenants/alpha/sites/{$site->id}/email-settings/recipients/{$recipient->id}", [
                'email_address' => 'new@example.test',
                'display_name' => 'Updated label',
                'is_enabled' => '0',
            ])
            ->assertRedirect("/tenants/alpha/sites/{$site->id}/email-settings")
            ->assertSessionHas('success', 'Email recipient updated.');

        app(TenantContext::class)->activate($membership->tenant, $membership);
        $updated = SiteEmailRecipient::query()->findOrFail($recipient->id);

        $this->assertSame('new@example.test', $updated->email_address);
        $this->assertSame('NEW@EXAMPLE.TEST', $updated->normalized_email_address);
        $this->assertSame('Updated label', $updated->display_name);
        $this->assertFalse((bool) $updated->is_enabled);
        app(TenantContext::class)->forget();

        $this->actingAs($user)
            ->get("/tenants/alpha/sites/{$site->id}/email-settings")
            ->assertOk()
            ->assertSee('new@example.test')
            ->assertSee('Updated label');
    }

    public function test_duplicate_email_permission_and_foreign_recipient_fail_closed_without_cross_record_mutation(): void
    {
        $owner = User::factory()->create();
        $alpha = $this->membership($owner, 'alpha', ['tenant.view', 'sites.view', 'settings.manage']);
        $alphaSite = $this->site($alpha, 'Alpha');
        $first = $this->recipient($alpha, $alphaSite, 'first@example.test', 'First', true);
        $second = $this->recipient($alpha, $alphaSite, 'second@example.test', 'Second', true);

        $this->actingAs($owner)
            ->from("/tenants/alpha/sites/{$alphaSite->id}/email-settings")
            ->patch("/tenants/alpha/sites/{$alphaSite->id}/email-settings/recipients/{$first->id}", [
                'email_address' => 'second@example.test',
                'display_name' => 'Duplicate attempt',
                'is_enabled' => '1',
            ])
            ->assertRedirect("/tenants/alpha/sites/{$alphaSite->id}/email-settings")
            ->assertSessionHasErrors(['email_address']);

        app(TenantContext::class)->activate($alpha->tenant, $alpha);
        $this->assertSame('first@example.test', SiteEmailRecipient::query()->findOrFail($first->id)->email_address);
        app(TenantContext::class)->forget();

        $limited = User::factory()->create();
        $limitedMembership = $this->membership($limited, 'limited', ['tenant.view', 'sites.view']);
        $limitedSite = $this->site($limitedMembership, 'Limited');
        $limitedRecipient = $this->recipient($limitedMembership, $limitedSite, 'limited@example.test', null, true);

        $this->actingAs($limited)
            ->patch("/tenants/limited/sites/{$limitedSite->id}/email-settings/recipients/{$limitedRecipient->id}", [
                'email_address' => 'blocked@example.test',
                'display_name' => '',
                'is_enabled' => '1',
            ])
            ->assertForbidden();

        $betaOwner = User::factory()->create();
        $beta = $this->membership($betaOwner, 'beta', ['tenant.view', 'sites.view', 'settings.manage']);
        $betaSite = $this->site($beta, 'Beta');
        $betaRecipient = $this->recipient($beta, $betaSite, 'beta@example.test', null, true);

        $this->actingAs($owner)
            ->patch("/tenants/alpha/sites/{$alphaSite->id}/email-settings/recipients/{$betaRecipient->id}", [
                'email_address' => 'intruder@example.test',
                'display_name' => 'Intruder',
                'is_enabled' => '0',
            ])
            ->assertNotFound();

        app(TenantContext::class)->activate($beta->tenant, $beta);
        $this->assertSame('beta@example.test', SiteEmailRecipient::query()->findOrFail($betaRecipient->id)->email_address);
        $this->assertTrue((bool) SiteEmailRecipient::query()->findOrFail($betaRecipient->id)->is_enabled);
        app(TenantContext::class)->forget();

        $this->assertNotSame($first->id, $second->id);
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "save-edit-{$slug}-{$user->id}"]);

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
            'url' => 'https://'.strtolower(str_replace(' ', '-', $name)).'.example.test',
            'status' => 'active',
        ]);
        $context->forget();

        return $site;
    }

    private function recipient(
        TenantMembership $membership,
        Site $site,
        string $email,
        ?string $displayName,
        bool $enabled,
    ): SiteEmailRecipient {
        $context = app(TenantContext::class);
        $context->activate($membership->tenant, $membership);
        $recipient = SiteEmailRecipient::query()->create([
            'site_id' => $site->id,
            'email_address' => $email,
            'normalized_email_address' => mb_strtoupper($email, 'UTF-8'),
            'display_name' => $displayName,
            'is_enabled' => $enabled,
            'created_by_user_id' => $membership->user_id,
        ]);
        $context->forget();

        return $recipient;
    }
}
