# Email Delivery Parity Closure

Authority: Issue #257  
Canonical ledger: `variants/laravel-aiwmweb/docs/capability-parity-ledger.json` @ blob `fcad8c0386bbb747aa1af943ae174d14d49d0c17`  
Canonical denominator: 931 operations; Email denominator: **82**.  
Core provenance: `f88e41f9b74442cbb9666f5618c9845c2ac48a9a`.  
Closure baseline: `bd6d272748753e21b65655901eaed8bb65a267c7`.  
Classification: **PORTED 0 / ADAPTED 33 / PENDING 49 / BLOCKED 0 / VERIFIED_UNAVAILABLE_EXTERNAL 0**.  
Evidence-backed Email parity: **40.24% (33/82)**.

Billing #266 @ `3c2e2904457b18882f0a168857ed149c067bdd1c` does not expose a stable Laravel domain-event publisher. `DomainNotificationBridge::billing()` remains an explicit fail-closed adapter and this PR does not duplicate Billing state. Sync #275 @ `bdc05a52ff0e600f45ab0714c9ddd688e77fddab` exposes the actual string-event contract `SyncStarted`, `SyncFailed`, `SyncCompleted` with `(SyncRun, payload)`; `SyncNotificationSubscriber` consumes exactly that shape.

Aggregate digest composition, dedicated recipient CRUD, frontend controls/routes, dismiss/prune, per-delivery detail and unbound security-publisher behavior are not inferred.

## Evidence profiles

All terminal rows below reference one of these profiles. The implementation provenance is the core SHA above; closure verification is `variants/laravel-aiwmweb/backend/tests/Feature/EmailDeliveryAcceptanceTest.php` plus the feature PR exact-head CI.

**A — operational event bridge**  
Destination: `DomainNotificationBridge` + `NotificationPlatformService`. Route/API: internal event adapter plus notification-center API. Service: `operational()` / `consume()`. Persistence: `notification_event_receipts`, `in_app_notifications`, `email_deliveries`. Tenant/permission: active tenant membership + `BelongsToTenant`; notification API requires `tenant.view`. Queue/transport: optional `EmailDeliveryService::queue()` → `SendEmailDeliveryJob` → `EmailTransport`. Verification: `test_domain_event_bridges_cover_billing_sync_and_job_failures`.

**S — Sync event bridge**  
Destination: `SyncNotificationSubscriber` + `DomainNotificationBridge`. Route/API: consumes #275 exact string events. Service: `subscribe()` / `handle()` / `sync()`. Persistence: receipt, in-app notification, optional delivery. Tenant/permission: event `tenant_id` must equal `TenantContext`; initiating user must be active member. Queue/transport: tenant-aware delivery job. Verification: domain-event bridge test. External contract: PR #275 @ `bdc05a52ff0e600f45ab0714c9ddd688e77fddab`.

**Q — queued delivery/outbox**  
Destination: `EmailDeliveryService`. Route/API: internal queue/send; history exposed under tenant Email API. Service: `queue()` / `send()`. Persistence: `email_deliveries`, tenant-scoped unique idempotency key and truthful state/timestamps. Tenant/permission: `BelongsToTenant` + `TenantJobMiddleware`. Queue/transport: `SendEmailDeliveryJob` + per-delivery `TenantLock` + `EmailTransport`, bounded transient retry. Verification: delivery, retry, duplicate and repeated-send tests.

**N — notification center API**  
Destination: `EmailNotificationController` + `NotificationPlatformService`. Route/API: `/api/v1/tenants/{tenant}/notifications*`. Service: list, unread count, mark read, mark all read. Persistence: `in_app_notifications`. Tenant/permission: `tenant.context`, `tenant.view`, current-user filter, `BelongsToTenant`. Queue/transport: none for reads. Verification: notification-center and IDOR tests.

**C — account mail configuration**  
Destination: `MailConfigurationService` + `EmailSecretStore`. Route/API: GET/PUT tenant Email configuration. Service: `get()` / `save()` / secret put-clear. Persistence: `mail_configurations` + encrypted/hidden `tenant_secrets`. Tenant/permission: `tenant.manage`, tenant scope; `site_id` ownership is validated when supplied. Queue/transport: configuration is consumed by delivery transport. Verification: configuration, secret-redaction and IDOR tests.

