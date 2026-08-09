# TEAM_PROGRESS_176

## Task
`OPS-007` — Notification center tenant ownership and delivery policy.

## Scope
Turn the existing notification surfaces into one persistent, tenant-safe inbox for operational events. Notifications must be attributed to the relevant application owner, survive browser/circuit absence, support read/dismiss lifecycle, retain traceability to site/execution jobs, and fail closed for legacy or spoofed identities.

## Implemented
- Extracted `NotificationInboxService` from the content-planner bundle into a dedicated persistent service.
- Added in-place SQLite self-migration for `OwnerUserId`, `SiteId`, `ExecutionJobId`, `ReadAtUtc`, `DismissedAtUtc`, and notification `Source` metadata.
- Existing ownerless legacy rows remain intact but are not attributed to any tenant; new notifications require a non-empty owner identity.
- Added owner-scoped reads, mark-read, mark-all-read, dismiss, and retention cleanup.
- Added a 90-day read/dismissed retention policy plus a bounded per-owner history cap; cleanup never removes another owner's rows.
- Secured the existing notification HTTP compatibility path: caller-supplied `userId` is ignored and authenticated `NameIdentifier` is authoritative; ID-only mark-read cannot mutate another tenant's record.
- Updated `/notifications` to use `CurrentUserContext.UserId`, added dismiss support, and kept filtering/search bilingual.
- Unified the header notification center with the persistent inbox and corrected numeric enum severity mapping.
- Header notification routing now uses `Source` and `ExecutionJobId`; the footer opens the full notification inbox.
- Content Planner notifications now use authenticated owner identity rather than free-text `CreatedBy`, and queued planner notifications carry the linked execution-job ID.
- Bulk WordPress background work persists one final success/warning/failure notification per job using the authoritative owner resolved for background execution.
- WordPress media upload/update/delete jobs now persist owner/site/job identity and success/failure notifications; notification persistence is best-effort and cannot turn a successful remote WordPress operation into a false failure.
- Approval submission/review/execution notifications resolve the current authoritative `Site.OwnerUserId` from the application database, never `RequestedBy`/`Reviewer` display strings.
- Immediately executed approvals now enqueue owner/site-identified Execution Center jobs and include their job ID in the recipient notification.

## Verification coverage
- Existing notification SQLite schema upgrades in place.
- Legacy ownerless notifications are not exposed to a tenant.
- Owner/site/execution-job/source identity survives restart.
- Cross-owner read and dismiss mutations are rejected.
- Mark-all-read and retention cleanup are owner scoped.
- HTTP compatibility ignores a spoofed query `userId` and authorizes by the authenticated claim.
- Full solution/Razor build and automated tests passed on the implementation head before final documentation reconciliation (`Build #1235`, `.NET Build Verification #919`).

## Delivery policy
- Persistent inbox is the durable operational channel; transient Blazor toasts remain immediate UI feedback only.
- Background work writes a final durable outcome rather than one notification per item/retry.
- Notification persistence is best-effort after the authoritative operation state is committed.
- Recipient identity comes from application ownership (`OwnerUserId` / current Site owner), not display names or request-provided user strings.
- Legacy rows with no trustworthy owner are preserved but hidden rather than guessed.

## Version
`155.123.0`

## Status
Verified implementation — final-head CI pending after canonical roadmap reconciliation.
