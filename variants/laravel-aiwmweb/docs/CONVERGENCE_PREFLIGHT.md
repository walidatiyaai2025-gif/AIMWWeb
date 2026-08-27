# Laravel AIWMWeb Convergence Preflight

Authority: Issue #257. Worker 11 is a dry-run preflight specialist; Fresh Codex remains the only integration authority.

## Captured live graph

Canonical base is `main` at `e8abab4d9ce9487efdb97ea21a37ca3fbb99e0eb` (Tenant Core + merged acceptance framework).

| Role | Live source | Captured head | Dependency |
| --- | --- | --- | --- |
| Site / Connector protocol | PR #260 | `85b83ce53ce6be434176964bc77ced6beefa6e68` | main |
| Advanced Connector | PR #269 | `391c717c2b197226207afe2ddf432dd86e0ce6eb` | contains #260 lineage |
| SEO closure | `worker/laravel-aiwmweb-seo-closure` | `600d83aab3392456bd59d0c60812f8d3b12b8a72` | stacked on #269 |
| Sites diagnostics | `worker/laravel-aiwmweb-sites-diagnostics` | `aa868f03a5ad095e165fb79f56a8427d6cffb76e` | stacked on #269 |
| Content | PR #263 | `7cb250d893fbf4c0f82b13093eec87e3c1310dfd` | main; consumes #260 contracts when present |
| Billing | PR #266 | `3c2e2904457b18882f0a168857ed149c067bdd1c` | main |
| AI Platform | PR #267 | `f981d18fde23bd02bb7b6783ed22b3df9fc81790` | main; fail-closed adapters |
| Admin / Operations | PR #264 | `7f0a297b331c4e324c8f76309b2c5cb61660e44a` | main |
| Production Runtime | PR #265 | `285355ada0032cd013bb9fb814b1211beb0eb53e` | main |
| Frontend | PR #262 | `81c639e23a96d7fbaceda66b6275ca27cb276ace` | main; integrate last |

Excluded from product composition:

- PR #268: zero changed files and explicitly not Email functionality.
- `worker/laravel-aiwmweb-email-delivery-closure`: still exactly equal to main; no implementation delta.
- `worker/laravel-aiwmweb-sync-reconciliation` at `bcd20b364a77ee4cebd1fb2e17a4941bc5401e7f`: three commits above #263 containing only `.sync-payload.part1`, `.part2`, `.part3`; staging transport, not reviewable product source.

## Dependency graph and order

```text
main / Tenant Core
 |
 +--> #260 Site + Connector protocol
 |      +--> #269 Advanced Connector
 |             +--> SEO closure
 |             +--> Sites diagnostics
 |
 +--> #263 Content --------consumes #260 Site/WordPressGateway
 +--> #266 Billing --------adapter required by #267 quota gateway
 +--> #267 AI Platform ----adapter required for Site + Approval
 +--> #264 Admin/Ops ------consumes content/connector gateways
 +--> #265 Runtime --------validates final backend/runtime stack
 +--> #262 Frontend -------discovery layer and catch-all LAST
```

Recommended convergence order: use #269 as the transport for #260+#269, then compose SEO closure, Sites diagnostics, #263, #266, #267, #264, #265, and #262 last. Rebuild the parity ledger only after the final source tree is stable.

## Confirmed migration findings

Expected lexical order after Tenant Core:

