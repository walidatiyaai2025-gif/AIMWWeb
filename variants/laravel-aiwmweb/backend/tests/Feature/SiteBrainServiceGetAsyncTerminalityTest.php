<?php

namespace Tests\Feature;

use App\AI\SiteBrain\SiteBrainService;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Operations\AdministrationService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SiteBrainServiceGetAsyncTerminalityTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATION_ID = 'AIMW-AI-0F3763FDB4';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_exact_canonical_get_operation_is_pending_during_implementation_stage(): void
    {
        $ledger = json_decode(file_get_contents(base_path('../docs/operation-parity-reconciliation.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = collect($ledger['operations'])->firstWhere('operation_id', self::OPERATION_ID);

        $this->assertNotNull($operation);
        $this->assertSame('PENDING', $operation['migration_state']);
        $this->assertSame('ai', $operation['domain']);
        $this->assertSame('service', $operation['kind']);
        $this->assertSame('SiteBrainService', $operation['service']);
        $this->assertSame('GetAsync', $operation['visible_control']);
        $this->assertSame('service:SiteBrainService', $operation['route_screen']);
        $this->assertSame('src/AIWordPressManager.Persistence/SiteBrain/SiteBrainService.cs', $operation['current_source']);
        $this->assertFalse((bool) $operation['mutation']);
        $this->assertTrue((bool) $operation['tenant_owned']);

        $source = file_get_contents(base_path('../../../src/AIWordPressManager.Persistence/SiteBrain/SiteBrainService.cs'));
        $profileSource = file_get_contents(base_path('../../../src/AIWordPressManager.Application/SiteBrain/SiteBrainProfile.cs'));
        $destination = file_get_contents(app_path('AI/SiteBrain/SiteBrainService.php'));

        $this->assertStringContainsString('GetAsync(Guid siteId', $source);
        $this->assertStringContainsString('AsNoTracking()', $source);
        $this->assertStringContainsString('string.IsNullOrWhiteSpace(value)', $source);
        $this->assertStringContainsString('JsonSerializer.Deserialize<SiteBrainProfile>', $source);
        $this->assertStringContainsString('catch (JsonException)', $source);
        $this->assertStringContainsString('SiteBrainProfile.CreateDefault(siteId)', $source);
        $this->assertStringContainsString('"Arabic"', $profileSource);
        $this->assertStringContainsString('"Professional"', $profileSource);
        $this->assertStringContainsString('"2 articles per week"', $profileSource);

        $this->assertStringContainsString(self::OPERATION_ID, $destination);
        $this->assertStringContainsString('function getAsync', $destination);
        $this->assertStringContainsString('Site::query()->findOrFail', $destination);
        $this->assertStringContainsString("where('tenant_id', \$this->context->id())", $destination);
        $this->assertStringContainsString("where('scope', 'site')", $destination);
        $this->assertStringContainsString("where('is_secret', false)", $destination);
        $this->assertStringContainsString('catch (JsonException)', $destination);
        $this->assertStringContainsString('CANONICAL_SAVE_OPERATION', $destination);
    }

    public function test_missing_profile_returns_source_equivalent_defaults(): void
    {
        Carbon::setTestNow('2026-09-06T02:30:00Z');
        [$tenant, $site] = $this->tenantAndSite('alpha');
        app(TenantContext::class)->activate($tenant);

        $profile = app(SiteBrainService::class)->getAsync($site->id);

        $this->assertSame($site->id, $profile['site_id']);
        $this->assertSame('Arabic', $profile['primary_language']);
        $this->assertSame('Professional', $profile['writing_tone']);
        $this->assertSame('General audience', $profile['target_audience']);
        $this->assertSame('Auto detect', $profile['preferred_seo_plugin']);
        $this->assertSame('Auto detect', $profile['preferred_page_builder']);
        $this->assertSame('Black, white and readable gold', $profile['brand_colors']);
        $this->assertSame('1200x630', $profile['preferred_image_size']);
        $this->assertSame('Natural contextual links', $profile['internal_link_strategy']);
        $this->assertSame('Clear parent and child categories', $profile['category_strategy']);
        $this->assertSame('Factual, concise, no invented statistics', $profile['content_rules']);
        $this->assertSame('Responsive, accessible, consistent spacing', $profile['design_rules']);
        $this->assertSame('', $profile['rejected_patterns']);
        $this->assertSame('Increase organic traffic', $profile['primary_goal']);
        $this->assertSame('', $profile['target_keywords']);
        $this->assertSame('', $profile['competitors']);
        $this->assertSame('2 articles per week', $profile['publishing_schedule']);
        $this->assertFalse($profile['autopilot_enabled']);
        $this->assertSame(now('UTC')->toIso8601String(), $profile['updated_at_utc']);
    }

    public function test_valid_site_scoped_profile_is_read_and_stored_site_id_cannot_escape_authority(): void
    {
        [$tenant, $site] = $this->tenantAndSite('alpha');
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $actor = User::factory()->create();
        $service = app(SiteBrainService::class);

        app(AdministrationService::class)->saveSetting(
            'site',
            SiteBrainService::SETTING_KEY,
            [
                'site_id' => 999999,
                'primary_language' => 'English',
                'writing_tone' => 'Warm',
                'target_audience' => 'Editors',
                'updated_at_utc' => '2026-09-01T12:00:00+00:00',
                'autopilot_enabled' => true,
            ],
            false,
            $service->siteKey($site),
            $actor->id,
        );

        $profile = $service->getAsync($site->id);

        $this->assertSame($site->id, $profile['site_id']);
        $this->assertSame('English', $profile['primary_language']);
        $this->assertSame('Warm', $profile['writing_tone']);
        $this->assertSame('Editors', $profile['target_audience']);
        $this->assertSame('2026-09-01T12:00:00+00:00', $profile['updated_at_utc']);
        $this->assertTrue($profile['autopilot_enabled']);
        $this->assertSame('Auto detect', $profile['preferred_seo_plugin']);
        $this->assertSame('Increase organic traffic', $profile['primary_goal']);
    }

    public function test_blank_malformed_and_non_object_values_fail_safe_to_defaults(): void
    {
        [$tenant, $site] = $this->tenantAndSite('alpha');
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $actor = User::factory()->create();
        $service = app(SiteBrainService::class);

        app(AdministrationService::class)->saveSetting(
            'site',
            SiteBrainService::SETTING_KEY,
            ['writing_tone' => 'Temporary'],
            false,
            $service->siteKey($site),
            $actor->id,
        );

        foreach (['   ', '{malformed-json', '"scalar"'] as $storedValue) {
            DB::table('scoped_settings')
                ->where('tenant_id', $tenant->id)
                ->where('scope', 'site')
                ->where('site_key', $service->siteKey($site))
                ->where('key', SiteBrainService::SETTING_KEY)
                ->update(['value' => $storedValue]);

            $profile = $service->getAsync($site->id);

            $this->assertSame($site->id, $profile['site_id']);
            $this->assertSame('Professional', $profile['writing_tone']);
            $this->assertFalse($profile['autopilot_enabled']);
        }
    }

    public function test_foreign_tenant_site_is_not_readable_by_site_brain_service(): void
    {
        [$alpha, $alphaSite] = $this->tenantAndSite('alpha');
        [$beta] = $this->tenantAndSite('beta');

        app(TenantContext::class)->activate($beta);

        $this->expectException(ModelNotFoundException::class);
        app(SiteBrainService::class)->getAsync($alphaSite->id);
    }

    private function tenantAndSite(string $slug): array
    {
        $tenant = Tenant::query()->create(['name' => ucfirst($slug), 'slug' => $slug]);
        $context = app(TenantContext::class);
        $context->activate($tenant);
        $site = Site::query()->create([
            'name' => ucfirst($slug).' Site',
            'url' => "https://{$slug}.example.test",
        ]);
        $context->forget();

        return [$tenant, $site];
    }
}
