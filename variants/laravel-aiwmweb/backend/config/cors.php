<?php

$origins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:8080')),
)));

return [
    'paths' => ['api/*', 'health/*', 'tenants/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Correlation-ID',
        'X-Request-ID',
        'X-Requested-With',
    ],
    'exposed_headers' => ['X-Correlation-ID', 'X-Request-ID'],
    'max_age' => 600,
    'supports_credentials' => true,
];
