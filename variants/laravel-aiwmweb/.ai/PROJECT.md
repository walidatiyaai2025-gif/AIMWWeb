# Project Identity

## Product

- Product family: `AIMWWEB`
- Variant: `LARAVEL_AIWMWEB`
- Implementation boundary: `variants/laravel-aiwmweb/**`
- Product authority: GitHub Issue #257
- Execution/handoff authority: GitHub Issue #589 and `docs/REPO_OPERATING_SYSTEM.md`
- Routing authority: `walidatiyaai2025-gif/project-control-center`

## Mission

Port the current AIMWWeb product to Laravel while preserving 100% functional parity and making multi-tenancy a first-class invariant.

## Runtime stack

- PHP 8.3+
- Laravel 13
- React 19 + TypeScript
- Vite
- MySQL/MariaDB production-like validation
- SQLite deterministic test/development path
- Redis queue/scheduler/locking validation
- WordPress REST plus AIMW Connector for advanced/sensitive operations

## Core product journey

`Tenant login -> Add/Pair WordPress site -> Verify -> Sync -> Explore -> SEO/AI -> Approval -> Execute -> Verify -> Evidence/Receipt`

## Non-negotiable invariants

- no cross-tenant read or mutation;
- tenant context is server-derived, not trusted from caller-owned identifiers;
- credentials and provider secrets never reach browser payloads;
- no fabricated success, demo-only success, or placeholder terminal states;
- real external mutations require truthful failure semantics and verification;
- retries must respect idempotency and destructive-operation safety;
- every canonical source operation remains represented in the parity denominator until terminal under repository rules;
- exact-head CI is evidence, not optional decoration.

## What does not belong here

Do not store current PR numbers, current pending counts, transient red CI, or branch-specific hypotheses in this stable identity file. Those belong in live GitHub or `CURRENT_STATE.md`.
