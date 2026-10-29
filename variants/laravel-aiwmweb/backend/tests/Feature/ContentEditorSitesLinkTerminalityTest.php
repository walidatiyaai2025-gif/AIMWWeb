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

final class ContentEditorSitesLinkTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-B6BB292F85';

    public function test_source_and_laravel_bind_exact_sites_breadcrumb_contract(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('<a href="/sites">@L["Sites"]</a>', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentEditorSitesLinkControl', $control);
        $this->assertStringContainsString("tenantUrl(context.tenant.slug, '/sites')", $control);
        $this->assertStringContainsString('data-canonical-operation={CONTENT_EDITOR_SITES_LINK_OPERATION_ID}', $control);
    }

    public function test_sites_breadcrumb_is_navigation_only_and_adds_no_mutation_or_identity_override(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export function ContentEditorSitesLinkControl');
        $end = strpos($control, 'export type ContentEditorSnapshot', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
        $this->assertStringNotContainsString('tenant_id', $slice);
        $this->assertStringNotContainsString('site_id', $slice);
    }

    public function test_sites_breadcrumb_destination_rejects_foreign_tenant(): void
    {
        $user = User::factory()->create();
        $tenant = Tenant::query()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "sites-breadcrumb-alpha-{$user->id}"]);
        foreach (['tenant.view', 'sites.view', 'content.view'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);

        $this->withoutVite();
        $this->actingAs($user)
            ->get('/tenants/foreign/sites')
            ->assertNotFound();
    }

    public function test_official_reconciliation_accepts_only_this_sites_breadcrumb_operation(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertCount(931, $document['operations']);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);
        $this->assertNotNull($operation);
        $this->assertSame('ADAPTED', $operation['migration_state']);
        $this->assertSame('focused_closure_contract', $operation['reconciliation']['evidence_mode'] ?? null);
        $this->assertSame(
            'variants/laravel-aiwmweb/docs/closure-evidence/content-editor-sites-link-terminality.json',
            $operation['reconciliation']['evidence_path'] ?? null,
        );
    }
}
