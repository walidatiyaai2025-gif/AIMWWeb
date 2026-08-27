# AIMWWeb 100% Production Closure Plan

This document is constitutional execution policy for reaching and preserving **100% user-facing production closure** for AIMWWeb. It is subordinate only to the actual repository state, security boundaries, and `AGENTS.md`, and is mandatory reading for any agent working on Issue #183 or claiming product completion.

## Mission

Reach a state where every visible route, control, action, dialog, menu item, bulk action, export, retry, confirmation, status, metric, and mutation is one of:

1. **BROWSER VERIFIED REAL** — the visible UI reaches the real authorization/ownership/entitlement boundary, application/service logic, persistence or WordPress/AI/job/runtime destination, reconciles the result, and browser evidence proves the user-visible outcome; or
2. **VERIFIED UNAVAILABLE** — the capability is explicitly non-interactive and honestly explains that it is unsupported/not configured/not entitled; it must not look actionable or claim success/readiness.

There is no third acceptable production state. `UNKNOWN`, `IN REVIEW`, `PLACEHOLDER`, `LOCAL ONLY`, `TOAST ONLY`, `SIMULATED`, or `ASSUMED` are temporary work states and prevent 100% closure.

## Team topology: one Lead + three Agents

### LEAD — Integration / Acceptance Captain

The Lead is the sole integration and completion authority for the 100% closure program.

Responsibilities:
- reconstruct source of truth from latest `main`, Issue #183, Issue #3, `docs/UI_SERVICE_CLOSURE.md`, this plan, open related PRs, latest merged closure PRs, and exact-head CI before assigning work;
- maintain the master route/control inventory and ensure every visible capability has an owner and a terminal status;
- split work into dependency-safe vertical slices and prevent two agents from editing the same production surface at the same time;
- reconcile/rebase work on current `main`, review scope/security/ownership, and merge only exact-head green PRs;
- reject documentation-only claims when browser or runtime evidence is required;
- arbitrate conflicts with REL-003 Backup/Restore and other active owners instead of duplicating their work;
- run the final cross-surface acceptance sweep and close #183 only when the 100% formula below is satisfied;
- after closure, require any new user-facing capability to add/update closure evidence before it can be considered release-ready.

The Lead must not become a fourth broad implementation worker while unowned integration/review work exists.

### AGENT 1 — UI / Interaction Census & Dead-Control Closer

Primary mission: prove that every visible UI control has an honest destination.

Ownership focus:
- Razor pages/components, navigation, menus, dialogs, modals, toolbars, data grids, bulk actions, empty/error/loading states, buttons and links;
- inventory every interactive control route-by-route;
- detect and remove `href="#"`, `javascript:`, inert controls, handlers that only mutate local UI, toast-only success, duplicate prototype workspaces, fabricated metrics/status/readiness, hard-coded records, fake exports/uploads/refreshes/retries;
- for each suspicious control, trace it to the real service or mark it explicitly unavailable;
- add narrow anti-regression contract tests for every concrete false-success/no-op pattern removed.

Agent 1 does not claim backend correctness merely because a handler exists. Any mutation requiring real runtime proof is handed to Agent 2/3 with exact route/control/service notes.

### AGENT 2 — Runtime / Service / Persistence Closure Engineer

Primary mission: make every claimed capability real end-to-end behind the UI.

Ownership focus:
- authorization, RBAC, ownership, tenant/account scope, entitlements;
- application/domain services, persistence, WordPress REST, AI providers, job workers, scheduling, synchronization, audits, retries, idempotency, conflict detection, timeouts;
- fix missing service destinations, swallowed/misleading exceptions, fake success, hard-coded readiness, missing workers/consumers, incomplete reconciliation and unsafe destructive flows;
- preserve security boundaries and never add test-only production endpoints;
- where a capability cannot be safely implemented, provide an explicit capability/readiness result so Agent 1 can render it honestly unavailable.

Agent 2 must leave production behavior testable through normal public UI/service boundaries.

### AGENT 3 — Browser Acceptance / Failure-State / Release Evidence Engineer

Primary mission: independently prove that the visible capability actually works and remains honest under failure.

Ownership focus:
- Playwright/browser acceptance for critical mutations and user-visible actions;
- verify request reaches real application/service boundary and, where applicable, authenticated WordPress/AI/job boundary;
- verify persistence/audit/session/job changes and visible UI reconciliation;
- verify confirmation, cancellation, retry, conflict, permission denied, provider unavailable, external failure and reload/restart states where material;
- maintain UX Regression Gate shards and closure ledger evidence;
- never weaken assertions to make CI green; fix race/locator/test-fixture issues without reducing the production contract.

Agent 3 is the independent evidence owner and should not mark a capability Browser Verified based only on Agent 1/2 statements.

## Mandatory ownership protocol

Before any agent edits code:
1. Fetch latest `main` and inspect open PRs.
2. Read Issue #183, Issue #3, `AGENTS.md`, this file, and `docs/UI_SERVICE_CLOSURE.md`.
3. Announce/record one dependency-safe slice with explicit routes/components and expected evidence.
4. Do not edit a surface owned by another active PR unless the Lead explicitly reconciles ownership.
5. Use a focused branch and PR referencing #183.
6. After every merge, the next worker reconstructs from GitHub; never continue from chat memory alone.

