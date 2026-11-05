<?php

return [
    'enabled' => is_file(base_path('.cpanel-profile')) || env('AIMW_RUNTIME_PROFILE', 'standard') === 'cpanel',
    'ai_enabled' => env('CPANEL_AI_ENABLED', false),
    'cron_enabled' => env('CPANEL_CRON_ENABLED', false),
    'preflight_passed' => env('CPANEL_PREFLIGHT_PASSED', false),
    'max_queued_jobs' => (int) env('CPANEL_MAX_QUEUED_JOBS', 20),
    'max_pending_actions' => (int) env('CPANEL_MAX_PENDING_AI_ACTIONS', 500),
    'provider_timeout' => 30,
    'lease_seconds' => 90,
    'min_interval' => 5,
    'daily_calls' => (int) env('CPANEL_AI_DAILY_CALLS', 100),
    'daily_tokens' => (int) env('CPANEL_AI_DAILY_TOKENS', 100000),
    'batch_budget_usd' => (float) env('CPANEL_AI_BATCH_BUDGET_USD', 1),
    'daily_budget_usd' => (float) env('CPANEL_AI_DAILY_BUDGET_USD', 1),
    'call_reserve_usd' => (float) env('CPANEL_AI_CALL_RESERVE_USD', 0.1),
];
