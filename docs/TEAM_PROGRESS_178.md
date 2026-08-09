# TEAM PROGRESS 178 — HOTFIX SiteSyncRuns SQLite schema

## Status
Verified — ready to merge

## Problem
Existing SQLite databases could reach WordPress synchronization without a `SiteSyncRuns` table and fail with:

`SQLite Error 1: 'no such table: SiteSyncRuns'`

## Root cause
Migration `20260809094500_AddSiteSyncRuns` had a `Migration` attribute but was missing the `DbContext(typeof(AppDbContext))` attribute used by the repository's other manually-authored migrations. EF Core therefore did not discover/apply the migration for `AppDbContext`.

## Fix
- Added the missing `DbContext(typeof(AppDbContext))` migration metadata.
- Added regression coverage that requires EF Core to discover the migration and verifies `SiteSyncRuns` exists after database initialization.
- Bumped the web application version to `155.124.1`.

## Validation
- Build #1270: SUCCESS.
- .NET Build Verification #938: SUCCESS.
- Regression coverage confirms `20260809094500_AddSiteSyncRuns` is discoverable by EF Core and that `SiteSyncRuns` exists after `DatabaseInitializationService.InitializeAsync()`.
