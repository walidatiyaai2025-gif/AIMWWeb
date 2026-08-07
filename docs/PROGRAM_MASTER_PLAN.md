# AI WordPress Manager — Program Master Plan

**Repository:** `walidatiyaai2025-gif/AIMWWeb`  
**Development branch:** `main`  
**Current baseline:** `155.96.12`  
**Plan owner:** Project maintainers  
**Status source of truth:** `src/AIWordPressManager.Web/wwwroot/development-status.json`

> This document is the mandatory starting point for every developer or AI coding agent working on the project. Read it before changing code, select work from the current phase, update the feature registry, build, test, and record the result.

---

## 1. Product vision

AI WordPress Manager is a multi-user, multi-site web application for securely connecting WordPress sites, synchronizing content into a local/offline cache, managing content and operations, improving SEO with AI-assisted recommendations, executing approved changes through auditable workflows, sending scheduled operational email reports, and offering commercial subscription plans with payment-gateway-backed entitlement control.

The product must support:

- Multiple application users.
- Strict site ownership and tenant isolation.
- Multiple WordPress sites per application user.
- Live WordPress REST API operations.
- Local/offline database cache.
- SQLite, SQL Server, PostgreSQL, MySQL, and MariaDB application database providers.
- Arabic RTL and English LTR.
- Background execution, scheduling, retries, logs, notifications, and approvals.
- One to three notification email recipients per WordPress site.
- Independent outbound email configuration per site when required.
- Dashboard/account-level email notification settings independent from site-level settings.
- Scheduled email digests and event-driven alert emails.
- Subscription plans, feature entitlements, usage limits, trials/grace periods, and billing lifecycle.
- PayPal as the first subscription payment gateway behind a provider abstraction.
- Secure credential storage and least-privilege access.
- Clear operational and development status reporting.

---

## 2. Architecture baseline

```text
AIWordPressManager
├── Domain            Entities, value objects, domain rules
├── Application       Use cases, interfaces, DTOs
├── Infrastructure    External integrations, AI, email, payment gateways, security
├── Persistence       EF Core, database providers, configurations, initialization
└── Web               Blazor Server UI, API endpoints, orchestration
```

### Core ownership model

```text
Application User
├── Account / Dashboard Settings
│   ├── Dashboard email recipients
│   ├── Dashboard outbound mail profile
│   └── Subscription / entitlements
└── Owned WordPress Sites
    ├── Credentials
    ├── Posts and Pages cache
    ├── Media cache
    ├── Taxonomy cache
    ├── Comments and WordPress users
    ├── SEO analyses
    ├── Operations and jobs
    ├── Schedules
    ├── Email recipients (1..3)
    ├── Site outbound mail profile
    ├── Email report schedules
    ├── Approvals
    └── Audit records
```

Every request carrying a `SiteId` must verify that the site belongs to the authenticated application user before reading local data, sending a WordPress request, sending email, changing schedules, or changing state.

Every subscription or billing request must resolve the authenticated application user and must never accept tenant identity, plan status, amount, or entitlement decisions from client-side data alone.

---

## 3. Mandatory developer workflow

1. Pull and reset to the approved branch.
2. Read this plan, `docs/EMAIL_AND_BILLING_PLAN.md`, and `development-status.json` when working on communications or billing.
3. Select one feature marked `in_progress` or the first unblocked `planned` feature in the active phase.
4. Confirm dependencies and acceptance criteria before coding.
5. Implement the smallest complete vertical slice.
6. Add or update validation, authorization, error handling, localization, logging, and audit behavior.
7. Run restore and build. Run relevant tests when available.
8. Never claim build or runtime success without actual evidence.
9. Update the feature status registry:
   - `completed`: implemented and acceptance criteria met.
   - `in_progress`: actively being implemented or partially functional.
   - `planned`: approved but not started.
   - `blocked`: cannot proceed until a named dependency is resolved.
10. Add release notes and increment the application version.
11. Commit with a focused message.

### Definition of Done

A feature is `completed` only when all applicable checks pass:

