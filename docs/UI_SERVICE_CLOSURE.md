# UI → Service Closure Ledger

This ledger records user-visible capabilities that have been inspected end-to-end. Runtime behavior and the current GitHub implementation remain authoritative.

| Route / page | User action | Service / backend target | Status | Automated evidence | PR / commit | Remaining blocker |
|---|---|---|---|---|---|---|
| `/sites/{siteId}/seo` | Open owned SEO workspace and load synchronized posts/pages | `SeoAnalysisWebService` → ownership → Premium SEO entitlement → SQLite synchronized content | BROWSER VERIFIED | `SeoAuditUxTests.Owned_site_without_Premium_SEO_shows_entitlement_error_instead_of_false_not_found`; success journey setup/load assertions | PR #177 | None |
| `/sites/{siteId}/seo` | Run full audit | `SeoAuditExecutionService` → entitlement/ownership → analysis → `ISeoAuditService` persistence → `ExecutionOperationTracker` → refreshed UI/history | BROWSER VERIFIED | `SeoAuditUxTests.Run_full_audit_from_UI_persists_history_and_surfaces_execution_center_job` | PR #177 | None |
| `/module/execution` | Inspect completed SEO audit operation | `ExecutionCenterService` / `ExecutionOperationTracker` | BROWSER VERIFIED | SEO browser journey navigates from the SEO workspace and asserts the completed `Run SEO audit` job | PR #177 | None |

## Closure evidence

- The SEO workspace exposes the real `Run full audit` action and does not bypass production ownership or Premium SEO entitlement checks.
- The browser journey verifies synchronized WordPress content, pagination, Details/Fix/external-link behavior, search/reset behavior, full-audit execution, persisted audit history after refresh, and the completed Execution Center job.
- An owned site without Premium SEO is rendered as an entitlement/access failure rather than a false `Site Not Found` state.
- The repository UX Regression Gate contains a dedicated `seo-audit` shard and a contract test that requires the shard to remain present.
- CI merge readiness is evaluated by GitHub required checks for the PR head; transient harness failures are not treated as product closure evidence.

## Next candidates

- Reconstruct remaining user-visible actions from current `main` after PR #177 is integrated.
- Prefer dead/no-op controls, UI handlers that stop before real services, and WordPress mutations that do not reach the remote API.
