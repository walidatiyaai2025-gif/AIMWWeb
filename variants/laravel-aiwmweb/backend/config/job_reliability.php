<?php

return [
    'pause_after_failures' => filter_var(env('JOBS_PAUSE_AFTER_FAILURES', true), FILTER_VALIDATE_BOOL),
    'consecutive_failures_before_pause' => min(20, max(1, (int) env('JOBS_CONSECUTIVE_FAILURES_BEFORE_PAUSE', 3))),
    'failure_pause_minutes' => min(1440, max(1, (int) env('JOBS_FAILURE_PAUSE_MINUTES', 15))),
    'auto_resume_after_pause' => filter_var(env('JOBS_AUTO_RESUME_AFTER_PAUSE', true), FILTER_VALIDATE_BOOL),
];