- Ownership and authorization enforced.
- Input validation implemented.
- Arabic and English UI text provided.
- Loading, empty, success, and error states handled.
- No mock data presented as real data.
- Database upgrade path considered.
- Secrets are encrypted at rest and never written to logs.
- Logging and user-facing diagnostics included.
- Idempotency is implemented for retries where duplicate side effects are possible.
- Background work preserves tenant/user context.
- Build succeeds in the target configuration.
- Runtime path tested manually or automatically.
- Feature registry and release notes updated.

For email features, Definition of Done additionally requires test-send support, queue/retry behavior, delivery audit records, recipient validation, and tenant-safe schedule execution.

For billing features, Definition of Done additionally requires verified gateway webhooks, server-side amount/plan validation, idempotent event processing, entitlement tests, and no trust in browser redirects as proof of payment.

---

## 4. Delivery phases

### Phase 0 — Governance and visibility

**Goal:** Make development measurable and repeatable.

Deliverables:

- Master development plan.
- Machine-readable feature registry.
- Interactive HTML development dashboard.
- Release/version discipline.
- Developer Definition of Done.
- Build and runtime verification policy.

Exit gate: Any developer can identify the current phase, next unblocked item, dependencies, and project completion percentage.

### Phase 1 — Platform foundation

**Goal:** Stable Blazor Server and clean solution architecture.

Scope:

- Layered solution structure.
- Dependency injection.
- EF Core and database initialization.
- Multi-database provider setup.
- Localization and theming.
- Shared UI components.
- Health endpoints and build information.

Exit gate: Application starts reliably, database initializes automatically, and core shell is functional.

### Phase 2 — Identity and tenant isolation

**Goal:** Each application user sees and controls only their own data.

Scope:

- Registration and login.
- Password hashing and password change.
- Account profile.
- Site ownership.
- Ownership validation across local cache, APIs, operations, schedules, and email.
- Roles, permissions, sessions, lockout, audit, and MFA foundation.

Exit gate: Cross-user access is impossible through UI, URL manipulation, API calls, background jobs, email schedules, and billing APIs.

### Phase 3 — WordPress site lifecycle

**Goal:** A user can connect, verify, manage, and remove WordPress sites safely.

Scope:

- First-run onboarding.
- Application Password setup.
- Encrypted credential storage.
- Connection testing and diagnostics.
- Site settings and status.
- Initial synchronization.

Exit gate: New user can register, connect a site, synchronize it, and open a populated workspace.

### Phase 4 — Content and offline data

**Goal:** Reliable content visibility and editing with local cache support.

Scope:

- Posts, pages, media, categories, tags.
- Comments and WordPress users.
- Global and site-specific explorers.
- Workspace filtering.
- Offline snapshots and cache health.
- Bulk operations and conflict strategy.

Exit gate: All content modules are real, ownership-safe, paginated, searchable, and backed by live or clearly labeled cached data.

### Phase 5 — Operations and automation

**Goal:** Auditable execution of synchronous and background work.

Scope:

- Synchronization workspace.
- Execution Center.
- Job progress and activity logs.
- Schedules and retries.
- Multi-user background identity context.
- Notifications and failure recovery.

Exit gate: Scheduled and manual jobs run under the correct owner identity, survive restarts, and expose complete status.

### Phase 6 — SEO, AI, and approval workflow

**Goal:** Generate safe, reviewable, and executable improvements.

Scope:

- SEO audit.
- AI provider configuration.
- Prompt registry.
- AI suggestions with before/after values.
- Approval queue.
- Execution and rollback.
- Usage and cost logging.

Exit gate: No AI-generated change reaches WordPress without the configured approval policy and a traceable audit record.

### Phase 7 — Administration and security

**Goal:** Enterprise-grade administration.

Scope:

- User management.
- Roles and permissions engine.
- Feature flags.
- Session management.
- Lockout and password reset.
- Security events and audit trails.
- Secrets rotation.

Exit gate: Administrators can govern access without direct database changes.

### Phase 8 — Reliability, backup, and observability

**Goal:** Recoverable and diagnosable production operation.

Scope:

