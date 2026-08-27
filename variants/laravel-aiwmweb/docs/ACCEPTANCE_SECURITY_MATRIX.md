# Laravel AIWMWeb Acceptance & Security Matrix

Authority: AIMWWeb Issue #257. Target: `LARAVEL_AIWMWEB`.

This document defines the reusable acceptance denominator and release gates. It does **not** convert an absent product runtime into a passing test. A matrix family can be `ACTIVE`, `PARTIAL`, or `BLOCKED_RUNTIME`; only executed evidence may be reported as passing.

## Status vocabulary

- `ACTIVE` — executable against current Laravel variant and required in CI.
- `PARTIAL` — executable coverage exists for the current foundation, but later domain resources add required cases.
- `BLOCKED_RUNTIME` — the owning runtime is not present yet; the cases remain mandatory and may not be mocked as final integration evidence.

## Tenant security

Status: **PARTIAL / gate active**.

Every tenant-owned resource must execute the same attack matrix: own-resource success, guessed foreign ID read/update/delete, mixed-tenant bulk request, and where applicable enqueue/approve/execute/retry/cancel of a foreign resource. Required resource families are machine-catalogued in `backend/tests/Contracts/acceptance-matrix.json`.

The merged Tenant Core already exercises tenant-context resolution, permission denial, direct-ID read/update/delete isolation, encrypted tenant secrets, tenant-partitioned cache/queue/idempotency/locks/audits, immutable audit events, and tenant-context cleanup after synchronous job execution. New domain models are not accepted merely because they use a tenant column; their public and bulk boundaries must join this matrix.

## Connector security

Status: **BLOCKED_RUNTIME** until the AIMW Connector protocol/runtime lands.

Required cases: valid signature, modified payload, expired timestamp, nonce replay, wrong connector/site/tenant, revoked connector, disabled scope, unsupported operation, protocol mismatch, idempotent replay, and rate-limit behavior. Unit doubles may test parsers, but final integration must use the real connector plugin and signed protocol.

## Execution safety

Status: **BLOCKED_RUNTIME / contract active** for governed WordPress mutations.

Every mutation pipeline must prove: before-state capture → approval when required → exactly-once remote mutation → after-state → authoritative verification → durable evidence. Failure injection is mandatory before mutation, after remote mutation before response, and after response before verification. Retrying the same idempotency key must not duplicate the remote mutation.

## Queue and concurrency

Status: **PARTIAL / gate active**.

Current Tenant Core proves queue key partitioning and tenant-context cleanup. Domain workers must additionally prove parallel tenants, same-site operation lease, different-site concurrency, retry/cancel, worker failure, stale-lock recovery, and idempotency.

## MySQL / MariaDB production-like validation

Status: **ACTIVE**.

CI runs Laravel migrations and tests against MySQL in addition to SQLite. It exercises `migrate:fresh`, rollback, forward migration, foreign-key/index/unique-constraint behavior exposed by the schema, and the complete PHPUnit suite. SQLite-only green is not sufficient for release acceptance.

## WordPress integration harness

Status: **ACTIVE for native WordPress REST; BLOCKED_RUNTIME for connector pairing**.

CI installs a disposable real WordPress instance backed by MySQL, creates an application password, reads content through real WordPress REST, performs an authenticated REST mutation, and re-reads the authoritative value. No mocked WordPress response qualifies for this tier. When the connector plugin exists, this harness becomes the mandatory location for plugin installation/pairing/health/scope/security execution rather than inventing a fake endpoint.

## Performance

Status: **PARTIAL / static gate active**.

The release gate rejects obvious unbounded Eloquent `::all()` reads and synchronous `usleep()` in Laravel production paths. Domain acceptance must add bounded datasets and budgets for large content lists, sync/audit/report batches, queues, and connector calls, plus query-count/N+1 assertions once those runtimes exist.

## Frontend acceptance

Status: **BLOCKED_RUNTIME** until the React/TypeScript frontend lands.

Mandatory matrix: route coverage, dead controls, loading/error/empty states, permissions, connector-capability disabled states, RTL, responsive behavior, and accessibility. The final frontend tier must exercise real API boundaries; static component existence is not acceptance evidence.

## Release gate

Status: **ACTIVE**.

`tools/acceptance_gate.py` fails when the parity ledger is absent/malformed, operation IDs collide, migration-state totals do not equal the denominator, a `PORTED`/`ADAPTED` row lacks acceptance evidence, an unavailable/blocker row lacks explicit evidence, a required connector scope is missing, Tenant Core migration invariants disappear, the acceptance matrix shrinks below Issue #257 requirements, or high-confidence fake/dead/unbounded patterns appear in Laravel production source.

`tools/capability_census.py` derives the parity denominator from current ASP.NET routes, visible controls, HTTP APIs, service methods and background entry points. Every new discovered operation starts `PENDING`; therefore adding source surface increases the denominator instead of silently increasing the completion percentage.
