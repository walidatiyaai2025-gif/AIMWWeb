# Email, Notifications, Subscriptions & Billing Implementation Plan

**Repository:** `walidatiyaai2025-gif/AIMWWeb`  
**Branch:** `main`  
**Status:** Approved scope / planned  
**Related phases:** 10 (Email communications), 11 (Subscriptions & billing)

This document defines the implementation contract for site-level email settings, dashboard/account email settings, scheduled email delivery, and subscription billing beginning with PayPal.

---

## 1. Product requirements

### Site email settings

Each owned WordPress site must support:

- Between 1 and 3 notification recipient email addresses.
- Per-recipient enable/disable state.
- Recipient display label/name.
- Validation and duplicate prevention.
- A site-level outbound mail profile when the customer wants that site to use its own sender configuration.
- Ability to inherit the account/dashboard mail profile instead of duplicating credentials.
- Test-send before enabling schedules.
- Audit trail for recipient and mail-profile changes.

### Dashboard/account email settings

Each application account must support:

- Dashboard notification recipients independent of site recipients.
- Account/dashboard outbound mail profile.
- Digest schedule independent from site report schedules.
- Notifications for account-level events, billing events, security events, and consolidated site status.

### Scheduling

Users must be able to schedule:

- Individual site operational report.
- Individual site SEO summary.
- Individual site synchronization/failure digest.
- Dashboard/global digest covering all owned sites.

A schedule contains:

- Owner user ID.
- Optional SiteId for site-specific reports.
- Report/template type.
- Timezone.
- Frequency: hourly, daily, weekly, monthly; custom schedules can be added later behind the same abstraction.
- Time of day / weekday / month-day as applicable.
- Enabled state.
- Retry count and retry delay policy.
- Last run, next run, last status, last error.
- Recipient snapshot or recipient-group reference.

No schedule may run using a different tenant's mail settings or recipients.

---

## 2. Recommended domain model

Suggested entities/value objects. Exact names may be adjusted to match existing conventions, but responsibilities must remain separate.

### `SiteEmailRecipient`

- `Id`
- `OwnerUserId`
- `SiteId`
- `EmailAddress`
- `DisplayName`
- `IsEnabled`
- `CreatedAtUtc`
- `UpdatedAtUtc`

Rules:

- Maximum three enabled recipients per site.
- Case-insensitive duplicate email prevention within one site.
- Site ownership required for read/write.

### `EmailProfile`

Supports either account/dashboard or site scope.

- `Id`
- `OwnerUserId`
- `SiteId` nullable
- `Scope` (`Account`, `Site`)
- `ProviderType` initially `Smtp`
- `FromName`
- `FromAddress`
- `ReplyToAddress`
- `SmtpHost`
- `SmtpPort`
- `SecurityMode` (`Tls`, `StartTls`, `None` only if explicitly allowed)
- `UserName`
- `ProtectedPasswordOrSecret`
- `IsEnabled`
- `LastTestedAtUtc`
- `LastTestStatus`
- `LastTestError`
- timestamps

Secrets must use the existing secret-protection service and must never be returned to the UI after storage.

### `AccountEmailRecipient`

- `Id`
- `OwnerUserId`
- `EmailAddress`
- `DisplayName`
- `IsEnabled`
- timestamps

A practical initial limit is also three dashboard recipients, but keep the domain rule configurable.

### `EmailSchedule`

- `Id`
- `OwnerUserId`
- `SiteId` nullable
- `Scope`
- `ReportType`
- `TemplateKey`
- `TimezoneId`
- `Frequency`
- schedule fields
- `Enabled`
- `RetryCount`
- `NextRunUtc`
- `LastRunUtc`
- `LastStatus`
- `LastError`
- timestamps

### `EmailOutboxMessage`

- `Id`
- `OwnerUserId`
- `SiteId` nullable
- `ScheduleId` nullable
- `TemplateKey`
- `Subject`
- rendered body or payload snapshot
- recipient snapshot
- `IdempotencyKey`
- `CorrelationId`
- `Status` (`Queued`, `Sending`, `Sent`, `RetryWaiting`, `Failed`, `Cancelled`)
- `AttemptCount`
- `NextAttemptAtUtc`
- `CreatedAtUtc`
- `SentAtUtc`
- `LastError`

### `EmailDeliveryAttempt`

