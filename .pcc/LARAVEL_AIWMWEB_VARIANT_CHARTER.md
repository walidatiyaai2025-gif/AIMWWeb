# Laravel AIWMWeb Variant Charter

Authority: AIMWWeb Issue #257  
Variant ID: `LARAVEL_AIWMWEB`  
Display name: **Laravel AIWMWeb**

## Product mandate

Laravel AIWMWeb is a product variant of AIMWWeb. Its purpose is to reproduce the current AIMWWeb product with **100% functional parity** while moving the application runtime to a Laravel-centered architecture and managing WordPress targets through native REST plus the AIMW Connector where advanced access is required.

No current AIMWWeb screen, action, workflow, integration, background process, failure path, audit/evidence behavior, permission rule or user-visible capability may be silently dropped. The canonical migration denominator will be a capability/operation census derived from the live current AIMWWeb implementation.

## Multi-tenancy is constitutional

Multi-tenancy is not a later milestone. It is required in the first domain model, migrations, authorization layer, queues, cache, connector protocol and acceptance tests.

Every tenant-owned record must carry enforceable tenant ownership or an equivalent relationship that is impossible to bypass through normal application paths.

Tenant-scoped domains include at minimum:

- tenant/account/workspace;
- memberships, users, roles and permissions;
- managed WordPress sites;
- connector pairings and enabled connector scopes;
- remote credentials and AI provider credentials;
- content/sync snapshots;
- SEO audits/findings/scores;
- AI requests, usage and recommendations;
- approvals;
- jobs, job items, retries and schedules;
- executions and idempotency keys;
- evidence, receipts and audit logs;
- reports and exports;
- quotas, limits, entitlements and billing metadata when enabled;
- notifications and operational alerts.

### Mandatory isolation rules

1. Resolve an explicit tenant context on every authenticated application/API request.
2. Authorization must combine user membership/role + tenant ownership + resource ownership.
3. Route-model binding and repository/service queries must not permit cross-tenant IDOR.
4. Database uniqueness and indexes must include tenant identity whenever uniqueness is tenant-local.
5. Queue payloads must include immutable tenant identity and workers must re-resolve tenant context before execution.
6. Scheduler dispatch must be tenant-aware.
7. Cache keys, locks, rate limits and idempotency keys must be tenant-namespaced.
8. Secrets must be tenant-scoped, server-side only, encrypted at rest using the chosen production secret strategy, and never exposed to browser assets/logs.
9. Audit/evidence records must include tenant + site + actor + request/job/execution provenance.
10. Tenant A must be unable to read, mutate, execute, retry, cancel, export or infer Tenant B resources.
11. Global support/super-admin access, if introduced, must be explicit, least-privilege, auditable and never implicit tenant bypass.
12. Automated isolation tests are release gates, including direct-ID and queued-job cross-tenant attacks.

## WordPress management boundary

Use two execution paths:

- **Native WordPress REST** for standard supported reads/writes.
- **AIMW Connector** for advanced or privileged operations not safely available through native REST.

The Connector is a remote execution/data-access boundary, not the location of Laravel business logic.

### Connector owner controls

The WordPress site owner controls sensitive capabilities through explicit scopes/toggles. The Connector must reject operations for scopes that are not enabled even when the Laravel caller is otherwise valid.

Example scope families:

- `content.read`, `content.write`;
- `media.read`, `media.write`;
- `taxonomy.manage`, `comments.manage`;
- `seo.read`, `seo.write`;
- `plugins.read`, `plugins.manage`;
- `themes.read`, `themes.manage`;
- `site_health.read`;
- `cache.purge`;
- `backup.create`, `backup.restore`;
- `filesystem.read`, `filesystem.write`;
- `database.maintenance`;
- `cron.read`, `cron.manage`;
- `users.read`, `users.manage`.

High-risk scopes are disabled by default. Connector requests require a versioned signed protocol, replay protection, timestamp/nonce validation, pairing validation, scope validation and local WordPress capability validation.

## Capability parity ledger

Before broad porting, build a canonical ledger from current AIMWWeb.

Each capability/action receives a stable operation ID and maps to:

- current screen/control;
- current service/runtime destination;
- Laravel destination module;
- WordPress native REST or Connector execution driver where applicable;
- persistence model;
- tenant ownership rule;
- permission rule;
- failure/retry semantics;
- required browser/E2E evidence;
- migration state.

Allowed terminal migration states:

- `PORTED`;
- `ADAPTED` (technology changed, user capability preserved);
- `VERIFIED_UNAVAILABLE_EXTERNAL` (only genuine external limitation with evidence).

`BLOCKED`, `UNKNOWN`, placeholders, mock-only behavior and silently missing rows are non-terminal.

Functional parity is 100% only when every canonical capability is terminal with zero silent omissions.

## Intended runtime architecture

- Laravel application/domain/API runtime;
- React/TypeScript or repository-approved equivalent frontend matching current AIMWWeb visual and interaction language;
- MySQL/MariaDB;
- Laravel queues and scheduler;
- tenant-aware cache/locks/rate limiting;
- provider abstraction for Gemini/OpenAI/other configured AI services;
- capability registry and operation registry;
- WordPress/plugin adapter layer;
- versioned AIMW Connector protocol;
- approval engine;
- execution engine with idempotency/cancel/retry;
- before/after evidence and verification;
- risk-based snapshot/backup/rollback strategy;
- local Connector execution journal plus central Laravel audit trail.

## Performance rules

- No long browser request is the correctness boundary for audits, syncs, AI workloads, bulk changes, reports or backups.
- Use bounded queued slices/checkpoints and resumable jobs.
- Server-side pagination/filtering for large datasets.
- Avoid N+1 service/database/remote calls.
- Tenant-aware caching with explicit invalidation.
- Prevent two conflicting mutations on the same tenant/site using leases/locks.
- Every retryable remote mutation has an idempotency key.

## First real demo gate

A demo is accepted only when a real tenant can complete:

`Login -> Add/Pair Site -> Verify -> Sync -> Explorer -> SEO Audit -> AI Suggestion -> Approval -> Execute -> Remote Verify -> Evidence/Receipt`

The same candidate must also prove cross-tenant isolation using at least Tenant A and Tenant B and show that direct IDs, list endpoints, jobs, evidence and connector operations cannot cross the tenant boundary.

## Current governance state

The Laravel variant is intentionally registered as `UNMATERIALIZED / BLOCKED_UNMATERIALIZED` during the governance normalization step. Product implementation must not begin until the physical implementation boundary is deliberately materialized, recorded as `MAPPED`, and a PCC routing packet for Issue #257 is valid.
