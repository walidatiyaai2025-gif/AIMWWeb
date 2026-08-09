# TEAM_PROGRESS_174

## Task
`OPS-005` — Owner identity in background worker.

## Scope
Ensure hosted WordPress operations execute under the authoritative owner of the target site instead of depending on an HTTP request identity that does not exist inside background services.

## Implemented
- Added `BackgroundExecutionIdentity`, an async-flow execution lease that carries only an application owner user ID.
- `CurrentUserContext` now prefers an authenticated HTTP `NameIdentifier` and falls back to the background owner only when no HTTP user identity is available.
- Administrator authorization remains HTTP-only; background execution identity never grants roles or administrator access.
- `AutomationSchedulerService` resolves the current `Site.OwnerUserId` from the main application database before running synchronization or SEO audit work.
- `BulkContentOperationWorker` resolves the same authoritative owner before resolving and invoking WordPress editor/synchronization services.
- Missing/deleted/legacy ownerless sites fail closed instead of running with an unknown identity.
- Owner identity is derived at execution time, so existing automation jobs require no automation-database migration and cannot retain stale owner metadata after site ownership changes.
- Added deterministic tests covering background fallback, HTTP-user precedence, administrator isolation, nested identity restoration, and invalid owner rejection.

## Security model
- The site record in the main application database is the source of truth for background ownership.
- HTTP identity always wins when a request identity exists.
- Background identity carries a user ID only; it cannot confer application roles.
- Each worker establishes and disposes the identity within one execution flow.

## Validation gate
- Full solution build.
- Full automated test suite.
- `BackgroundExecutionIdentityTests` green.
- GitHub Actions `Build` and `.NET Build Verification` green before merge.

## Version
`155.121.0`

## Status
Implemented — CI validation pending.
