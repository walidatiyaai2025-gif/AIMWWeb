# Laravel AIWMWeb Convergence Preflight

Authority: Issue #257. This document is a **dry-run convergence aid**, not integration authority. Fresh Codex remains responsible for the final architectural and merge decisions.

## Captured live heads

| Authority | PR / branch | Captured head | Relationship |
| --- | --- | --- | --- |
| Tenant Core + acceptance | `main` | `e8abab4d9ce9487efdb97ea21a37ca3fbb99e0eb` | canonical base |
| Site / Connector protocol | #260 `feature/laravel-aiwmweb-demo-vertical-slice` | `85b83ce53ce6be434176964bc77ced6beefa6e68` | logical protocol authority |
| Advanced Connector | #269 `worker/laravel-aiwmweb-wp-runtime` | `391c717c2b197226207afe2ddf432dd86e0ce6eb` | contains #260 lineage plus advanced extension |
| Content | #263 `worker/laravel-aiwmweb-content-platform` | `7cb250d893fbf4c0f82b13093eec87e3c1310dfd` | independent worker |
| Billing | #266 `worker/laravel-aiwmweb-billing-platform` | `3c2e2904457b18882f0a168857ed149c067bdd1c` | independent worker |
| AI Platform | #267 `worker/laravel-aiwmweb-ai-platform` | `f981d18fde23bd02bb7b6783ed22b3df9fc81790` | independent worker; fail-closed adapters |
| Admin / Operations | #264 `worker/laravel-aiwmweb-admin-operations` | `7f0a297b331c4e324c8f76309b2c5cb61660e44a` | independent worker |
| Production Runtime | #265 `worker/laravel-aiwmweb-production-runtime` | `285355ada0032cd013bb9fb814b1211beb0eb53e` | independent worker |
| Frontend | #262 `worker/laravel-aiwmweb-full-frontend` | `81c639e23a96d7fbaceda66b6275ca27cb276ace` | independent worker; merge last |
| Email handoff | #268 `worker/laravel-aiwmweb-email-notifications` | `2b54783b24834b41ed60a4ee73d7f50213b16a21` | zero changed files; **not product functionality** |

Additional live branch census:

- `worker/laravel-aiwmweb-sync-reconciliation` = `f3855fa2c2728ab5920deb24e32c566caa0cc7ec`, one commit above #263 that only adds `.sync-payload.part1`. It is staging material, not reviewable product source, and is excluded from the composition.
- `worker/laravel-aiwmweb-email-delivery-closure` currently equals `main` exactly and has no implementation delta.
- No Laravel `seo-closure` branch was present under that name.
- No Laravel `sites-diagnostics` branch was present under that name.

The machine-readable capture is `tools/convergence/manifest.json`.

## Dependency graph

```text
main / Tenant Core
 |
 +--> #260 Site + Connector protocol --------------------+
 |       |                                               |
 |       +--> #269 Advanced Connector (stacked)          |
 |                                                       |
 +--> #263 Content ----consumes Site/WordPressGateway----+
 |                                                       |
 +--> #266 Billing ----quota/entitlement adapter-------> #267 AI Platform
 |                                                       |
 +--> #264 Admin/Operations --connector/content gateways-+
 |                                                       |
 +--> #265 Runtime --validates DB/Redis/queue/WP----------+
 |                                                       |
 +--> #262 Frontend --discovers all integrated APIs-------+
```

`#269` is intentionally used as the **composition transport for #260 + #269**. The #260 head is an ancestor of #269 and its canonical Site/Connector types are retained. Merging both as unrelated feature trees is unnecessary and creates avoidable conflict churn.

## Recommended integration / cherry-pick order

1. Establish #260 as the logical Site/Connector contract authority; use the current #269 head as the transport that contains #260 plus its advanced extension.
2. #263 Content.
3. #266 Billing.
4. #267 AI Platform. Keep its fail-closed quota/site/approval gateways until explicit adapters are installed.
5. #264 Admin / Operations.
6. #265 Production Runtime.
7. #262 Frontend last, so its tenant catch-all route remains after every specific backend route.
8. Rebuild the parity ledger from the final source tree.
9. Integrate newer sync/email/SEO/sites work only when real reviewable source exists. Do not treat #268 as email functionality.

This is an integration order, not a request to merge any PR from this worker.

## Exact shared-file conflict set

### `app/Providers/AppServiceProvider.php`

Touched by #260/#269, #263, #266 and #267.

Bindings are **additive, not competing** when #269 transports #260:

