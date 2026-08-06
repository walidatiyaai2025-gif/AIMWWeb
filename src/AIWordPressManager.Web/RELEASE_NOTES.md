# AI WordPress Manager Release Notes

## 155.53.0 - 2026-08-06 - Phase 1 Foundation Complete
- Added a live About Build and Release Notes center.
- Release notes are loaded from a versioned Markdown source instead of hard-coded UI text.
- Added current-release matching, historical release browsing, and copyable build diagnostics.
- Completed the Phase 1 platform foundation covering health, diagnostics, configuration validation, backup safety, tracked errors, and version reporting.

## 155.52.0 - 2026-08-06 - Tracked Error Handling
- Added global Blazor error boundaries with recoverable error presentation.
- Generated Error ID and Correlation ID values for unhandled exceptions.
- Wrote structured JSON error records to daily log files.
- Replaced the generic error page with a safe diagnostics page.

## 155.51.0 - 2026-08-06 - Backup Safety
- Added backup manifest inspection and archive validation.
- Added restore preflight checks, disk-space validation, and an operation audit trail.
- Kept live SQLite restore blocked while the application is running.

## 155.50.0 - 2026-08-06 - Configuration Validation
- Added runtime, storage, SQLite, AllowedHosts, and detailed-error validation.
- Added bilingual readiness results with corrective guidance.

## 155.49.0 - 2026-08-06 - Logs and Error Center
- Added searchable and filterable log inspection.
- Added selectable events, error-code extraction, and copyable diagnostic reports.

## 155.48.0 - 2026-08-06 - System Health
- Added application, SQLite, storage, and logs health diagnostics.
- Added health endpoints for liveness and detailed diagnostics.
