# AI WordPress Manager Web

## Current release

**Version 155.52.0**

### Platform foundation completed in this release

- System Health diagnostics and liveness endpoints.
- Logs & Errors workspace with filtering, selection, and copyable diagnostic reports.
- Configuration Validation Center for storage, runtime, SQLite, AllowedHosts, and production safety checks.
- Backup & Restore Safety Center with manifest validation, preflight checks, disk-space validation, and audit history.
- Tracked Blazor error boundary with generated Error ID and Correlation ID.
- Structured JSON error entries written to the daily application log.
- User-facing error center without exposing stack traces or sensitive implementation details.

### Diagnostic workflow

1. Copy the Error ID or Correlation ID shown in the error screen.
2. Open `/logs`.
3. Search for the copied identifier.
4. Copy the complete diagnostic event when escalation is required.

### Restore safety

Database restore is intentionally blocked while the web application is running. Stop the application and perform the verified offline restore procedure to avoid replacing active SQLite files.