- Backup and restore.
- Export and import.
- Structured logs and correlation IDs.
- Health dashboard.
- Retention policies.
- Database integrity checks.
- Performance and memory monitoring.

Exit gate: Operators can diagnose failures and restore service and data using documented procedures.

### Phase 9 — Quality, delivery, and production readiness

**Goal:** Repeatable release pipeline and verified product quality.

Scope:

- Unit, integration, authorization, and UI tests.
- CI build and test workflow.
- Migrations and upgrade tests.
- Security review.
- Accessibility and responsive testing.
- Deployment documentation.
- Release packaging.

Exit gate: A tagged release can be built, tested, deployed, upgraded, and rolled back using documented automation.

### Phase 10 — Email communications and scheduled reporting

**Goal:** Give each user reliable, tenant-safe email reporting for every WordPress site and for their overall dashboard.

Scope:

- Add one, two, or three notification recipient email addresses to each owned site.
- Validate, deduplicate, enable/disable, and audit recipients.
- Per-site outbound email profile with sender name/address, reply-to, SMTP host, port, TLS mode, username, encrypted password/API secret, and test-send.
- Optional shared/account-level outbound mail profile to avoid repeating SMTP configuration on every site.
- Dashboard/account-level email recipients and outbound mail settings.
- Email templates in Arabic and English.
- Site report schedules with timezone, frequency, next-run calculation, enable/disable, retry policy, and ownership-safe execution.
- Dashboard digest schedule independent of site schedules.
- Event-triggered alerts for sync failures, scheduled job failures, security-relevant events, and selected operational warnings.
- Email queue/outbox with retry/backoff, idempotency key, correlation ID, status, sent timestamp, failure reason, and attempt history.
- Email delivery history visible per site and globally for the authenticated account.
- Rate limiting and duplicate suppression.
- Subscription entitlement checks before premium email scheduling features execute.

Exit gate: A user can configure up to three recipients on each owned site, send a test email, schedule site reports and dashboard digests, inspect delivery history, and trust that no email or SMTP credential crosses tenant boundaries.

### Phase 11 — Subscriptions, entitlements, and billing

**Goal:** Commercialize the application through secure subscription plans while keeping payment processing isolated behind a gateway abstraction.

Scope:

- Subscription plan catalog with code, localized name, billing interval, price, currency, enabled status, sort order, and feature limits.
- Entitlement model for site count, email schedules, email recipients, automation, AI usage, storage/retention, and premium modules.
- Free/trial plan and configurable trial period.
- Active, trialing, past-due, grace-period, suspended, cancelled, and expired subscription states.
- Account billing page with current plan, renewal date, payment status, usage against limits, upgrade/downgrade/cancel actions, and billing history.
- Payment gateway abstraction (`IPaymentGateway` or equivalent) so business logic does not depend directly on PayPal.
- PayPal as the first gateway implementation.
- Server-created PayPal product/plan mapping and subscription checkout flow.
- PayPal webhook endpoint with signature/authenticity verification, idempotent event storage, raw-event retention policy, correlation, and replay-safe processing.
- Subscription activation only from verified server-side payment state; browser return/cancel URLs never activate access by themselves.
- Cancellation, renewal, failed-payment, suspension, reactivation, and plan-change synchronization.
- Grace period policy so temporary payment failures do not corrupt or delete customer data.
- Feature/limit enforcement in both UI and server-side services.
- Admin plan management and account subscription override tools with full audit trail.
- Billing email notifications using the Phase 10 mail infrastructure.
- Gateway secrets encrypted at rest and excluded from logs and Git.
- Provider-ready design for later Stripe/KNET/other gateways without rewriting entitlement logic.

Exit gate: A customer can choose a plan, subscribe through PayPal, receive access only after verified payment state, see their billing status and limits, cancel safely, and have entitlements enforced server-side across application features.

---

## 5. Current priority order

