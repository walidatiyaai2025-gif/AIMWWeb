# Architecture Map

## Layering

### Browser
React/TypeScript UI preserves AIMWWeb workflows and exposes only authorized, tenant-safe actions. It must not receive provider secrets, WordPress credentials, master keys, or caller-selectable tenant ownership fields.

### Laravel web/API
Routes establish authenticated tenant context, validate input, enforce permissions/policies, and call application services. Controllers/routes should remain thin.

### Application/domain services
Services own use-case orchestration, idempotency, transactions, state transitions, retries, reconciliation, evidence, and audit semantics.

### Persistence
Every tenant-owned model/query is scoped by the active tenant. Unique constraints and indexes must include tenant scope where identity is tenant-local. Direct-ID access must fail closed across tenants.

### Async runtime
Jobs, scheduler entries, cache keys, locks, rate limits, idempotency keys, and evidence must preserve tenant context. Queue payloads must not become an authorization bypass.

### WordPress boundary
Use native WordPress REST for standard operations. Use AIMW Connector for advanced/sensitive capabilities requiring signed/versioned requests, explicit owner-enabled scopes, replay protection, WordPress capability checks, and local audit.

### AI boundary
AI provider configuration and usage are tenant-scoped. Suggestions are evidence-bearing inputs to governed workflows; AI output is not automatic authorization for destructive mutations.

### Billing boundary
Provider state is authoritative for payment/subscription outcomes. Browser return URLs do not create success. Webhooks/provider verification, tenant entitlement state, idempotency, audit, and reconciliation are required.

## Canonical mutation shape

`request -> tenant context -> authorization -> validate ownership/input -> idempotency/lock -> before-state -> execute -> verify/reconcile -> persist -> audit/evidence -> authoritative reread -> response`

Not every read-only operation needs every step, but security and truthfulness may not be skipped.

## Failure semantics

- external failure must remain failure;
- a lost response may be reconciled by authoritative reread, not by fabricating success;
- destructive calls are not blindly retried;
- local state must not claim a remote mutation that cannot be verified;
- partial failure must preserve rollback/reconciliation evidence.

## Architecture evidence

Primary sources: root `AGENTS.md`, Issue #257, `README.md`, `docs/REPO_OPERATING_SYSTEM.md`, security matrices, parity evidence, migrations, routes, services, tests, and exact-head CI.
