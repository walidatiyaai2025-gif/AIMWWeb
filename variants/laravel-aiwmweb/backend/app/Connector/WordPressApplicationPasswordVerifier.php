<?php

namespace App\Connector;

use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class WordPressApplicationPasswordVerifier
{
    /**
     * Verify proposed WordPress Application Password credentials without persisting them.
     *
     * @return array{limited_permissions:bool,remote_user_id:int|null}
     */
    public function verify(Site $site, string $username, string $applicationPassword): array
    {
        $endpoint = rtrim((string) $site->url, '/').'/wp-json/wp/v2/users/me?context=edit';

        try {
            $response = Http::connectTimeout(5)
                ->timeout(15)
                ->acceptJson()
                ->withBasicAuth($username, $applicationPassword)
                ->get($endpoint);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('WordPress connection test could not be completed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('WordPress connection test failed.');
        }

        $body = $response->json();
        if (! is_array($body) || ! is_numeric($body['id'] ?? null)) {
            throw new RuntimeException('WordPress connection test returned an invalid response.');
        }

        $capabilities = is_array($body['capabilities'] ?? null) ? $body['capabilities'] : [];
        $limitedPermissions = ! (($capabilities['manage_options'] ?? false) === true);

        return [
            'limited_permissions' => $limitedPermissions,
            'remote_user_id' => (int) $body['id'],
        ];
    }
}
