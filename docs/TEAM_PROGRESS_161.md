# AIMWWeb Team Progress - 161

## SETUP-DB-008 SQLite OwnerUserId migration ordering

Status: Implemented - CI validation pending

## Incident
Fresh/legacy SQLite setup failed during `DatabaseInitializationService.InitializeAsync()` with:

`SQLite Error 1: 'no such column: OwnerUserId'`

The failure occurred while applying migration `20260808022000_AllowDuplicateSiteProfiles`.

## Root cause
- `Sites.OwnerUserId` is a SQLite compatibility column added by `EnsureSqliteSiteOwnerColumnAsync()`.
- `DatabaseInitializationService` runs EF `MigrateAsync()` before the compatibility step.
- Migration `20260808022000_AllowDuplicateSiteProfiles` attempted to create `IX_Sites_OwnerUserId_SiteUrl` during `MigrateAsync()`.
- On databases where `OwnerUserId` did not exist yet, migration execution stopped before the compatibility step could add it.

## Fix
- Changed the duplicate-profile migration so it removes the legacy unique URL constraint without referencing `OwnerUserId`.
- The migration temporarily keeps a non-unique `IX_Sites_SiteUrl` lookup index.
- After migrations complete, SQLite compatibility adds `OwnerUserId`, creates `IX_Sites_OwnerUserId` and the final `IX_Sites_OwnerUserId_SiteUrl` index, then removes the temporary URL-only index.
- Added a regression test that executes full database initialization against a fresh in-memory SQLite database and verifies the owner column, final indexes, and zero pending migrations.
- Bumped web version to `155.108.0`.

## Compatibility
- Existing site data is preserved.
- No database deletion/reset is required.
- Databases that already contain `OwnerUserId` remain compatible because the compatibility check is idempotent.
- Databases missing `OwnerUserId` can now complete EF migrations before the column is added safely.

## Validation gate
1. Build solution.
2. Run automated tests including `DatabaseInitializationRegressionTests`.
3. Confirm both GitHub Actions workflows are green.
4. Merge only after CI passes.
