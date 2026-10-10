# Known Recurring Risks

This file is for verified recurring project risks, not a list of every open bug.

## KI-001 — Static parity summaries can become stale
Observed: `docs/CAPABILITY_PARITY_LEDGER.md` can retain bootstrap totals while `docs/operation-parity-reconciliation.md` contains the current materialized reconciliation.
Rule: use the canonical reconciliation and live generator output for current counts; never infer progress from the bootstrap summary.
Evidence snapshot: composition `f8d50324021a8d96b74b8d4384607882dc888bab`.

## KI-002 — Focused implementation success does not imply full-suite success
Observed repeatedly in closure work: a focused operation can be correct while SQLite/MySQL/full-suite integration exposes a regression or stale assumption.
Rule: do not merge based only on focused tests. Repair exact-head full-suite failures on the same task lineage.
Evidence: repository operating system and required acceptance/convergence gates.

## KI-003 — Stale branches are not evidence of recoverable work
Rule: compare branch ancestry/unique commits and canonical operation state before reusing, deleting, or superseding historical branches.
Evidence: Issue #257 handoff history; `docs/REPO_OPERATING_SYSTEM.md`.

## KI-004 — Provider success cannot be inferred from navigation/return URLs
Rule: billing and external-provider state must be verified against authoritative provider/webhook/reconciliation evidence.
Evidence: Issue #257 billing contract and root governance.
