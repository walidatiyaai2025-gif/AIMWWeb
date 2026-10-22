# Laravel AIWMWeb Billing Platform

The database plan catalog is the only pricing/entitlement source used by public pricing, tenant billing APIs and enforcement. Paid seed plans intentionally have no price or PayPal plan mapping until product administration supplies approved commercial values; checkout remains unavailable instead of fabricating production pricing.

## APIs
- `GET /api/v1/billing/plans` — public enabled plan catalog.
- `/api/v1/tenants/{tenant}/billing/*` — authenticated tenant billing APIs; `billing.view` or `billing.manage` permissions are required.
- `/api/v1/billing/admin/plans/*` — platform-admin plan management.
- `POST /api/v1/billing/webhooks/paypal` — PayPal verified webhook ingress; browser return URLs never activate a subscription.

## Entitlements and quotas
Inject `EntitlementService` to assert features and `UsageQuotaService` to atomically consume monthly counters. Worker C can consume `ai.requests.month` and `ai.tokens.month` without reading billing tables directly.

## PayPal
Secrets can be persisted using `php artisan billing:store-paypal-credentials`, which uses Laravel encrypted casts; environment values are a secret-store fallback. Provider subscription and transaction identifiers are encrypted at rest and never returned by tenant APIs. Webhooks are verified through PayPal's verification endpoint and deduplicated by a hash of the provider event id.

## Scheduled lifecycle
Run the Laravel scheduler. `billing:maintain` expires trials, advances grace states, applies due downgrade requests, and reconciles provider subscriptions when PayPal is configured.
