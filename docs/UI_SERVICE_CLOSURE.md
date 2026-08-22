# UI → Service Closure Ledger

This ledger records user-visible capabilities that have been inspected end-to-end. Runtime behavior and the current GitHub implementation remain authoritative.

| Route / page | User action | Service / backend target | Status | Automated evidence | PR / commit | Remaining blocker |
|---|---|---|---|---|---|---|
| `/sites/{siteId}/seo` | Open owned SEO workspace and load synchronized posts/pages | `SeoAnalysisWebService` → ownership → Premium SEO entitlement → SQLite synchronized content | BROWSER VERIFIED | `SeoAuditUxTests.Owned_site_without_Premium_SEO_shows_entitlement_error_instead_of_false_not_found`; success journey setup/load assertions | PR #177; `7c9a1177` + `b0e54cac` | CI must be green before merge |
| `/sites/{siteId}/seo` | Run full audit | `SeoAuditExecutionService` → entitlement/ownership → analysis → `ISeoAuditService` persistence → `ExecutionOperationTracker` → refreshed UI/history | BROWSER VERIFIED | `SeoAuditUxTests.Run_full_audit_from_UI_persists_history_and_surfaces_execution_center_job` | PR #177; `7c9a1177` + `b0e54cac` + `f12ae985` | CI must be green before merge |
| `/module/execution` | Inspect completed SEO audit operation | `ExecutionCenterService` / `ExecutionOperationTracker` | BROWSER VERIFIED | SEO browser journey navigates from the SEO workspace and asserts the completed `Run SEO audit` job | PR #177; `b0e54cac` | CI must be green before merge |

## Next candidates

- Reconstruct remaining user-visible actions from current `main` after PR #177 is integrated.
- Prefer dead/no-op controls, UI handlers that stop before real services, and WordPress mutations that do not reach the remote API.
