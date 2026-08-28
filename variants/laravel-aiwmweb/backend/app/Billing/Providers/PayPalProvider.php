<?php

namespace App\Billing\Providers;

use App\Billing\Exceptions\InvalidProviderSignatureException;
use App\Models\BillingPlan;
use App\Models\BillingProviderCredential;
use App\Models\TenantSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayPalProvider implements BillingProvider
{
    public function name(): string
    {
        return 'paypal';
    }

    private function credentials(): array
    {
        $stored = BillingProviderCredential::query()->where('provider', 'paypal')->first()?->encrypted_credentials ?? [];

        return ['client_id' => $stored['client_id'] ?? config('billing.paypal.client_id'), 'client_secret' => $stored['client_secret'] ?? config('billing.paypal.client_secret'), 'webhook_id' => $stored['webhook_id'] ?? config('billing.paypal.webhook_id')];
    }

    public function configured(): bool
    {
        $c = $this->credentials();

        return filled($c['client_id']) && filled($c['client_secret']) && filled($c['webhook_id']);
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new RuntimeException('PayPal is not configured.');
        }
    }

    private function token(): string
    {
        $this->ensureConfigured();
        $c = $this->credentials();
        $r = Http::asForm()->withBasicAuth($c['client_id'], $c['client_secret'])->timeout(20)->post(rtrim(config('billing.paypal.base_url'), '/').'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        if (! $r->successful() || ! filled($r->json('access_token'))) {
            throw new RuntimeException('PayPal authentication failed.');
        }

        return (string) $r->json('access_token');
    }

    private function auth()
    {
        return Http::withToken($this->token())->acceptJson()->asJson()->timeout(30);
    }

    public function createSubscriptionIntent(TenantSubscription $subscription, BillingPlan $plan): array
    {
        if (! $plan->commerciallyConfigured() || $plan->provider !== 'paypal') {
            throw new RuntimeException('Plan is not configured for PayPal checkout.');
        } $r = $this->auth()->post(rtrim(config('billing.paypal.base_url'), '/').'/v1/billing/subscriptions', ['plan_id' => $plan->provider_plan_id, 'custom_id' => 'aimw-sub-'.$subscription->id, 'application_context' => ['return_url' => config('billing.paypal.return_url'), 'cancel_url' => config('billing.paypal.cancel_url'), 'user_action' => 'SUBSCRIBE_NOW']]);
        if (! $r->successful()) {
            throw new RuntimeException('PayPal subscription intent failed.');
        } $approval = collect($r->json('links', []))->firstWhere('rel', 'approve');
        if (! filled($r->json('id')) || ! filled($approval['href'] ?? null)) {
            throw new RuntimeException('PayPal intent response is incomplete.');
        }

        return ['provider_subscription_id' => (string) $r->json('id'), 'approval_url' => $approval['href'], 'status' => (string) $r->json('status', 'APPROVAL_PENDING')];
    }

    public function changeSubscription(TenantSubscription $subscription, BillingPlan $plan): array
    {
        $id = $subscription->encrypted_provider_subscription_id;
        if (! $id || ! $plan->provider_plan_id) {
            throw new RuntimeException('PayPal plan change cannot be requested.');
        } $r = $this->auth()->post(rtrim(config('billing.paypal.base_url'), '/').'/v1/billing/subscriptions/'.rawurlencode($id).'/revise', ['plan_id' => $plan->provider_plan_id]);
        if (! $r->successful()) {
            throw new RuntimeException('PayPal plan change failed.');
        }

        return ['requested' => true];
    }

    public function cancelSubscription(TenantSubscription $subscription): void
    {
        $id = $subscription->encrypted_provider_subscription_id;
        if (! $id) {
            throw new RuntimeException('PayPal subscription id is unavailable.');
        } $r = $this->auth()->post(rtrim(config('billing.paypal.base_url'), '/').'/v1/billing/subscriptions/'.rawurlencode($id).'/cancel', ['reason' => 'Customer requested cancellation']);
        if (! $r->successful() && $r->status() !== 204) {
            throw new RuntimeException('PayPal cancellation failed.');
        }
    }

    public function verifyAndParseWebhook(Request $request): array
    {
        $this->ensureConfigured();
        $event = $request->json()->all();
        $verify = $this->auth()->post(rtrim(config('billing.paypal.base_url'), '/').'/v1/notifications/verify-webhook-signature', ['auth_algo' => $request->header('PAYPAL-AUTH-ALGO'), 'cert_url' => $request->header('PAYPAL-CERT-URL'), 'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'), 'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'), 'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'), 'webhook_id' => $this->credentials()['webhook_id'], 'webhook_event' => $event]);
        if (! $verify->successful() || strtoupper((string) $verify->json('verification_status')) !== 'SUCCESS') {
            throw new InvalidProviderSignatureException('Invalid PayPal webhook signature.');
        } $resource = (array) ($event['resource'] ?? []);
        $billingInfo = (array) ($resource['billing_info'] ?? []);
        $lastPayment = (array) ($billingInfo['last_payment'] ?? []);
        $amount = (array) ($lastPayment['amount'] ?? []);

        return ['id' => (string) ($event['id'] ?? ''), 'type' => (string) ($event['event_type'] ?? ''), 'provider_subscription_id' => (string) ($resource['billing_agreement_id'] ?? ($resource['id'] ?? '')), 'provider_plan_id' => (string) ($resource['plan_id'] ?? ''), 'occurred_at' => (string) ($event['create_time'] ?? now()->toAtomString()), 'transaction_id' => (string) ($lastPayment['id'] ?? ($resource['id'] ?? '')), 'amount_minor' => isset($amount['value']) ? (int) round(((float) $amount['value']) * 100) : null, 'currency' => $amount['currency_code'] ?? null, 'payload_hash' => hash('sha256', $request->getContent())];
    }

    public function reconcile(TenantSubscription $subscription): array
    {
        $id = $subscription->encrypted_provider_subscription_id;
        if (! $id) {
            throw new RuntimeException('PayPal subscription id is unavailable.');
        } $r = $this->auth()->get(rtrim(config('billing.paypal.base_url'), '/').'/v1/billing/subscriptions/'.rawurlencode($id));
        if (! $r->successful()) {
            throw new RuntimeException('PayPal reconciliation failed.');
        }

        return ['status' => (string) $r->json('status'), 'provider_plan_id' => (string) $r->json('plan_id'), 'occurred_at' => now()->toAtomString()];
    }
}
