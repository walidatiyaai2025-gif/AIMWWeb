# AI SEO Remediation Execution Contract

TASK_ID: `AIMW-SEO-AI-001`
PROJECT_ID: `AIMWWEB`
TARGET_SCOPE: `PROJECT`
PCC_SOURCE_SHA: `a9093cf20f9d068c01444f772b74005ccb198ef3`
CANONICAL_TASK_BRANCH: `closure/183-ai-seo-remediation-constitution`
PARENT: Issue #253
PROGRAM: Issue #183
ROADMAP: Issue #3

## Mission

Turn the existing SEO analysis experience into actionable AI remediation without creating a duplicate workspace. The canonical `/sites/{siteId}/seo` surface must generate reviewable field-level proposals and execute supported changes through the real authorization, AI-provider, WordPress mutation, synchronization/re-read and audit boundaries.

## Mandatory measurable denominator

The program owns exactly 8 terminal rows, defined in `docs/PRODUCTION_CLOSURE_100_PLAN.md` and `docs/UI_SERVICE_CLOSURE.md`.

`AI SEO Remediation % = terminal rows / 8 × 100`

Initial state on this constitutional candidate: `0/8 = 0.0%`.

The global visible-capability denominator becomes 35 rows after these 8 rows are added to the historical 27-row ledger. With the previously terminal 22 rows unchanged, the constitutional candidate starts at `22/35 = 62.9%` overall verified completion. This number must only increase from terminal evidence; implementation presence alone does not increase it.

## Dependency-safe execution order

1. Proposal model and provider/runtime generation contract using current persisted content.
2. Current-vs-suggested preview and field selection.
3. One-field Apply through authenticated WordPress mutation and authoritative re-read.
4. Selected rows/fields bulk Apply with bounded concurrency and per-item results.
5. Apply All Safe with explicit safety classification and review-gated exclusions.
6. Partial failure, failed-only retry and idempotency.
7. Audit/history and rollback/undo where supported.
8. Browser acceptance for permissions, provider unavailable, single apply, selected apply, all-safe, persistence/reload, partial failure, retry and audit/rollback behavior.

## Non-negotiable runtime chain

`analysis -> configured AI provider/runtime -> proposal -> preview/selection -> authorization/ownership/entitlement -> authenticated WordPress mutation -> authoritative re-fetch/synchronization -> visible reconciliation -> audit/history`

A request dispatch, local state mutation, toast, or mocked provider response is never success.

## Safety boundary

Safe bulk execution may include bounded non-destructive metadata/text improvements only when runtime validation confirms eligibility. Slug/canonical/taxonomy deletion, destructive identity changes, or large body replacement remain `NEEDS_REVIEW` unless a narrower acceptance contract explicitly proves them safe.

## Required handoff

Every implementation handoff must report exact base/head SHA, changed files, tests, exact-head CI, terminal AI-remediation rows, `AI SEO Remediation %`, recomputed global closure percentage, blockers and the next exact action. No worker may claim browser verification from unit/static evidence.