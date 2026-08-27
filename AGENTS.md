# AIMWWeb Agent Instructions

## PCC authority and routing contract
- This repository is managed through `walidatiyaai2025-gif/project-control-center` (PCC) as project `AIMWWEB`.
- Project model: `PRODUCT_FAMILY`; there is no repository-wide default implementation scope. Every write must resolve to `CORE` or exactly one routed `VARIANT`.
- Family manifest: `.pcc/project-family.json`.
- Active variants are `AIMWWEB_CURRENT` (primary/current ASP.NET product) and `LARAVEL_AIWMWEB` (Laravel AIWMWeb product variant).
- `LARAVEL_AIWMWEB` implementation is isolated to `variants/laravel-aiwmweb`. Variant-specific source, configuration, branding, dependencies, deployment behavior, database schema and runtime code must not leak into `AIMWWEB_CURRENT`.
- Shared `CORE` writes are blocked unless current PCC and `.pcc/project-family.json` both report `CORE_ROUTING_STATE=READY`. A branch name never establishes variant identity.
- Every Manager/Lead/Worker/QA/Integration/Release role must fetch current PCC `main`, read the PCC root `AGENTS.md` and applicable policies, resolve `AIMWWEB` through `portfolio/project-routing.json`, and obtain/reconcile a current PCC routing packet before implementation writes.
- Live GitHub state remains authoritative for repository SHAs, PRs, CI, production lineage, and implementation evidence. Stale prompts and historical SHAs are non-authoritative until revalidated.
- If PCC routing and this repository conflict, stop non-emergency writes and reconcile governance first. A verified production emergency may use only the minimum safe stabilization path allowed by the PCC emergency-production policy.
- Existing AIMWWeb production-closure, Patch, package, security, QA, release, and delivery contracts below remain authoritative for `AIMWWEB_CURRENT` and are not automatically inherited as Laravel packaging/deployment rules unless Issue #257 or later routed Laravel governance explicitly adopts them.
- Durable governance changes must be persisted in PCC and repository control files; chat memory is not canonical.

## Laravel AIWMWeb constitutional invariants

Issue #257 is the product authority for the initial `LARAVEL_AIWMWEB` port. Any Worker routed to that variant must preserve these invariants:

- Functional parity target is **100%** relative to the current AIMWWeb capability/action inventory. No existing capability may be silently dropped because the technology changes.
- Establish and maintain a canonical Capability Parity Ledger with stable capability/operation IDs. Terminal migration states are `PORTED`, `ADAPTED`, `VERIFIED_UNAVAILABLE_EXTERNAL`, or `BLOCKED`; missing rows are not allowed to count as progress.
- Multi-tenancy is architectural from first production code, not a later feature. Every tenant-owned record and runtime path must carry explicit tenant context and authorization.
- Tenant isolation covers at least memberships/RBAC, sites, connector pairings, credentials/secrets, provider configuration, AI usage, jobs, schedules, cache keys, locks, rate limits, approvals, executions, evidence, reports, audit logs, quotas, entitlements and idempotency keys.
- Direct identifier access must not cross tenant boundaries. Isolation tests must prove Tenant A cannot read, mutate, enqueue, approve, execute, cancel, retry or inspect Tenant B resources.
- Laravel owns application/domain/backend runtime. The frontend must preserve current AIMWWeb visual and workflow parity as closely as practical.
- Managed WordPress targets use native WordPress REST where sufficient and the AIMW Connector plugin for advanced/sensitive operations.
- Connector sensitive scopes are disabled by default and explicitly enabled by the target-site owner. The Connector must still enforce WordPress capabilities, signed/versioned requests, replay protection and local audit.
- Provider keys and WordPress credentials remain server-side, tenant-scoped and absent from browser payloads/assets.
- Mutations require governed authorization, idempotency, before-state evidence where applicable, execution, verification and durable audit/receipt semantics.
- Laravel variant implementation writes must remain inside `variants/laravel-aiwmweb` unless a valid PCC packet explicitly routes a proven `CORE` change.

