# AI WordPress Manager Release Notes

## 155.67.0 - 2026-08-06 - Global Posts Explorer
- Added a global posts explorer at `/module/posts` backed entirely by the local SQLite cache.
- Added cross-site search by title, slug, and excerpt.
- Added filtering by WordPress site and publication status.
- Added 10, 25, 50, and 100 item page sizes with server-side pagination.
- Added global published, draft, pending, total-post, and represented-site summaries.
- Added direct links to the post editor, per-site content explorer, and the public WordPress post.
- Kept the version and active branch visible together as `v155.67.0 • agent/logs-error-center`.

## 155.66.0 - 2026-08-06 - Version and Branch Identity
- Added reliable Git branch detection for local runs, GitHub Actions, and published builds.
- Added embedded `GitBranch` assembly metadata as a safe fallback when Git is unavailable at runtime.
- Added the active branch beside the version badge in the application top bar.
- Kept the footer build identity linked to the full About Build screen.

## 155.65.0 - 2026-08-06 - Operation History Maintenance
- Added an operation-history maintenance page at `/operations/maintenance` and `/site-operations/maintenance`.
- Added storage file size, record count, represented-site count, and oldest/newest operation indicators.
- Added cleanup preview by retention age while protecting a configurable number of the newest records.
- Added typed `CLEANUP` confirmation before irreversible deletion.
- Added cleanup result reporting and immediate storage-stat refresh.
- Added maintenance access from the Operations Hub.

## 155.64.0 - 2026-08-06 - Operation Diagnostic Details
- Added a dedicated operation-details page at `/operations/sites/{operationId}` and `/site-operations/{operationId}`.
- Added operation, site, result, timing, duration, affected-record, message, and technical-detail views.
- Added safe not-found handling when an operation record is no longer available.
- Added one-click clipboard copying for a complete diagnostic report.
- Added direct navigation back to operation history and the related site connection center.
- Added operation lookup by ID to the persistent history service.
- Moved CI verification from `windows-latest` to `ubuntu-latest` and added concurrency cancellation to reduce queued builds.

## 155.63.0 - 2026-08-06 - Operations Navigation Integration
- Added Operations Hub, Site Operations, and Site Reliability to the main sidebar.
- Added the three operations pages to the global command palette in Arabic and English.
- Updated navigation grouping so operations routes keep the Operations section expanded.
- Added accurate breadcrumb and page-title resolution for operations routes.
- Improved route matching by prioritizing the most specific command path.

## 155.62.0 - 2026-08-06 - Site Operations Hub
- Added a unified operations hub at `/operations` and `/operations/hub`.
- Added 30-day site-operation totals, success rate, failure count, and average duration summaries.
- Added direct cards for operation history, site reliability, synchronization, and site management.
- Added a recent cross-site activity feed with localized status presentation.
- Added operations and reliability shortcuts to the global Quick Actions menu.
- Updated the Add Site quick action to use the guided `/sites/connect` workflow.

## 155.61.0 - 2026-08-06 - Filtered Operations CSV Export
- Added one-click CSV export to the cross-site operations dashboard.
- Exported only the operations currently matched by the active search, site, status, and date filters.
- Added bilingual CSV column labels and UTF-8 BOM support for reliable Arabic display in Excel.
- Added CSV escaping for quotes, line breaks, messages, and diagnostic details.
- Added localized success and failure values plus duration and affected-record columns.

## 155.60.0 - 2026-08-06 - Site Reliability Dashboard
- Added a site reliability dashboard at `/site-reliability` and `/operations/reliability`.
- Added per-site success rates for connection and synchronization operations.
- Added 7, 30, and 90-day analysis periods with a configurable minimum operation count.
- Added overall success rate, measured-site count, and sites-needing-attention indicators.
- Added reliability classifications, last failure timestamps, and direct navigation to each site connection center.

## 155.59.0 - 2026-08-06 - Operations Date Filtering
- Added from/to date filtering to the cross-site operations dashboard.
- Added dynamic success, failure, active-site, and average-duration summaries based on the visible result set.
- Added visible-versus-total operation counts and one-click filter reset.
- Increased the loaded operation history limit to support broader operational review.

## 155.58.0 - 2026-08-06 - Site Operations Overview
- Added a cross-site operations dashboard at `/site-operations` and `/operations/sites`.
- Added unified filtering by site, result, operation, and diagnostic message.
- Added 30-day success, failure, active-site, and average-duration summaries.
- Added direct navigation from each operation to the related site connection center.
- Registered the persistent site operation history service in dependency injection.
- Added cross-site history and summary queries while keeping the existing per-site history behavior.

## 155.57.0 - 2026-08-06 - Site Data Snapshot
- Added a per-site offline data snapshot at `/sites/{id}/snapshot` and `/sites/{id}/offline-data`.
- Added cached counts for posts, pages, categories, tags, and media.
- Added last synchronization time, cache age, freshness classification, and offline readiness scoring.
- Added content distribution indicators and direct smart synchronization from the snapshot screen.
- Kept the screen fully backed by local SQLite data so it remains useful while WordPress is unavailable.

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