- `Id`
- `OutboxMessageId`
- `AttemptNumber`
- `StartedAtUtc`
- `FinishedAtUtc`
- `Status`
- provider response summary
- error category and sanitized error message

Do not store passwords, SMTP authentication headers, or full sensitive provider payloads in history.

---

## 3. Email application services

Introduce clear abstractions:

```text
IEmailSender
IEmailProfileResolver
IEmailTemplateRenderer
IEmailOutbox
IEmailScheduleService
IEmailDeliveryHistoryService
```

`IEmailSender` must not know how tenants are selected. It receives an already authorized/resolved mail profile and immutable message request.

`IEmailProfileResolver` resolves site profile first when enabled; otherwise it may fall back to the owner's account profile.

All background workers must carry `OwnerUserId` explicitly.

---

## 4. Email UI

### Site Settings → Email tab

Sections:

1. **Recipients**
   - Recipient 1, Recipient 2, Recipient 3.
   - Add/edit/disable/remove.
   - Validation and duplicate feedback.

2. **Sender configuration**
   - Use dashboard/account sender toggle.
   - SMTP host.
   - Port.
   - TLS/security mode.
   - Username.
   - Password/secret entry.
   - From name/address.
   - Reply-to.
   - Test connection/test email.

3. **Schedules**
   - List schedules.
   - Add/edit/enable/disable/run-now.
   - Show next run and last result.

4. **History**
   - Sent/failed messages.
   - Recipient summary.
   - Attempts and diagnostic message.

### Account/Dashboard Settings → Email

Same sender controls but account-scoped, plus:

- Dashboard recipients.
- Dashboard digest schedule.
- Account/security alert toggles.
- Billing notification toggles.

---

## 5. Email scheduling and outbox architecture

Never send email directly from a page action or schedule timer.

Required flow:

```text
Schedule/Event
  → create immutable EmailOutboxMessage
  → commit transaction
  → background delivery worker claims message
  → resolve authorized profile
  → render or use stored render snapshot
  → send
  → record attempt
  → mark Sent or RetryWaiting/Failed
```

Important guarantees:

- Claiming must be atomic enough to prevent duplicate sends after concurrent worker ticks.
- Use an idempotency key for scheduled runs and event-triggered alerts.
- Restart must recover messages stuck in `Sending` according to a documented recovery policy.
- Retry only retryable failures.
- Permanent authentication/configuration failures should stop repeated aggressive retries and surface a diagnostic notification.
- Outbox retention must be configurable.

---

## 6. Email templates

Initial template keys:

- `site.daily-summary`
- `site.weekly-summary`
- `site.sync-failure`
- `site.job-failure`
- `site.seo-summary`
- `dashboard.daily-digest`
- `dashboard.weekly-digest`
- `account.security-alert`
- `billing.subscription-created`
- `billing.payment-failed`
- `billing.subscription-cancelled`
- `billing.subscription-renewed`

Templates require Arabic and English rendering and must escape dynamic HTML content.

---

## 7. Subscription architecture

Billing must be split into three layers:

```text
Plan Catalog + Entitlements
        ↓
Subscription Domain State
        ↓
Payment Gateway Adapter
        ↓
PayPal first; other gateways later
```

Business rules must never call PayPal directly from UI components.

### `SubscriptionPlan`

Recommended fields:

- `Id`
- `Code`
- localized display name/description
- `BillingInterval`
- `Price`
- `Currency`
- `TrialDays`
- `GracePeriodDays`
- `IsEnabled`
- `SortOrder`
- `GatewayProductId`
- `GatewayPlanId`
- timestamps

### `PlanEntitlement`

Use entitlement keys instead of plan-name checks in application code.

Initial keys:

- `sites.max`
- `email.siteRecipients.max`
- `email.schedules.max`
- `email.dashboardDigest`
- `automation.schedules.max`
- `ai.enabled`
- `ai.monthlyRequests.max`
- `backup.retentionDays`
- `premium.seo`

Values may be boolean, integer, decimal, or string depending on entitlement type.

### `AccountSubscription`

- `Id`
- `OwnerUserId`
- `PlanId`
- `Gateway`
- `GatewayCustomerId` nullable
- `GatewaySubscriptionId`
- `Status`
- `StartedAtUtc`
- `CurrentPeriodStartUtc`
- `CurrentPeriodEndUtc`
- `TrialEndsAtUtc`
- `GraceEndsAtUtc`
- `CancelledAtUtc`
- `CancelAtPeriodEnd`
- timestamps

