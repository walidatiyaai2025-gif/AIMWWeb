<?php

namespace App\Automation;

use App\Authorization\TenantAuthorizer;
use App\Content\ContentPlatformService;
use App\Models\ContentItem;
use InvalidArgumentException;

final class BulkTrashExecutionService
{
    public const OPERATION_ID = 'AIMW-AUTO-2B8A1146F8';

    public function __construct(
        private readonly TenantAuthorizer $authorizer,
        private readonly ContentPlatformService $content,
    ) {}

    /**
     * Laravel adaptation of BulkTrashExecutionService.RunAsync.
     *
     * @param  list<array{content_type:string,wordpress_id:int}>  $targets
     * @return array{operation_id:string,succeeded:int,failed:int,errors:list<string>}
     */
    public function runAsync(int $siteId, array $targets): array
    {
        $this->authorizer->authorize('content.edit');
        $normalized = collect($targets)
            ->filter(fn (array $target): bool => in_array($target['content_type'] ?? null, ['post', 'page'], true) && (int) ($target['wordpress_id'] ?? 0) > 0)
            ->unique(fn (array $target): string => ($target['content_type'] ?? '').':'.(int) ($target['wordpress_id'] ?? 0))
            ->values();
        if ($normalized->isEmpty()) {
            throw new InvalidArgumentException('At least one valid post or page is required.');
        }

        $succeeded = 0;
        $errors = [];
        foreach ($normalized as $target) {
            $item = ContentItem::query()
                ->where('site_id', $siteId)
                ->where('type', $target['content_type'])
                ->where('remote_id', (int) $target['wordpress_id'])
                ->firstOrFail();

            if (strtolower((string) $item->status) === 'trash') {
                $succeeded++;

                continue;
            }

            try {
                $this->content->mutateContent(
                    $siteId,
                    (string) $item->type,
                    $item->remote_id,
                    'trash',
                    [],
                    ['hash' => $item->remote_hash, 'modified_at' => $item->remote_modified_at?->toIso8601String()],
                );
                $succeeded++;
            } catch (\Throwable $exception) {
                $errors[] = sprintf('%s #%d: %s', $item->type, $item->remote_id, $exception->getMessage());
            }
        }

        return [
            'operation_id' => self::OPERATION_ID,
            'succeeded' => $succeeded,
            'failed' => $normalized->count() - $succeeded,
            'errors' => $errors,
        ];
    }
}