- `WordPressGateway -> HttpWordPressGateway` (#260/#269)
- `AdvancedWordPressGateway -> HttpWordPressGateway` (#269)
- `AiProvider -> HttpAiProvider` legacy demo path (#260/#269)
- `ContentRemoteDriver -> DualPathContentDriver` (#263)
- `BillingProvider -> PayPalProvider` (#266)
- `AiQuotaGateway -> UnconfiguredAiQuotaGateway` (#267)
- `AiGenerator -> AiGenerationService` (#267)
- `PlannerApprovalGateway -> UnconfiguredPlannerApprovalGateway` (#267)
- `PlannerSiteGateway -> UnconfiguredPlannerSiteGateway` (#267)

Mechanical fix: union the bindings once. Architectural follow-up: Codex decides when the #267 fail-closed gateways are replaced by Billing/Site/Approval adapters.

### `bootstrap/app.php`

Touched by #263, #265 and #266.

Mechanical union required:

- load `routes/api.php` (#263),
- preserve `tenant.context`,
- add request correlation and trusted proxy handling (#265),
- add `platform.admin`, PayPal CSRF exception and Billing exception rendering (#266),
- preserve JSON rendering for API plus runtime health paths.

No middleware alias name collision was found.

### `routes/web.php`

Touched by #260/#269, #262, #264, #265 and #266.

The routes are largely disjoint. The one repeatedly edited route is `/tenants/{tenant}/context`: use #262's richer frontend context response, then integrate worker discovery data deliberately. Required order in the final file:

1. health + root,
2. #260 Connector/demo APIs,
3. #266 Billing APIs,
4. rich tenant context,
5. #264 Admin routes,
6. #260 console route,
7. #262 `/tenants/{tenant}/{path?}` frontend catch-all **last**.

The preflight CI runs `php artisan route:list --json` and fails on duplicate HTTP method + URI pairs.

### `routes/console.php`

Touched by #264, #265 and #266. Command names are disjoint:

- `ops:dispatch-due`,
- runtime health/MySQL/Redis/queue/scheduler commands,
- billing credential/maintenance commands.

Mechanical fix: union imports, commands and schedules. Do not drop `withoutOverlapping`; keep the runtime heartbeat and Billing `onOneServer` behavior.

### `.env.example`

Touched by #265 and #266. Use the Runtime environment as the production baseline and append the PayPal variables. No actual secrets are introduced.

### `.github/workflows/laravel-aiwmweb.yml`

Touched by #262 and #265. #265 is the broader production acceptance superset (MySQL, Redis, queues, scheduler, WordPress, Compose, artifacts). #262 adds mandatory frontend typecheck/test/build semantics. Final convergence must retain both. The preflight workflow independently executes strict `npm run typecheck`, `npm test`, and `npm run build` so a missing script cannot silently pass via `--if-present`.

### `tests/wordpress/bootstrap-wordpress.sh`

#269 provides the real advanced-Connector install/activate/schema/namespace test. It supersedes #265's earlier behavior that intentionally stopped when Connector source appeared. Final convergence should keep #269's real Connector E2E while retaining #265's disposable runtime infrastructure.

### Capability parity ledger

#260 and #264 contain stale/manual ledger edits relative to merged #261 acceptance authority. The final ledger must be regenerated from the fully composed source. The preflight composition restores merged-main generated ledger files rather than allowing stale worker conflict resolution to become canonical evidence.

## Migration plan and conflicts

Expected order by filename:

1. merged-main Tenant Core migrations,
2. `2026_08_27_000100_create_demo_vertical_slice_tables.php` (#260/#269) — creates canonical `sites` and Connector/demo tables,
3. `2026_08_27_191500_create_ai_platform_tables.php` (#267),
4. `2026_08_27_210000_create_billing_platform.php` (#266),
5. `2026_08_27_210000_create_content_platform_tables.php` (#263),
6. `2026_08_27_220000_create_admin_operations_tables.php` (#264).

The two `210000` migrations have distinct filenames and no direct FK dependency; Laravel's lexical ordering makes Billing run before Content. Retimestamping is therefore not required for correctness, though Codex may choose a deterministic naming cleanup during convergence.

### Confirmed mechanical migration blocker

#260's `synced_contents` and #263's `content_items` both explicitly name a unique index `content_remote_unique`. MySQL permits identical index names on different tables. SQLite index names are schema-global, so the composed SQLite migration can fail with `index content_remote_unique already exists`.

Safe preflight overlay: rename the #263 index in the ephemeral composition to `content_items_remote_unique`. Column set and uniqueness semantics remain unchanged. The final convergence should commit an equivalent name disambiguation on the integrated source.

### Other migration findings

- No duplicate `Schema::create()` table names were found across the captured canonical workers.
- No exact duplicate migration filename was found.
- `users.platform_admin` is added only by #266.
- No competing FK definition for the same column/table was found.
- #263 and #267 intentionally use raw `site_id` values rather than introducing a second Site table/foreign key. Whether to strengthen those FKs later is a domain/integration decision, not a preflight mechanical fix.
- `SyncedContent` (#260) and `ContentItem` (#263) are separate tables/models. That is a **semantic legacy-vs-content-authority overlap**, not a duplicate-table error. Codex must decide the adaptation of the demo SEO pipeline to #263 ownership.

## Model and contract overlap

### Site / Connector

There is one canonical `App\Models\Site` lineage: #260, inherited by #269. #263 does not create a competing Site model and dynamically consumes `App\Models\Site` plus `App\Connector\WordPressGateway`. Keep #260 signatures canonical; apply #269 only as an extension.

### AI provider overlap

There is no duplicate PHP class name, but two layers exist:

- #260 legacy demo `App\AI\AiProvider` + `AiProviderConfig` used by `GenerateSuggestionJob`,
- #267 advanced `App\AI\Platform\...` provider/profile/generation contracts.

#267 deliberately does not redefine #260 classes. Codex must adapt the legacy suggestion flow to the canonical AI Platform when appropriate; preflight does not replace interfaces.

### Billing -> AI quota

#267 `AiQuotaGateway::check(tenantId, userId, workflow, requestedAdditional)` does not directly match #266 `UsageQuotaService::consume(metric, amount)` / entitlement APIs. #267 currently binds an `UnconfiguredAiQuotaGateway` and therefore fails closed. Codex must supply an explicit adapter with metric mapping and consume/check semantics; do not bind these classes directly merely because both concern quota.

### Approval / execution

#260 owns `Approval`, `Execution`, `EvidenceReceipt` for the governed demo mutation journey. #264 owns automation/operation execution records with different table/class names. #267 consumes a `PlannerApprovalGateway` and ships an unconfigured implementation. There is no class/table collision, but Codex must decide which canonical approval API the planner submits into.

## Frontend discovery contract

#262 already has typed paths for #260, #263 and #264 in `resources/js/contracts.ts`, but runtime availability is controlled by `FrontendContext.api`, `capabilities`, `actions`, and `connectors`. Its current context response intentionally advertises empty maps.

Therefore a composed build can be green while feature screens remain `pending_integration`. Codex must populate discovery maps from integrated, real backend capabilities. Preflight does not mark endpoints available merely because a route file exists.

Billing (#266) and AI Platform (#267) also need their final discovery keys mapped into #262's route/API contract after Codex establishes the canonical public API surface.

## npm / Composer preflight

Composer constraints are shared and no worker changes `composer.json` in the captured feature set.

Confirmed npm blocker: #262 updates `package.json` with React, TypeScript, Vitest, Testing Library, React Query and Zod, but does not update `package-lock.json`. #260/#269 brings an older lock whose root dependency contract lacks those packages. #265 correctly chooses `npm ci` whenever a lock exists. A raw composition therefore fails deterministic frontend installation.

Safe mechanical fix: after #262 is integrated, regenerate `package-lock.json` from the final `package.json`, then run `npm ci`, typecheck, tests and build. The preflight workflow performs this regeneration only inside the disposable composed working tree and its invariant scanner verifies root package/lock parity.

## CI composition proof

`.github/workflows/laravel-aiwmweb-convergence-preflight.yml` does not merge feature PRs to main. It:

1. checks out this preflight PR,
2. copies the preflight tooling to runner temp,
3. resets a disposable working tree to the captured main SHA,
4. merges #269, #263, #266, #267, #264, #265, #262 using pinned heads,
5. applies only the mechanical overlays described above,
6. regenerates the npm lock,
7. runs Composer install,
8. runs Pint normalization then `pint --test`,
9. runs SQLite `migrate:fresh`,
10. runs MySQL 8.4 `migrate:fresh`,
11. runs a route collision census,
12. runs the convergence invariant scanner,
13. runs the full PHPUnit suite,
14. runs frontend typecheck, Vitest and Vite production build,
15. uploads a merge log, route census and invariant report.

The merge strategy is intentionally not a final source history. Shared files are synthesized from their canonical owners only to prove compatibility and expose remaining non-mechanical blockers.

## Codex-owned decisions remaining

These are explicitly **not** fixed by Worker 11:

- adapt #260 legacy `SyncedContent` / SEO suggestion pipeline to #263 Content ownership,
- install #266-backed implementation of #267 `AiQuotaGateway`, including check-vs-consume semantics,
- connect #267 `PlannerSiteGateway` to canonical #260 Site authority,
- connect #267 `PlannerApprovalGateway` to the canonical governed approval/execution destination,
- decide how #260 legacy `AiProvider` transitions to #267 AI Platform without breaking the demo journey,
- populate #262 frontend API/capability/action/connector discovery maps from final integrated routes,
- reconcile the final authoritative workflow file, preserving #265 production gates plus strict #262 frontend gates and #269 real Connector E2E,
- regenerate the parity ledger after final convergence,
- ignore #268 as product functionality and wait for real email-delivery source,
- exclude `.sync-payload.part1` until sync-reconciliation is committed as reviewable source.
