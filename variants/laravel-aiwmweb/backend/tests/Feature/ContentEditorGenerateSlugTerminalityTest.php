<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentEditorGenerateSlugTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-564636DDC9';

    public function test_source_and_laravel_bind_exact_generate_slug_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('@onclick="GenerateSlug"', $source);
        $this->assertStringContainsString('private void GenerateSlug()', $source);
        $this->assertStringContainsString('PlainText(_model.Title).Trim().ToLowerInvariant()', $source);
        $this->assertStringContainsString('Regex.Replace(plain, @"[^\p{L}\p{N}]+", "-").Trim', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('generateContentEditorSlug', $control);
        $this->assertStringContainsString("replace(/[^\\p{L}\\p{N}]+/gu, '-')", $control);
        $this->assertStringContainsString('ContentEditorGenerateSlugControl', $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_GENERATE_SLUG_OPERATION_ID}', $control);
        $this->assertStringContainsString("onGenerate={(slug) => set('slug', slug)}", $control);
    }

    public function test_generate_slug_is_local_editor_state_only(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function generateContentEditorSlug');
        $end = strpos($control, 'export function ContentEditorClearFeaturedControl', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('tenant', strtolower($slice));
        $this->assertStringNotContainsString('success', strtolower($slice));
    }

    public function test_generate_slug_inherits_tenant_scoped_editor_and_foreign_tenant_returns_404(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'content.view', 'content.edit']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/foreign/sites/1/content/post/1/edit')
            ->assertNotFound();
    }

    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "generate-slug-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
