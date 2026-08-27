<?php

namespace App\Jobs;

use App\Content\ContentPlatformService;
use App\Content\Remote\ContentRemoteDriver;
use App\Models\ContentTransfer;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MediaUploadJob extends TenantAwareJob
{
    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(int $tenantId, public readonly int $transferId) { parent::__construct($tenantId); }
    public function uniqueId(): string { return "tenant:{$this->tenantId}:media-upload:{$this->transferId}"; }

    public function handle(ContentRemoteDriver $remote, ContentPlatformService $service): void
    {
        $transfer = ContentTransfer::query()->where('kind','media-upload')->findOrFail($this->transferId);
        $transfer->update(['state'=>'running','progress'=>10,'started_at'=>now(),'last_error'=>null]);
        try {
            $path = $transfer->storage_path;
            if (! $path || ! Storage::disk('local')->exists($path)) throw new RuntimeException('Queued media source is unavailable.');
            $options = $transfer->options ?? [];
            $result = $remote->upload($transfer->site_id, Storage::disk('local')->path($path), (string)($options['name'] ?? basename($path)), (string)($options['mime_type'] ?? 'application/octet-stream'), (array)($options['metadata'] ?? []));
            $transfer->update(['progress'=>85,'result'=>$result]);
            $service->sync($transfer->site_id, false);
            $transfer->update(['state'=>'completed','progress'=>100,'completed_at'=>now()]);
            Storage::disk('local')->delete($path);
        } catch (\Throwable $e) {
            $transfer->update(['state'=>'failed','last_error'=>$e->getMessage(),'completed_at'=>now()]);
            throw $e;
        }
    }
}
