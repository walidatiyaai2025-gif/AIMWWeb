# AIMWWeb Team Progress - 167

## SYNC-HIST-014 Persistent per-site synchronization history

Status: IMPLEMENTED - CI VALIDATION PENDING

## Roadmap gap
Phase 3 already had offline-first WordPress snapshots and a synchronization workspace, but the workspace only exposed the latest local-cache timestamp. Generic Execution Center activity is stored separately and identifies the site by display name, so it is not a canonical history for a specific site profile.

## Implementation
- Added the `SiteSyncRun` domain entity as the durable record for one synchronization attempt.
- Every record is keyed to the exact `Site.Id`, which keeps history correct even when profiles reuse the same WordPress URL or have similar display names.
- Sync attempts start as `Running` and finish as `Completed`, `Skipped`, or `Failed`.
- Each run stores start/end timestamps, downloaded-record count, skip state, and a bounded operational message.
- Added EF Core configuration, cascade ownership through `SiteId`, and a `(SiteId, StartedAtUtc)` index.
- Added migration `20260809094500_AddSiteSyncRuns` and updated the model snapshot.
- `WordPressSyncWebService.SynchronizeAsync` now persists lifecycle state for successful, no-change, and failed attempts without masking the primary synchronization exception if failure-history persistence also fails.
- Added `GetHistoryAsync` with ownership enforcement and bounded result size.
- `/module/sync` now displays the latest 15 attempts for the selected site profile, with bilingual status labels, timestamps, downloaded counts, and messages.
- Failed attempts are refreshed into the UI immediately after an operation error when history remains available.
- Added domain regression tests for running/completed/skipped/failed state transitions and bounded failure messages.
- Bumped the web version to `155.114.0`.

## Security and tenancy
- History queries call `EnsureOwnershipAsync` before reading records.
- Records are linked by `Site.Id`, not by URL or display name.
- Deleting a site cascades its synchronization history.
- Stored failure text is limited to 2000 characters; stack traces remain in diagnostics/notifications rather than the history table.

## Validation gate
1. Restore full solution.
2. Build full solution including Razor compilation and EF migration code.
3. Run automated tests.
4. Verify normal sync -> `Completed`.
5. Verify no-change delta probe -> `Skipped`.
6. Verify sync exception -> `Failed` without masking the original exception.
7. Verify history is scoped to the selected owned `Site.Id`.
8. Verify Arabic/English history UI renders in the synchronization workspace.
9. Merge only after required GitHub Actions checks are green.

Refs #3.
