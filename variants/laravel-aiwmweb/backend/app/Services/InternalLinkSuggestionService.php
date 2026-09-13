<?php

namespace App\Services;

use App\Authorization\TenantAuthorizer;
use App\Models\ContentItem;
use App\Models\Site;
use Illuminate\Support\Collection;

final class InternalLinkSuggestionService
{
    public const OPERATION_ID = 'AIMW-BILL-90CF990147';

    public function __construct(private readonly TenantAuthorizer $authorizer) {}

    /**
     * Canonical parity for InternalLinkSuggestionService.GenerateAsync.
     *
     * @return array<int, array{
     *     source_wordpress_id:int,
     *     source_title:string,
     *     target_wordpress_id:int,
     *     target_title:string,
     *     suggested_anchor:string,
     *     reason:string,
     *     confidence:float
     * }>
     */
    public function generateAsync(int $siteId): array
    {
        $this->authorizer->authorize('sites.view');

        // Site and content models are tenant scoped. Resolving the site first makes
        // a foreign-tenant site fail closed rather than returning an ambiguous empty set.
        Site::query()->findOrFail($siteId);

        /** @var Collection<int, ContentItem> $content */
        $content = ContentItem::query()
            ->where('site_id', $siteId)
            ->where('status', 'publish')
            ->where('stale', false)
            ->get();

        $results = [];

        foreach ($content as $source) {
            $sourceWords = $this->keywords($this->normalize(
                (string) $source->title.' '.$this->stripHtml((string) $source->body),
            ));

            foreach ($content as $target) {
                if ((int) $target->getKey() === (int) $source->getKey()) {
                    continue;
                }

                $targetLink = trim((string) $target->link);
                if ($targetLink !== '' && stripos((string) $source->body, $targetLink) !== false) {
                    continue;
                }

                $targetWords = $this->keywords($this->normalize(
                    (string) $target->title.' '.(string) $target->slug,
                ));
                $overlap = count(array_intersect_key($sourceWords, $targetWords));
                if ($overlap < 2) {
                    continue;
                }

                $confidence = min(0.95, 0.45 + ($overlap * 0.1));
                $results[] = [
                    'source_wordpress_id' => (int) $source->remote_id,
                    'source_title' => (string) $source->title,
                    'target_wordpress_id' => (int) $target->remote_id,
                    'target_title' => (string) $target->title,
                    'suggested_anchor' => (string) $target->title,
                    'reason' => "Shared topical terms: {$overlap}.",
                    'confidence' => $confidence,
                ];
            }
        }

        usort($results, static function (array $left, array $right): int {
            $confidence = $right['confidence'] <=> $left['confidence'];
            if ($confidence !== 0) {
                return $confidence;
            }

            return strcmp($left['source_title'], $right['source_title']);
        });

        return array_slice($results, 0, 200);
    }

    private function stripHtml(string $value): string
    {
        return html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function normalize(string $value): string
    {
        $normalized = mb_strtolower($value, 'UTF-8');

        return preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? '';
    }

    /** @return array<string, true> */
    private function keywords(string $value): array
    {
        $keywords = [];
        $parts = preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') >= 4) {
                $keywords[$part] = true;
            }
        }

        return $keywords;
    }
}
