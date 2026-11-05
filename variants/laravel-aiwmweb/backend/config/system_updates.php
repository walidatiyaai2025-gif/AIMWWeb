<?php

return [
    // Fail closed until an administrator deploys the initial updater and public signing key.
    'enabled' => (bool) env('AIWM_UPDATES_ENABLED', false),
    // Explicit opt-in; never auto-apply production changes merely because a release exists.
    'auto_pull_enabled' => (bool) env('AIWM_AUTO_UPDATES_ENABLED', false),
    'public_key' => env('AIWM_UPDATES_PUBLIC_KEY', ''),
    'current_version' => env('APP_VERSION', '0.0.0'),
    // Configure when cPanel's document root is outside the Laravel app directory.
    'public_root' => env('AIWM_UPDATES_PUBLIC_ROOT', ''),
];