Status state machine:

```text
Trialing
  → Active
  → PastDue
  → GracePeriod
  → Suspended
  → Active (recovery)
  → Cancelled / Expired
```

Transitions must be server-controlled and auditable.

---

## 8. Payment gateway abstraction

Suggested contract:

```text
IPaymentGateway
  CreateCheckoutAsync(...)
  GetSubscriptionAsync(...)
  CancelSubscriptionAsync(...)
  ChangePlanAsync(...)
  VerifyWebhookAsync(...)
  ParseWebhookAsync(...)
```

Gateway-specific identifiers are stored, but the rest of the application operates on domain subscription states and entitlements.

---

## 9. PayPal first implementation

Initial PayPal scope:

- Configuration screen for sandbox/live mode.
- Client ID and secret stored encrypted.
- PayPal product/plan IDs mapped to internal plans.
- Server-side subscription checkout creation.
- Return URL used only for user navigation/status display.
- Cancel URL used only for navigation.
- Verified webhook endpoint is authoritative for payment/subscription events.
- Webhook events stored with unique gateway event ID.
- Replayed event must be idempotent.
- Subscription state synchronized after important webhook events.
- Support cancellation and payment-failure handling.
- Never trust price, currency, plan ID, account ID, or payment success sent by the browser.

Before production enablement, implementation must be validated against the current PayPal developer documentation and sandbox behavior.

---

## 10. Billing data model

Additional entities:

### `PaymentGatewayConfiguration`

- gateway name
- environment/sandbox flag
- encrypted credentials
- webhook configuration identifiers as required
- enabled state
- last validation result

### `BillingEvent`

- `Id`
- `Gateway`
- unique `GatewayEventId`
- `OwnerUserId` nullable until resolved
- `SubscriptionId` nullable
- event type
- received timestamp
- processed timestamp
- status
- retry count
- sanitized diagnostic error
- payload hash and optionally encrypted/minimized raw payload depending on retention policy

### `SubscriptionAuditEntry`

Records every internal status/plan change including actor/source:

- User
- Admin
- PayPal webhook
- Reconciliation job
- System recovery

---

## 11. Entitlement enforcement

Server-side checks are mandatory.

Examples:

- Adding a fourth site when `sites.max=3` must fail in the service/API even if the UI is bypassed.
- Adding a fourth site recipient must fail regardless of plan and must also respect the absolute product limit of three.
- Creating an email schedule must check `email.schedules.max`.
- Dashboard digest must check `email.dashboardDigest`.
- AI requests must check the applicable entitlement/usage counters when subscription enforcement is enabled.

The UI should explain limits, but it is not a security boundary.

---

## 12. Billing UX

### Pricing / Plans

Show:

- Plan name.
- Price/currency/interval.
- Included site limit.
- Email and scheduling limits.
- AI/SEO/automation availability.
- Trial information.
- Current plan marker.

### Account → Subscription & Billing

Show:

- Current plan.
- Status.
- Trial end / renewal date.
- Grace-period warning when applicable.
- Usage vs entitlement limits.
- Upgrade/downgrade/cancel controls.
- Payment synchronization status.
- Billing event/history summaries suitable for customers.

### Admin billing support

Administrators need:

- Search account subscription.
- View gateway IDs and sync status without exposing secrets.
- Force reconciliation.
- Grant audited temporary override/grace period if product policy permits.
- Never directly edit payment success flags.

---

## 13. Security requirements

Email:

- Encrypt SMTP/API passwords.
- Never return stored secret to browser.
- Sanitize SMTP errors before UI/logging.
- Ownership check on every profile, recipient, schedule, outbox, and history query.
- Protect against header injection in From/Reply-To/subject fields.

Billing:

- Encrypt gateway credentials.
- Verify PayPal webhook authenticity according to current PayPal requirements.
- Idempotent webhook processing.
- CSRF protection for authenticated billing actions where applicable.
- Server-side plan and amount validation.
- Correlation IDs across checkout, webhook, subscription update, and notification.
- No payment secrets or access tokens in logs.
- Audit every entitlement-affecting manual admin action.

---

## 14. Recommended implementation order

### Email delivery sequence

