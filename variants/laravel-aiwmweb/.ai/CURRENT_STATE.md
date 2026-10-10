# Current State Snapshot

This file is a convenience snapshot, not canonical authority. Reconstruct live GitHub state before acting.

Snapshot base composition SHA: `f8d50324021a8d96b74b8d4384607882dc888bab`

Canonical reconciliation at this snapshot:

- TOTAL: 931
- TERMINAL: 654
- PENDING: 277
- BLOCKED: 0
- OVERALL_PARITY_PERCENT: 70.25%
- Owner-only acceptance queue: NONE

Largest remaining domains at this snapshot include content, sync, billing, email, media, and taxonomy. Domain counts change as operations integrate.

## Always refresh before work

Run:

`python variants/laravel-aiwmweb/.ai/scripts/refresh_context.py`

Then verify:
- composition head;
- open Laravel PRs;
- active/next/ready handoff Issues;
- exact-head workflow conclusions;
- `docs/operation-parity-reconciliation.md`;
- owner acceptance queue.

Use `--write` only when intentionally committing an updated snapshot.
