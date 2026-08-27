<?php
use App\Billing\BillingMaintenanceService;
use App\Models\BillingProviderCredential;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
Artisan::command('inspire',function(){$this->comment(Inspiring::quote());})->purpose('Display an inspiring quote');
Artisan::command('billing:store-paypal-credentials',function(){$credentials=['client_id'=>config('billing.paypal.client_id'),'client_secret'=>config('billing.paypal.client_secret'),'webhook_id'=>config('billing.paypal.webhook_id')];if(collect($credentials)->contains(fn($v)=>blank($v)))throw new RuntimeException('PayPal credentials are incomplete.');BillingProviderCredential::query()->updateOrCreate(['provider'=>'paypal'],['encrypted_credentials'=>$credentials]);$this->info('PayPal credentials stored encrypted.');})->purpose('Persist PayPal credentials using Laravel encrypted casts');
Artisan::command('billing:maintain',function(BillingMaintenanceService $service){$this->line(json_encode($service->run(),JSON_THROW_ON_ERROR));})->purpose('Advance billing lifecycle and reconcile provider state');
Schedule::command('billing:maintain')->everyTenMinutes()->withoutOverlapping(9)->onOneServer();
