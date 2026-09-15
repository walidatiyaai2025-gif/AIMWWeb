<?php

namespace App\Services;

use App\AI\Platform\Contracts\AiGenerator;
use App\Connector\WordPressGateway;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\SeoAudit;
use App\Models\SeoFinding;
use App\Models\Site;
use App\Models\Suggestion;
use App\Models\SyncedContent;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SeoManagerService
{
    public const WRITABLE_FIELDS = [
        'title', 'slug', 'seo_title', 'seo_description', 'seo_canonical', 'seo_robots',
    ];

    public function __construct(private readonly WordPressGateway $wordpress) {}

    public function runAudit(SeoAudit $audit): SeoAudit
    {
        $contents = SyncedContent::query()->where('site_id', $audit->site_id)->orderBy('id')->get();
        $audit->update([
            'status' => 'running',
            'total_items' => $contents->count(),
            'processed_items' => 0,
            'failed_items' => 0,
            'current_item' => null,
            'log' => [],
            'failure' => null,
        ]);
        $log = [];
        $failed = 0;
        $processed = 0;

        foreach ($contents as $content) {
            $audit->update(['current_item' => $content->resource_type.':'.$content->remote_id]);
            try {
                $findings = $this->analyze($content);
                foreach ($findings as $finding) {
                    SeoFinding::query()->updateOrCreate(
                        [
                            'seo_audit_id' => $audit->id,
                            'synced_content_id' => $content->id,
                            'code' => $finding['code'],
                        ],
                        [
                            'severity' => $finding['severity'],
                            'field' => $finding['field'],
                            'recommendation' => $finding['recommendation'],
                            'before_value' => $finding['before_value'],
                            'suggested_value' => $finding['suggested_value'],
                            'evidence' => $finding['evidence'],
                            'status' => 'open',
                        ]
                    );
                }
                $processed++;
                $log[] = ['item' => $content->resource_type.':'.$content->remote_id, 'status' => 'succeeded', 'findings' => count($findings)];
            } catch (Throwable $exception) {
                $failed++;
                $log[] = ['item' => $content->resource_type.':'.$content->remote_id, 'status' => 'failed', 'error' => $exception->getMessage()];
            }
            $audit->update(['processed_items' => $processed, 'failed_items' => $failed, 'log' => $log]);
        }

        $audit->update([
            'status' => $failed > 0 ? 'partial' : 'succeeded',
            'current_item' => null,
            'completed_at' => now(),
            'log' => $log,
        ]);

        return $audit->refresh();
    }

    /** @return array<int,array<string,mixed>> */
    public function analyze(SyncedContent $content): array
    {
        $metadata = $this->metadata($content->toArray());
        $findings = [];
        $titleLength = mb_strlen($metadata['seo_title'] ?: $metadata['title']);
        $descriptionLength = mb_strlen($metadata['seo_description']);
        $slugLength = mb_strlen($metadata['slug']);
        $readability = $this->readabilityScore((string) $content->content);
        $content->update([
            'seo_readability_score' => $readability,
            'seo_source_hash' => $this->sourceHash($metadata),
        ]);

        if ($titleLength === 0) {
            $findings[] = $this->finding('missing_title', 'critical', 'seo_title', 'Add a descriptive SEO title.', '', $this->fallbackTitle($metadata['slug']), ['title_length' => 0]);
        } elseif ($titleLength < 30 || $titleLength > 60) {
            $findings[] = $this->finding('title_length', 'medium', 'seo_title', 'Keep the SEO title between 30 and 60 characters.', $metadata['seo_title'] ?: $metadata['title'], null, ['title_length' => $titleLength]);
        }
        if ($descriptionLength === 0) {
            $findings[] = $this->finding('missing_meta_description', 'high', 'seo_description', 'Add a concise meta description grounded in the page content.', '', $this->fallbackDescription((string) $content->excerpt, (string) $content->content), ['description_length' => 0]);
        } elseif ($descriptionLength > 160) {
            $findings[] = $this->finding('meta_description_length', 'medium', 'seo_description', 'Keep the meta description at or below 160 characters.', $metadata['seo_description'], Str::limit($metadata['seo_description'], 157, '...'), ['description_length' => $descriptionLength]);
        }
        if ($metadata['seo_canonical'] !== null && ! filter_var($metadata['seo_canonical'], FILTER_VALIDATE_URL)) {
            $findings[] = $this->finding('invalid_canonical', 'high', 'seo_canonical', 'Use an absolute valid canonical URL.', $metadata['seo_canonical'], null, ['canonical_valid' => false]);
        }
        if (in_array('noindex', $metadata['seo_robots'], true)) {
            $findings[] = $this->finding('robots_noindex', 'high', 'seo_robots', 'Review whether this published resource should be excluded from indexing.', implode(',', $metadata['seo_robots']), null, ['robots' => $metadata['seo_robots']]);
        }
        if ($slugLength === 0) {
            $findings[] = $this->finding('missing_slug', 'high', 'slug', 'Add a stable descriptive slug.', '', $this->fallbackTitle($metadata['title']), ['slug_length' => 0]);
        } elseif ($slugLength > 75) {
            $findings[] = $this->finding('slug_length', 'low', 'slug', 'Shorten the slug while retaining its primary meaning.', $metadata['slug'], null, ['slug_length' => $slugLength]);
        }
        if ($readability < 60) {
            $findings[] = $this->finding('readability', $readability < 40 ? 'high' : 'medium', 'content', 'Improve sentence length and structure for readability.', (string) $readability, null, ['readability_score' => $readability]);
        }

        return $findings;
    }

    public function inspectRemote(Site $site, string $type, int $remoteId): array
    {
        $remote = $this->wordpress->read($site, $type, $remoteId);
        $metadata = $this->metadata($remote);

        return [
            'metadata' => $metadata,
            'source_hash' => $this->sourceHash($metadata),
            'provider' => $this->providerState(
                $metadata['seo_provider'],
                $this->nullableBool($remote['seo_provider_enabled'] ?? null),
                $this->nullableBool($remote['seo_provider_available'] ?? null),
            ),
            'readability_score' => $this->readabilityScore((string) ($remote['content'] ?? '')),
            'authoritative' => true,
        ];
    }

    public function prepareRemediation(SeoFinding $finding, int $actorUserId, array $requested = []): array
    {
        $content = SyncedContent::query()->findOrFail($finding->synced_content_id);
        $before = $this->metadata($content->toArray());
        $changes = $requested !== [] ? Arr::only($requested, self::WRITABLE_FIELDS) : $this->suggestedChanges($finding);
        if ($changes === []) {
            throw new RuntimeException('Finding has no safe deterministic remediation.');
        }
        $this->assertProviderCanWrite($before['seo_provider'], array_keys($changes));
        $changes = $this->normalizeChanges($changes);

        $suggestion = Suggestion::query()->create([
            'site_id' => $content->site_id,
            'seo_finding_id' => $finding->id,
            'synced_content_id' => $content->id,
            'actor_user_id' => $actorUserId,
            'status' => 'awaiting_approval',
            'before_state' => $before,
            'proposed_state' => $changes,
        ]);
        $approval = Approval::query()->create([
            'suggestion_id' => $suggestion->id,
            'actor_user_id' => $actorUserId,
            'status' => 'PENDING',
            'before_state' => $before,
            'proposed_state' => $changes,
        ]);

        return ['suggestion' => $suggestion, 'approval' => $approval];
    }

    public function prepareBulk(array $items, int $actorUserId): array
    {
        $result = ['prepared' => [], 'failed' => []];
        foreach ($items as $item) {
            $findingId = (int) ($item['finding_id'] ?? 0);
            try {
                $finding = SeoFinding::query()->findOrFail($findingId);
                $prepared = $this->prepareRemediation($finding, $actorUserId, (array) ($item['changes'] ?? []));
                $result['prepared'][] = [
                    'finding_id' => $findingId,
                    'suggestion_id' => $prepared['suggestion']->id,
                    'approval_id' => $prepared['approval']->id,
                    'status' => 'pending_approval',
                ];
            } catch (Throwable $exception) {
                $result['failed'][] = ['finding_id' => $findingId, 'error' => $exception->getMessage()];
            }
        }

        return $result;
    }

    public function generateAiProposal(SeoFinding $finding, int $siteId): array
    {
        if (! app()->bound(AiGenerator::class)) {
            throw new RuntimeException('PR #267 AI generator contract is not bound.');
        }
        $content = SyncedContent::query()->findOrFail($finding->synced_content_id);
        $result = app(AiGenerator::class)->generate([
            'workflow' => 'seo.remediation',
            'site_id' => $siteId,
            'user_prompt' => 'Suggest safe SEO metadata changes only. Do not publish.',
            'variables' => [
                'finding' => $finding->only(['code', 'severity', 'field', 'recommendation']),
                'metadata' => $this->metadata($content->toArray()),
                'content_excerpt' => Str::limit(strip_tags((string) $content->content), 1200),
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'seo_title' => ['type' => 'string'],
                    'seo_description' => ['type' => 'string'],
                    'seo_canonical' => ['type' => ['string', 'null']],
                    'seo_robots' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'slug' => ['type' => 'string'],
                ],
                'additionalProperties' => false,
            ],
        ]);

        return [
            'correlation_id' => $result['correlation_id'],
            'provider' => $result['provider'],
            'model' => $result['model'],
            'proposal' => $this->normalizeChanges((array) ($result['structured'] ?? [])),
            'requires_approval' => true,
        ];
    }

    public function retryable(Execution $execution): bool
    {
        return $execution->status === 'failed' && $execution->attempts < 5;
    }

    public function metadata(array $source): array
    {
        $robots = $source['seo_robots'] ?? [];
        if (is_string($robots)) {
            $robots = preg_split('/[\s,]+/', strtolower($robots), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        $robots = array_values(array_unique(array_filter(array_map(static fn ($value): string => strtolower(trim((string) $value)), (array) $robots))));
        sort($robots);
        $canonical = trim((string) ($source['seo_canonical'] ?? ''));

        return [
            'title' => trim(strip_tags((string) ($source['title'] ?? ''))),
            'slug' => trim((string) ($source['slug'] ?? '')),
            'seo_title' => trim(strip_tags((string) ($source['seo_title'] ?? ''))),
            'seo_description' => trim(strip_tags((string) ($source['seo_description'] ?? ''))),
            'seo_canonical' => $canonical === '' ? null : $canonical,
            'seo_robots' => $robots,
            'seo_provider' => $source['seo_provider'] ?? null,
            'modified_at' => $source['modified_at'] ?? ($source['remote_modified_at'] ?? null),
        ];
    }

    public function sourceHash(array $metadata): string
    {
        return hash('sha256', json_encode($this->metadata($metadata), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function statesMatch(array $expected, array $actual): bool
    {
        $expected = $this->metadata($expected);
        $actual = $this->metadata($actual);
        foreach (self::WRITABLE_FIELDS as $field) {
            if (array_key_exists($field, $expected) && $expected[$field] !== $actual[$field]) {
                return false;
            }
        }

        return true;
    }

    public function proposedStateVerified(array $proposed, array $actual): bool
    {
        $actual = $this->metadata($actual);
        foreach ($this->normalizeChanges($proposed) as $field => $value) {
            if (($actual[$field] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }

    public function providerState(?string $provider, ?bool $enabled = null, ?bool $available = null): array
    {
        if (! in_array($provider, ['yoast-seo', 'rank-math'], true)) {
            return match ($provider) {
                null, '' => ['provider' => null, 'state' => 'WORDPRESS_NATIVE', 'writable' => ['title', 'slug']],
                default => ['provider' => $provider, 'state' => 'UNSUPPORTED', 'writable' => ['title', 'slug']],
            };
        }

        if ($available === false) {
            return ['provider' => $provider, 'state' => 'TEMPORARILY_UNAVAILABLE', 'writable' => []];
        }
        if ($enabled === false) {
            return ['provider' => $provider, 'state' => 'SUPPORTED_DISABLED', 'writable' => []];
        }

        return [
            'provider' => $provider,
            'state' => 'SUPPORTED_ENABLED',
            'writable' => ['seo_title', 'seo_description', 'seo_canonical', 'seo_robots'],
        ];
    }

    public function readabilityScore(string $html): int
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if ($text === '') {
            return 0;
        }
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentences = max(1, preg_match_all('/[.!?]+/', $text));
        $average = count($words) / $sentences;
        $long = count(array_filter($words, static fn (string $word): bool => mb_strlen(trim($word, ".,!?;:()[]{}\"'")) > 12));
        $score = 100 - max(0, (int) round(($average - 18) * 2)) - min(35, $long * 2);

        return max(0, min(100, $score));
    }

    private function finding(string $code, string $severity, string $field, string $recommendation, ?string $before, ?string $suggested, array $evidence): array
    {
        return compact('code', 'severity', 'field', 'recommendation') + [
            'before_value' => $before,
            'suggested_value' => $suggested,
            'evidence' => $evidence,
        ];
    }

    private function suggestedChanges(SeoFinding $finding): array
    {
        if ($finding->suggested_value === null || $finding->field === null) {
            return [];
        }
        $value = $finding->field === 'seo_robots'
            ? preg_split('/[\s,]+/', $finding->suggested_value, -1, PREG_SPLIT_NO_EMPTY)
            : $finding->suggested_value;

        return [$finding->field => $value];
    }

    private function normalizeChanges(array $changes): array
    {
        $changes = Arr::only($changes, self::WRITABLE_FIELDS);
        if (array_key_exists('seo_robots', $changes)) {
            $robots = is_array($changes['seo_robots']) ? $changes['seo_robots'] : preg_split('/[\s,]+/', (string) $changes['seo_robots'], -1, PREG_SPLIT_NO_EMPTY);
            $robots = array_values(array_unique(array_filter(array_map(static fn ($value): string => strtolower(trim((string) $value)), $robots ?: []))));
            sort($robots);
            $changes['seo_robots'] = $robots;
        }
        foreach (['title', 'slug', 'seo_title', 'seo_description', 'seo_canonical'] as $field) {
            if (array_key_exists($field, $changes)) {
                $changes[$field] = trim((string) $changes[$field]);
            }
        }
        if (isset($changes['seo_canonical']) && $changes['seo_canonical'] !== '' && ! filter_var($changes['seo_canonical'], FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Canonical URL must be an absolute valid URL.');
        }

        return $changes;
    }

    private function assertProviderCanWrite(?string $provider, array $fields): void
    {
        $pluginFields = array_intersect(['seo_title', 'seo_description', 'seo_canonical', 'seo_robots'], $fields);
        if ($pluginFields === []) {
            return;
        }
        $state = $this->providerState($provider);
        if (in_array($state['state'], ['UNSUPPORTED', 'WORDPRESS_NATIVE'], true)) {
            throw new RuntimeException('SEO plugin metadata write is unsupported for the detected provider.');
        }
        if ($state['state'] !== 'SUPPORTED_ENABLED') {
            throw new RuntimeException('SEO plugin metadata write is unavailable for the detected provider state.');
        }
        if (array_diff($pluginFields, $state['writable'])) {
            throw new RuntimeException('Detected SEO provider does not support the requested metadata fields.');
        }
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes', 'enabled', 'available' => true,
                '0', 'false', 'no', 'disabled', 'unavailable' => false,
                default => null,
            };
        }

        return null;
    }

    private function fallbackTitle(string $value): string
    {
        return Str::of($value)->replace(['-', '_'], ' ')->headline()->limit(60, '')->toString();
    }

    private function fallbackDescription(string $excerpt, string $content): string
    {
        $value = trim(strip_tags($excerpt !== '' ? $excerpt : $content));

        return Str::limit(preg_replace('/\s+/', ' ', $value) ?? '', 157, '...');
    }
}