## Source of truth
- Reconstruct the current repository state from GitHub before acting. Do not treat chat memory or stale task trackers as authoritative.
- `main`, merged pull requests, current CI, and the actual implementation are authoritative.
- Do not claim a capability is complete unless the relevant UI/service/runtime path and required automated evidence are complete.

## Constitutional 100% production-closure contract

`docs/PRODUCTION_CLOSURE_100_PLAN.md` is mandatory execution policy for Issue #183 and for any future claim that AIMWWeb is fully wired or production complete.

Every agent working on user-facing production closure MUST read, in this order:
1. latest `main` and current CI;
2. Issue #183 and Issue #3;
3. this `AGENTS.md`;
4. `docs/PRODUCTION_CLOSURE_100_PLAN.md`;
5. `docs/UI_SERVICE_CLOSURE.md`;
6. all active PRs that overlap the intended surface.

The permanent team model for driving closure is **one Lead + three Agents**:
- **Lead — Integration / Acceptance Captain:** owns source-of-truth reconstruction, slice assignment, conflict/ownership arbitration, exact-head CI, integration, final inventory, and the only authority to declare 100% closure.
- **Agent 1 — UI / Interaction Census & Dead-Control Closer:** owns route/control inventory, Razor/UI tracing, dead/no-op/placeholder/fabricated controls and honest unavailable states.
- **Agent 2 — Runtime / Service / Persistence Closure Engineer:** owns authorization/ownership/entitlements, service/runtime/persistence/WordPress/AI/job wiring, real mutations, reconciliation and failure semantics.
- **Agent 3 — Browser Acceptance / Failure-State / Release Evidence Engineer:** independently proves critical visible flows end-to-end in the browser and owns the required regression/CI evidence.

No worker may claim 100% based on Issue count, PR count, handler presence, unit tests alone, or source search alone. The denominator is the complete visible-capability inventory defined by `docs/PRODUCTION_CLOSURE_100_PLAN.md`.

Issue #183 may close only when the inventory is **100.00% terminal** with every row either `BROWSER VERIFIED REAL` or `VERIFIED UNAVAILABLE`; there are zero `UNKNOWN`, unresolved `BLOCKED`, `IN REVIEW`, or browser-required `CONTRACT VERIFIED` rows; final exact-head CI is green; the closure ledger matches latest `main`; and no known fake/mock/sample/placeholder/no-op/toast-only/simulated/misleading production behavior remains.

This rule is permanent after #183: any future feature that adds an actionable user-facing control without a real production destination or an explicit unavailable state plus appropriate automated evidence is a release blocker.

## Constitutional Git-based Patch delivery contract
When the user asks for a **"Patch"**, **"باتش"**, or wording that clearly requests the project patch/update mechanism, this contract takes precedence over the prebuilt update-package contract below.

A Patch is a **small Windows updater ZIP that pulls and builds the latest approved merged `main` from GitHub at execution time**. It is not a source ZIP, not a prebuilt application payload, and must never be silently pinned to a historical commit.

Required behavior before delivering a Patch:
1. Fetch live GitHub state first: current `main`, open PRs, exact heads, mergeability, and required CI.
2. Integrate only production work that is genuinely completed, dependency-safe, merge-ready, and green on its required exact-head gates. Never force-merge failing, stale-conflicted, explicitly incomplete, or dependency-blocked work merely to make the Patch appear newer.
3. Re-fetch final `main` after any integration and treat that final merged state as the only production source of truth.
4. The Patch itself must resolve the current remote `main` again when it runs, then record and build the exact SHA it actually fetched. A SHA may be displayed as current provenance but must not be hard-coded as the updater's permanent source.
5. By default, refuse deployment when required GitHub Actions for that exact fetched `main` SHA are failed or not terminal. Any bypass must be explicit and opt-in.
6. Restore, build and publish the exact fetched source using the repository-supported .NET/runtime contract before IIS downtime.
7. Deploy through safe update semantics: full rollback backup, preservation of runtime data/local configuration, IIS stop/start, payload verification, and automatic rollback on deployment or health-check failure.
8. Verify the real application health endpoint after restart (`/health/live` unless the application contract changes).
9. Write durable local provenance including exact installed Git SHA, version, UTC install time, target path, and rollback backup location.
10. Hand the user the actual Git-updater ZIP. Do not substitute an older Actions artifact when the user explicitly requested a Patch.

