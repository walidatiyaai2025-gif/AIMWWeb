<?php

namespace App\Content;

use App\Content\Remote\NativeWordPressRestPath;
use App\Models\AuditEvent;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

final class MediaDeleteService
{
    public const OPERATION_ID = 'AIMW-BILL-4DCB58743D';

    public function __construct(private readonly NativeWordPressRestPath $wordpress) {}

    public function deletePermanently(int $siteId, int $mediaId): array
    {
        // Resolve every ownership boundary from the active TenantContext. The
        // BelongsToTenant scope makes guessed foreign tenant/site/media IDs 404.
        Site::query()->findOrFail($siteId);

        $media = MediaItem::query()
            ->where('site_id', $siteId)
            ->findOrFail($mediaId);

        $this->assertNotReferenced($siteId, (int) $media->remote_id);

        $remoteId = (int) $media->remote_id;
        abort_if($remoteId < 1, 409, 'Media has no authoritative WordPress identifier.');

        // Connector content.execute does not currently advertise a safe,
        // operation-specific permanent-delete scope. Never widen that scope or
        // synthesize success: require the real native WordPress REST boundary.
        abort_unless(
            $this->wordpress->available($siteId),
            503,
            'Permanent media deletion requires configured WordPress REST credentials.',
        );

        // A previous attempt may have removed WordPress media and then lost the
        // response before local reconciliation. Treat authoritative remote
        // absence as retry recovery; otherwise perform the force=true delete.
        $alreadyAbsent = ! $this->wordpress->exists($siteId, 'media', $remoteId);
        $provider = $alreadyAbsent
            ? []
            : $this->wordpress->deletePermanently($siteId, $remoteId);

        // Remote absence is already authoritative on both branches: the
        // preflight proves absence for lost-response recovery, while
        // deletePermanently() does not return until its post-delete WordPress
        // reread proves absence. Do not add a third provider reread here.
        DB::transaction(function () use ($siteId, $mediaId, $remoteId): void {
            $locked = MediaItem::query()
                ->where('site_id', $siteId)
                ->whereKey($mediaId)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $this->assertNotReferenced($siteId, $remoteId);
            $locked->delete();
        }, 3);

        abort_if(
            MediaItem::query()->where('site_id', $siteId)->whereKey($mediaId)->exists(),
            409,
            'Local media reconciliation did not complete.',
        );

        AuditEvent::query()->create([
            'actor_user_id' => auth()->id(),
            'event' => 'media.deleted_permanently',
            'subject_type' => 'media',
            'subject_id' => (string) $mediaId,
            'metadata' => [
                'operation_id' => self::OPERATION_ID,
                'site_id' => $siteId,
                'remote_id' => $remoteId,
                'remote_verified_absent' => true,
                'retry_recovered' => $alreadyAbsent,
            ],
            'occurred_at' => now(),
        ]);

        return [
            'id' => $mediaId,
            'remote_id' => $remoteId,
            'deleted' => true,
            'remote_verified_absent' => true,
            'retry_recovered' => $alreadyAbsent,
            'provider_deleted' => (bool) data_get($provider, 'deleted', ! $alreadyAbsent),
        ];
    }

    private function assertNotReferenced(int $siteId, int $remoteId): void
    {
        $used = ContentItem::query()
            ->where('site_id', $siteId)
            ->where('featured_media_remote_id', $remoteId)
            ->exists();

        abort_if($used, 409, 'Media is referenced as featured media; detach it before deletion.');
    }
}
