<?php

namespace App\Sites;

use App\Connector\AdvancedWordPressGateway;
use App\Connector\PairingService;
use App\Models\Connector;
use App\Models\Site;
use App\Models\SiteDiagnostic;
use DateTimeInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

final class SiteDiagnosticsService
{
    public function __construct(
        private readonly AdvancedWordPressGateway $wordpress,
        private readonly PairingService $pairing,
        private readonly SiteOperationHistoryService $history,
    ) {}

    public function status(Site $site): array
    {
        $connector = Connector::query()->where('site_id', $site->id)->first();
        $latest = SiteDiagnostic::query()->where('site_id', $site->id)->latest('checked_at')->first();

        return [
            'site' => $site,
            'connection' => $this->localConnectionState($connector),
            'last_verified_at' => $site->last_verified_at?->toIso8601String(),
            'connector' => $connector ? [
                'identity' => $connector->identity,
                'protocol_version' => $connector->protocol_version,
                'verified_at' => $connector->verified_at?->toIso8601String(),
                'revoked' => (bool) $connector->revoked_at,
            ] : null,
            'latest_diagnostic' => $latest,
        ];
    }

    public function reconnect(Site $site): array
    {
        $started = now();
        $token = $this->pairing->create($site);
        $site->update(['connection_status' => SiteConnectionState::DISCONNECTED]);
        $this->history->record($site->id, 'reconnect', true, 'New canonical Connector pairing token issued.', ['expires_in' => 600], null, null, $started);

        return ['pairing_token' => $token, 'expires_in' => 600, 'state' => SiteConnectionState::DISCONNECTED];
    }

    public function disconnect(Site $site): array
    {
        $started = now();
        $connector = Connector::query()->where('site_id', $site->id)->firstOrFail();
        $this->wordpress->disconnect($site);
        $connector->update(['revoked_at' => now(), 'enabled_scopes' => []]);
        $site->update(['connection_status' => SiteConnectionState::DISCONNECTED, 'health_state' => SiteConnectionState::DISCONNECTED]);
        $this->history->record($site->id, 'disconnect', true, 'Connector disconnected and local scopes revoked.', [], null, null, $started);

        return ['state' => SiteConnectionState::DISCONNECTED, 'revoked' => true];
    }

    public function capabilities(Site $site): array
    {
        $connector = Connector::query()->where('site_id', $site->id)->first();
        if (! $connector) {
            return ['state' => SiteConnectionState::DISCONNECTED, 'capabilities' => [], 'enabled_scopes' => [], 'remote' => null];
        }
        if ($connector->revoked_at) {
            return ['state' => SiteConnectionState::DISCONNECTED, 'capabilities' => $connector->capabilities, 'enabled_scopes' => [], 'remote' => null];
        }
        if (! in_array('health', $connector->enabled_scopes, true)) {
            return ['state' => SiteConnectionState::CAPABILITY_DISABLED, 'capabilities' => $connector->capabilities, 'enabled_scopes' => $connector->enabled_scopes, 'remote' => null];
        }

        try {
            $remote = $this->wordpress->capabilities($site);
            $states = (array) data_get($remote, 'runtime.states', []);
            $disabled = [];
            foreach ($states as $scope => $entry) {
                if (($entry['state'] ?? null) === 'SUPPORTED_DISABLED') {
                    $disabled[] = $scope;
                }
            }

            return [
                'state' => $this->remoteConnectorState($remote),
                'capabilities' => $connector->capabilities,
                'enabled_scopes' => $connector->enabled_scopes,
                'disabled_by_owner' => $disabled,
                'remote' => $this->redact($remote),
            ];
        } catch (Throwable $e) {
            return [
                'state' => $this->classifyFailure($e),
                'capabilities' => $connector->capabilities,
                'enabled_scopes' => $connector->enabled_scopes,
                'remote' => null,
                'failure' => $this->safeMessage($e),
            ];
        }
    }

    public function recheck(Site $site): SiteDiagnostic
    {
        $started = now();
        $rest = $this->restReadiness($site);
        $connector = Connector::query()->where('site_id', $site->id)->first();
        if (! $connector || $connector->revoked_at) {
            return $this->persist($site, SiteConnectionState::DISCONNECTED, $rest, null, 'connector_missing', 'Connector is not paired or has been revoked.', [], [], $started);
        }
        if (! in_array('health', $connector->enabled_scopes, true)) {
            return $this->persist($site, SiteConnectionState::CAPABILITY_DISABLED, $rest, null, 'health_scope_disabled', 'Connector health scope is disabled by the owner.', [], [], $started);
        }

        try {
            $capabilities = $this->wordpress->capabilities($site);
            $connectorState = $this->remoteConnectorState($capabilities);
            if ($connectorState !== SiteConnectionState::CONNECTED) {
                return $this->persist($site, $connectorState, $rest, null, 'connector_state', 'Connector reported a non-connected state.', $capabilities, [], $started);
            }
            if (! in_array('diagnostics.read', $connector->enabled_scopes, true)) {
                return $this->persist($site, SiteConnectionState::CAPABILITY_DISABLED, $rest, null, 'diagnostics_scope_disabled', 'Diagnostics capability is supported but disabled by the owner.', $capabilities, [], $started);
            }

            $operationId = (string) Str::uuid();
            $result = $this->wordpress->operate($site, $operationId, 'site.health');
            $health = (array) ($result['health'] ?? []);
            $db = (bool) data_get($health, 'database.connected', false);
            $remoteRest = (string) data_get($health, 'rest.state', SiteConnectionState::TEMPORARILY_UNAVAILABLE);
            $classification = $rest['state'] === SiteConnectionState::CONNECTED && $db && $remoteRest === 'SUPPORTED_ENABLED'
                ? SiteConnectionState::CONNECTED
                : SiteConnectionState::DEGRADED;
            $diagnostic = $this->persist($site, $classification, $rest, $health, null, null, $capabilities, $health, $started);
            $site->update(['last_verified_at' => now(), 'connection_status' => $classification, 'health_state' => $classification]);
            $connector->update(['verified_at' => now()]);

            return $diagnostic->fresh();
        } catch (Throwable $e) {
            return $this->persist($site, $this->classifyFailure($e), $rest, null, class_basename($e), $this->safeMessage($e), [], [], $started);
        }
    }

