# AI WordPress Manager Release Notes

## 155.56.0 - 2026-08-06 - Site Settings and Lifecycle
- Added a dedicated settings page for each WordPress site.
- Added profile editing with normalized URL and duplicate-site validation.
- Added safe Application Password replacement and encrypted credential removal.
- Added temporary site disable and re-enable operations without deleting local data.
- Added guarded site deletion that requires typing the exact site name.

## 155.55.0 - 2026-08-06 - Site Connection Operations Center
- Added a dedicated connection and synchronization center for each WordPress site.
- Added saved-credential status, connection retesting, smart synchronization, and direct operational shortcuts.
- Added persistent local operation history for connection tests and synchronization attempts.
- Added operation duration, success or failure status, affected record counts, and diagnostic report copying.
- Added safe per-site operation-history clearing without exposing saved Application Passwords.

## 155.54.0 - 2026-08-06 - WordPress Site Onboarding
- Added a guided three-step WordPress site connection workflow.
- Added site profile validation before saving.
- Added secure Application Password guidance and encrypted credential storage.
- Added save-and-test REST API validation with clear diagnostics.
- Added direct navigation to the connected site dashboard after successful onboarding.

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
