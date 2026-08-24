# UI → Service Closure Ledger

This ledger records user-visible capabilities inspected end-to-end for Issue #183. Runtime behavior on the current GitHub `main` remains authoritative. A capability is marked **BROWSER VERIFIED** only when browser evidence reaches the real application/service boundary and reconciles visible state; unsupported behavior must remain explicitly unavailable.

| Route / page | User action | Real production target | Status | Automated evidence | Evidence / blocker |
|---|---|---|---|---|---|
| `/sites/{siteId}/seo` | Load owned workspace and run full audit | ownership + Premium SEO entitlement → synchronized content → `SeoAuditExecutionService` / persisted audit / execution tracking | BROWSER VERIFIED | `SeoAuditUxTests` | PR #177; none |
| `/module/seo-audit`, `/module/seo-suggestions` | Choose owned site and enter canonical SEO workspace | `SiteWebService.GetSitesAsync` → `/sites/{siteId}/seo` | BROWSER VERIFIED | `ModuleWorkspaceSeoAuditUxTests`; `ModuleWorkspaceNoMockContractTests` | PR #184; none |
| `/content-workspace`, `/seo-workspace`, `/ai-workspace`, `/operations-workspace` | Navigate capability cards | canonical implemented routes only; no static Ready/In Progress claims | BROWSER VERIFIED | `WorkspaceHubNavigationUxTests`; `WorkspaceHubNoMockContractTests` | PR #185; none |
| `/ai-center` | Refresh metadata / generate / submit approval | prompt registry + usage log + owner-scoped sites + `IAIOrchestrator` + `ApprovalWorkflowService` | BROWSER VERIFIED | `AICenterNoFalseReadinessContractTests`; `AICenterReadinessUxTests` | PR #189; none |
| `/settings/ai-providers` | Enable supported provider, save and reload | `SettingsManage` → runtime catalogue → encrypted application settings | BROWSER VERIFIED | `AIProviderRuntimeAvailabilityTests`; `AIProviderSettingsAvailabilityUxTests` | PR #190; none |
| `/automation-center` | Schedule/run supported automation | ownership/entitlements → real Sync or SEO executors; unsupported generic Content Operation disabled | BROWSER VERIFIED | `ExecutionRuntimeAuthenticityContractTests`; `ExecutionAuthenticityUxTests` | PR #192; none |
| `/execution-center`, `/module/execution` | Monitor runtime work | real tracker / external approved-change jobs → `ExecutionCenterService`; no simulator | BROWSER VERIFIED | `ExecutionCenterTests`; `ExecutionCenterExternalExecutionTests`; `ExecutionAuthenticityUxTests` | PR #192; PR #201 real external-job consumer |
| `/settings/sessions` | Revoke owned session | circuit identity → `ApplicationSessionAdministrationService.EndMySessionAsync` → persisted registry → security audit → refreshed UI | BROWSER VERIFIED | `SessionRevocationUxTests` | PR #203; none |
| `/admin/sessions` | End sessions for another account | `Users.Manage` → `EndUserSessionsAsync` → persisted registry → security audit → refreshed inventory | BROWSER VERIFIED | `SessionRevocationUxTests` | PR #203; none |
| `/admin/application-users` | Create account / disable account | `Users.Manage` → `ApplicationUserAdministrationService` → `AuthUsers` persistence + password hashing → security audit; disable revokes persisted sessions → refreshed UI | BROWSER VERIFIED | `ApplicationUserAdministrationUxTests` | PR #204 merged exact-head green as `7646c73f47e82dd46dbb76b6a45fca3a49bc40b8`; none |
| `/admin/roles-permissions` | Create custom role / assign it to an application user | `Settings.Manage` → `ApplicationRoleAdministrationService` → `ApplicationRoleStore` (`Security.CustomRoles`) + authorization audit; assignment → `ApplicationUserAdministrationService` → `AuthUsers` + session revocation + account audit → refreshed UI | BROWSER VERIFIED | `RolesPermissionsUxTests` | PR #205 merged exact-head green as `c981544b0a960ded36f3915acf3437f8738bc336`; none |
| `/reports`, `/module/reports` | Load live reports / export Sites CSV | owner-scoped `SiteWebService` + approval/automation/planner services → rendered application data → `aiwmReports.downloadCsv` browser download | BROWSER ACCEPTANCE IN REVIEW | `ReportsExportsUxTests` | Current #183 slice proves an owned persisted site is rendered and the visible Sites CSV control produces `sites-report.csv` containing that real site data |
| `/sites/{siteId}/comments` | Approve comment / reply | `Content.Edit` → `WordPressCommentsWebService` → authenticated WordPress REST → refreshed UI | BROWSER VERIFIED | `CommentsModerationUxTests` | PR #178; none |
| `/sites/{siteId}/taxonomy` | Create/edit/delete category | `Content.Edit` → `WordPressTaxonomyWebService` → authenticated WordPress REST → full sync → SQLite → UI | BROWSER VERIFIED | `TaxonomyMutationsUxTests` | PR #179; none |
| `/sites/{siteId}/media` | Upload media | `Content.Edit` → `MediaBatchUploadPanel` → authenticated `POST /wp-json/wp/v2/media` → production sync → UI | BROWSER VERIFIED | `MediaUploadUxTests` | PR #196; none |
| `/sites/{siteId}/media` | Update metadata / permanently delete media | `Content.Edit` → `WordPressMediaWebService` → authenticated WordPress REST → SQLite reconciliation → UI | BROWSER VERIFIED | `MediaUpdateDeleteUxTests` | PR #197; none |
| all production `.razor` surfaces | Prevent placeholder/simulated runtime constructs | repository-wide static anti-mock guard in normal unit-test CI | CONTRACT VERIFIED | `RazorProductionAntiMockGuardTests` | PR #198; none |
| `/sites/{siteId}/content/{post|page}/{id}/edit` | Save edited post/page | `Content.Edit` → remote-version conflict check → authenticated WordPress mutation → synchronization → fresh editor load | BROWSER VERIFIED | `ContentEditorMutationsUxTests` | PR #200; none |
| `/approvals` | Approve and execute supported WordPress content proposal | owner + `Approvals.Decide` → external execution job → `ApprovedChangeExecutionWorker` → explicit background authorization → WordPress editor → forced sync → execution + approval reconciliation | BROWSER VERIFIED | `ApprovedChangeExecutionWorkerContractTests`; `ApprovalExecutionUxTests` | PR #201 + PR #202; none |