### Patch safety invariants
The Git-based Patch must:
- use fresh remote `main` state on every run and avoid reuse of an old local checkout;
- build before taking IIS down whenever possible;
- preserve `Data`, `Logs`, `Screenshots`, `Backups`, `Exports`, `Temp`, `appsettings.Production.json`, and `appsettings.Local.json`;
- never delete runtime/user data to make deployment succeed;
- automatically restore the previous application when post-deployment verification fails and a rollback backup is available;
- leave an auditable marker such as `.aimw-git-update.json` and a human-readable last-update record containing the exact installed SHA;
- support an administrator-friendly one-command launcher (`.cmd`/`.bat`) for Windows Server.

### Patch naming
Use the stable delivery name:
`AIMWWeb-Git-Updater.zip`

The updater is intentionally reusable: running the same delivered Patch later must fetch the then-current approved `main`, subject to the exact-SHA quality gate above.

## Permanent update-package delivery contract
When the user asks for any wording equivalent to **"نسخة"**, **"آخر نسخة"**, **"update package"**, **"installable package"**, **"package update"**, or asks for a build to install, the default deliverable is an **installable Windows/IIS ZIP produced by GitHub Actions**, not a source-code archive and not an unverified local build. If the user explicitly says Patch/باتش, use the Git-based Patch contract above instead.

Required behavior on every such request:
1. Reconstruct the latest `main` state and identify the newest completed/merged product commit.
2. Verify the relevant GitHub CI evidence. Prefer the latest `main` artifact when available; never silently package an unmerged feature PR as the user's production update.
3. Use the permanent `Package AIMWWeb Update` GitHub Actions workflow/artifact format.
4. Download the GitHub Actions artifact and hand the actual ZIP file to the user directly.
5. Report the application version, exact source commit, artifact name, and SHA-256 when available.
6. Do not answer with only build commands, a GitHub source ZIP, or a link to source code when the requested installable artifact can be produced or retrieved.

### Required ZIP shape
The delivered archive must contain at its root:
- `app/` — published AIMWWeb application payload.
- `Install-Update.ps1` — safe Windows/IIS update installer.
- `README_AR.txt` — concise Arabic install/update instructions.
- `VERSION.txt` — application version, exact source commit, runtime, framework, and deployment mode.

### Update safety invariants
The installer must:
- create a full rollback backup before replacing an existing installation;
- preserve portable runtime directories: `Data`, `Logs`, `Screenshots`, `Backups`, `Exports`, and `Temp`;
- preserve local deployment overrides: `appsettings.Production.json` and `appsettings.Local.json` when they exist;
- stop/start the matching IIS site/app pool when possible, without requiring IIS for non-IIS installs;
- verify the installed application payload after replacement;
- automatically attempt rollback if replacement fails;
- never delete or overwrite user/runtime data merely to make an update succeed.

### Artifact naming
Use this stable naming convention:
`AIMWWeb-Update-<Version>-<ShortCommit>-win-x64`

The package must be produced from the exact GitHub commit recorded in `VERSION.txt`.

## UI-to-service work
For normal product implementation, continue closing one dependency-safe vertical slice at a time from the user-visible UI through authorization/ownership/entitlement, application/service/domain behavior, persistence or external WordPress/AI boundary, refreshed UI state, and automated browser evidence. Prefer dead/no-op controls and unverified WordPress mutations before cosmetic work.