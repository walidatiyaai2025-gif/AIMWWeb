# TEAM_PROGRESS_173

## Task
`CNT-012` — Synchronization conflict resolution.

## Scope
Prevent synchronization from silently replacing a useful local mirror when live WordPress posts/pages have diverged, and provide a deliberate review and resolution workflow without treating the read-only cache as a publishable draft.

## Implemented
- Added a pure synchronization conflict comparison policy with deterministic tests.
- Classifies local-vs-remote differences as `RemoteUpdated` or `RemoteDeleted` and counts remote additions separately.
- Compares title, slug, status, link, rendered content/excerpt, and normalized modification timestamps.
- Added `ReviewConflictsAsync` to load live WordPress posts/pages without changing the local cache.
- Start Sync now performs pre-sync review when a local baseline exists and stops before cache mutation when conflicts are present.
- Added review UX inside `/module/sync` with local/live version panes, conflict type, modification timestamps, snippets, and transient Defer state.
- Remote-updated posts/pages can open the existing direct editor for a manual merge; the editor retains CNT-009 optimistic-concurrency protection.
- Added explicit `Accept WordPress & force full sync` resolution with confirmation.
- Forced synchronization bypasses the delta shortcut and replaces the mirror from a fresh full WordPress snapshot, allowing remote deletions to be reconciled.
- No action ever publishes stale local cached HTML back to WordPress.
- No database migration is required because `WordPressContentRecord` is a mirror and has no local dirty/draft state.

## Resolution semantics
- **Defer**: keep the current cache for this UI session; do not write to WordPress or the database.
- **Open editor**: manually review/merge a remotely updated post/page using the live editor.
- **Accept WordPress**: perform a forced full refresh and make the local cache match live WordPress.
- **Remote additions**: not conflicts because there is no local version to protect; normal synchronization can import them.

## Validation gate
- Full solution/Razor build.
- Full automated test suite.
- `SyncConflictPolicyTests` green.
- GitHub Actions `Build` and `.NET Build Verification` green before merge.

## Version
`155.120.0`

## Status
Implemented — CI validation pending.