**D — configuration diagnostics**  
Destination: `MailConfigurationService::diagnose()`. Route/API: POST tenant Email configuration diagnose. Service: `EmailTransport::diagnose()`. Persistence: configuration + encrypted secret. Tenant/permission: `tenant.manage` + tenant scope. Queue/transport: direct diagnostic only. Verification: diagnostics and secret-omission test.

**H — delivery history**  
Destination: `EmailDeliveryService::history()`. Route/API: GET tenant Email deliveries. Persistence: `email_deliveries`. Tenant/permission: `tenant.manage` + `BelongsToTenant`. Queue/transport: read-only. Verification: history, redaction and cross-tenant history tests.

**W — outbox worker**  
Destination: `SendEmailDeliveryJob`. Service: `handle()` → `EmailDeliveryService::send()`. Persistence: deliveries + `audit_events`. Tenant/permission: `TenantAwareJob` / `TenantJobMiddleware`; worker-safe system audit when no authenticated membership exists. Queue/transport: `ShouldBeUnique`, `TenantLock`, transport. Verification: queue TenantContext and idempotency-isolation tests.

**G — scheduling**  
Destination: `EmailScheduleService` + `RunEmailSchedulesJob`. Service: `save()` / `all()` / `dispatchDue()` / `queueWorker()`. Persistence: `email_schedules` + deliveries. Tenant/permission: tenant scope plus site ownership validation. Queue/transport: deterministic schedule-run idempotency key → delivery job. Verification: `test_due_schedules_queue_once_and_validate_site_ownership`. No schedule UI parity is claimed.

**I — notification persistence service**  
Destination: `NotificationPlatformService::consume()`. Persistence: event receipt + in-app notification + optional delivery. Tenant/permission: active tenant membership + `BelongsToTenant`. Queue/transport: server preference controls immediate/digest-window/suppressed delivery. Verification: notification/preference/domain-event tests.

**SC — site-keyed mail configuration**  
Destination: site-keyed `MailConfigurationService`. Route/API: tenant Email configuration API. Persistence: configuration + encrypted secret. Tenant/permission: `tenant.manage`; `Site::query()->findOrFail()` under tenant scope prevents foreign association. Queue/transport: configuration consumable by delivery path. Verification: site ownership/configuration test.

**SD — site-keyed diagnostics**  
Destination: site-keyed configuration diagnostics. Service: `MailConfigurationService::diagnose()` → transport diagnostic. Persistence: config + secret. Tenant/permission: `tenant.manage`, tenant/site ownership. Verification: diagnostic/secret-omission test.

## Exact 82-row classification

`-` means PENDING and therefore no terminal evidence is claimed.

