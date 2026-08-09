# TEAM_PROGRESS_177

## Task
`AI-005` — Tenant-safe approval queue and review policy.

## Scope
Complete the approval queue as a multi-tenant operational workflow. Approval list/read/audit/edit/review/execute actions must be isolated to the owning application user, direct IDs must not reveal another tenant's proposal, reviewer identity must come from the authenticated application identity, and legacy approvals must not be assigned guessed ownership.

## Implemented
- Added durable nullable `OwnerUserId` to Approval Workflow SQLite with in-place self-migration and owner/status/site indexes.
- New approval submissions persist an authoritative owner snapshot.
- Site-associated submissions resolve and verify the current `Site.OwnerUserId`; a caller cannot submit an approval into another tenant's site.
- Site-less approvals require an explicit/authenticated owner; AI Center now passes its current application owner explicitly so Blazor circuit events do not depend on ambient `HttpContext` availability.
- Approval list, direct-ID read, audit trail, proposal edit, approve, reject, and immediate-execution operations now have owner-scoped overloads.
- Existing HTTP API compatibility methods resolve `ClaimTypes.NameIdentifier` server-side; no owner/user id is accepted from request data.
- HTTP review/edit compatibility derives the audit actor from the authenticated identity and ignores spoofable Reviewer/Actor strings from the request body.
- `/approvals` caches the current owner/reviewer at initialization and only calls owner-scoped workflow methods; the reviewer field is no longer editable.
- Legacy ownerless approvals are preserved, but are visible/actionable only while their legacy `SiteId` currently belongs to the caller.
- Explicitly owned historical approvals remain owned by their original tenant; approval/edit/execution is blocked if the site's ownership changed after submission.
- Immediate execution is fail-closed for site-less approvals and creates an Execution Center job with explicit owner + site identity when allowed.
- Approval idempotency keys are physically scoped by owner so identical logical keys from different tenants cannot collide in the existing globally-unique SQLite column.
- Approval notifications continue to use the persisted/verified owner and remain correlated to site/execution job identity.

## Verification coverage
- Same-owner idempotency reuse returns the existing pending approval.
- Same logical idempotency key is isolated across two owners.
- Direct-ID, audit, reject, and edit operations deny cross-owner access.
- Immediate execution creates an owner/site-scoped Execution Center job.
- A pending approval cannot be approved after site ownership transfers.
- Existing pre-OwnerUserId SQLite schema upgrades in place.
- Legacy ownerless rows are visible only to the current owner of their SiteId.
- HTTP compatibility returns only the authenticated owner's rows and records the authenticated reviewer rather than request-provided reviewer text.
- Full solution/Razor build and automated tests passed after the implementation and AI Center integration (`Build #1257`, `.NET Build Verification #929`).

## Version
`155.124.0`

## Status
Verified implementation — final-head CI pending after canonical roadmap reconciliation.
