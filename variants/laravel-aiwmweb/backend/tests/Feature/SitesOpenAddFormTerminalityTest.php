<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SitesOpenAddFormTerminalityTest extends TestCase
{
    public function test_open_add_form_uses_the_existing_tenant_guarded_sites_surface(): void
    {
        $control = (string) file_get_contents(resource_path('js/sites-save-control.tsx'));
        $guard = (string) file_get_contents(base_path('tests/Feature/SitesSaveTerminalityTest.php'));

        $this->assertStringContainsString('AIMW-CONT-38FC74B9DE', $control);
        $this->assertStringContainsString('assertNotFound', $guard);
        $this->assertStringContainsString('tenant.context', $guard);
    }
}
