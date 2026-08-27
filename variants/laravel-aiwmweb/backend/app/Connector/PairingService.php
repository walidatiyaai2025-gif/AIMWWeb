<?php

namespace App\Connector;

use App\Models\Connector;
use App\Models\ConnectorPairing;
use App\Models\Site;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Support\Str;
use RuntimeException;

final class PairingService
{
    public const CAPABILITIES = ['health', 'content.read', 'content.update', 'seo.read', 'seo.write', 'audit.local'];

    public const SAFE_DEFAULT_SCOPES = ['health', 'content.read', 'seo.read'];

    public function create(Site $site): string
    {
        $token = Str::random(64);
        ConnectorPairing::query()->where('site_id', $site->id)->whereNull('used_at')->delete();
        ConnectorPairing::query()->create(['site_id' => $site->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10)]);

        return $token;
    }

    public function complete(string $token, string $identity, array $capabilities, string $version): array
    {
        $pairing = ConnectorPairing::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->firstOrFail();
        if ($pairing->used_at || $pairing->expires_at->isPast()) {
            throw new RuntimeException('Pairing token expired or already used.');
        }
        if ($version !== ConnectorProtocol::VERSION) {
            throw new RuntimeException('Unsupported connector protocol version.');
        }
        $context = app(TenantContext::class);
        $tenant = Tenant::query()->findOrFail($pairing->tenant_id);
        $context->activate($tenant);
        try {
            $site = Site::query()->findOrFail($pairing->site_id);
            $secret = Str::random(64);
            $connector = Connector::query()->updateOrCreate(['site_id' => $site->id], [
                'identity' => $identity,
                'encrypted_secret' => $secret,
                'protocol_version' => $version,
                'capabilities' => array_values(array_intersect(self::CAPABILITIES, $capabilities)),
                'enabled_scopes' => array_values(array_intersect(self::SAFE_DEFAULT_SCOPES, $capabilities)),
                'revoked_at' => null,
            ]);
            $pairing->forceFill(['used_at' => now()])->save();
            $site->update(['connection_status' => 'paired']);

            return ['connector_id' => $connector->identity, 'secret' => $secret, 'protocol_version' => $version, 'enabled_scopes' => $connector->enabled_scopes];
        } finally {
            $context->forget();
        }
    }
}