## 100% control inventory

The Lead maintains a complete inventory covering at minimum:
- Dashboard and navigation shell;
- Site list/details/connection diagnostics/explorer;
- Posts and Pages list/create/edit/delete/bulk/filter/export;
- Media upload/metadata/delete/export;
- Comments moderation/reply;
- Categories/Tags/Taxonomy;
- SEO workspace and suggestions/audits/execution/history;
- **AI SEO Remediation Workspace: AI-generated field-level proposals, preview/diff, per-field Apply, selected-row bulk Apply, Apply All Safe, WordPress persistence, re-read verification, partial-failure/retry, audit and rollback/undo evidence**;
- AI Center, providers, prompts and usage;
- Approvals and execution reconciliation;
- Scheduling, Automation Center and Execution Center;
- Synchronization/conflicts/history/retries;
- Logs, System Health, diagnostics and reliability;
- Reports and every export format exposed in UI;
- Backup/Restore after REL-003 ownership lands;
- Import/Export surfaces;
- Application users, WordPress users where exposed, roles/permissions and sessions;
- Settings and database/provider readiness;
- Workspace hubs and all `/module/*` routes;
- all dialogs, confirmations, retry/error/loading/empty states and bulk controls.

Each inventory row must record:
`Route | Visible control/capability | Permission/ownership | Real target | Reconciliation | Evidence | Status | PR/commit | Remaining blocker`.

## AI SEO Remediation constitutional acceptance

The existing SEO analyzer is not considered product-complete if it only diagnoses problems and sends the operator to manual editing. For supported remediation classes, AIMWWeb must turn analysis into an actionable, reviewable AI proposal and a real persisted mutation.

The minimum terminal capability set is exactly **8 independently countable closure rows**:

1. **Generate field-level AI proposals** — generate a concrete proposal for a supported field from current persisted content and configured AI provider/runtime; never fabricate provider success.
2. **Preview current vs suggested value** — show the current persisted value and AI suggestion before mutation, with enough context to understand what will change.
3. **Apply one field** — a user can apply only one proposed field without implicitly applying sibling proposals.
4. **Apply selected rows/fields** — a user can select a subset of content rows/fields and execute only that selection.
5. **Apply All Safe** — a deliberate bulk command applies all currently eligible safe proposals while review-required/destructive classes remain gated.
6. **Persist and re-read verify** — success requires authenticated WordPress mutation followed by authoritative re-fetch/reconciliation proving the persisted value; request dispatch or toast alone never counts.
7. **Partial failure, retry and idempotency** — bulk execution reports success/failure per row/field, retries only failed work, and repeated execution must not duplicate links/text or corrupt content.
8. **Audit plus rollback/undo evidence** — retain actor/time/content/field/before/after/result evidence and provide safe rollback/undo where the mutation class supports it; unsupported rollback must be explicit rather than fabricated.

Suggested lifecycle states are `NOT_GENERATED`, `AI_SUGGESTED`, `SELECTED`, `APPLYING`, `APPLIED`, `VERIFIED`, `FAILED`, and `NEEDS_REVIEW`. Only the final user-visible behavior and persisted evidence determine terminal closure status.

Safety classification is mandatory. Non-destructive metadata/text improvements may be eligible for bulk safe application when runtime validation permits. Destructive or identity-affecting operations such as slug/canonical/taxonomy deletion or large body replacement must remain review-gated unless a narrower constitutional rule explicitly proves them safe.

For these eight rows, AI SEO Remediation completion is calculated independently as:

`AI SEO Remediation % = terminal AI-remediation rows / 8 × 100`

It is also part of the global visible-capability inventory, so adding these rows increases the authoritative global denominator. The Lead must not report the historical denominator after this constitutional change. Until `docs/UI_SERVICE_CLOSURE.md` is reconciled with these eight rows, the old `22/27` percentage is historical and **must not be presented as current overall completion**.

Terminal acceptance for each mutation row requires, as applicable:
`analysis → AI proposal → preview/selection → authorization/ownership/entitlement → authenticated WordPress mutation → persisted re-read → visible reconciliation → audit/history`.

No row may be marked `BROWSER VERIFIED REAL` from static code, mocked provider output, local-only state, request dispatch, or a success toast.

## Status vocabulary

Only these terminal statuses count toward 100%:
- `BROWSER VERIFIED REAL`
- `VERIFIED UNAVAILABLE`

Temporary statuses:
- `CONTRACT VERIFIED` — implementation traced/static evidence exists but browser proof is still required for a critical visible flow;
- `IN REVIEW` — PR exists but exact-head CI/merge is incomplete;
- `BLOCKED` — explicit dependency/owner exists;
- `UNKNOWN` — not yet traced. `UNKNOWN` is a P0 closure gap.

## 100% formula

Closure percentage is calculated from the **complete visible-capability inventory**, not from number of Issues or PRs:

