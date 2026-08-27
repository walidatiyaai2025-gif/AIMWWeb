<?php

use App\Sync\SyncFallbackReconciler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sync:reconcile-stale {--limit=200}', function (SyncFallbackReconciler $fallback) {
    $result = $fallback->dispatchDue((int) $this->option('limit'));
    $this->line(json_encode($result, JSON_THROW_ON_ERROR));
})->purpose('Queue bounded fallback reconciliation for stale WordPress sites');

Schedule::command('sync:reconcile-stale --limit=200')
    ->everyFifteenMinutes()
    ->withoutOverlapping(14);
