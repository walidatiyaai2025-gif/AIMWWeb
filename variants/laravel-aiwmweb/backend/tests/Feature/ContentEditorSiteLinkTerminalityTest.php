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

final class ContentEditorSiteLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-E89EDDF780';

    public function test_exact_canonical_operation_matches_content_editor_site_breadcrumb(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/capability-parity-ledger.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/content/{ContentType}/{WordPressId:int}/edit', $operation['route_screen']);
        $this->assertSame('@($ -> @($', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_source_and_laravel_preserve_authoritative_site_breadcrumb_semantics(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('<a href="@($"/sites/{SiteId}")">@_site.Name</a>', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentEditorSiteLinkControl', $control);
        $this->assertStringContainsString('authoritativeContentEditorSiteHref', $control);
        $this->assertStringContainsString('context.api[', $control);
        $this->assertStringContainsString('sites.detail.', $control);
        $this->assertStringContainsString('tenantUrl(context.tenant.slug', $control);
        $this->assertStringContainsString('/sites/${requestedSiteId}', $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_SITE_LINK_OPERATION_ID}', $control);
        $this->assertStringContainsString('{activeSite.name}', $control);
    }

    public function test_site_breadcrumb_inherits_tenant_isolation_and_foreign_tenant_returns_404(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'sites.view', 'content.view']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/foreign/sites/1/content/post/1/edit')
            ->assertNotFound();
    }

    public function test_site_breadcrumb_is_navigation_only_and_does_not_claim_sibling_operations(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function authoritativeContentEditorSiteHref');
        $end = strpos($control, 'const contentEditorExplorerRoute', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
        $this->assertStringNotContainsString('tenant_id', $slice);
        $this->assertStringNotContainsString('site_id', $slice);
        $this->assertStringNotContainsString('AIMW-CONT-B6BB292F85', $slice);
        $this->assertStringNotContainsString('AIMW-CONT-607ACDF4D8', $slice);
    }

    public function test_generated_reconciliation_materializes_this_operation_as_adapted(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame(
            'variants/laravel-aiwmweb/docs/closure-evidence/content-editor-site-link-terminality.json',
            $operation['reconciliation']['evidence_path'] ?? null,
        );
        $this->assertSame('focused_closure_contract', $operation['reconciliation']['evidence_mode'] ?? null);
    }

    /** @param list<string> $permissions */
    private function membership(User $user, string $slug, array $permissions): TenantMembership
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "site-link-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
