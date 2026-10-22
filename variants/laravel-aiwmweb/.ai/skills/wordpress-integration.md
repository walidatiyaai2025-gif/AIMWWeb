# Skill: WordPress Integration

## Trigger
Site verification/sync, content/media/taxonomy/comments/users, Connector capability, or any WordPress mutation.

## Procedure
1. Confirm tenant/site ownership and credentials are server-derived.
2. Choose native REST for standard capability; Connector for advanced/sensitive capability.
3. For Connector: enforce explicit scope, signed/versioned request, replay protection, WordPress capability, and local audit.
4. Treat remote IDs as site-scoped data; prevent cross-site/tenant substitution.
5. Do not blindly retry destructive methods.
6. On ambiguous/lost response, reconcile with safe read-only checks when possible.
7. Persist local state only when remote outcome is authoritative.
8. Verify post-state and produce evidence/audit before success when required.
9. Test remote failure, unauthorized scope, replay/idempotency, and authoritative absence/presence semantics as applicable.
10. Run disposable/real REST E2E gates required by the repository.

## Never
Expose WordPress credentials to the browser or convert a provider error into a local success.
