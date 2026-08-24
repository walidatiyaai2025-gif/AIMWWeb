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
| `/admin/application-users` | Create account / disable account | `Users.Manage` → `ApplicationUserAdministrationService` → `AuthUsers` persistence + password hashing → security audit; disable revokes persisted sessions → refreshed UI | BROWSER VERIFIED | `ApplicationUserAdministrationUxTests` | PR #204; none |
| `/admin/roles-permissions` | Create custom role / assign it to an application user | `Settings.Manage` → `ApplicationRoleAdministrationService` → `ApplicationRoleStore` (`Security.CustomRoles`) + authorization audit; assignment → `ApplicationUserAdministrationService` → `AuthUsers` + session revocation + account audit → refreshed UI | BROWSER VERIFIED | `RolesPermissionsUxTests` | PR #205; none |
| `/reports`, `/module/reports` | Load live reports / export Sites CSV | owner-scoped `SiteWebService` + approval/automation/planner services → rendered application data → `aiwmReports.downloadCsv` browser Blob download | BROWSER VERIFIED | `ReportsExportsUxTests` | PR #206 merged exact-head green as `907a8dcdb64913312f10181de55dcdee7e296d48`; actions remain disabled during static prerender and the visible CSV control produces a real UTF-8 BOM download containing persisted site data |
| `/settings` | View application database provider/readiness | `IConfiguration` runtime keys `Database:Provider` + `Database:SetupComplete`, matching `DatabaseSetupService` provider contract; no connection strings or secrets exposed | BROWSER VERIFIED | `SettingsDatabaseProviderHonestyContractTests`; `SettingsDatabaseProviderUxTests` | PR #209 merged exact-head green into `main` as `62c46581bf1c456429517c5fbb0959583e972966`; none |
| `/module/sync` | Run synchronization and recover persistent history after a failed sync | owned site → `ReviewConflictsAsync` / `SynchronizeAsync` → local mirror + `GetHistoryAsync`; secondary history-read failures are surfaced without replacing the primary sync error | CONTRACT VERIFIED | `GlobalSynchronizationWorkspaceHonestyContractTests` | PR #210 merged; browser failure injection is not yet claimed |
| `/system-health` | Inspect configured database and platform health | real `SystemHealthWebService` → configured provider/storage/log diagnostics | CONTRACT VERIFIED | `SystemHealthDatabaseProviderHonestyContractTests` | PR #212 merged exact-head green as `2ddb01deda1c852a1c6859e6d7fc6101f771be27`; remaining actions and authorization boundary still require acceptance review |
| `/logs`, `/module/logs` | Inspect and copy live server log diagnostics | `Settings.Manage` route authorization → real `LogReaderService` files/lines | IN REVIEW | `OperationalDiagnosticsAuthorizationContractTests` | Reconciled #183 logs slice on latest `main`; requires exact-head green CI and browser acceptance before terminal status |
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
- PR #204 browser-verifies account creation/disable with password hashing, security audit and session revocation.
- PR #205 browser-verifies least-privilege custom roles, reserved-permission exclusion, persisted assignment, audit and session revocation.
- PR #206 is merged after exact-head green CI. Reports render owner-scoped persisted application data, export through the real browser Blob path, preserve UTF-8 BOM spreadsheet compatibility, and no longer expose enabled inert Refresh/CSV controls during InteractiveServer prerender.
- PR #209 is merged after exact-head green CI. Settings reports the real configured database provider and setup state without exposing connection strings or secrets.
- PR #210 preserves the primary synchronization failure and surfaces secondary history-refresh failure instead of swallowing it.
- PR #212 makes System Health database diagnostics provider-aware on the configured provider/storage path.
- The reconciled live-log slice adds the privileged `Settings.Manage` authorization boundary without replacing the real `LogReaderService` destination.
- The production tree currently has no known `href="#"`, `javascript:` placeholder navigation, or `NotImplementedException` occurrence from the Issue #183 scan.

## Remaining closure work

Issue #183 remains open. Before final closure:

1. Land exact-head green live-log authorization evidence and add browser acceptance, then continue Site Operations, maintenance/reliability, System Health actions, and other visible operational surfaces.
2. Add browser failure/retry/history evidence for synchronization; `CONTRACT VERIFIED` is not terminal where browser proof is required.
3. Every suspicious action must be traced to a real service/runtime destination or made explicitly unavailable; add narrower regression contracts whenever a concrete false-success/no-op pattern is removed.
4. Do not duplicate the active REL-003 Backup/Restore ownership stream; consume its merged production state when that dependency lands, then inspect resulting visible Backup/Restore behavior under #183.
5. Keep repository-wide anti-mock guards active in CI and complete the full Razor/control census defined by `docs/PRODUCTION_CLOSURE_100_PLAN.md`.
6. Close #183 only after the full visible-capability inventory is terminal, required exact-head CI is green, all closure work is present on latest `main`, and no unresolved blocker remains.
