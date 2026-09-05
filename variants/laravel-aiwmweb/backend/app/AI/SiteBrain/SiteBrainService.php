<?php

namespace App\AI\SiteBrain;

use App\Authorization\TenantAuthorizer;
use App\Models\Site;
use App\Operations\AdministrationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SiteBrainService
{
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