`Closure % = terminal inventory rows / total visible-capability inventory rows × 100`

Issue #183 may close only when:
- closure percentage = **100.00%**;
- `UNKNOWN = 0`, `IN REVIEW = 0`, `CONTRACT VERIFIED requiring browser proof = 0`, unresolved `BLOCKED = 0`;
- no known fabricated runtime dataset, fake metric/readiness, placeholder navigation, dead/no-op control, toast-only mutation, swallowed material error, simulated work or unsupported interactive capability remains;
- every destructive/critical mutation has browser evidence through the real boundary and result reconciliation;
- anti-mock guards are active in normal CI;
- final exact-head CI is green;
- latest `main` contains every closure commit;
- `docs/UI_SERVICE_CLOSURE.md` is final and matches `main`;
- Backup/Restore visible behavior is re-inspected after REL-003 work lands instead of being assumed complete;
- the Lead posts final evidence to #183 and only then closes it.

## Execution waves

### Wave A — Finish current #183 operational surfaces
Lead prioritizes current unclosed surfaces after existing Browser Verified core flows:
1. merge/finish live Logs authorization/evidence;
2. Site Operations / maintenance / reliability;
3. System Health remaining actions and diagnostics;
4. synchronization browser failure/retry/history evidence;
5. remaining Settings/operations/import-export visible controls;
6. mechanical Razor/control census for any surface not represented in the ledger.

### Wave A2 — AI SEO Remediation execution
Treat AI SEO remediation as priority closure work, not an optional future enhancement. Reuse the canonical SEO workspace and existing AI/provider/WordPress boundaries rather than creating a duplicate prototype workspace.

Dependency-safe order:
1. proposal model + provider/runtime generation contract + persisted current-value snapshot;
2. current-vs-suggested preview and per-field selection;
3. authenticated single-field WordPress mutation + re-read verification;
4. selected-row/field bulk execution with bounded concurrency, per-item result and idempotency;
5. Apply All Safe classification/gating;
6. audit/history and rollback/undo contract;
7. browser acceptance for single, selected, all-safe, partial failure, retry, permission/provider failure and persisted reload.

The Lead reports both the independent `AI SEO Remediation %` (`terminal/8`) and the recomputed global closure percentage on every meaningful checkpoint until all eight rows are terminal.

### Wave B — REL-003 dependency consumption
Do not duplicate active Backup/Restore implementation ownership. The Lead monitors #167/#172/#173 and related PRs. Once their work is merged:
- Agent 1 inventories every Backup/Restore UI claim/control;
- Agent 2 traces it to safe provider-aware backup/recovery runtime;
- Agent 3 proves supported browser/operator-visible paths and explicit unavailable states for unsupported providers/destructive actions.

### Wave C — Full repository anti-mock sweep
Run systematic source search plus route/control inventory reconciliation for:
`Mock`, `Fake`, `Sample`, `Demo`, `Example`, placeholders, hard-coded rows/scores/statuses/statistics, `href="#"`, `javascript:`, `NotImplementedException`, empty/swallowed catches, fixed/simulated delays, local-only success, message-only handlers, unreachable buttons, duplicate workspaces and unsupported interactive features.

Every finding is either fixed or documented as a false positive with a regression-safe reason. No unexplained exclusion is allowed.

### Wave D — Terminal acceptance and release candidate
Agent 3 executes the final browser matrix across all critical flows and representative failure states. The Lead then:
- verifies the inventory is 100%;
- verifies exact final `main` CI;
- verifies no open #183 implementation PR remains;
- verifies closure ledger and Issue evidence match the exact `main` commit;
- produces/retrieves the installable Windows/IIS package from that exact commit when a release is requested.

## Required browser evidence for critical controls

A critical visible control is not complete until evidence proves, as applicable:
`UI interaction → permission/ownership/entitlement → application service → persistence/external boundary → audit/job/session effects → synchronization/reconciliation → visible final state`.

For WordPress mutations, prove the authenticated REST request and reconciled UI. For AI, prove the configured provider/runtime path or explicit unavailable state. For jobs/approvals, prove the worker consumes the job and terminal status follows real execution. For exports, prove a real browser download with real persisted data. For account/security actions, prove persistence + audit + session effects.

## Non-negotiable safety rules

- Never push directly to `main`.
- Never close a task solely to make the project look complete.
- Never replace a real-but-incomplete capability with fabricated success.
- Never weaken tests, auth, ownership, RBAC, secrets, audit, retries, conflict detection, transactions or recovery guarantees to get green CI.
- Never expose secrets, connection strings, recovery material, raw keys or sensitive log content beyond authorized surfaces.
- Never add test-only production endpoints.
- Never count a hidden/unreachable control as fixed if another route/component still exposes it.
- Never claim 100% from static code search alone.

## permanent rule for future features

After #183 closes, this remains the release constitution: every new user-facing capability must enter the closure inventory and ship with a real runtime destination or an explicit unavailable state plus appropriate automated evidence. A feature that adds an actionable UI control without production closure evidence is a release blocker.