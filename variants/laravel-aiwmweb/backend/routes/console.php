<?php

use App\Operations\OperationsControlPlaneService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ops:dispatch-due', function (OperationsControlPlaneService $operations) {
    $count = $operations->dispatchDueSchedules();
    $this->info("Queued {$count} due tenant operation(s).");
})->purpose('Queue due tenant-scoped scheduled operations');

Schedule::command('ops:dispatch-due')->everyMinute()->withoutOverlapping();
