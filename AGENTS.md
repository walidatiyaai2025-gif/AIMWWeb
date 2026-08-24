# AIMWWeb Agent Instructions

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

## Permanent update-package delivery contract
When the user asks for any wording equivalent to **"نسخة"**, **"آخر نسخة"**, **"update package"**, **"installable package"**, **"package update"**, or asks for a build to install, the default deliverable is an **installable Windows/IIS ZIP produced by GitHub Actions**, not a source-code archive and not an unverified local build.

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