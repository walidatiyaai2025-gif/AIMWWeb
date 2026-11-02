<?php

return [
    // Fail closed until an administrator deploys the initial updater and public signing key.
    'enabled' => (bool) env('AIWM_UPDATES_ENABLED', false),
    'public_key' => env('AIWM_UPDATES_PUBLIC_KEY', ''),
    'current_version' => env('APP_VERSION', '0.0.0'),
    // Configure when cPanel's document root is outside the Laravel app directory.
    'public_root' => env('AIWM_UPDATES_PUBLIC_ROOT', ''),
];
