<?php

/**
 * Real WP_REST_Request regression suite for AIMW signed Connector headers.
 *
 * Executed under disposable WordPress by WP-CLI, never on production.
 * Prior to Connector v0.2.2 get_headers() used underscore keys while
 * authorize() looked up hyphenated keys, making all valid requests fail.
 */

if (! defined('ABSPATH') || ! class_exists('AIMW_Connector_V1')) {
    fwrite(STDERR, "Connector plugin must be active in WordPress.\n");
    exit(1);
}

$original = get_option('aimw_connector', []);
$secret = str_repeat('t', 64);
$identity = '12345678-1234-4123-8123-123456789abc';
$tenant = '17';
$site = '25';

update_option('aimw_connector', [
    'platform_url' => 'https://aiwm.example.invalid',
    'identity' => $identity,
    'secret' => $secret,
    'protocol_version' => AIMW_Connector_V1::PROTOCOL_VERSION,
    'tenant_id' => $tenant,
    'site_id' => $site,
    'enabled_scopes' => ['health', 'content.read'],
    'revoked' => false,
    'local_enabled' => true,
    'owner_user_id' => 1,
], false);

$fail = static function (string $label, int $expected, int $actual): void {
    if ($actual !== $expected) {
        throw new RuntimeException(sprintf('%s: expected HTTP %d, got %d', $label, $expected, $actual));
    }
};

$make = static function (string $route, string $scope, array $override = []) use ($secret, $identity, $tenant, $site): WP_REST_Request {
    $request = new WP_REST_Request('GET', $route);
    $request->set_body('');
    $fields = [
        'X-AIMW-Version' => AIMW_Connector_V1::PROTOCOL_VERSION,
        'X-AIMW-Tenant' => $tenant,
        'X-AIMW-Site' => $site,
        'X-AIMW-Connector' => $identity,
        'X-AIMW-Timestamp' => (string) time(),
        'X-AIMW-Nonce' => wp_generate_uuid4(),
        'X-AIMW-Request-ID' => wp_generate_uuid4(),
        'X-AIMW-Correlation-ID' => wp_generate_uuid4(),
        'X-AIMW-Operation-ID' => wp_generate_uuid4(),
        'X-AIMW-Scope' => $scope,
    ];
    foreach ($override as $key => $value) {
        if ($key !== 'X-AIMW-Signature') {
            $fields[$key] = $value;
        }
    }
    $canonical = implode("\n", array_merge(
        ['GET', '/wp-json'.$route, hash('sha256', '')],
        array_values($fields),
    ));
    $fields['X-AIMW-Signature'] = hash_hmac('sha256', $canonical, $secret);
    if (array_key_exists('X-AIMW-Signature', $override)) {
        $fields['X-AIMW-Signature'] = $override['X-AIMW-Signature'];
    }

    $request->set_headers($fields);

    return $request;
};

try {
    $valid = $make('/aimw/v1/health', 'health');
    $internalHeaders = $valid->get_headers();
    if (! isset($internalHeaders['x_aimw_version']) || isset($internalHeaders['x-aimw-version'])) {
        throw new RuntimeException('Test is not exercising canonicalized WordPress REST headers.');
    }

    $response = rest_do_request($valid);
    $fail('valid signed health request', 200, $response->get_status());
    if (! is_array($response->get_data()) || ($response->get_data()['status'] ?? null) !== 'healthy') {
        throw new RuntimeException('Signed health payload does not match expected runtime response.');
    }
    echo "CONNECTOR_CANONICAL_HEADER_AUTH=PASS\n";

    $content = rest_do_request($make('/aimw/v1/content', 'content.read'));
    $fail('content.read signed request', 200, $content->get_status());
    echo "CONNECTOR_SIGNED_CONTENT_READ=PASS\n";

    $wrongIdentity = rest_do_request($make('/aimw/v1/health', 'health', ['X-AIMW-Connector' => wp_generate_uuid4()]));
    $fail('foreign connector identity', 401, $wrongIdentity->get_status());

    $wrongSite = rest_do_request($make('/aimw/v1/health', 'health', ['X-AIMW-Site' => '100']));
    $fail('foreign site binding', 401, $wrongSite->get_status());

    $wrongVersion = rest_do_request($make('/aimw/v1/health', 'health', ['X-AIMW-Version' => '999']));
    $fail('unsupported protocol version', 401, $wrongVersion->get_status());

    $tampered = rest_do_request($make('/aimw/v1/health', 'health', ['X-AIMW-Signature' => str_repeat('0', 64)]));
    $fail('tampered signature', 401, $tampered->get_status());

    $expired = rest_do_request($make('/aimw/v1/health', 'health', ['X-AIMW-Timestamp' => (string) (time() - 601)]));
    $fail('expired signed timestamp', 401, $expired->get_status());

    $disabled = rest_do_request($make('/aimw/v1/history', 'audit.local'));
    $fail('disabled scope', 403, $disabled->get_status());

    $replay = rest_do_request($valid);
    $fail('replayed nonce', 409, $replay->get_status());

    echo "CONNECTOR_PROTOCOL_REJECTION_GATES=PASS\n";
} finally {
    update_option('aimw_connector', $original, false);
}