    public function diagnosticHistory(Site $site, int $take = 100): array
    {
        return SiteDiagnostic::query()->where('site_id', $site->id)->latest('checked_at')->limit(max(1, min(500, $take)))->get()->toArray();
    }

    private function persist(Site $site, string $classification, array $rest, ?array $health, ?string $failureCode, ?string $failureMessage, array $capabilities, array $details, DateTimeInterface $started): SiteDiagnostic
    {
        $record = SiteDiagnostic::query()->create([
            'site_id' => $site->id,
            'classification' => $classification,
            'connection_state' => $classification,
            'rest_state' => $rest['state'],
            'database_state' => data_get($health, 'database.connected') === true ? SiteConnectionState::CONNECTED : (isset($health['database']) ? SiteConnectionState::DEGRADED : null),
            'cron_state' => isset($health['cron']) ? ((int) data_get($health, 'cron.due', 0) > 0 ? SiteConnectionState::DEGRADED : SiteConnectionState::CONNECTED) : null,
            'cache_state' => $this->cacheState($health),
            'capability_summary' => $this->redact($capabilities),
            'health' => $this->redact($health ?? []),
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
            'checked_at' => now(),
        ]);
        $site->update(['connection_status' => $classification, 'health_state' => $classification]);
        $this->history->record($site->id, 'diagnostic_recheck', $classification === SiteConnectionState::CONNECTED || $classification === SiteConnectionState::DEGRADED, $failureMessage ?: 'Site diagnostic recheck completed.', ['classification' => $classification, 'rest' => $rest, 'details' => $details], null, null, $started);

        return $record;
    }

    private function restReadiness(Site $site): array
    {
        try {
            $response = Http::timeout(10)->acceptJson()->get(rtrim($site->url, '/').'/wp-json/');
            if ($response->successful() && is_array($response->json())) {
                return ['state' => SiteConnectionState::CONNECTED, 'status' => $response->status()];
            }

            return ['state' => $response->status() === 401 || $response->status() === 403 ? SiteConnectionState::AUTH_FAILED : SiteConnectionState::TEMPORARILY_UNAVAILABLE, 'status' => $response->status()];
        } catch (ConnectionException $e) {
            return ['state' => SiteConnectionState::TEMPORARILY_UNAVAILABLE, 'status' => null, 'failure' => $this->safeMessage($e)];
        }
    }

    private function localConnectionState(?Connector $connector): string
    {
        if (! $connector || $connector->revoked_at) {
            return SiteConnectionState::DISCONNECTED;
        }
        if (! in_array('health', $connector->enabled_scopes, true)) {
            return SiteConnectionState::CAPABILITY_DISABLED;
        }

        return SiteConnectionState::CONNECTED;
    }

    private function remoteConnectorState(array $payload): string
    {
        if (data_get($payload, 'connection.protocol_state') === 'UNSUPPORTED') {
            return SiteConnectionState::UNSUPPORTED;
        }

        return match ((string) data_get($payload, 'connection.connection', 'CONNECTED')) {
            'CONNECTED' => SiteConnectionState::CONNECTED,
            'LOCALLY_DISABLED' => SiteConnectionState::CONNECTOR_DISABLED,
            'REVOKED', 'UNPAIRED' => SiteConnectionState::DISCONNECTED,
            default => SiteConnectionState::DEGRADED,
        };
    }

    private function classifyFailure(Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'scope is disabled') || (str_contains($message, 'capability') && str_contains($message, 'disabled'))) {
            return SiteConnectionState::CAPABILITY_DISABLED;
        }
        if (str_contains($message, 'connector is revoked') || str_contains($message, 'not active')) {
            return SiteConnectionState::DISCONNECTED;
        }
        if (str_contains($message, 'signature') || str_contains($message, '401') || str_contains($message, '403') || str_contains($message, 'auth')) {
            return SiteConnectionState::AUTH_FAILED;
        }
        if (str_contains($message, 'unsupported')) {
            return SiteConnectionState::UNSUPPORTED;
        }
        if (str_contains($message, 'disabled')) {
            return SiteConnectionState::CONNECTOR_DISABLED;
        }

        return SiteConnectionState::TEMPORARILY_UNAVAILABLE;
    }

    private function cacheState(?array $health): ?string
    {
        if (! $health) {
            return null;
        }
        $adapters = (array) ($health['adapters'] ?? []);
        foreach ($adapters as $adapter) {
            if (in_array($adapter['id'] ?? null, ['litespeed-cache', 'wp-rocket'], true) && ($adapter['state'] ?? null) === 'SUPPORTED_ENABLED') {
                return SiteConnectionState::CONNECTED;
            }
        }

        return SiteConnectionState::UNSUPPORTED;
    }

    private function safeMessage(Throwable $e): string
    {
        $message = preg_replace('/(Bearer|secret|token|password|api.?key)\s*[:=]?\s*[^\s,;]+/i', '$1 [REDACTED]', $e->getMessage()) ?? 'Operation failed.';

        return mb_substr($message, 0, 500);
    }

    private function redact(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            if (preg_match('/secret|token|password|authorization|cookie|api.?key/i', (string) $key)) {
                $out[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $out[$key] = $this->redact($item);
            } else {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}
