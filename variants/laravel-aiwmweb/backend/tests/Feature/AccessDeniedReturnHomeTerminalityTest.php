<?php

namespace Tests\Feature;

use App\Http\Controllers\AccessDeniedReadController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AccessDeniedReturnHomeTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-9D5E269773';

    public function test_exact_canonical_operation_is_the_pending_return_home_visible_control(): void
    {
        $row = collect($this->reconciliation()['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($row);
        $this->assertSame('PENDING', $row['migration_state']);
        $this->assertSame('content', $row['domain']);
        $this->assertSame('visible_control', $row['kind']);
        $this->assertSame('/access-denied', $row['route_screen']);
        $this->assertSame('/ -> /', $row['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/AccessDeniedPage.razor', $row['current_source']);
        $this->assertFalse((bool) $row['mutation']);
        $this->assertTrue((bool) $row['tenant_owned']);
        $this->assertSame('low', $row['risk']);
        $this->assertSame('rendered/read response matches authoritative source', $row['verification']);
    }

    public function test_real_source_page_renders_the_exact_canonical_return_home_control(): void
    {
        $this->get('/access-denied')
            ->assertOk()
            ->assertSee('Return home')
            ->assertSee('href="/"', false)
            ->assertSee('data-canonical-operation="'.self::OPERATION_ID.'"', false);
    }

    public function test_source_and_destination_are_real_explicit_anonymous_routes(): void
    {
        $source = Route::getRoutes()->match(Request::create('/access-denied', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/', 'GET'));

        $this->assertSame(AccessDeniedReadController::class, $source->getActionName());
        $this->assertSame('canonical.access-denied', $source->getName());
        $this->assertSame('access-denied', $source->uri());
        $this->assertSame([], $source->parameterNames());
        $this->assertContains('web', $source->gatherMiddleware());
        $this->assertNotContains('auth', $source->gatherMiddleware());
        $this->assertNotContains('tenant.context', $source->gatherMiddleware());

        $this->assertSame('/', $destination->uri());
        $this->assertSame([], $destination->parameterNames());
        $this->assertContains('web', $destination->gatherMiddleware());
        $this->assertNotContains('auth', $destination->gatherMiddleware());
        $this->assertNotContains('tenant.context', $destination->gatherMiddleware());

        $this->get('/')->assertOk();
    }

    public function test_anonymous_and_authenticated_tenant_a_and_b_callers_receive_the_same_control_without_identity_disclosure(): void
    {
        $tenantA = Tenant::query()->create(['name' => 'Return Home Alpha Tenant', 'slug' => 'return-home-alpha']);
        $tenantB = Tenant::query()->create(['name' => 'Return Home Beta Tenant', 'slug' => 'return-home-beta']);
        $userA = User::factory()->create(['name' => 'Return Home Alpha User', 'email' => 'return-home-alpha@example.test']);
        $userB = User::factory()->create(['name' => 'Return Home Beta User', 'email' => 'return-home-beta@example.test']);

        $anonymous = $this->get('/access-denied?tenant='.$tenantA->slug)->assertOk()->getContent();
        $alpha = $this->actingAs($userA)->get('/access-denied?tenant='.$tenantA->slug)->assertOk()->getContent();
        $beta = $this->actingAs($userB)->get('/access-denied?tenant='.$tenantB->slug)->assertOk()->getContent();

        $this->assertSame($anonymous, $alpha);
        $this->assertSame($anonymous, $beta);
        $this->assertStringContainsString('data-canonical-operation="'.self::OPERATION_ID.'"', $anonymous);
        $this->assertStringContainsString('href="/"', $anonymous);

        foreach ([$tenantA->name, $tenantA->slug, $tenantB->name, $tenantB->slug, $userA->name, $userA->email, $userB->name, $userB->email] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $anonymous);
            $this->assertStringNotContainsString($sentinel, $alpha);
            $this->assertStringNotContainsString($sentinel, $beta);
        }
    }

    public function test_control_introduces_no_direct_id_or_tenant_routing_surface(): void
    {
        $source = Route::getRoutes()->match(Request::create('/access-denied', 'GET'));
        $destination = Route::getRoutes()->match(Request::create('/', 'GET'));

        $this->assertSame([], $source->parameterNames());
        $this->assertSame([], $destination->parameterNames());

        $html = $this->get('/access-denied')->assertOk()->getContent();
        $this->assertStringContainsString('href="/"', $html);
        $this->assertStringNotContainsString('/tenants/', $html);
        $this->assertStringNotContainsString('{tenant}', $html);
        $this->assertStringNotContainsString('{user}', $html);
        $this->assertStringNotContainsString('{id}', $html);
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
