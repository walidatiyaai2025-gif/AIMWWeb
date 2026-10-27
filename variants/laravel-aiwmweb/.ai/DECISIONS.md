# Durable Engineering Decisions

Only proven decisions belong here. Each entry must identify durable evidence.

## ADR-001 — PCC and repository governance outrank chat memory
Decision: routing and operating rules come from live PCC plus committed repository governance. Chat history is advisory only.
Evidence: root `AGENTS.md`; PCC constitution; Issue #257.

## ADR-002 — Multi-tenancy is architectural
Decision: tenant context, authorization, persistence, queues, cache/locks, credentials, audit/evidence, billing, and integrations are tenant-scoped by design.
Evidence: Issue #257; root `AGENTS.md`; variant `README.md`.

## ADR-003 — Caller input never establishes tenant ownership
Decision: tenant identity and ownership are resolved server-side from authenticated context and scoped records. Caller-owned `tenant_id`-style overrides are rejected or ignored according to endpoint contract.
Evidence: Issue #257; integrated tenant-isolation closure evidence.

## ADR-004 — WordPress uses a dual execution path
Decision: native REST handles standard operations; AIMW Connector handles advanced/sensitive operations with explicit scopes and signed/replay-protected protocol.
Evidence: Issue #257; variant `README.md`.

## ADR-005 — Canonical parity denominator is preserved
Decision: migration progress is measured against the canonical 931-operation inventory. Operations are not removed to improve percentage.
Evidence: Issue #257; `docs/operation-parity-reconciliation.md`.

## ADR-006 — Exact-head CI is required evidence
Decision: green checks on another SHA do not authorize merge or completion. Required gates must prove the exact candidate.
Evidence: `docs/REPO_OPERATING_SYSTEM.md`; Issue #589.

## ADR-007 — GitHub is the interruption checkpoint
Decision: after a new chat, lost response, or worker replacement, reconstruct from live composition/Issue/branch/PR/CI state rather than remembered prose.
Evidence: `docs/REPO_OPERATING_SYSTEM.md`.

## ADR-008 — Repository-native AI knowledge is advisory, evidence-backed memory
Decision: `.ai/**` captures stable understanding and repeatable skills, while dynamic truth remains live GitHub/PCC/reconciliation/CI.
Evidence: Issue #698.
