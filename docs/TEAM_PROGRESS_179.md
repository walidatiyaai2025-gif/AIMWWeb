# TEAM PROGRESS 179 — AI-006 Execute approved changes

## Status
Implemented and verified on the implementation head. The final release head must pass both repository CI gates before merge.

## Problem closed
The previous Approval → Execution Center path did not execute an approved WordPress mutation. `ExecutionCenterService` simulated progress and could mark a queued job completed, while `ApprovalWorkflowService` marked the approval `Executed` immediately after enqueue. This created false-success semantics.

## Delivered
- Added an explicit `External` execution mode alongside existing simulated/tracking jobs.
- The legacy progress timer processes only `Simulated` jobs and cannot complete approved external changes.
- Execution jobs now persist owner, site, execution mode, idempotency key, and correlation ID with backward-compatible SQLite self-upgrade.
- Added owner-scoped external lifecycle APIs: enqueue, claim/start, complete, fail, retry discovery.
- Approval `Execute immediately` now means **approve and queue**. The approval stays `Approved` until the background executor confirms a successful WordPress mutation.
- Failed execution is audited and leaves the approval `Approved` for safe retry; no false `Executed` transition occurs.
- Added typed allowlist policy. Initial automatic executor supports only `WordPress.Content.Update` for post/page targets containing a concrete WordPress ID and `ExpectedModifiedGmt`.
- Generic AI proposals without a concrete WordPress target remain approval-only.
- Added Content Editor `Send for approval`, capturing full Before/After `WordPressContentUpdateRequest` snapshots with the remote version observed during review.
- Extended the existing owner-aware background worker to execute approved external jobs with authoritative site-owner revalidation.
- Automatic execution never uses force overwrite.
- The worker performs a fresh remote read before mutation and blocks on version conflict.
- Idempotent recovery compares the current remote state with the approved desired state; if the mutation already landed before a process interruption, the workflow reconciles without a second POST.
- Approval state is made durable before final job bookkeeping so restart recovery cannot duplicate a successful remote mutation.
- Local cache refresh is best-effort after the authoritative WordPress mutation and cannot turn a successful external change into a failed business result.
- Approval Queue now exposes whether a proposal can be auto-executed and displays the linked execution job state.
- Execution Center identifies approved external jobs and does not expose pause/cancel while a remote mutation is managed; failed approved jobs expose safe retry.
- Added controlled rollback: an executed content approval can create a **new Pending rollback approval**. The current WordPress version is fetched first, the original Before snapshot becomes the rollback target, and the rollback is guarded by the current `ModifiedGmt`. Rollback never bypasses approval.

## Security and consistency rules
- Owner identity is revalidated against the current Site record before background execution.
- Approval OwnerUserId, SiteId, and ExecutionJobId must agree before mutation.
- Unsupported operation types cannot request immediate execution.
- `ForceOverwrite=true` is rejected for background approved changes.
- Remote version drift fails safely with the existing editor conflict behavior.
- Retry is idempotent across crash windows.
- Rollback is a second approval, not an unreviewed inverse mutation.

## Regression coverage
- Typed execution policy allow/block cases.
- Generic AI proposals remain approval-only.
- Missing version and force-overwrite rejection.
- Remote desired-state replay detection.
- External job persistence, owner/site identity, idempotency, correlation, lifecycle, and legacy schema upgrade.
- Progress simulator cannot advance external jobs.
- Queueing immediate execution leaves Approval status `Approved`.
- Only explicit success callback transitions to `Executed`.
- Failure audit preserves `Approved` state.
- Version-guarded rollback proposal creation and no-op rollback prevention.

## Validation receipts
Implementation head validation before release bookkeeping:
- Build #1297 — SUCCESS
- .NET Build Verification #948 — SUCCESS

## Release
`155.125.0`
