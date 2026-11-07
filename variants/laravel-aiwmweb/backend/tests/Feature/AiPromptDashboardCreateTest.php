<?php

namespace Tests\Feature;

use App\Http\Controllers\AiPromptTemplateCreateController;
use App\Models\AiPromptRevision;
use App\Models\AiPromptTemplate;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AiPromptDashboardCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_page_uses_authenticated_dark_dashboard_shell_and_real_stylesheet(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $response = $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts');

        $response->assertOk()
            ->assertSee('data-ai-prompt-dashboard-shell="true"', false)
            ->assertSee('class="app-shell"', false)
            ->assertSee('class="sidebar"', false)
            ->assertSee('class="topbar"', false)
            ->assertSee('data-mode="dark"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('data-ai-prompt-nav-open', false)
            ->assertSee('data-ai-prompt-create-form', false)
            ->assertSee('name="_token"', false)
            ->assertSee('/js/ai-prompt-shell.js', false)
            ->assertSee('0 persisted templates')
            ->assertSee('No AI prompt templates have been persisted for this tenant.');

        $source = file_get_contents(resource_path('views/ai/prompt-templates.blade.php'));
        $this->assertStringContainsString("@vite(['resources/css/app.css'])", $source);
        $this->assertStringNotContainsString('<html lang="en"><head>', $source);
        $this->assertDatabaseCount('ai_prompt_templates', 0);
    }

    public function test_arabic_locale_is_rtl_and_persists_after_a_server_side_form_round_trip(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts?lang=ar')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('قوالب أوامر الذكاء الاصطناعي')
            ->assertSee('حفظ القالب الجديد');

        $this->actingAs($user)->post('/tenants/alpha/settings/ai-prompts', [
            'stable_key' => 'new.arabic',
            'title' => 'عنوان',
            'user_template' => 'النص',
            'enabled' => '1',
        ])->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Created new.arabic as revision r1.');

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee('عنوان');
        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts?lang=en')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('dir="ltr"', false)
            ->assertSee('Template library');
    }

    public function test_create_is_real_tenant_scoped_revisioned_and_xss_escaped(): void
    {
        $user = User::factory()->create();
        $tenant = $this->membership($user, 'alpha', ['settings.manage']);

        $this->actingAs($user)->post('/tenants/alpha/settings/ai-prompts', [
            '_prompt_key' => '__new__',
            'stable_key' => 'custom.alpha',
            'title' => 'Draft editor',
            'system_template' => 'System prompt',
            'user_template' => '<script>alert(1)</script>',
            'enabled' => '1',
        ])->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Created custom.alpha as revision r1.');

        $row = AiPromptTemplate::query()->withoutGlobalScopes()->where('stable_key', 'custom.alpha')->firstOrFail();
        $this->assertSame($tenant->id, $row->tenant_id);
        $this->assertSame(1, $row->current_version);
        $this->assertTrue($row->enabled);
        $this->assertSame(1, AiPromptRevision::query()->withoutGlobalScopes()->where('ai_prompt_template_id', $row->id)->count());
        $this->assertSame('created', AiPromptRevision::query()->withoutGlobalScopes()->where('ai_prompt_template_id', $row->id)->firstOrFail()->change_type);
        $this->assertSame($tenant->id, AuditEvent::query()->withoutGlobalScopes()->where('event', 'ai.prompt.changed')->firstOrFail()->tenant_id);

        $this->actingAs($user)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('custom.alpha')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_duplicate_bad_key_and_foreign_tenant_are_denied_without_side_effects(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['settings.manage']);
        $payload = [
            '_prompt_key' => '__new__',
            'stable_key' => 'custom.unique',
            'title' => 'A title',
            'user_template' => 'A prompt',
            'enabled' => '0',
        ];

        $route = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-prompts', 'POST'));
        $this->assertSame(AiPromptTemplateCreateController::class, $route->getActionName());
        $this->assertContains('auth', $route->gatherMiddleware());
        $this->assertContains('tenant.context', $route->gatherMiddleware());

        $this->actingAs($user)->post('/tenants/alpha/settings/ai-prompts', $payload)->assertRedirect();
        $this->actingAs($user)->from('/tenants/alpha/settings/ai-prompts')
            ->post('/tenants/alpha/settings/ai-prompts', $payload)
            ->assertSessionHasErrors(['stable_key']);
        $this->actingAs($user)->post('/tenants/alpha/settings/ai-prompts', [
            ...$payload, 'stable_key' => '<bad.key>',
        ])->assertSessionHasErrors(['stable_key']);
        $this->assertDatabaseCount('ai_prompt_templates', 1);
        $this->assertDatabaseCount('ai_prompt_revisions', 1);

        Tenant::query()->create(['name' => 'Beta', 'slug' => 'beta']);
        $this->actingAs($user)->post('/tenants/beta/settings/ai-prompts', $payload)->assertNotFound();
        $this->assertDatabaseCount('ai_prompt_templates', 1);

        $guest = User::factory()->create();
        $this->membership($guest, 'limited', ['tenant.view']);
        $this->actingAs($guest)->post('/tenants/limited/settings/ai-prompts', $payload)->assertForbidden();
        $this->assertDatabaseCount('ai_prompt_templates', 1);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        app(TenantContext::class)->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "ai-prompt-layout-{$slug}-{$user->id}"]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        app(TenantContext::class)->forget();

        return $tenant;
    }
}
