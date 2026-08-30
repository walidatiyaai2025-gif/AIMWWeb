<?php

namespace App\AI\SiteBrain;

use App\Models\Site;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use JsonException;

final class SiteBrainService
{
    public const CANONICAL_GET_OPERATION = 'AIMW-AI-0F3763FDB4';

    public const SETTING_KEY = 'site_brain.profile';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Laravel adaptation of SiteBrainService.GetAsync.
     *
     * The source operation is a read-only, fail-safe lookup: absent, blank, or
     * malformed persisted JSON resolves to the canonical default profile.
     */
    public function getAsync(int $siteId): array
    {
        $site = Site::query()->findOrFail($siteId);
        $row = DB::table('scoped_settings')
            ->where('tenant_id', $this->context->id())
            ->where('scope', 'site')
            ->where('site_key', $this->siteKey($site))
            ->where('key', self::SETTING_KEY)
            ->where('is_secret', false)
            ->first(['value']);

        if (! $row || ! is_string($row->value) || trim($row->value) === '') {
            return $this->defaultProfile($site);
        }

        try {
            $stored = json_decode($row->value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->defaultProfile($site);
        }

        if (! is_array($stored)) {
            return $this->defaultProfile($site);
        }

        return $this->normalizeProfile($site, $stored);
    }

    public function siteKey(Site $site): string
    {
        return 'site:'.$site->getKey();
    }

    private function defaultProfile(Site $site): array
    {
        return [
            'site_id' => (int) $site->getKey(),
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
            'updated_at_utc' => now('UTC')->toIso8601String(),
            'primary_goal' => 'Increase organic traffic',
            'target_keywords' => '',
            'competitors' => '',
            'publishing_schedule' => '2 articles per week',
            'autopilot_enabled' => false,
        ];
    }

    private function normalizeProfile(Site $site, array $stored): array
    {
        $profile = $this->defaultProfile($site);
        $stringFields = [
            'primary_language',
            'writing_tone',
            'target_audience',
            'preferred_seo_plugin',
            'preferred_page_builder',
            'brand_colors',
            'preferred_image_size',
            'internal_link_strategy',
            'category_strategy',
            'content_rules',
            'design_rules',
            'rejected_patterns',
            'updated_at_utc',
            'primary_goal',
            'target_keywords',
            'competitors',
            'publishing_schedule',
        ];

        foreach ($stringFields as $field) {
            if (array_key_exists($field, $stored) && is_string($stored[$field])) {
                $profile[$field] = $stored[$field];
            }
        }
        if (array_key_exists('autopilot_enabled', $stored) && is_bool($stored['autopilot_enabled'])) {
            $profile['autopilot_enabled'] = $stored['autopilot_enabled'];
        }

        // Site ownership comes from the tenant-scoped Site model, never from stored JSON.
        $profile['site_id'] = (int) $site->getKey();

        return $profile;
    }
}
