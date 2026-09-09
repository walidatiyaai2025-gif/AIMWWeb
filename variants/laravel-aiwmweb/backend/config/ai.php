<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI generation quota
    |--------------------------------------------------------------------------
    |
    | A null daily limit means that this deployment does not impose an
    | application-level daily cap. Commercial plans may still provide the
    | canonical `ai_generations_daily` limit through BillingPlan::limits.
    |
    */
    'daily_generation_limit' => env('AI_DAILY_GENERATION_LIMIT'),
];