1. `EML-001` — Site recipients (1..3), ownership, validation, persistence.
2. `EML-004` — Secret-storage extension for mail credentials.
3. `EML-002` — Site mail profile.
4. `EML-003` — Account/dashboard profile + recipients.
5. `EML-005` — Test connection/test send.
6. `EML-006` — Bilingual template renderer.
7. `EML-009` — Outbox + attempts + retry worker.
8. `EML-007` — Site report schedules.
9. `EML-008` — Dashboard digest schedules.
10. `EML-010` — History/diagnostics UI.
11. `EML-011` — Event alerts.
12. `EML-012` — Rate limits, duplicate suppression, retention.

### Billing sequence

1. `BIL-001` — Plan catalog.
2. `BIL-002` — Entitlement engine.
3. `BIL-003` — Subscription state machine.
4. `BIL-004` — Trial/grace/suspension rules.
5. `BIL-006` — Gateway abstraction.
6. `BIL-007` — PayPal encrypted configuration and validation.
7. `BIL-008` — PayPal checkout.
8. `BIL-009` — Verified/idempotent PayPal webhook processor.
9. `BIL-010` — Lifecycle synchronization and reconciliation.
10. `BIL-012` — Server-side entitlement enforcement across product modules.
11. `BIL-005` — Customer billing UI.
12. `BIL-011` — Upgrade/downgrade/cancel/reactivate.
13. `BIL-013` — Billing history + Phase 10 notifications.
14. `BIL-014` — Admin/support diagnostics.

---

## 15. Acceptance scenarios

### Site recipients

- User A cannot read/update Site B email settings.
- Site cannot have more than three enabled recipients.
- Same address cannot be duplicated in different casing for the same site.
- Disabled recipient receives no scheduled email.

### Site SMTP

- Stored password is encrypted.
- Reloaded settings never expose the existing password.
- Test send provides actionable sanitized error on DNS/auth/TLS failure.

### Scheduling

- Schedule runs in configured timezone but persists next run in UTC.
- Restart does not duplicate an already-enqueued scheduled email.
- Failed retry history is visible.
- User/account/site ownership is preserved in every worker execution.

### PayPal

- Successful browser return with no verified server state does not activate subscription.
- Duplicate webhook event is processed once.
- Unknown PayPal subscription cannot be attached to an arbitrary account.
- Payment failure moves subscription through configured policy without deleting site data.
- Cancellation stops renewal according to chosen policy and preserves data during retention period.

### Entitlements

- UI and server report the same limit values.
- Direct API attempts cannot bypass limits.
- Admin overrides are explicit, time-bounded where applicable, and audited.

---

## 16. Testing requirements

Email:

- Unit tests for recipient limits and validation.
- Ownership tests.
- Mail-profile resolution tests.
- Secret protection tests.
- Schedule next-run tests across timezone/DST boundaries.
- Outbox claim/idempotency/retry/recovery tests.
- Template rendering and HTML escaping tests.

Billing:

- Plan entitlement tests.
- State-machine transition tests.
- Checkout request validation tests.
- Webhook authenticity adapter tests using PayPal sandbox/test fixtures where possible.
- Duplicate webhook/idempotency tests.
- Reconciliation tests.
- Feature-limit authorization tests.
- Cross-tenant subscription access tests.

Production release requires a complete PayPal sandbox end-to-end run before switching any gateway configuration to live mode.

---

## 17. Migration and backward compatibility

- Existing users/sites receive no email schedule by default.
- Existing application behavior must remain functional without SMTP configuration.
- Email is opt-in until a profile is tested/enabled.
- Existing accounts should receive the configured default/free entitlement set when subscription enforcement is introduced.
- Subscription enforcement should be introduced behind a controlled migration/feature flag so existing installations are not accidentally locked out.
- No migration may delete user/site data because payment state is unavailable.

---

## 18. Definition of completion for the commercial layer

The email + billing program is complete only when:

- Site recipients, account recipients, profiles, schedules, outbox, history, and alerts are tenant-safe and tested.
- PayPal checkout and webhook lifecycle are verified in sandbox.
- Entitlements are enforced server-side.
- Customer and administrator billing views are operational.
- Secrets are encrypted and excluded from logs/Git.
- Recovery and idempotency tests pass.
- Upgrade path from existing installations is tested.
- Development status registry and release notes accurately reflect implementation state.
