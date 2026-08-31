<?php

namespace Tests\Feature;

use App\AI\SiteBrain\SiteBrainService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SiteBrainServiceSaveAsyncTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-34EC6312B9';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exact_canonical_save_operation_and_source_semantics_are_preserved(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('SiteBrainService.SaveAsync', $operation['service']);
        $this->assertSame('src/AIWordPressManager.Persistence/SiteBrain/SiteBrainService.cs', $operation['current_source']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Persistence/SiteBrain/SiteBrainService.cs'));
        $destination = file_get_contents(app_path('AI/SiteBrain/SiteBrainService.php'));

        $this->assertStringContainsString('SaveAsync(SiteBrainProfile profile', $source);
        $this->assertStringContainsString('Key(profile.SiteId)', $source);
        $this->assertStringContainsString('profile with { UpdatedAtUtc = DateTime.UtcNow }', $source);
        $this->assertStringContainsString('SingleOrDefaultAsync', $source);
        $this->assertStringContainsString('ApplicationSettings.Add', $source);
        $this->assertStringContainsString('row.SetValue', $source);
        $this->assertStringContainsString('SaveChangesAsync', $source);

        $this->assertStringContainsString(self::OPERATION_ID, $destination);
        $this->assertStringContainsString('function saveAsync', $destination);
        $this->assertStringContainsString("authorize('settings.manage')", $destination);
        $this->assertStringContainsString('Site::query()->findOrFail', $destination);
        $this->assertStringContainsString("now('UTC')->toIso8601String()", $destination);
        $this->assertStringContainsString('saveSetting(', $destination);
        $this->assertStringContainsString("'site',", $destination);
        $this->assertStringContainsString('self::SETTING_KEY', $destination);
        $this->assertStringContainsString('DB::transaction', $destination);
    }

    public function test_save_creates_one_tenant_scoped_site_profile_with_server_utc_timestamp(): void
    {
        Carbon::setTestNow('2026-08-31T06:00:00Z');
        [$tenant, $site] = $this->tenantSiteAndUser('alpha', ['settings.manage']);
        app(TenantContext::class)->activate($tenant);

        $profile = $this->profile($site->id, [
            'writing_tone' => 'Warm',
            'primary_goal' => 'Grow qualified traffic',
            'autopilot_enabled' => true,
            'updated_at_utc' => '1999-01-01T00:00:00Z',
        ]);

        app(SiteBrainService::class)->saveAsync($profile);

        $row = DB::table('scoped_settings')->where('tenant_id', $tenant->id)->where('key', SiteBrainService::SETTING_KEY)->sole();
        $stored = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('site', $row->scope);
        $this->assertSame('site:'.$site->id, $row->site_key);
        $this->assertFalse((bool) $row->is_secret);
        $this->assertSame($site->id, $stored['site_id']);
        $this->assertSame('Warm', $stored['writing_tone']);
        $this->assertSame('Grow qualified traffic', $stored['primary_goal']);
        $this->assertTrue($stored['autopilot_enabled']);
        $this->assertSame(now('UTC')->toIso8601String(), $stored['updated_at_utc']);
        $this->assertNotSame('1999-01-01T00:00:00Z', $stored['updated_at_utc']);
    }

    public function test_save_updates_the_existing_site_profile_in_place_instead_of_creating_duplicates(): void
    {
        [$tenant, $site] = $this->tenantSiteAndUser('alpha', ['settings.manage']);
        app(TenantContext::class)->activate($tenant);
        $service = app(SiteBrainService::class);

        Carbon::setTestNow('2026-08-31T06:00:00Z');
        $service->saveAsync($this->profile($site->id, ['writing_tone' => 'Professional']));
        $rowId = DB::table('scoped_settings')->where('tenant_id', $tenant->id)->where('key', SiteBrainService::SETTING_KEY)->value('id');

        Carbon::setTestNow('2026-08-31T07:30:00Z');
        $service->saveAsync($this->profile($site->id, ['writing_tone' => 'Editorial', 'target_keywords' => 'laravel, wordpress']));

        $rows = DB::table('scoped_settings')->where('tenant_id', $tenant->id)->where('key', SiteBrainService::SETTING_KEY)->get();
        $stored = json_decode($rows->sole()->value, true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $rows);
        $this->assertSame($rowId, $rows->sole()->id);
        $this->assertSame('Editorial', $stored['writing_tone']);
        $this->assertSame('laravel, wordpress', $stored['target_keywords']);
        $this->assertSame(now('UTC')->toIso8601String(), $stored['updated_at_utc']);
    }

    public function test_foreign_tenant_site_cannot_be_mutated(): void
    {
        [$alpha] = $this->tenantSiteAndUser('alpha', ['settings.manage']);
        [, $betaSite] = $this->tenantSiteAndUser('beta', ['settings.manage']);
        app(TenantContext::class)->activate($alpha);

        try {
            app(SiteBrainService::class)->saveAsync($this->profile($betaSite->id));
            $this->fail('Foreign tenant site mutation should fail closed.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, DB::table('scoped_settings')->where('site_key', 'site:'.$betaSite->id)->count());
        }
    }

    public function test_missing_settings_permission_fails_before_persistence(): void
    {
        [$tenant, $site] = $this->tenantSiteAndUser('alpha', ['tenant.view']);
        app(TenantContext::class)->activate($tenant);

        $this->expectException(AuthorizationException::class);

        try {
            app(SiteBrainService::class)->saveAsync($this->profile($site->id));
        } finally {
            $this->assertSame(0, DB::table('scoped_settings')->where('tenant_id', $tenant->id)->count());
        }
    }

    public function test_invalid_typed_profile_fails_without_partial_write(): void
    {
        [$tenant, $site] = $this->tenantSiteAndUser('alpha', ['settings.manage']);
        app(TenantContext::class)->activate($tenant);
        $profile = $this->profile($site->id);
        $profile['autopilot_enabled'] = 'yes';

        $this->expectException(ValidationException::class);

        try {
            app(SiteBrainService::class)->saveAsync($profile);
        } finally {
            $this->assertSame(0, DB::table('scoped_settings')->where('tenant_id', $tenant->id)->count());
        }
    }

    private function profile(int $siteId, array $overrides = []): array
    {
        return array_replace([
            'site_id' => $siteId,
            'primary_language' => 'Arabic',
            'writing_tone' => 'Professional',
            'target_audience' => 'General audience',
            'preferred_seo_plugin' => 'Auto detect',
            'preferred_page_builder' => 'Auto detect',
            'brand_colors' => 'Black, white and readable gold',
            'preferred_image_size' => '1200x630',
            'internal_link_strategy' => 'Natural contextual links',
            'category_strategy' => 'Clear parent and child categories',
            'content_rules' => 'Factual, concise, no invented statistics',
            'design_rules' => 'Responsive, accessible, consistent spacing',
            'rejected_patterns' => '',
            'updated_at_utc' => '2000-01-01T00:00:00Z',
            'primary_goal' => 'Increase organic traffic',
            'target_keywords' => '',
            'competitors' => '',
            'publishing_schedule' => '2 articles per week',
            'autopilot_enabled' => false,
        ], $overrides);
    }

    private function tenantSiteAndUser(string $slug, array $permissions): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);

        $user = User::factory()->create();
        $membership = TenantMembership::query()->create([
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => "site-brain-save-{$slug}-{$user->id}"]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
            $role->permissions()->attach($permission, ['tenant_id' => $tenant->id]);
        }
        $membership->roles()->attach($role, ['tenant_id' => $tenant->id]);

        $site = Site::query()->create([
            'name' => ucfirst($slug).' Site',
            'url' => "https://{$slug}.example.test",
        ]);
        $context->forget();

        return [$tenant, $site, $user];
    }
}
