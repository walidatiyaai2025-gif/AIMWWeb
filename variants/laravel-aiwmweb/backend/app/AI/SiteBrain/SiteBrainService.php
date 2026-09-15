<?php

namespace App\AI\SiteBrain;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Operations\AdministrationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

final class SiteBrainService
{
    public const CANONICAL_GET_OPERATION = 'AIMW-AI-0F3763FDB4';

    public const CANONICAL_SAVE_OPERATION = 'AIMW-AI-34EC6312B9';

    public const SETTING_KEY = 'site_brain.profile';

    private const REQUIRED_STRING_FIELDS = [
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
    ];

    private const DEFAULTS = [
        'primary_goal' => 'Increase organic traffic',
        'target_keywords' => '',
        'competitors' => '',
        'publishing_schedule' => '2 articles per week',
        'autopilot_enabled' => false,
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthorizer $authorizer,
        private readonly AdministrationService $administration,
    ) {}

    /**
     * Laravel adaptation of canonical SiteBrainService.GetAsync.
     *
     * The source operation is a read-only, fail-safe lookup: absent, blank, or
     * malformed persisted JSON resolves to the canonical default profile.
     * Tenant authority comes from the scoped Site lookup and is repeated on the
     * settings query so a stored site identifier can never select another tenant.
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

    /**
     * Laravel adaptation of canonical SiteBrainService.SaveAsync.
     *
     * The source operation serializes the complete profile, overwrites
     * UpdatedAtUtc with server UTC, upserts one site-keyed setting, and commits.
     */
    public function saveAsync(array $profile): void
    {
        $this->authorizer->authorize('settings.manage');

        if (! isset($profile['site_id']) || ! is_int($profile['site_id']) || $profile['site_id'] < 1) {
            throw ValidationException::withMessages(['site_id' => 'A valid integer site_id is required.']);
        }

        $site = Site::query()->findOrFail($profile['site_id']);
        $payload = $this->validatedPayload($profile);
        $payload['site_id'] = (int) $site->getKey();
        $payload['updated_at_utc'] = now('UTC')->toIso8601String();
        $actorUserId = (int) $this->context->membership()->user_id;

        DB::transaction(function () use ($site, $payload, $actorUserId): void {
            $this->administration->saveSetting(
                'site',
                self::SETTING_KEY,
                $payload,
                false,
                $this->siteKey($site),
                $actorUserId,
            );
        });
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
            'primary_goal' => self::DEFAULTS['primary_goal'],
            'target_keywords' => self::DEFAULTS['target_keywords'],
            'competitors' => self::DEFAULTS['competitors'],
            'publishing_schedule' => self::DEFAULTS['publishing_schedule'],
            'autopilot_enabled' => self::DEFAULTS['autopilot_enabled'],
        ];
    }

    private function normalizeProfile(Site $site, array $stored): array
    {
        $profile = $this->defaultProfile($site);
        $stringFields = [
            ...self::REQUIRED_STRING_FIELDS,
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

    private function validatedPayload(array $profile): array
    {
        $payload = [];

        foreach (self::REQUIRED_STRING_FIELDS as $field) {
            if (! array_key_exists($field, $profile) || ! is_string($profile[$field])) {
                throw ValidationException::withMessages([$field => "{$field} must be a string."]);
            }
            $payload[$field] = $profile[$field];
        }

        foreach (['primary_goal', 'target_keywords', 'competitors', 'publishing_schedule'] as $field) {
            $value = $profile[$field] ?? self::DEFAULTS[$field];
            if (! is_string($value)) {
                throw ValidationException::withMessages([$field => "{$field} must be a string."]);
            }
            $payload[$field] = $value;
        }

        $autopilot = $profile['autopilot_enabled'] ?? self::DEFAULTS['autopilot_enabled'];
        if (! is_bool($autopilot)) {
            throw ValidationException::withMessages(['autopilot_enabled' => 'autopilot_enabled must be a boolean.']);
        }
        $payload['autopilot_enabled'] = $autopilot;

        return $payload;
    }
}
