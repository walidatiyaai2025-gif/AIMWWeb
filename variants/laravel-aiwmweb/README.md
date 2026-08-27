# Laravel AIWMWeb Variant

Variant ID: `LARAVEL_AIWMWEB`
Parent product family: `AIMWWEB`
Authority: Issue #257
Routing authority: `walidatiyaai2025-gif/project-control-center`

This directory is the physical implementation boundary for the Laravel AIWMWeb product variant.

## Non-negotiable invariants

- Preserve 100% functional parity with the current AIMWWeb product. No current capability, visible action, background workflow, service behavior, WordPress operation, AI flow, approval/execution/evidence flow, reporting function, or administrative function may be silently omitted.
- Multi-tenancy is architectural from the first production code. Tenant isolation must cover authentication context, memberships/RBAC, sites, credentials, AI provider configuration, jobs, schedules, cache keys, locks, rate limits, approvals, executions, evidence, reports, audit logs, connector pairings, quotas, entitlements and all persisted domain entities.
- Tenant A must never read, mutate, enqueue, execute, cancel, retry, approve, inspect evidence for, or otherwise act on Tenant B resources, including by direct identifier/IDOR attempts.
- Laravel is the application/domain/backend runtime. The browser frontend must preserve AIMWWeb visual and workflow parity.
- Managed WordPress sites use native WordPress REST where sufficient and the AIMW Connector plugin for advanced or sensitive operations.
- Connector sensitive capabilities are disabled by default and explicitly enabled by the target-site owner through capability scopes/toggles.
- No browser-side secrets. Provider keys and WordPress credentials remain server-side and tenant-scoped.
- Every mutation is governed by authorization, approval where required, idempotency, before-state evidence, execution, verification and durable audit/receipt semantics.

## Initial architecture boundary

Planned implementation roots under this directory:

- `backend/` — Laravel application/API, domain modules, queues, scheduler, tenancy, policies, persistence, AI providers and evidence.
- `frontend/` — React/TypeScript AIMWWeb-parity application.
- `connector/` — AIMW Connector WordPress plugin with versioned signed protocol, capability scopes, adapters and local audit.
- `docs/` — capability parity ledger, architecture decisions, protocol and acceptance evidence.
- `tests/` — tenant-isolation, contract, integration and end-to-end acceptance.

The initial Laravel 13 Tenant Core backend is under `backend/`. It establishes tenant context, scoped persistence, RBAC, queues, cache/locks/idempotency, encrypted secrets, immutable audit events, and isolation tests. It is not evidence that broad product parity is complete.