| Operation ID | Status | Evidence profile |
|---|---|---|
| AIMW-EMAI-C4D7E4214E | ADAPTED | A |
| AIMW-EMAI-DD9E2A6D60 | ADAPTED | A |
| AIMW-EMAI-E80950F9CB | ADAPTED | A |
| AIMW-EMAI-2A656DC035 | ADAPTED | S |
| AIMW-EMAI-DD177E164D | ADAPTED | S |
| AIMW-EMAI-5E1DA90CDD | ADAPTED | Q |
| AIMW-EMAI-0750D0FEEA | ADAPTED | Q |
| AIMW-EMAI-D2E518F62D | ADAPTED | Q |
| AIMW-EMAI-C6BF2CB6ED | ADAPTED | Q |
| AIMW-EMAI-501A1DEF36 | ADAPTED | Q |
| AIMW-EMAI-470599356B | PENDING | - |
| AIMW-EMAI-77C637E3E3 | PENDING | - |
| AIMW-EMAI-62B0B8EE4C | PENDING | - |
| AIMW-EMAI-8E3B0AACD6 | PENDING | - |
| AIMW-EMAI-0AA71A5EF6 | PENDING | - |
| AIMW-EMAI-2E59E39808 | PENDING | - |
| AIMW-EMAI-A9CB66F400 | PENDING | - |
| AIMW-EMAI-54E7EEFB15 | PENDING | - |
| AIMW-EMAI-10F3C44369 | PENDING | - |
| AIMW-EMAI-00CC1272F6 | PENDING | - |
| AIMW-EMAI-B2CFCF818C | PENDING | - |
| AIMW-EMAI-7A54150265 | PENDING | - |
| AIMW-EMAI-F8E8A2BEE9 | PENDING | - |
| AIMW-EMAI-8C9768BCD0 | PENDING | - |
| AIMW-EMAI-78352CD34E | PENDING | - |
| AIMW-EMAI-EC34E40629 | PENDING | - |
| AIMW-EMAI-F77DB6435F | PENDING | - |
| AIMW-EMAI-BDF888551C | PENDING | - |
| AIMW-EMAI-2E95AF6C05 | PENDING | - |
| AIMW-EMAI-12A6FEB2FF | PENDING | - |
| AIMW-EMAI-8E56589573 | PENDING | - |
| AIMW-EMAI-E4EEF1914B | PENDING | - |
| AIMW-EMAI-7F2D7C5921 | PENDING | - |
| AIMW-EMAI-7E1D4105B0 | PENDING | - |
| AIMW-EMAI-BFDC050625 | PENDING | - |
| AIMW-EMAI-01F5713C4F | PENDING | - |
| AIMW-EMAI-9EB09C490D | PENDING | - |
| AIMW-EMAI-2D94EFDD53 | ADAPTED | N |
| AIMW-EMAI-316EE6ABC9 | ADAPTED | N |
| AIMW-EMAI-88E5A6EBAB | PENDING | - |
| AIMW-EMAI-10A5F850E9 | ADAPTED | C |
| AIMW-EMAI-AF857CDA3C | PENDING | - |
| AIMW-EMAI-037471B2F6 | ADAPTED | C |
| AIMW-EMAI-F796B7C5EB | ADAPTED | C |
| AIMW-EMAI-8E1468A262 | ADAPTED | D |
| AIMW-EMAI-0225E8C170 | ADAPTED | C |
| AIMW-EMAI-2DC45863CD | PENDING | - |
| AIMW-EMAI-DE62420D1D | PENDING | - |
| AIMW-EMAI-8847629582 | PENDING | - |
| AIMW-EMAI-841917DFB1 | PENDING | - |
| AIMW-EMAI-69ACF14687 | PENDING | - |
| AIMW-EMAI-8802F21A0D | PENDING | - |
| AIMW-EMAI-FF4BD609EE | PENDING | - |
| AIMW-EMAI-3A8713DD02 | PENDING | - |
| AIMW-EMAI-08184E05B6 | ADAPTED | H |
| AIMW-EMAI-7AEB3D1733 | PENDING | - |
| AIMW-EMAI-54C9C8558F | ADAPTED | W |
| AIMW-EMAI-AC917AA0F0 | ADAPTED | G |
| AIMW-EMAI-BF0FD6EFDB | ADAPTED | G |
| AIMW-EMAI-1254BBE09D | PENDING | - |
| AIMW-EMAI-26A1BBFA7E | ADAPTED | G |
| AIMW-EMAI-74E4DF6678 | PENDING | - |
| AIMW-EMAI-547A43D2DA | PENDING | - |
| AIMW-EMAI-583800E2B4 | PENDING | - |
| AIMW-EMAI-2C339F8577 | ADAPTED | G |
| AIMW-EMAI-8B45B1833F | ADAPTED | G |
| AIMW-EMAI-69DD8BD3C1 | ADAPTED | I |
| AIMW-EMAI-57A304822D | PENDING | - |
| AIMW-EMAI-7811B08205 | ADAPTED | N |
| AIMW-EMAI-A27C067345 | ADAPTED | N |
| AIMW-EMAI-D6D9A0FE42 | ADAPTED | N |
| AIMW-EMAI-1EF37BC8B8 | PENDING | - |
| AIMW-EMAI-BD96E2D5F9 | PENDING | - |
| AIMW-EMAI-96BBA8D8B5 | PENDING | - |
| AIMW-EMAI-073B44CD76 | PENDING | - |
| AIMW-EMAI-DE3ECADFFC | PENDING | - |
| AIMW-EMAI-845CE312CC | PENDING | - |
| AIMW-EMAI-351046F246 | ADAPTED | SC |
| AIMW-EMAI-AFD4ADAF6A | ADAPTED | SC |
| AIMW-EMAI-AA70A6FF87 | ADAPTED | SC |
| AIMW-EMAI-CC3AE08A87 | ADAPTED | SD |
| AIMW-EMAI-B2D6A96405 | ADAPTED | SC |
