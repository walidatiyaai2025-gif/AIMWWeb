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

final class ContentEditorShowEditTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-CONT-A5D88995F3';

    public function test_exact_canonical_operation_matches_show_edit_control(): void
    {
        $document = json_decode((string) file_get_contents(base_path('../docs/capability-parity-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('content', $operation['domain']);
        $this->assertSame('visible_control', $operation['kind']);
        $this->assertSame('/sites/{SiteId:guid}/content/{ContentType}/{WordPressId:int}/edit', $operation['route_screen']);
        $this->assertSame('ShowEdit [ShowEdit]', $operation['visible_control']);
        $this->assertSame('src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);
        $this->assertSame('WordPress', $operation['external_dependency']);
    }

    public function test_source_and_laravel_preserve_local_edit_mode_semantics_only(): void
    {
        $source = (string) file_get_contents(base_path('../../../src/AIWordPressManager.Web/Components/Pages/ContentEditor.razor'));
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));

        $this->assertStringContainsString('@onclick="ShowEdit"', $source);
        $this->assertStringContainsString('private void ShowEdit() => _viewMode = "edit";', $source);
        $this->assertStringContainsString('@if (_viewMode == "edit")', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $control);
        $this->assertStringContainsString('ContentEditorShowEditControl', $control);
        $this->assertStringContainsString("return 'edit';", $control);
        $this->assertStringContainsString("useState<ContentEditorViewMode>('edit')", $control);
        $this->assertStringContainsString('onShowEdit={() => setViewMode(showContentEditorEdit())}', $control);
        $this->assertStringContainsString("viewMode === 'edit' ? (", $control);
    }

    public function test_show_edit_inherits_tenant_scoped_editor_and_foreign_tenant_returns_404(): void
    {
        $user = User::factory()->create();
        $this->membership($user, 'alpha', ['tenant.view', 'content.view', 'content.edit']);
        Tenant::query()->create(['name' => 'Foreign', 'slug' => 'foreign']);
        $this->withoutVite();

        $this->actingAs($user)
            ->get('/tenants/foreign/sites/1/content/post/1/edit')
            ->assertNotFound();
    }

    public function test_show_edit_slice_is_local_state_only_and_does_not_claim_show_preview(): void
    {
        $control = (string) file_get_contents(resource_path('js/content-editor-save-control.tsx'));
        $start = strpos($control, 'export type ContentEditorViewMode');
        $end = strpos($control, 'export type ContentEditorSnapshot', $start);
        $slice = substr($control, $start, $end - $start);

        $this->assertStringNotContainsString('apiRequest', $slice);
        $this->assertStringNotContainsString('fetch(', $slice);
        $this->assertStringNotContainsString('POST', $slice);
        $this->assertStringNotContainsString('PATCH', $slice);
        $this->assertStringNotContainsString('DELETE', $slice);
        $this->assertStringNotContainsString('AIMW-CONT-F57B1E1503', $control);
        $this->assertStringNotContainsString('ContentEditorShowPreviewControl', $control);
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
        $role = Role::query()->create(['name' => "show-edit-{$slug}-{$user->id}"]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }

        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);
        $context->forget();

        return $membership->fresh('tenant');
    }
}
