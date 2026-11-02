<?php

return [
    // Fail closed until an administrator deploys the initial updater and public signing key.
    'enabled' => (bool) env('AIWM_UPDATES_ENABLED', false),
    'public_key' => env('AIWM_UPDATES_PUBLIC_KEY', ''),
    'current_version' => env('APP_VERSION', '0.0.0'),
];