1. Stabilize current `155.96.x` build/tests and database first-run recovery.
2. Complete tenant-safe identity propagation for all hosted/background execution.
3. Finish internal storage path unification for Execution Center and Approval Workflow.
4. Implement application user administration, roles, and granular permissions.
5. Start Phase 10 with the shared email domain model, encrypted mail profiles, site recipients (1..3), and test-send.
6. Add site email scheduling, dashboard digest scheduling, outbox/retry/history, and event alerts.
7. Complete SEO audit, AI suggestion workflow, approvals, and rollback.
8. Implement Phase 11 subscription/entitlement domain independently of PayPal.
9. Add PayPal gateway, checkout, verified webhooks, subscription state synchronization, and billing UI.
10. Enforce plan limits across sites, email, automation, AI, and premium modules.
11. Complete backup/restore, CI coverage, security review, and production hardening.

---

## 6. New approved work-item namespaces

The following IDs are reserved and must be added to the canonical feature registry as implementation begins:

### Email and communications

- `EML-001` — Site notification recipients (1..3).
- `EML-002` — Site outbound email profile.
- `EML-003` — Dashboard/account email profile and recipients.
- `EML-004` — Encrypted email secrets and credential rotation.
- `EML-005` — Email connection validation and test send.
- `EML-006` — Email templates and bilingual rendering.
- `EML-007` — Site email report schedules.
- `EML-008` — Dashboard digest schedules.
- `EML-009` — Email outbox, retries, idempotency, and correlation.
- `EML-010` — Delivery history and diagnostics.
- `EML-011` — Event-driven operational alert emails.
- `EML-012` — Email rate limits, duplicate suppression, and retention.

### Subscription and billing

- `BIL-001` — Subscription plan catalog.
- `BIL-002` — Entitlement and usage-limit engine.
- `BIL-003` — Account subscription state machine.
- `BIL-004` — Trial, grace-period, suspension, and expiry policies.
- `BIL-005` — Billing/account UI.
- `BIL-006` — Payment gateway abstraction.
- `BIL-007` — PayPal gateway configuration and encrypted credentials.
- `BIL-008` — PayPal subscription checkout.
- `BIL-009` — PayPal verified webhook ingestion and idempotency.
- `BIL-010` — PayPal subscription lifecycle synchronization.
- `BIL-011` — Upgrade, downgrade, cancel, and reactivation flows.
- `BIL-012` — Server-side feature and usage limit enforcement.
- `BIL-013` — Billing history, audit, and customer notifications.
- `BIL-014` — Administrative billing controls and support diagnostics.

Detailed requirements, data model, sequencing, and acceptance criteria are defined in `docs/EMAIL_AND_BILLING_PLAN.md`.

---

## 7. Status rules

The canonical registry is JSON. Each feature contains:

- `id`: stable identifier; never reuse it.
- `phase`: numeric execution phase.
- `area`: functional module.
- `nameAr` and `nameEn`: display names.
- `status`: `completed`, `in_progress`, `planned`, or `blocked`.
- `priority`: `critical`, `high`, `medium`, or `low`.
- `version`: first version containing the completed or partial implementation.
- `notes`: factual current state, including known limitations.

Never change a status to `completed` solely because code was committed. Build/runtime evidence and acceptance criteria are required.

---

## 8. Versioning and release notes

- Functional feature: increment minor component, e.g. `155.96.x → 155.97.0`.
- Runtime/build fix: increment patch component.
- Every version must include release notes under `docs/releases/`.
- The footer/build information must show both version and branch.

---

## 9. Developer handoff template

```markdown
### Work item
Feature ID:
Phase:
Branch:
Version target:

### Implemented
-

### Files changed
-

### Security and ownership checks
-

### Validation and localization
-

### Verification
- Restore:
- Build:
- Tests:
- Runtime path:

### Known limitations
-

### Registry update
- Previous status:
- New status:
```

---

## 10. Dashboard locations

- In-app/offline HTML dashboard: `/development-status.html`
- Registry: `/development-status.json`
- Repository source:
  - `docs/PROGRAM_MASTER_PLAN.md`
  - `docs/EMAIL_AND_BILLING_PLAN.md`
  - `src/AIWordPressManager.Web/wwwroot/development-status.html`
  - `src/AIWordPressManager.Web/wwwroot/development-status.json`

The dashboard calculates totals directly from the registry and supports phase, status, priority, and text filters.
