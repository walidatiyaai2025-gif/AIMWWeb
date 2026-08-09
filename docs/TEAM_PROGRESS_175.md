# TEAM_PROGRESS_175

## Task
`OPS-006` — Owner, site, and job identity in operation history.

## Scope
Remove tenant ambiguity from Execution Center and site-operation history by persisting explicit owner/site/job identities, protecting lifecycle actions and direct history reads, and preserving legacy records without guessing ownership.

## Implemented
- Added nullable `OwnerUserId` and `SiteId` columns to `ExecutionCenterJobs` with startup self-migration for existing SQLite databases.
- New execution jobs can persist owner + site identity; legacy jobs remain readable internally but are not assigned fabricated ownership.
- Added owner-scoped job/activity queries and owner-scoped cancel/pause/resume/retry mutations.
- Updated Dashboard and Execution Center to use owner/site identity instead of matching by display `SiteName`.
- Updated synchronization, SEO audit, bulk status, and bulk trash producers to create identified execution jobs.
- Synchronization results now expose the linked `ExecutionJobId` for downstream history correlation.
- Extended site-operation history records with optional `OwnerUserId` and `ExecutionJobId` while preserving old JSON compatibility.
- Legacy ownerless history is visible only when its exact SiteId is currently owned by the signed-in user.
- Site Connection records owner identity and synchronization job correlation and no longer creates a private history-service instance outside DI.
- Operations Hub, Overview, Reliability, Details, and Maintenance now use owner-scoped history reads.
- Direct operation-details URLs fail closed for records outside the signed-in owner's scope.
- History maintenance preview/storage/cleanup operate only on the signed-in owner's visible records and never remove another tenant's records.
- CSV diagnostics now include SiteId and ExecutionJobId for traceability.

## Verification coverage
- Execution Center owner/site persistence and restart behavior.
- Same-named-site tenant isolation.
- Cross-tenant lifecycle mutation rejection.
- Legacy Execution Center schema migration without assigning untrusted owners.
- Site-operation history owner/site/job round-trip.
- Explicit-owner direct-ID isolation.
- Legacy ownerless history visibility by current owned SiteId.
- Tenant-safe cleanup retention.

## Known boundary
Approval-workflow ownership remains tracked under the existing AI approval tasks. Legacy/unidentified approval-created execution entries are intentionally not exposed through owner-scoped Execution Center views until that workflow receives authoritative tenant identity.

## Version
`155.122.0`

## Status
Implemented — CI validation pending.
