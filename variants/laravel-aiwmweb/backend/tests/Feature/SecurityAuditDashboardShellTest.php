<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecurityAuditDashboardShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_security_audit_has_bilingual_dashboard_shell(): void
    {
        $admin = User::factory()->create(['platform_admin' => true]);
        $previousLocale = app()->getLocale();

        try {
            foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
                app()->setLocale($locale);

                $response = $this->actingAs($admin)->get('/admin/security-audit');
                $response->assertOk()
                    ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'" data-mode="dark">', false)
                    ->assertSee('class="app-shell security-audit-shell"', false)
                    ->assertSee('data-dashboard-shell="aiwmweb"', false)
                    ->assertSee('class="sidebar"', false)
                    ->assertSee('class="topbar"', false)
                    ->assertSee('aria-current="page"', false)
                    ->assertSee('data-canonical-operation="AIMW-CONT-EBD89B0642"', false)
                    ->assertSee('name="category"', false)
                    ->assertSee('name="outcome"', false);

                $response->assertSee($locale === 'ar' ? 'تدقيق الأمان' : 'Security Audit');
                $response->assertSee($locale === 'ar' ? 'لا توجد أحداث أمان مطابقة.' : 'No matching security events.');
            }
        } finally {
            app()->setLocale($previousLocale);
        }

        $source = (string) file_get_contents(resource_path('views/security/audit.blade.php'));
        $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
        $this->assertStringContainsString('@media (max-width: 700px)', $source);
    }

    public function test_security_audit_remains_platform_admin_only_and_read_only(): void
    {
        $this->get('/admin/security-audit')->assertRedirect('/login');

        $member = User::factory()->create(['platform_admin' => false]);
        $this->actingAs($member)->get('/admin/security-audit')->assertForbidden();

        $admin = User::factory()->create(['platform_admin' => true]);
        $this->actingAs($admin)->get('/admin/security-audit')->assertOk();
        $this->post('/admin/security-audit')->assertStatus(405);
    }
}
