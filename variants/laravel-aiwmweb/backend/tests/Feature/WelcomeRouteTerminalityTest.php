<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicWelcomeReadController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class WelcomeRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-86346F4C6C';

    public function test_canonical_reconciliation_row_is_generator_backed_explicit_anonymous_terminal(): void
    {
        $payload = $this->reconciliation();
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('route', $row['kind']);
        $this->assertSame('content', $row['domain']);
        $this->assertSame('/welcome', $row['route_screen']);
        $this->assertSame('Open/render route', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/Welcome.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
        $this->assertSame('explicit_route_contract', $row['reconciliation']['evidence_mode']);
        $this->assertSame('explicit_anonymous', $row['reconciliation']['security_mode']);
        $this->assertSame(
            'variants/laravel-aiwmweb/docs/closure-evidence/welcome-route-terminality.json',
            $row['reconciliation']['evidence_path'],
        );
        $this->assertContains('source:AllowAnonymous', $row['reconciliation']['signals']);
        $this->assertContains('middleware:web-only', $row['reconciliation']['signals']);
        $this->assertContains('tenant:neutral', $row['reconciliation']['signals']);
        $this->assertContains('route:no-parameters', $row['reconciliation']['signals']);
        $this->assertContains('identity:no-disclosure', $row['reconciliation']['signals']);

        $this->assertSame(931, $payload['totals']['total']);
        $this->assertSame(
            $payload['totals']['ported'] + $payload['totals']['adapted'] + $payload['totals']['verified_unavailable_external'],
            $payload['totals']['terminal'],
        );
        $this->assertTrue((bool) ($payload['validation']['passed'] ?? false));
        $this->assertContains(self::OPERATION_ID, $payload['validation']['tenant_neutral_route_contract_terminals']);
        $this->assertContains(self::OPERATION_ID, $payload['validation']['source_boundary_route_contract_terminals']);
    }

    public function test_route_is_explicit_web_only_anonymous_and_has_no_direct_object_surface(): void
    {
        $route = Route::getRoutes()->match(Request::create('/welcome', 'GET'));

        $this->assertSame(PublicWelcomeReadController::class, $route->getActionName());
        $this->assertSame('public.welcome', $route->getName());
        $this->assertSame('welcome', $route->uri());
        $this->assertSame('AIMW-AI-4C07560F0B', $route->defaults['canonical_operation_id'] ?? null);
        $this->assertSame(self::OPERATION_ID, $route->defaults['canonical_route_operation_id'] ?? null);
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
    }

    public function test_reference_route_is_explicitly_anonymous_and_laravel_renders_the_real_public_product_entry(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/Welcome.razor'));

        $this->assertStringContainsString('@page "/welcome"', $source);
        $this->assertStringContainsString('@attribute [Microsoft.AspNetCore.Authorization.AllowAnonymous]', $source);

        $this->get('/welcome')
            ->assertOk()
            ->assertSee('<title>AI WordPress Manager</title>', false)
            ->assertSee('AI WordPress Manager')
            ->assertSee('Operate WordPress as one system.')
            ->assertSee('Protected tenant workspace')
            ->assertSee('data-canonical-operation="AIMW-AI-4C07560F0B"', false)
            ->assertDontSee('Welcome to Laravel')
            ->assertDontSee('Laravel has an incredibly rich ecosystem');
    }

    public function test_anonymous_and_authenticated_callers_receive_identity_neutral_content_without_tenant_or_user_disclosure(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant Alpha Sentinel', 'slug' => 'tenant-alpha-sentinel']);
        $tenantB = Tenant::query()->create(['name' => 'Tenant Beta Sentinel', 'slug' => 'tenant-beta-sentinel']);
        $userA = User::factory()->create(['name' => 'Alpha User Sentinel', 'email' => 'alpha-user-sentinel@example.test']);
        $userB = User::factory()->create(['name' => 'Beta User Sentinel', 'email' => 'beta-user-sentinel@example.test']);

        $anonymous = $this->get('/welcome?tenant='.$tenantA->slug.'&user=foreign-secret')->assertOk()->getContent();
        $alpha = $this->actingAs($userA)->get('/welcome?tenant='.$tenantA->slug.'&user=database-password-secret')->assertOk()->getContent();
        $beta = $this->actingAs($userB)->get('/welcome?tenant='.$tenantB->slug.'&user=foreign-secret')->assertOk()->getContent();

        $this->assertSame($anonymous, $alpha);
        $this->assertSame($anonymous, $beta);

        foreach ([$tenantA->name, $tenantA->slug, $tenantB->name, $tenantB->slug, $userA->name, $userA->email, $userB->name, $userB->email, 'foreign-secret', 'database-password-secret'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $anonymous);
            $this->assertStringNotContainsString($sentinel, $alpha);
            $this->assertStringNotContainsString($sentinel, $beta);
        }
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
