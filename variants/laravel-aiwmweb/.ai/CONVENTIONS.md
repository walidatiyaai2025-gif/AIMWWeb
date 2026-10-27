# Engineering Conventions

## Scope
- Keep Laravel variant writes inside `variants/laravel-aiwmweb/**` unless a fresh PCC packet explicitly authorizes another boundary.
- Do not mix ASP.NET runtime changes into a Laravel PR.

## Tenancy and authorization
- Resolve active tenant from authenticated context.
- Scope tenant-owned queries before resolving direct identifiers.
- Test foreign-tenant direct-ID/IDOR attempts.
- Treat permissions and ownership as separate checks where both matter.
- Never trust actor, tenant, entitlement, status, provider-success, or ownership fields supplied by the caller.

## Mutations
- Prefer explicit desired state over blind toggles when replay is possible.
- Use transactions/locks where concurrent mutation could violate invariants.
- Use idempotency for replayable external or queued actions.
- Verify authoritative post-state before reporting success when the operation contract requires it.
- Do not blindly retry destructive external mutations.

## External boundaries
- Keep credentials server-side and encrypted/scoped.
- Preserve truthful provider/WordPress error semantics.
- Reconcile ambiguous/lost responses with read-only authoritative checks.

## UI
- Bind visible controls to canonical operation IDs where parity requires it.
- A button that only toasts, simulates, or fabricates success is not terminal.
- Loading/error/disabled states must reflect real runtime state.

## Tests
- Add focused deterministic tests for the changed contract.
- Security-sensitive work includes tenant isolation and caller-owned-field rejection tests.
- Run the full repository-required suites; focused green tests do not override full-suite failures.

## Knowledge hygiene
- Stable rule -> `DECISIONS.md` or `CONVENTIONS.md`.
- Recurring verified hazard -> `KNOWN_ISSUES.md`.
- Task-specific transient state -> handoff/live GitHub, not durable docs.
- Repeatable execution improvement -> update the relevant skill with evidence.