1. `2026_08_27_000100_create_demo_vertical_slice_tables.php` (#260/#269)
2. `2026_08_27_191500_create_ai_platform_tables.php` (#267)
3. `2026_08_27_210000_create_billing_platform.php` (#266)
4. `2026_08_27_210000_create_content_platform_tables.php` (#263)
5. `2026_08_27_210000_extend_seo_parity_tables.php` (SEO closure)
6. `2026_08_27_220000_create_admin_operations_tables.php` (#264)
7. `2026_08_27_231000_expand_seo_domain.php` (SEO closure)
8. `2026_08_27_232000_create_site_diagnostics_tables.php` (Sites diagnostics)

The shared `210000` timestamp is not itself a duplicate filename; lexical ordering is deterministic. SEO extension tables depend on the #260 base and therefore must follow #269.

Confirmed mechanical blocker: #260 `synced_contents` and #263 `content_items` both explicitly name an index `content_remote_unique`. MySQL permits that name on different tables; SQLite index names are schema-global. The disposable composition renames only #263's index to `content_items_remote_unique`, preserving columns and uniqueness semantics.

No duplicate `Schema::create()` table name or duplicate migration filename was found in the canonical captured set. Sites diagnostics adds `site_diagnostics` and `site_operation_histories` with FKs to the canonical #260 `sites` table rather than creating another Site table.

## Shared-file conflict plan

### `app/Providers/AppServiceProvider.php`

Touched by #260/#269, #263, #266 and #267. Bindings are additive in the mechanical union: WordPressGateway, AdvancedWordPressGateway, legacy AiProvider, ContentRemoteDriver, BillingProvider, AiQuotaGateway, AiGenerator, PlannerApprovalGateway and PlannerSiteGateway each have one concrete binding. Codex must later replace #267's fail-closed adapters deliberately; preflight does not invent adapters.

### `bootstrap/app.php`

Union #263 API routing, #265 correlation/trusted-proxy runtime middleware, and #266 platform-admin/PayPal exception semantics. No middleware alias collision was found.

### `routes/web.php`

Touched by #260/#269, SEO closure, Sites diagnostics, #262, #264, #265 and #266. Mechanical composition preserves #260 Site CRUD as canonical, adds non-overlapping SEO and diagnostics extension routes, adds Billing/Admin/health routes, uses #262's rich tenant-context shape, and keeps #262's `/tenants/{tenant}/{path?}` catch-all last.

Sites diagnostics also proposes `SiteManagementController` for the same CRUD URIs. Because the prompt declares #260 Site/Connector protocol canonical, preflight does not silently replace #260 CRUD. Whether Codex adopts the newer controller behind the same public contract is an integration decision.

### `routes/console.php`

#264, #265 and #266 commands/schedules are name-disjoint and can be mechanically unioned. Keep runtime heartbeat, `withoutOverlapping`, and Billing `onOneServer` semantics.

### `.env.example`

Use #265 runtime environment as baseline and append #266 PayPal variables. No secrets are introduced.

### CI workflow

#265 is the broad production acceptance superset; #262 requires strict frontend typecheck/test/build; #269 has the real Connector WordPress E2E. Final CI must retain all three characteristics.

## Model / contract collisions

There is a single canonical `App\Models\Site` lineage from #260, inherited by #269 and both newer Site/SEO branches. #263 does not define another Site model.

SEO closure and #267 both add `App\AI\Platform\Contracts\AiGenerator.php`, but the files are byte-identical (`6484c13fa93161d88a27429972c68abc9e7da131`). This is a lineage/ownership overlap, not an incompatible interface. Keep #267 as AI Platform authority while allowing SEO to consume the same contract.

#260 legacy `App\AI\AiProvider` / `AiProviderConfig` and #267 advanced AI Platform are different APIs. They do not collide at class level, but Codex must adapt the legacy suggestion journey rather than deleting either path during a mechanical merge.

#267 `AiQuotaGateway::check(...)` is not directly equivalent to #266 `UsageQuotaService::consume(...)`. An explicit Billing-to-AI quota adapter is required; direct rebinding would change semantics.

#260 owns governed `Approval` / `Execution` / evidence for the demo mutation journey. #264 owns separate automation/operation records. #267 ships a fail-closed `PlannerApprovalGateway`. Codex must select the canonical planner submission adapter.

## Frontend contract

#262 has typed API paths and explicit `api`, `capabilities`, `actions` and `connectors` discovery maps. Its current backend context intentionally returns empty discovery maps, so a compiled frontend can remain truthfully `pending_integration`. Codex must populate maps from the final real backend surface; preflight never marks availability based only on route existence.

Billing, AI, SEO and Sites diagnostics need final discovery-map entries after their public API surface is accepted.

## npm incompatibility

A strict composed install exposed a real dependency collision, not merely a stale lock:

- #262 declares `react` / `react-dom` `^18.3.1`.
- the Laravel 13 template keeps optional `@laravel/multiplex ^0.4.1`.
- the current lock resolves Multiplex `0.4.3`, whose direct dependency is `react ^19.2.7`.
- strict `npm install --package-lock-only` fails `ERESOLVE`; using `--legacy-peer-deps` would weaken the gate and is forbidden.

The disposable preflight tree therefore probes the smallest compatibility upgrade: React/ReactDOM to `^19.2.7` and React type packages to `^19`. It then regenerates the lock and requires strict `npm ci`, TypeScript, Vitest and Vite build. If that proof passes, Codex has a tested mechanical dependency fix; the frontend worker branch itself is not rewritten by Worker 11.

## Composition CI

The preflight workflow resets an ephemeral tree to captured main, fetches exact branch heads, composes them without merging anything to main, applies the mechanical overlays above, and then runs:

- Composer install
- strict npm lock regeneration + `npm ci`
- Pint normalization in the disposable tree followed by `pint --test`
- SQLite `migrate:fresh`
- MySQL 8.4 `migrate:fresh`
- `route:list --json` duplicate method/URI census
- duplicate table/index/FQCN/service-binding/package-lock/conflict-marker invariants
- full PHPUnit
- frontend typecheck, Vitest and Vite production build

Artifacts contain the exact-head manifest, merge log, route census and invariant report where reached.

## Codex must resolve

1. Decide whether Sites diagnostics' `SiteManagementController` replaces #260's CRUD implementation while preserving #260's public Site/Connector contract.
2. Adapt #260 legacy `SyncedContent`/SEO journey to #263 Content ownership without duplicating content truth.
3. Provide #266-backed `AiQuotaGateway` semantics for #267.
4. Connect #267 Planner Site and Approval gateways to canonical #260/#approval destinations.
5. Transition #260 legacy AI provider usage toward #267 without breaking existing governed mutation behavior.
6. Populate #262 discovery maps for Content, Billing, AI, SEO, Sites, Admin and Connector capability truth.
7. Commit a final deterministic npm dependency/lock decision only after strict frontend proof.
8. Regenerate the parity ledger after convergence.
9. Ignore #268 and email-delivery placeholder branches until actual Email source appears; ignore sync payload parts until reviewable source replaces staging transport.
