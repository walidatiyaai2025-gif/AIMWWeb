<?php

namespace App\Email\Services;

use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SyncNotificationSubscriber
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly DomainNotificationBridge $bridge,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen('SyncStarted', fn (mixed $run, array $payload = []) => $this->handle('started', $run, $payload));
        $events->listen('SyncFailed', fn (mixed $run, array $payload = []) => $this->handle('failed', $run, $payload));
        $events->listen('SyncCompleted', fn (mixed $run, array $payload = []) => $this->handle('completed', $run, $payload));
    }

    private function handle(string $state, mixed $run, array $payload): void
    {
        if (! is_object($run) || ! $this->context->active()) {
            return;
        }

        $tenantId = (int) ($run->tenant_id ?? 0);
        $runId = (int) ($run->id ?? 0);
        $siteId = (int) ($run->site_id ?? 0);
        $userId = (int) ($run->initiated_by_user_id ?? 0);
        if ($tenantId !== $this->context->id() || $runId < 1 || $userId < 1) {
            return;
        }

        $membership = TenantMembership::query()->where('user_id', $userId)->where('status', 'active')->first();
        $user = $membership ? User::query()->find($userId) : null;
        if (! $membership || ! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $title = match ($state) {
            'started' => 'Sync started',
            'failed' => 'Sync failed',
            default => 'Sync completed',
        };
        $message = match ($state) {
            'started' => 'The synchronization run has started.',
            'failed' => 'The synchronization run failed. Review Sync history for diagnostics.',
            default => 'The synchronization run completed.',
        };

        try {
            $this->bridge->sync($this->eventId($tenantId, $runId, $state), $state, [
                'user_id' => $userId,
                'recipient_email' => $user->email,
                'locale' => 'en',
                'title' => $title,
                'message' => $message,
                'deep_link' => '/sync',
                'metadata' => [
                    'sync_run_id' => $runId,
                    'site_id' => $siteId,
                    'state' => $payload['state'] ?? $state,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::warning('Email sync notification reaction failed.', [
                'sync_run_id' => $runId,
                'exception' => class_basename($exception),
            ]);
        }
    }

    private function eventId(int $tenantId, int $runId, string $state): string
    {
        $hex = substr(hash('sha256', "aiwmweb:tenant:{$tenantId}:sync:{$runId}:{$state}"), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }
}
