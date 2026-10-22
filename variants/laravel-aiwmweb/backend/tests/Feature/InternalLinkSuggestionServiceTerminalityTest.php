<?php

namespace Tests\Feature;

use App\Models\ContentItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\InternalLinkSuggestionService;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalLinkSuggestionServiceTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-BILL-90CF990147';

    public function test_exact_canonical_operation_is_internal_link_generate_async_service(): void
    {
        $document = json_decode(
            (string) file_get_contents(base_path('../docs/operation-parity-reconciliation.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $operation = collect($document['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('billing', $operation['domain']);
        $this->assertSame('service:InternalLinkSuggestionService', $operation['route_screen']);
        $this->assertSame('GenerateAsync', $operation['visible_control']);
        $this->assertSame('InternalLinkSuggestionService', $operation['service']);
        $this->assertSame(
            'src/AIWordPressManager.Persistence/Planning/InternalLinkSuggestionService.cs',
            $operation['current_source'],
        );
        $this->assertSame(self::OPERATION_ID, InternalLinkSuggestionService::OPERATION_ID);
    }

    public function test_generate_async_matches_canonical_filter_overlap_confidence_and_existing_link_rules(): void
    {
        $user = User::factory()->create();
        [, $site] = $this->workspace($user, 'links', ['sites.view']);

        $this->content($site, 10, 'Cloud Security Guide', 'cloud-security-guide', '<p>Cloud &amp; security operations improve resilient platforms.</p>', 'publish', false, 'https://links.example.test/cloud-security-guide');
        $this->content($site, 20, 'Cloud Security Checklist', 'cloud-security-checklist', '<p>Checklist for cloud security teams.</p>', 'publish', false, 'https://links.example.test/cloud-security-checklist');
        $this->content($site, 30, 'Existing Link Source', 'existing-link-source', '<p>Cloud security already links https://links.example.test/cloud-security-checklist</p>', 'publish', false, 'https://links.example.test/existing-link-source');
        $this->content($site, 40, 'Cloud Security Draft', 'cloud-security-draft', '<p>Cloud security draft.</p>', 'draft');
        $this->content($site, 50, 'Cloud Security Stale', 'cloud-security-stale', '<p>Cloud security stale.</p>', 'publish', true);

        $results = app(InternalLinkSuggestionService::class)->generateAsync((int) $site->id);
        $direct = collect($results)->first(
            fn (array $item): bool => $item['source_wordpress_id'] === 10 && $item['target_wordpress_id'] === 20,
        );

        $this->assertNotNull($direct);
        $this->assertSame('Cloud Security Guide', $direct['source_title']);
        $this->assertSame('Cloud Security Checklist', $direct['target_title']);
        $this->assertSame('Cloud Security Checklist', $direct['suggested_anchor']);
        $this->assertSame('Shared topical terms: 2.', $direct['reason']);
        $this->assertEqualsWithDelta(0.65, $direct['confidence'], 0.000001);
        $this->assertFalse(collect($results)->contains(
            fn (array $item): bool => $item['source_wordpress_id'] === 30 && $item['target_wordpress_id'] === 20,
        ));
        $this->assertFalse(collect($results)->contains(
            fn (array $item): bool => in_array($item['source_wordpress_id'], [40, 50], true)
                || in_array($item['target_wordpress_id'], [40, 50], true),
        ));
    }

    public function test_generate_async_is_deterministic_by_confidence_then_source_title_and_caps_at_200(): void
    {
        $user = User::factory()->create();
        [, $site] = $this->workspace($user, 'cap', ['sites.view']);

        foreach (range(1, 16) as $number) {
            $this->content(
                $site,
                1000 + $number,
                sprintf('%02d Shared Cloud Security', $number),
                sprintf('%02d-shared-cloud-security', $number),
                '<p>Shared cloud security controls.</p>',
            );
        }

        $results = app(InternalLinkSuggestionService::class)->generateAsync((int) $site->id);

        $this->assertCount(200, $results);
        $this->assertSame('01 Shared Cloud Security', $results[0]['source_title']);
        $this->assertGreaterThanOrEqual($results[199]['confidence'], $results[0]['confidence']);
    }

    public function test_tenant_scope_excludes_cross_tenant_content_and_foreign_site_fails_closed(): void
    {
        $alphaUser = User::factory()->create();
        [$alphaTenant, $alphaSite, $alphaMembership] = $this->workspace($alphaUser, 'alpha-links', ['sites.view']);
        $this->content($alphaSite, 101, 'Shared Cloud Security Alpha', 'shared-cloud-security-alpha', '<p>Shared cloud security material.</p>');
        $this->content($alphaSite, 102, 'Shared Cloud Security Target', 'shared-cloud-security-target', '<p>Shared cloud security target.</p>');

        $betaUser = User::factory()->create();
        [, $betaSite] = $this->workspace($betaUser, 'beta-links', ['sites.view']);
        ContentItem::query()->create([
            'site_id' => $alphaSite->id,
            'remote_id' => 999,
            'type' => 'post',
            'slug' => 'cross-tenant-shared-cloud-security',
            'title' => 'Cross Tenant Shared Cloud Security',
            'body' => '<p>Shared cloud security material.</p>',
            'status' => 'publish',
            'stale' => false,
        ]);

        app(TenantContext::class)->activate($alphaTenant, $alphaMembership);
        $results = app(InternalLinkSuggestionService::class)->generateAsync((int) $alphaSite->id);
        $remoteIds = collect($results)
            ->flatMap(fn (array $item): array => [$item['source_wordpress_id'], $item['target_wordpress_id']])
            ->all();

        $this->assertNotContains(999, $remoteIds, 'Cross-tenant content must never enter tenant-scoped suggestions.');

        $this->expectException(ModelNotFoundException::class);
        app(InternalLinkSuggestionService::class)->generateAsync((int) $betaSite->id);
    }

    public function test_missing_sites_view_permission_throws_authorization_exception_403(): void
    {
        $user = User::factory()->create();
        [, $site] = $this->workspace($user, 'restricted-links', []);

        $this->expectException(AuthorizationException::class);
        app(InternalLinkSuggestionService::class)->generateAsync((int) $site->id);
    }

    /** @return array{Tenant, Site, TenantMembership} */
    private function workspace(User $user, string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $membership = TenantMembership::query()->create(['user_id' => $user->id, 'status' => 'active']);
        $role = Role::query()->create(['name' => "internal-links-{$slug}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);

        $site = Site::query()->create([
            'name' => ucfirst($slug).' Site',
            'url' => "https://{$slug}.example.test",
            'status' => 'active',
        ]);
        $context->activate($tenant, $membership);

        return [$tenant, $site, $membership];
    }

    private function content(
        Site $site,
        int $remoteId,
        string $title,
        string $slug,
        string $body,
        string $status = 'publish',
        bool $stale = false,
        ?string $link = null,
    ): ContentItem {
        return ContentItem::query()->create([
            'site_id' => $site->id,
            'remote_id' => $remoteId,
            'type' => 'post',
            'slug' => $slug,
            'title' => $title,
            'body' => $body,
            'status' => $status,
            'link' => $link,
            'stale' => $stale,
        ]);
    }
}
