<?php

namespace Tests\Feature;

use App\Http\Controllers\AccessDeniedReadController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AccessDeniedRouteTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-8EE96B77A8';

    public function test_canonical_reconciliation_row_is_generator_backed_tenant_neutral_terminal(): void
    {
        $payload = $this->reconciliation();
        $row = collect($payload['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('route', $row['kind']);
        $this->assertSame('content', $row['domain']);
        $this->assertSame('/access-denied', $row['route_screen']);
        $this->assertSame('Open/render route', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccessDeniedPage.razor', $row['current_source']);
        $this->assertFalse($row['mutation']);
        $this->assertTrue($row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('ADAPTED', $row['migration_state']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
        $this->assertSame('explicit_route_contract', $row['reconciliation']['evidence_mode']);
        $this->assertSame('tenant_neutral', $row['reconciliation']['security_mode']);
        $this->assertSame(
            'variants/laravel-aiwmweb/docs/closure-evidence/access-denied-route-terminality.json',
            $row['reconciliation']['evidence_path'],
        );
        $this->assertContains('source:AllowAnonymous', $row['reconciliation']['signals']);
        $this->assertContains('middleware:web-only', $row['reconciliation']['signals']);
        $this->assertContains('tenant:neutral', $row['reconciliation']['signals']);
        $this->assertContains('route:no-parameters', $row['reconciliation']['signals']);
        $this->assertContains('identity:no-disclosure', $row['reconciliation']['signals']);

        $this->assertCount(931, $payload['operations']);
        $this->assertSame(481, $payload['totals']['terminal']);
        $this->assertSame(450, $payload['totals']['pending']);
        $this->assertSame(0, $payload['totals']['blocked']);
        $this->assertSame(
            931,
            $payload['totals']['terminal'] + $payload['totals']['pending'] + $payload['totals']['blocked'],
        );
        $this->assertContains(self::OPERATION_ID, $payload['validation']['tenant_neutral_route_contract_terminals']);
    }

    public function test_route_is_explicit_anonymous_and_not_a_tenant_spa_catch_all(): void
    {
        $route = Route::getRoutes()->match(Request::create('/access-denied', 'GET'));

        $this->assertSame(AccessDeniedReadController::class, $route->getActionName());
        $this->assertSame('canonical.access-denied', $route->getName());
        $this->assertSame('access-denied', $route->uri());
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
        $this->assertNotContains('tenant.context', $route->gatherMiddleware());
        $this->assertSame([], $route->parameterNames());
    }

    public function test_anonymous_user_receives_the_real_source_equivalent_denial_page(): void
    {
        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/AccessDeniedPage.razor'));

        $this->assertStringContainsString('@attribute [Microsoft.AspNetCore.Authorization.AllowAnonymous]', $source);
        $this->assertStringContainsString('@page "/access-denied"', $source);

        $this->get('/access-denied')
            ->assertOk()
            ->assertSee('<title>Access denied</title>', false)
            ->assertSee('403')
            ->assertSee('Access denied')
            ->assertSee('You are signed in, but your account does not have permission to open this page.')
            ->assertSee('href="/"', false)
            ->assertSee('Return home');
    }

    public function test_authenticated_tenant_a_and_tenant_b_callers_receive_identical_static_content_without_identity_disclosure(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Tenant Alpha Sentinel', 'slug' => 'alpha-sentinel']);
        $tenantB = Tenant::query()->create(['name' => 'Tenant Beta Sentinel', 'slug' => 'beta-sentinel']);
        $userA = User::factory()->create(['name' => 'Alpha User Sentinel', 'email' => 'alpha-sentinel@example.test']);
        $userB = User::factory()->create(['name' => 'Beta User Sentinel', 'email' => 'beta-sentinel@example.test']);

        $anonymous = $this->get('/access-denied?tenant='.$tenantA->slug)->assertOk()->getContent();
        $alpha = $this->actingAs($userA)->get('/access-denied?tenant='.$tenantA->slug)->assertOk()->getContent();
        $beta = $this->actingAs($userB)->get('/access-denied?tenant='.$tenantB->slug)->assertOk()->getContent();

        $this->assertSame($anonymous, $alpha);
        $this->assertSame($anonymous, $beta);

        foreach ([$tenantA->name, $tenantA->slug, $tenantB->name, $tenantB->slug, $userA->name, $userA->email, $userB->name, $userB->email] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $anonymous);
            $this->assertStringNotContainsString($sentinel, $alpha);
            $this->assertStringNotContainsString($sentinel, $beta);
        }
    }

    /** @return array<string, mixed> */
    private function reconciliation(): array
    {
        return json_decode(
            file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
