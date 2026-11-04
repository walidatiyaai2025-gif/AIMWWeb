<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupMutationController;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\DatabaseSetupMutationService;
use App\Services\SetupOwnerProof;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class SetupMutationTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const SETUP_POST_OPERATION_ID = 'AIMW-CONT-475267F150';

    private string $ownerChallengePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerChallengePath = storage_path('framework/testing/owner-'.bin2hex(random_bytes(8)).'.secret');
        config()->set('setup.owner_proof_file', $this->ownerChallengePath);
        app(SetupOwnerProof::class)->ensureIssued();
    }

    protected function tearDown(): void
    {
        @unlink($this->ownerChallengePath);
        @unlink($this->ownerChallengePath.'.lock');
        parent::tearDown();
    }

    private function ownerActivation(): string
    {
        return trim((string) file_get_contents($this->ownerChallengePath));
    }

    public function test_canonical_setup_post_is_anonymous_pre_tenant_web_mutation_with_csrf_middleware(): void
    {
        $route = Route::getRoutes()->getByName('canonical.api.setup.submit');

        $this->assertNotNull($route, self::SETUP_POST_OPERATION_ID);
        $this->assertSame(SetupMutationController::class, $route->getActionName());
        $this->assertContains('POST', $route->methods());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertContains('throttle:5,1', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
    }

    public function test_fresh_setup_creates_one_hashed_owner_and_real_rbac_then_redirects(): void
    {
        $password = 'correct-horse-battery-staple';

        $this->post('/setup', [
            'tenant_name' => 'Primary Workspace',
            'owner_activation_secret' => $this->ownerActivation(),
            'admin_name' => 'First Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ])->assertRedirect('/');

        $user = User::query()->sole();
        $this->assertSame('owner@example.test', $user->email);
        $this->assertTrue($user->platform_admin, 'The first setup owner must be able to access platform system updates.');
        $this->actingAs($user)->get('/admin/system-updates')->assertOk();
        auth()->logout();

        $this->assertNotSame($password, $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check($password, $user->getRawOriginal('password')));

        $membership = TenantMembership::withoutGlobalScopes()->sole();
        $tenant = DB::table('tenants')->where('id', $membership->tenant_id)->first();
        $this->assertNotNull($tenant);

        app(TenantContext::class)->activate(Tenant::query()->findOrFail($membership->tenant_id), $membership);
        $role = $membership->roles()->where('name', 'Owner')->sole();
        $this->assertGreaterThan(0, $role->permissions()->count());
        app(TenantContext::class)->forget();

        $this->assertFileDoesNotExist($this->ownerChallengePath);
        $this->get('/setup')->assertRedirect('/');
    }

    public function test_setup_form_keeps_database_credentials_deployment_owned_and_csrf_protected(): void
    {
        $view = file_get_contents(resource_path('views/setup.blade.php'));

        $this->assertIsString($view, self::SETUP_POST_OPERATION_ID);
        $this->assertStringContainsString('@csrf', $view);
        foreach (['Provider', 'SqlitePath', 'Host', 'Port', 'Database', 'Username', 'Password'] as $sourceField) {
            $this->assertStringNotContainsString('name="'.$sourceField.'"', $view);
        }
    }

    public function test_partial_existing_identity_state_is_never_claimed_and_password_is_not_rendered(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);
        $password = 'never-render-this-password';

        $this->post('/setup', [
            'tenant_name' => 'Attacker Workspace',
            'owner_activation_secret' => $this->ownerActivation(),
            'admin_name' => 'Replacement Owner',
            'admin_email' => 'replacement@example.test',
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ])
            ->assertStatus(400)
            ->assertSee('Setup could not be completed safely')
            ->assertDontSee($password)
            ->assertDontSee('replacement@example.test');

        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('tenant_memberships')->count());
    }

    public function test_existing_user_blocks_first_run_before_any_pending_migration(): void
    {
        User::factory()->create(['email' => 'already-installed@example.test']);

        // Simulate an existing deployment that has not yet run the latest
        // migration. Setup must not apply it before rejecting ownership.
        $migration = (string) DB::table('migrations')->orderByDesc('batch')
            ->orderByDesc('migration')->value('migration');
        $this->assertNotSame('', $migration);
        DB::table('migrations')->where('migration', $migration)->delete();

        try {
            app(DatabaseSetupMutationService::class)->apply([
                'tenant_name' => 'Overwrite Attempt',
                'admin_name' => 'Attacker',
                'admin_email' => 'attacker@example.test',
                'admin_password' => 'correct-horse-battery-staple',
            ]);
            $this->fail('First-run setup accepted an existing user.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Existing identity state', $e->getMessage());
        }

        $this->assertDatabaseMissing('migrations', ['migration' => $migration]);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
    }

    public function test_existing_tenant_also_blocks_first_run_before_migration(): void
    {
        Tenant::query()->create(['name' => 'Previous Tenant', 'slug' => 'previous-tenant']);

        $migration = (string) DB::table('migrations')->orderByDesc('batch')
            ->orderByDesc('migration')->value('migration');
        DB::table('migrations')->where('migration', $migration)->delete();

        try {
            app(DatabaseSetupMutationService::class)->apply([
                'tenant_name' => 'Second Owner',
                'admin_name' => 'Attacker',
                'admin_email' => 'attacker@example.test',
                'admin_password' => 'correct-horse-battery-staple',
            ]);
            $this->fail('First-run setup accepted an existing tenant.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Existing identity state', $e->getMessage());
        }

        $this->assertDatabaseMissing('migrations', ['migration' => $migration]);
        $this->assertSame(1, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_anonymous_visitor_cannot_create_platform_admin_without_hosting_owner_secret(): void
    {
        $credentials = [
            'tenant_name' => 'Attacker',
            'admin_name' => 'Attacker',
            'admin_email' => 'attacker@example.test',
            'admin_password' => 'correct-horse-battery-staple',
            'admin_password_confirmation' => 'correct-horse-battery-staple',
        ];

        $this->post('/setup', $credentials)->assertSessionHasErrors('owner_activation_secret');
        $this->post('/setup', $credentials + ['owner_activation_secret' => str_repeat('a', 64)])
            ->assertForbidden();

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());

        $page = $this->get('/setup')->assertOk();
        $page->assertDontSee($this->ownerActivation());
    }

    public function test_invalid_password_confirmation_does_not_mutate_identity_state(): void
    {
        $this->post('/setup', [
            'tenant_name' => 'Primary Workspace',
            'owner_activation_secret' => $this->ownerActivation(),
            'admin_name' => 'First Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => 'correct-horse-battery-staple',
            'admin_password_confirmation' => 'different-password-value',
        ])->assertSessionHasErrors('admin_password');

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenants')->count());
    }
}
