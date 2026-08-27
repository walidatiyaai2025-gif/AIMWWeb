<?php

use App\Sync\SyncFallbackReconciler;
use App\Operations\OperationsControlPlaneService;
use App\Billing\BillingMaintenanceService;
use App\Models\BillingProviderCredential;
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

Artisan::command('ops:dispatch-due', function (OperationsControlPlaneService $operations) {
    $count = $operations->dispatchDueSchedules();
    $this->info("Queued {$count} due tenant operation(s).");
})->purpose('Queue due tenant-scoped scheduled operations');

Schedule::command('ops:dispatch-due')->everyMinute()->withoutOverlapping();

Artisan::command('billing:store-paypal-credentials', function () {
    $credentials = ['client_id' => config('billing.paypal.client_id'), 'client_secret' => config('billing.paypal.client_secret'), 'webhook_id' => config('billing.paypal.webhook_id')];
    if (collect($credentials)->contains(fn ($v) => blank($v))) {
        throw new RuntimeException('PayPal credentials are incomplete.');
    }BillingProviderCredential::query()->updateOrCreate(['provider' => 'paypal'], ['encrypted_credentials' => $credentials]);
    $this->info('PayPal credentials stored encrypted.');
})->purpose('Persist PayPal credentials using Laravel encrypted casts');
Artisan::command('billing:maintain', function (BillingMaintenanceService $service) {
    $this->line(json_encode($service->run(), JSON_THROW_ON_ERROR));
})->purpose('Advance billing lifecycle and reconcile provider state');
Schedule::command('billing:maintain')->everyTenMinutes()->withoutOverlapping(9)->onOneServer();