## Closure evidence

- Generic module fallback and workspace hubs no longer present fabricated rows, hard-coded readiness, fake queues/logs/backups/reports, or inert demo actions.
- AI and execution surfaces use concrete registered providers/executors and persisted runtime state rather than local simulated success.
- Comments, taxonomy, media and Content Editor critical mutations have browser evidence through authenticated WordPress boundaries and reconciled UI.
- PR #198 keeps the repository-wide Razor anti-mock guard active in CI and rejects placeholder navigation, `NotImplementedException`, simulated non-cancellable work delays, and common Mock/Fake/Sample/Demo runtime dataset declarations.
- PR #202 closes the approval journey through the real hosted worker and requires `Executed` only after authenticated WordPress mutation and forced synchronization.
- PR #203 browser-verifies self-service and administrative session revocation with persisted reasons and append-only audit evidence.
- PR #204 is merged on `main` after exact-head green CI. Browser acceptance creates an account through the administrator UI, proves a non-plaintext password hash and `User.Created` audit, then disables it through the real confirmation dialog and requires persisted `IsActive=false`, session revocation, `User.Disabled` audit and rendered Disabled state.
- PR #205 is merged on `main` after exact-head green CI. `/admin/roles-permissions` now has browser evidence for least-privilege custom-role persistence, reserved-permission exclusion, `Role.Created` audit, persisted user assignment, `Account role changed.` session revocation, `User.Updated` audit, and refreshed role display.
- Reports/Exports was traced through runtime before changing it: its tables are populated from real application services, `App.razor` loads `reports-export.js`, and `aiwmReports.downloadCsv` creates a real browser Blob download. The current acceptance slice adds browser proof that owner-scoped persisted data reaches the rendered report and downloaded CSV rather than treating the existing JS call as sufficient evidence.
- The production tree currently has no known `href="#"`, `javascript:` placeholder navigation, or `NotImplementedException` occurrence from the Issue #183 scan.

## Remaining closure work

Issue #183 remains open. Before final closure:

1. Land exact-head green browser acceptance for `/reports` / `/module/reports`, then continue the remaining user-facing scan across Settings, synchronization/operations and other visible surfaces.
2. Every suspicious action must be traced to a real service/runtime destination or made explicitly unavailable; add narrower regression contracts whenever a concrete false-success/no-op pattern is removed.
3. Do not duplicate the active REL-003 Backup/Restore ownership stream; consume its merged production state when that dependency lands, then inspect resulting visible Backup/Restore behavior under #183.
4. Keep repository-wide anti-mock guards active in CI.
5. Close #183 only after the full Razor/user-facing scan is complete, required exact-head CI is green, all closure work is present on latest `main`, and no unresolved blocker remains.
