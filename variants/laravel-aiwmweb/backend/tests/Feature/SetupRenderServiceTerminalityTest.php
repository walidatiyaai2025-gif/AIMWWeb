<?php

namespace Tests\Feature;

use App\Services\DatabaseSetupPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupRenderServiceTerminalityTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_render_page_operation_is_the_setup_rendering_service_contract(): void
    {
        $ledgerPath = base_path('../docs/capability-parity-ledger.json');
        $this->assertFileExists($ledgerPath);

        $ledger = json_decode(file_get_contents($ledgerPath), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', 'AIMW-CONT-43AF0076B5');

        $this->assertNotNull($operation);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('backend', $operation['domain']);
        $this->assertSame('service:DatabaseSetupService', $operation['route_screen']);
        $this->assertSame('RenderPage', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Services/DatabaseSetupService.cs', $operation['current_source']);

        $servicePath = base_path('app/Services/DatabaseSetupPageService.php');
        $this->assertFileExists($servicePath);
        $serviceSource = file_get_contents($servicePath);
        $this->assertIsString($serviceSource);
        $this->assertStringContainsString('AIMW-CONT-43AF0076B5', $serviceSource);
        $this->assertStringContainsString('DatabaseSetupService.RenderPage', $serviceSource);
    }

    public function test_page_service_renders_authoritative_setup_state_and_real_submit_contract(): void
    {
        $service = app(DatabaseSetupPageService::class);
        $status = $service->status();

        $this->assertTrue($status['database_reachable']);
        $this->assertTrue($status['migrations_ready']);
        $this->assertFalse($status['identity_ready']);
        $this->assertFalse($status['complete']);

        $response = $service->render(setupStatus: $status);
        $html = (string) $response->getContent();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Database setup required', $html);
        $this->assertStringContainsString('Database reachable:</strong> yes', $html);
        $this->assertStringContainsString('Migrations ready:</strong> yes', $html);
        $this->assertStringContainsString('Identity ready:</strong> no', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('/setup', $html);
        $this->assertStringContainsString('This form never accepts or persists a database password or connection string.', $html);
    }

    public function test_page_service_escapes_failure_text_and_never_reads_database_credentials_into_the_page(): void
    {
        config(['database.connections.sqlite.password' => 'db-render-secret-never-show']);

        $response = app(DatabaseSetupPageService::class)->render(
            '<script>alert("owned")</script>',
            400,
        );
        $html = (string) $response->getContent();

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringNotContainsString('<script>alert("owned")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;owned&quot;)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('db-render-secret-never-show', $html);
    }

    public function test_pre_auth_setup_renderer_has_no_foreign_tenant_addressable_surface(): void
    {
        $foreignTenant = 'foreign-tenant';

        $this->get('/setup?tenant='.$foreignTenant)
            ->assertOk()
            ->assertDontSee($foreignTenant);

        $this->get('/tenants/'.$foreignTenant.'/setup')
            ->assertNotFound();
    }

    public function test_setup_get_is_composed_through_the_page_service_and_completed_installations_still_redirect(): void
    {
        $this->get('/setup')
            ->assertOk()
            ->assertSee('Database setup required');

        $password = 'correct-horse-battery-staple';
        $this->post('/setup', [
            'tenant_name' => 'Primary Workspace',
            'admin_name' => 'First Owner',
            'admin_email' => 'owner@example.test',
            'admin_password' => $password,
            'admin_password_confirmation' => $password,
        ])->assertRedirect('/');

        $this->get('/setup')->assertRedirect('/');
    }
}
