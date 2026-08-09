# TEAM PROGRESS 185 — AI-007 Persistent AI usage and cost observability

## Status
IMPLEMENTED on `agent/ai-007-usage-observability`; implementation-head CI is required before release reconciliation.

## Tracking
- Issue #44 — AI-007: tenant-safe persistent AI usage and cost dashboard.
- Target release: `155.130.0` after green implementation and release-head CI.

## Delivered
- Replaced the process-local AI usage queue in production DI with a persistent application-data usage log.
- Usage telemetry is written atomically to `Data/ai-usage-log.json`, retains the latest 10,000 entries, reloads after restart, and quarantines corrupt storage instead of blocking startup.
- Telemetry persistence failures are observational only: a successful AI provider response is not converted into an application failure because the usage file could not be written.
- Normalized telemetry bounds provider/model/operation/error fields and prevents negative token/cost counters.
- Added owner-scoped `AIUsageWebService` with owned-site validation, provider/operation breakdowns, success rate, token totals, estimated cost, and recent activity.
- `/api/ai/usage` no longer accepts a caller-selected `userId`; usage is resolved from the authenticated account.
- `/api/ai/generate` validates optional site ownership and attributes AI usage to the server-resolved current user rather than the request body `UserId`.
- Planner AI generation endpoints also use the server-resolved authenticated owner for usage attribution; legacy request `UserId` fields remain ignored for compatibility.
- AI Center now records and counts usage for the authenticated owner and links to the usage dashboard.
- Added bilingual `/module/ai-usage` UI with owned-site filtering, KPIs, provider/operation breakdowns, and recent success/failure activity.
- Added regression tests for persistence/reload, tenant filtering, bounded retention, corrupt-file quarantine/recovery, and value normalization.

## Architecture boundary
- No database migration.
- No prompt content or provider secret is stored in usage telemetry.
- Existing `IAIUsageLog` / `AIUsageEntry` contracts remain compatible.
- Provider adapters remain authoritative for `EstimatedCost`; this task does not invent model pricing when an adapter reports zero.

## Validation
Pending GitHub Actions Build and .NET Build Verification on the implementation head.
