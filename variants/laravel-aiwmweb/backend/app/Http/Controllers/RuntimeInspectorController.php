<?php

namespace App\Http\Controllers;

use App\Authorization\TenantAuthorizer;
use App\Models\Connector;
use App\Models\ContentItem;
use App\Models\MediaItem;
use App\Models\Site;
use App\Models\SiteDiagnostic;
use App\Models\SyncRun;
use App\Models\SyncedContent;
use App\Platform\SystemUpdateService;
use App\Sites\SiteDiagnosticsService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Read-only, tenant-scoped diagnostics that can be safely exported to support.
 * Never serialize models, raw error messages, environment or request metadata.
 */
final class RuntimeInspectorController extends Controller
{
    public const SCHEMA = 'aiwm.runtime-inspector.v1';

    private const SAFE_SCOPES = [
        'health', 'content.read', 'content.update', 'seo.read',
        'diagnostics.read', 'connector.manage',
    ];

    public function __invoke(
        string $tenant,
        TenantAuthorizer $authorizer,
        TenantContext $context,
        SystemUpdateService $updates,
        SiteDiagnosticsService $diagnostics,
        Request $request,
    ): JsonResponse {
        $authorizer->authorize('diagnostics.view');
        abort_unless(hash_equals((string) $context->tenant()->slug, $tenant), 404);

        // An explicit user-triggered run may probe at most three remote sites.
        // These are signed, read-only capability requests; never use site.health mutations.
        $probe = $request->boolean('probe');
        $sites = Site::query()->orderBy('id')->limit(51)->get();
        $truncated = $sites->count() > 50;
        $rows = $sites->take(50)->map(function (Site $site, int $index) use ($probe, $diagnostics): array {
            $connector = Connector::query()->where('site_id', $site->id)->first();
            $scopes = $connector
                ? array_values(array_intersect(self::SAFE_SCOPES, (array) $connector->enabled_scopes))
                : [];
            $lastRun = SyncRun::query()->where('site_id', $site->id)->latest('id')->first();
            $lastDiagnostic = SiteDiagnostic::query()->where('site_id', $site->id)->latest('id')->first();
            $posts = ContentItem::query()->where('site_id', $site->id)->where('type', 'post')->count();
            $pages = ContentItem::query()->where('site_id', $site->id)->where('type', 'page')->count();
            $media = MediaItem::query()->where('site_id', $site->id)->count();
            $legacy = SyncedContent::query()->where('site_id', $site->id)->count();
            $hasConnector = $connector !== null;
            $revoked = $connector !== null && $connector->revoked_at !== null;
            $read = in_array('content.read', $scopes, true);
            $status = (string) ($site->connection_status ?? 'unknown');
            $syncState = $lastRun ? (string) $lastRun->state : null;
            $remoteProbe = ['attempted' => false, 'state' => $probe && $index >= 3 ? 'limited' : 'not_run'];
            if ($probe && $index < 3 && $hasConnector && $revoked === false) {
                try {
                    $remote = $diagnostics->capabilities($site);
                    $remoteProbe = [
                        'attempted' => true,
                        'state' => $this->safeState((string) ($remote['state'] ?? 'unknown')),
                    ];
                } catch (Throwable) {
                    $remoteProbe = ['attempted' => true, 'state' => 'TEMPORARILY_UNAVAILABLE'];
                }
            }
            $finding = match (true) {
                $hasConnector === false => 'CONNECTOR_NOT_PAIRED',
                $revoked => 'CONNECTOR_REVOKED',
                $read === false => 'CONTENT_READ_SCOPE_DISABLED',
                in_array($status, ['paired', 'verified', 'connected'], true) === false => 'SITE_CONNECTION_NOT_READY',
                $remoteProbe['state'] === 'AUTH_FAILED' => 'REMOTE_AUTH_FAILED',
                $remoteProbe['state'] === 'UNSUPPORTED' => 'REMOTE_PROTOCOL_UNSUPPORTED',
                in_array($remoteProbe['state'], ['DEGRADED', 'DISCONNECTED', 'TEMPORARILY_UNAVAILABLE', 'CONNECTOR_DISABLED'], true) => 'REMOTE_NOT_READY',
                $lastRun === null => 'CANONICAL_SYNC_NOT_STARTED',
                in_array($syncState, ['failed', 'partial', 'cancelled'], true) => 'LAST_SYNC_NOT_COMPLETED',
                $syncState !== 'completed' && $syncState !== 'succeeded' => 'SYNC_RUNNING_OR_QUEUED',
                ($posts + $pages + $media) === 0 && $legacy > 0 => 'LEGACY_ROWS_NOT_CANONICAL',
                ($posts + $pages + $media) === 0 => 'NO_CANONICAL_ROWS',
                default => 'CANONICAL_ROWS_PRESENT',
            };

            return [
                'site_id' => (int) $site->id,
                'connection_state' => $this->safeState($status),
                'health_state' => $this->safeState((string) ($site->health_state ?? 'unknown')),
                'connector' => [
                    'present' => $hasConnector,
                    'revoked' => $revoked,
                    'verified' => $hasConnector && $connector->verified_at !== null,
                    'protocol' => $hasConnector && preg_match('/^\d+(?:\.\d+){0,2}$/', (string) $connector->protocol_version)
                        ? (string) $connector->protocol_version : 'unknown',
                    'enabled_scopes' => $scopes,
                ],
                'last_diagnostic' => $this->safeState((string) ($lastDiagnostic?->classification ?? 'not_run')),
                'remote_probe' => $remoteProbe,
                'last_sync' => [
                    'present' => $lastRun !== null,
                    'state' => $syncState === null ? 'not_run' : $this->safeState($syncState),
                    'finished' => $lastRun?->completed_at !== null,
                ],
                'record_counts' => [
                    'posts' => $posts,
                    'pages' => $pages,
                    'media' => $media,
                    'legacy_synced_contents' => $legacy,
                ],
                'finding' => $finding,
            ];
        })->values()->all();

        try {
            DB::connection()->select('SELECT 1');
            $database = 'reachable';
        } catch (Throwable) {
            $database = 'unreachable';
        }

        $release = $updates->state();
        $result = [
            'schema' => self::SCHEMA,
            'generated_at' => now()->utc()->toIso8601String(),
            'safety' => [
                'redacted' => true,
                'read_only' => true,
                'has_credentials' => false,
                'has_content_or_log_bodies' => false,
                'site_limit' => 50,
            ],
            'release' => [
                'version' => (string) $release['version'],
                'sha' => preg_match('/^[a-f0-9]{40}$/i', (string) $release['sha'])
                    ? (string) $release['sha'] : 'unknown',
            ],
            'runtime' => [
                'php_version' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
                'laravel_version' => app()->version(),
                'database' => $database,
                'database_driver' => $this->safeDriver((string) config('database.default')),
                'queue_driver' => $this->safeDriver((string) config('queue.default')),
                'cache_driver' => $this->safeDriver((string) config('cache.default')),
                'extensions' => [
                    'pdo_mysql' => extension_loaded('pdo_mysql'),
                    'openssl' => extension_loaded('openssl'),
                    'sodium' => extension_loaded('sodium'),
                    'mbstring' => extension_loaded('mbstring'),
                    'zip' => extension_loaded('zip'),
                ],
            ],
            'site_count' => Site::query()->count(),
            'remote_probe_limit' => 3,
            'sites_truncated' => $truncated,
            'sites' => $rows,
        ];

        return response()->json($result)
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function safeState(string $state): string
    {
        return in_array($state, [
            'active', 'disabled', 'connected', 'paired', 'verified', 'disconnected',
            'degraded', 'unknown', 'temporarily_unavailable', 'capability_disabled',
            'CONNECTED', 'DEGRADED', 'DISCONNECTED', 'AUTH_FAILED',
            'CONNECTOR_DISABLED', 'CAPABILITY_DISABLED', 'UNSUPPORTED', 'TEMPORARILY_UNAVAILABLE',
            'failed', 'partial', 'cancelled', 'cancel_requested', 'queued', 'running',
            'completed', 'succeeded', 'pending', 'not_run', 'unhealthy',
        ], true) ? $state : 'other';
    }

    private function safeDriver(string $value): string
    {
        return in_array($value, [
            'mysql', 'mariadb', 'sqlite', 'pgsql', 'redis', 'database',
            'sync', 'file', 'array', 'null', 'memcached', 'dynamodb',
        ], true) ? $value : 'other';
    }
}
