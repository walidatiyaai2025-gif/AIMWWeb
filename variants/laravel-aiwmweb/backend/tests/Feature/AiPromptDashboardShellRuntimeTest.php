<?php

namespace Tests\Feature;

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

final class AiPromptDashboardShellRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_page_uses_dashboard_shell_styles_and_locale_without_fake_templates(): void
    {
        $manager = User::factory()->create();
        $this->membership($manager, 'alpha', ['settings.manage']);

        $response = $this->actingAs($manager)->get('/tenants/alpha/settings/ai-prompts');
        $response->assertOk()
            ->assertSee('data-dashboard-shell="aiwmweb"', false)
            ->assertSee('class="app-shell prompt-shell"', false)
            ->assertSee('class="sidebar prompt-sidebar"', false)
            ->assertSee('class="topbar"', false)
            ->assertSee('data-aiwm-prompt-dashboard-styles', false)
            ->assertSee('data-prompt-locale-toggle', false)
            ->assertSee("localStorage.getItem('aiwm.locale')", false)
            ->assertSee('data-prompt-ar=', false)
            ->assertSee('data-ai-prompt-create-save', false)
            ->assertSee('No AI prompt templates have been persisted for this tenant.')
            ->assertSee('name="_token"', false);

        $this->assertDatabaseCount('ai_prompt_templates', 0);
        $this->assertDatabaseCount('ai_prompt_revisions', 0);
    }

    public function test_prompt_shell_uses_server_locale_until_a_valid_explicit_browser_choice_is_known(): void
    {
        $manager = User::factory()->create();
        $this->membership($manager, 'alpha', ['settings.manage']);

        app()->setLocale('ar');
        $arabic = $this->actingAs($manager)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl" data-mode="dark">', false)
            ->assertSee('<title>قوالب أوامر الذكاء الاصطناعي — AIWMWeb</title>', false)
            ->assertSee('data-prompt-locale-toggle', false)
            ->assertSee('data-dashboard-shell="aiwmweb"', false);

        // Verify the browser adapter may override only when it actually has
        // an ar/en preference. Corrupt/missing/unavailable localStorage keeps
        // the safe server-rendered document language and direction.
        $html = $arabic->getContent();
        $this->assertStringContainsString("const savedLocale = localStorage.getItem('aiwm.locale');", $html);
        $this->assertStringContainsString("if (savedLocale === 'ar' || savedLocale === 'en')", $html);
        $this->assertStringNotContainsString(
            "document.documentElement.lang = localStorage.getItem('aiwm.locale') === 'ar' ? 'ar' : 'en';",
            $html,
        );

        app()->setLocale('en');
        $this->actingAs($manager)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr" data-mode="dark">', false)
            ->assertSee('<title>AI Prompt Templates — AIWMWeb</title>', false);

        $this->assertDatabaseCount('ai_prompt_templates', 0);
        $this->assertDatabaseCount('ai_prompt_revisions', 0);
    }

    public function test_new_save_reload_edit_unchanged_save_history_and_restore_are_real(): void
    {
        $manager = User::factory()->create();
        $tenant = $this->membership($manager, 'alpha', ['settings.manage']);

        $createRoute = Route::getRoutes()->match(Request::create('/tenants/alpha/settings/ai-prompts', 'POST'));
        $this->assertSame('tenant.settings.ai-prompts.create', $createRoute->getName());
        $this->assertContains('auth', $createRoute->gatherMiddleware());
        $this->assertContains('tenant.context', $createRoute->gatherMiddleware());

        $this->actingAs($manager)
            ->from('/tenants/alpha/settings/ai-prompts')
            ->post('/tenants/alpha/settings/ai-prompts', [
                '_prompt_form' => 'create',
                'stable_key' => 'custom.demo',
                'title' => 'Initial title',
                'system_template' => 'Initial system.',
                'user_template' => 'Initial user prompt.',
                'enabled' => '1',
            ])
            ->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Created custom.demo as revision r1.');

        $template = AiPromptTemplate::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('stable_key', 'custom.demo')->firstOrFail();
        $this->assertSame(1, $template->current_version);
        $this->assertFalse($template->is_builtin);
        $this->assertSame('general', $template->domain);
        $this->assertSame($manager->id, $template->updated_by_user_id);

        $this->actingAs($manager)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()->assertSee('Initial title')->assertSee('r1')->assertSee('Initial user prompt.');

        $this->actingAs($manager)->patch('/tenants/alpha/settings/ai-prompts/custom.demo', [
            'title' => 'Edited title',
            'system_template' => 'Revised system.',
            'user_template' => 'Revised prompt.',
            'enabled' => '0',
        ])->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Saved custom.demo as revision r2.');

        $this->actingAs($manager)->patch('/tenants/alpha/settings/ai-prompts/custom.demo', [
            'title' => 'Edited title',
            'system_template' => 'Revised system.',
            'user_template' => 'Revised prompt.',
            'enabled' => '0',
        ])->assertSessionHas('status', 'Saved custom.demo as revision r2.');

        $this->assertSame(2, AiPromptRevision::query()->withoutGlobalScopes()->where('ai_prompt_template_id', $template->id)->count());

        $this->actingAs($manager)->post('/tenants/alpha/settings/ai-prompts/custom.demo/revisions/1/restore')
            ->assertRedirect('/tenants/alpha/settings/ai-prompts')
            ->assertSessionHas('status', 'Restored custom.demo as revision r3.');

        $saved = AiPromptTemplate::query()->withoutGlobalScopes()->findOrFail($template->id);
        $this->assertSame(3, $saved->current_version);
        $this->assertSame('Initial title', $saved->title);
        $this->assertSame('Initial user prompt.', $saved->user_template);
        $this->assertSame(3, AiPromptRevision::query()->withoutGlobalScopes()->where('ai_prompt_template_id', $template->id)->count());
        $this->assertSame(['created', 'updated', 'restored'],
            AiPromptRevision::query()->withoutGlobalScopes()->where('ai_prompt_template_id', $template->id)->orderBy('version')->pluck('change_type')->all());
        $this->assertSame(3, AuditEvent::query()->withoutGlobalScopes()->where('event', 'ai.prompt.changed')->count());
    }

    public function test_create_validation_duplicate_and_tenant_isolation_fail_closed_without_mutation(): void
    {
        $this->post('/tenants/alpha/settings/ai-prompts', [])->assertRedirect('/login');

        $limited = User::factory()->create();
        $this->membership($limited, 'limited', ['tenant.view']);
        $this->actingAs($limited)->post('/tenants/limited/settings/ai-prompts', [
            'stable_key' => 'hidden.example',
            'title' => 'Blocked',
            'user_template' => 'Blocked',
            'enabled' => '1',
        ])->assertForbidden();

        $manager = User::factory()->create();
        $alpha = $this->membership($manager, 'alpha', ['settings.manage']);
        Tenant::query()->firstOrCreate(['slug' => 'beta'], ['name' => 'Beta']);

        $payload = [
            '_prompt_form' => 'create',
            'stable_key' => 'custom.safe',
            'title' => 'Safe',
            'system_template' => 'System',
            'user_template' => 'User prompt',
            'enabled' => '1',
        ];

        $this->actingAs($manager)->post('/tenants/beta/settings/ai-prompts', $payload)->assertNotFound();

        $this->actingAs($manager)->from('/tenants/alpha/settings/ai-prompts')
            ->post('/tenants/alpha/settings/ai-prompts', array_replace($payload, ['stable_key' => '../invalid']))
            ->assertSessionHasErrors('stable_key')
            ->assertSessionMissing('status');

        $this->assertDatabaseCount('ai_prompt_templates', 0);

        $this->actingAs($manager)->post('/tenants/alpha/settings/ai-prompts', $payload)
            ->assertRedirect('/tenants/alpha/settings/ai-prompts');
        $this->actingAs($manager)->from('/tenants/alpha/settings/ai-prompts')
            ->post('/tenants/alpha/settings/ai-prompts', $payload)
            ->assertSessionHasErrors('stable_key')
            ->assertSessionMissing('status');

        $this->assertDatabaseHas('ai_prompt_templates', ['tenant_id' => $alpha->id, 'stable_key' => 'custom.safe']);
        $this->assertDatabaseCount('ai_prompt_templates', 1);
        $this->assertDatabaseCount('ai_prompt_revisions', 1);
    }

    public function test_server_escapes_user_supplied_prompt_html_and_preserves_form_on_create_failure(): void
    {
        $manager = User::factory()->create();
        $this->membership($manager, 'alpha', ['settings.manage']);
        $this->actingAs($manager)->post('/tenants/alpha/settings/ai-prompts', [
            'stable_key' => 'custom.escape',
            'title' => '<script>alert(1)</script>',
            'user_template' => 'Safe text.',
            'enabled' => '1',
        ])->assertRedirect();

        $this->actingAs($manager)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);

        $this->actingAs($manager)->from('/tenants/alpha/settings/ai-prompts')->post('/tenants/alpha/settings/ai-prompts', [
            '_prompt_form' => 'create',
            'stable_key' => 'custom.invalid',
            'title' => 'Draft form',
            'user_template' => '',
            'enabled' => '1',
        ])->assertSessionHasErrors('user_template');

        $this->actingAs($manager)->get('/tenants/alpha/settings/ai-prompts')
            ->assertOk()
            ->assertSee('name="stable_key"', false);
    }

    private function membership(User $user, string $slug, array $permissions): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "ai-prompt-shell-{$slug}-{$user->id}"]);
        foreach ($permissions as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $tenant;
    }
}
