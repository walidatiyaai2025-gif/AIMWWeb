<?php

namespace Tests\Feature;

use App\Billing\Enums\SubscriptionState;
use App\Http\Controllers\RegisterController;
use App\Models\AuditEvent;
use App\Models\BillingPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RegisterAsyncTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-34B7686F5E';

    public function test_exact_canonical_register_async_operation_is_terminalized(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/register', $operation['route_screen']);
        $this->assertStringContainsString('RegisterAsync', $operation['visible_control']);
        $this->assertSame(
            'src/AIWordPressManager.Web/Components/Pages/Register.razor',
            $operation['current_source'],
        );
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('low', $operation['risk']);
    }

    public function test_registration_route_is_anonymous_web_csrf_and_operation_bound_without_tenant_path_authority(): void
    {
        $route = Route::getRoutes()->match(Request::create('/register', 'POST'));

        $this->assertSame(
            RegisterController::class.'@store',
            ltrim($route->getActionName(), '\\'),
        );
        $this->assertSame('canonical.register.store', $route->getName());
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(['POST'], $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());

        $foreignRegistrationMutation = collect(Route::getRoutes()->getRoutes())
            ->first(static fn ($candidate): bool => in_array('POST', $candidate->methods(), true)
                && str_contains($candidate->uri(), 'tenant')
                && str_ends_with($candidate->uri(), '/register'));

        $this->assertNull(
            $foreignRegistrationMutation,
            'Foreign/cross-tenant registration mutation must remain absent (404-equivalent).',
        );

        $this->post('/register', [
            'username' => 'safe-user',
            'password' => 'Password9',
            'password_confirmation' => 'Password9',
            'tenant_id' => 999999,
        ])->assertSessionHasErrors('request');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_authenticated_identity_cannot_reuse_the_public_registration_mutation(): void
    {
        $existing = User::factory()->create();

        $this->actingAs($existing)
            ->post('/register', [
                'username' => 'second-workspace',
                'password' => 'StrongPass9',
                'password_confirmation' => 'StrongPass9',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('tenant_memberships', 0);
    }

    public function test_register_page_preserves_source_visible_contract_without_browser_secrets(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('Create your account')
            ->assertSee('14-DAY FREE TRIAL')
            ->assertSee('Start free trial')
            ->assertSee('1 WordPress site')
            ->assertSee('50 AI requests / month')
            ->assertSee('1 automation schedule')
            ->assertSee('3-day backup retention')
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false)
            ->assertSee('name="_token"', false)
            ->assertSee('name="username"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertDontSee('name="tenant_id"', false)
            ->assertDontSee('name="user_id"', false)
            ->assertDontSee('name="billing_plan_id"', false);
    }

    public function test_weak_or_mismatched_credentials_fail_before_any_identity_or_workspace_mutation(): void
    {
        $this->post('/register', [
            'username' => 'new-user',
            'password' => 'weakpass',
            'password_confirmation' => 'different',
        ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('tenant_memberships', 0);
    }

    public function test_success_creates_durable_identity_workspace_owner_rbac_trial_and_audit_then_login_accepts_username(): void
    {
        $this->assertTrue(BillingPlan::query()->where('code', 'free-trial')->where('enabled', true)->exists());

        $response = $this->post('/register', [
            'username' => 'Alpha.User',
            'password' => 'StrongPass9',
            'password_confirmation' => 'StrongPass9',
        ]);

        $response->assertRedirect('/login?registered=true');

        $user = User::query()->where('normalized_username', 'ALPHA.USER')->firstOrFail();
        $this->assertSame('Alpha.User', $user->username);
        $this->assertSame('Alpha.User', $user->name);
        $this->assertTrue(Hash::check('StrongPass9', $user->password));
        $this->assertNotSame('StrongPass9', $user->password);
        $this->assertStringEndsWith('@accounts.invalid', $user->email);

        $tenant = Tenant::query()->where('name', "Alpha.User's Workspace")->firstOrFail();
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->where('user_id', $user->id)->firstOrFail();
        $context->activate($tenant, $membership);

        $this->assertSame('active', $membership->status);
        $this->assertTrue($membership->roles()->where('name', 'owner')->exists());
        $this->assertTrue($membership->hasPermission('tenant.view'));
        $this->assertTrue($membership->hasPermission('billing.manage'));

        $subscription = TenantSubscription::query()->with('plan')->firstOrFail();
        $this->assertSame(SubscriptionState::TRIALING, $subscription->state);
        $this->assertSame('free-trial', $subscription->plan->code);
        $this->assertNotNull($subscription->trial_started_at);
        $this->assertNotNull($subscription->trial_expires_at);
        $this->assertSame(14, (int) $subscription->trial_started_at->diffInDays($subscription->trial_expires_at));

        $audit = AuditEvent::query()->where('event', 'account.registered')->firstOrFail();
        $this->assertSame($user->id, $audit->actor_user_id);
        $this->assertSame(['username' => 'Alpha.User'], $audit->metadata);
        $context->forget();

        $this->postJson('/api/login', [
            'login' => 'alpha.user',
            'password' => 'StrongPass9',
        ])
            ->assertOk()
            ->assertJsonPath('user.username', 'Alpha.User');
    }

    public function test_trial_failure_is_truthful_and_does_not_roll_back_the_created_account_workspace(): void
    {
        BillingPlan::query()->where('code', 'free-trial')->update(['enabled' => false]);

        $response = $this->post('/register', [
            'username' => 'NoPlan.User',
            'password' => 'StrongPass9',
            'password_confirmation' => 'StrongPass9',
        ]);

        $response
            ->assertStatus(503)
            ->assertSee('The free trial plan is currently unavailable. The account was created; contact an administrator to assign a subscription.')
            ->assertDontSee('StrongPass9');

        $user = User::query()->where('normalized_username', 'NOPLAN.USER')->firstOrFail();
        $tenant = Tenant::query()->where('name', "NoPlan.User's Workspace")->firstOrFail();

        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->where('user_id', $user->id)->firstOrFail();
        $context->activate($tenant, $membership);
        $this->assertFalse(TenantSubscription::query()->exists());
        $context->forget();

        $this->postJson('/register', [
            'username' => 'noplan.user',
            'password' => 'StrongPass9',
            'password_confirmation' => 'StrongPass9',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('username');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenants', 1);
    }

    public function test_existing_email_login_remains_backward_compatible(): void
    {
        $user = User::factory()->create([
            'email' => 'existing@example.test',
            'password' => 'LegacyPass9',
        ]);

        $this->postJson('/api/login', [
            'email' => 'existing@example.test',
            'password' => 'LegacyPass9',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_source_and_laravel_paths_preserve_registration_and_partial_failure_semantics(): void
    {
        $source = (string) file_get_contents(
            base_path('../../../src/AIWordPressManager.Web/Components/Pages/Register.razor'),
        );
        $controller = (string) file_get_contents(app_path('Http/Controllers/RegisterController.php'));
        $service = (string) file_get_contents(app_path('Auth/RegistrationService.php'));
        $view = (string) file_get_contents(resource_path('views/auth/register.blade.php'));

        $this->assertStringContainsString('RegisterAsync', $source);
        $this->assertStringContainsString('Authentication.RegisterAsync', $source);
        $this->assertStringContainsString('Plans.GetByCodeAsync(FreeTrialPlanCode)', $source);
        $this->assertStringContainsString('Subscriptions.CreateAsync', $source);
        $this->assertStringContainsString('/login?registered=true', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $controller);
        $this->assertStringContainsString('FORBIDDEN_OWNERSHIP_FIELDS', $controller);
        $this->assertStringContainsString('The account was created; contact an administrator', $controller);
        $this->assertStringContainsString('DB::transaction', $service);
        $this->assertStringContainsString('normalized_username', $service);
        $this->assertStringContainsString('TenantMembership::query()->create', $service);
        $this->assertStringContainsString('startFreeTrial', $service);
        $this->assertStringContainsString('@csrf', $view);
        $this->assertStringNotContainsString('api_key', strtolower($view));
        $this->assertStringNotContainsString('client_secret', strtolower($view));
    }
}
