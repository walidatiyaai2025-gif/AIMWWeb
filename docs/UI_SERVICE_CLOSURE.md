# UI → Service Closure Ledger

This ledger records user-visible capabilities that have been inspected end-to-end. Runtime behavior and the current GitHub implementation remain authoritative.

| Route / page | User action | Service / backend target | Status | Automated evidence | PR / commit | Remaining blocker |
|---|---|---|---|---|---|---|
| `/sites/{siteId}/seo` | Open owned SEO workspace and load synchronized posts/pages | `SeoAnalysisWebService` → ownership → Premium SEO entitlement → SQLite synchronized content | BROWSER VERIFIED | `SeoAuditUxTests.Owned_site_without_Premium_SEO_shows_entitlement_error_instead_of_false_not_found`; success journey setup/load assertions | PR #177 | None |
| `/sites/{siteId}/seo` | Run full audit | `SeoAuditExecutionService` → entitlement/ownership → analysis → `ISeoAuditService` persistence → `ExecutionOperationTracker` → refreshed UI/history | BROWSER VERIFIED | `SeoAuditUxTests.Run_full_audit_from_UI_persists_history_and_surfaces_execution_center_job` | PR #177 | None |
| `/module/execution` | Inspect completed SEO audit operation | `ExecutionCenterService` / `ExecutionOperationTracker` | BROWSER VERIFIED | SEO browser journey navigates from the SEO workspace and asserts the completed `Run SEO audit` job | PR #177 | None |
| `/sites/{siteId}` → `/sites/{siteId}/comments` | Save/test WordPress credentials, then approve a pending comment | `SiteWebService.SaveCredentialAndTestAsync` → protected credential persistence → `WordPressCommentsWebService.ApproveAsync` → `IWordPressApiClient` → `POST /wp-json/wp/v2/comments/{id}` → refreshed UI | BROWSER VERIFIED | `CommentsModerationUxTests.Comment_moderation_and_reply_reach_WordPress_REST_and_refresh_the_UI` records authenticated connection-test traffic, the real status payload, and the approved state after UI refresh | PR #178 | None |
| `/sites/{siteId}/comments` | Reply to an existing WordPress comment | `Content.Edit` authorization → `WordPressCommentsWebService.ReplyAsync` → `IWordPressApiClient` → `POST /wp-json/wp/v2/comments` → refreshed UI | BROWSER VERIFIED | Same browser journey records `post`, `parent`, reply content, and `approved` payload at the HTTP boundary, then asserts the reply is visible after reload | PR #178 | None |
| `/sites/{siteId}` → `/sites/{siteId}/taxonomy` | Save/test WordPress credentials, then create a category | `SiteWebService.SaveCredentialAndTestAsync` → protected credential persistence → `Content.Edit` → `WordPressTaxonomyWebService.CreateAsync` → `IWordPressApiClient` → `POST /wp-json/wp/v2/categories` → `WordPressSyncWebService` → SQLite snapshot → refreshed UI | BROWSER VERIFIED | `TaxonomyMutationsUxTests.Category_create_update_delete_reaches_WordPress_REST_and_reconciles_the_UI` records the authenticated create payload, verifies the five-endpoint sync sweep, and asserts the new category from the reconciled local snapshot | PR #179 | None |
| `/sites/{siteId}/taxonomy` | Edit a category | `Content.Edit` → `WordPressTaxonomyWebService.UpdateAsync` → `IWordPressApiClient` → `POST /wp-json/wp/v2/categories/{id}` → `WordPressSyncWebService` → SQLite snapshot → refreshed UI | BROWSER VERIFIED | Same browser journey records the updated name/slug/description payload and verifies the stale name disappears after synchronization | PR #179 | None |
| `/sites/{siteId}/taxonomy` | Delete a category permanently | `Content.Edit` → `WordPressTaxonomyWebService.DeleteAsync` → `IWordPressApiClient` → `DELETE /wp-json/wp/v2/categories/{id}?force=true` → `WordPressSyncWebService` → SQLite reconciliation → refreshed UI | BROWSER VERIFIED | Same browser journey records the forced delete request, verifies a third five-endpoint sync sweep, and asserts the category disappears from the UI | PR #179 | None |

## Closure evidence

- The SEO workspace exposes the real `Run full audit` action and does not bypass production ownership or Premium SEO entitlement checks.
- The SEO browser journey verifies synchronized WordPress content, pagination, Details/Fix/external-link behavior, search/reset behavior, full-audit execution, persisted audit history after refresh, and the completed Execution Center job.
- An owned site without Premium SEO is rendered as an entitlement/access failure rather than a false `Site Not Found` state.
- Comment moderation acceptance starts at the real Site Details credential form, executes the production `Save & Test` path, and therefore keeps ownership, connection testing, and secret protection in the chain before any comment mutation can run.
- Approve and Reply are triggered through the actual Comments UI under `Content.Edit`; the acceptance fixture is an external WordPress-shaped HTTP server owned by the UX test project, not a production test endpoint or service bypass.
- The comments journey verifies the outbound REST method/path/body and Basic-authenticated connection test, then verifies the resulting moderation state and reply after the UI reloads from WordPress.
- Taxonomy CRUD acceptance starts with the real credential form, proves InteractiveServer readiness through user-visible tab state, and executes Create, Edit, and Delete from the actual taxonomy UI under `Content.Edit`.
- Each taxonomy mutation is verified at the authenticated WordPress REST boundary and must complete the production five-endpoint `WordPressSyncWebService` reconciliation before the browser accepts the updated SQLite-backed UI state.
- The taxonomy fixture lives only in the UX test project; no production endpoint, authorization bypass, synchronization bypass, or alternate API client was added.
- The repository UX Regression Gate contains dedicated `seo-audit`, `comments-moderation`, and `taxonomy-mutations` shards, with contract tests requiring all three shards to remain present.
- CI merge readiness is evaluated by GitHub checks for the PR head; transient harness failures are not treated as product closure evidence.

## Next candidates

- Reconstruct remaining user-visible actions from current `main` after PR #179 is integrated.
- Prefer unverified WordPress post/media/user mutations, dead/no-op controls, and UI handlers that stop before a real service or remote API boundary.
