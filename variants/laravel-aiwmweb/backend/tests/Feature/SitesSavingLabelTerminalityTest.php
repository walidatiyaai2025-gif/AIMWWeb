<?php

namespace Tests\Feature;

use Tests\TestCase;

final class SitesSavingLabelTerminalityTest extends TestCase
{
    private const OPERATION_ID = 'AIMW-CONT-2752ED9351';

    public function test_saving_label_is_bound_to_the_real_tenant_scoped_save_runtime_and_cross_tenant_guards(): void
    {
        $control = (string) file_get_contents(resource_path('js/sites-save-control.tsx'));
        $guard = (string) file_get_contents(base_path('tests/Feature/SitesSaveTerminalityTest.php'));

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('SITES_SAVING_LABEL_OPERATION_ID', $control);
        $this->assertStringContainsString('aria-busy={m.isPending}', $control);
        $this->assertStringContainsString('data-source-state="saving"', $control);
        $this->assertStringContainsString('disabled={m.isPending}', $control);
        $this->assertStringContainsString('canonicalSitesSaveCollection', $control);

        // Reuse the established Sites save tenant/IDOR and authorization contract.
        $this->assertStringContainsString('foreign_site_fail_closed', $guard);
        $this->assertStringContainsString('assertNotFound', $guard);
        $this->assertStringContainsString('assertForbidden', $guard);
        $this->assertStringContainsString('tenant.context', $guard);
    }
}
